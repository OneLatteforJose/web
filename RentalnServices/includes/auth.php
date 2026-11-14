<?php
require_once __DIR__ . '/db.php';
session_start();
// Sessions opened before the role was renamed from 'user' to 'customer' keep working.
if (($_SESSION['user']['role'] ?? '') === 'user') {
    $_SESSION['user']['role'] = 'customer';
}

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Invalid or expired form. Go back and try again.');
    }
}

// Call at the top of every admin page. Re-checks the DB so a deactivated admin is locked out immediately.
// A signed-in customer who opens an admin URL is only redirected: their own session is left alone.
function require_admin(): void {
    $isAdminSession = ($_SESSION['user']['role'] ?? '') === 'admin';
    if ($isAdminSession) {
        $st = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
        $st->execute([$_SESSION['user']['id'] ?? 0]);
        if ($st->fetchColumn()) {
            return;
        }
        $_SESSION = [];   // the admin was deactivated or removed
    }
    deny_access('admin');
}

// Called by require_admin() / require_manager() when the visitor may not open that area.
// Not signed in: go to the sign-in section on the home page. Signed in with another kind of account: say so
// (instead of silently sending them to their own page, which looks like the link did nothing) and offer to switch.
// 1500 => "₱1,500", 99.5 => "₱99.50". Used by the admin, manager and customer pages.
function peso($n): string {
    $n = (float)$n;
    return "\u{20B1}" . number_format($n, fmod($n, 1.0) == 0.0 ? 0 : 2);
}

function deny_access(string $area): void {
    $role = $_SESSION['user']['role'] ?? '';
    if ($role === '') {
        header('Location: ../index.php#login');
        exit;
    }
    require_once __DIR__ . '/site.php';
    $labels = ['admin' => 'an admin', 'manager' => 'a manager', 'customer' => 'a customer', 'provider' => 'a provider'];
    $homes = ['admin' => 'admin/dashboard.php', 'manager' => 'manager/dashboard.php', 'provider' => 'provider/dashboard.php', 'customer' => 'home.php'];
    $needs = ['admin' => 'an admin', 'manager' => 'a manager', 'provider' => 'a provider'];
    http_response_code(403);
    public_header('Wrong account', false, '../');
    ?>
<section class="section">
  <div class="wrap">
    <h2>This is the <?= e($area) ?> area</h2>
    <p class="intro">You are signed in as <?= e($_SESSION['user']['name'] ?? 'this user') ?>, <?= e($labels[$role] ?? 'another kind of user') ?>.
      The <?= e($area) ?> area needs <?= e($needs[$area] ?? 'a different') ?> account. Log out and sign in with that account to open it.</p>
    <p>
      <a class="btn btn-road" href="../logout.php">Log out and sign in again</a>
      <?php if (isset($homes[$role])): ?>
        <a href="../<?= e($homes[$role]) ?>" style="margin-left:1rem;font-weight:600">Go to my page</a>
      <?php endif; ?>
    </p>
  </div>
</section>
<?php
    public_footer();
    exit;
}

function flash(string $msg): void {
    $_SESSION['flash'] = $msg;
}

// Best-effort audit trail. Never put passwords or other secrets in $details.
function log_action(string $action, string $targetType = '', ?int $targetId = null, string $details = ''): void {
    try {
        db()->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)")
            ->execute([$_SESSION['user']['id'] ?? null, $action, $targetType ?: null, $targetId, substr($details, 0, 255)]);
    } catch (Throwable $e) {
        // logging must never break the page
    }
}
