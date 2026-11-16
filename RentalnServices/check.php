<?php
// Sign-in diagnostic. Open http://localhost/RentalnServices/check.php, read the red lines, then DELETE this file.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This page only works on the computer that runs the server.');
}
require_once __DIR__ . '/includes/db.php';
session_start();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$rows = [];
function check(string $label, bool $ok, string $detail = ''): void { global $rows; $rows[] = [$label, $ok, $detail]; }

check('PHP version', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
check('PDO MySQL driver loaded', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '' : 'In XAMPP, open php.ini, make sure extension=pdo_mysql is not commented out, then restart Apache.');
check('Settings the app uses', true, 'host ' . DB_HOST . ', port ' . DB_PORT . ', database "' . DB_NAME . '", user "' . DB_USER . '"' . (is_file(__DIR__ . '/config.local.php') ? ' (from config.local.php)' : ' (defaults)'));

$pdo = null;
try {
    $pdo = db();
    check('Connected to MySQL and the database', true);
} catch (Throwable $e) {
    check('Connected to MySQL and the database', false, $e->getMessage() . ' | Start MySQL in XAMPP and check that a database named "' . DB_NAME . '" exists.');
}

$accounts = [];
$roleType = '';
if ($pdo) {
    $have = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $need = ['users', 'areas', 'vehicles', 'services', 'bookings', 'service_requests', 'payments', 'reviews', 'provider_profiles', 'admin_logs'];
    $missing = array_values(array_diff($need, $have));
    check('All tables exist', !$missing, $missing ? 'Missing: ' . implode(', ', $missing) . '. Import booking_and_services.sql into this database.' : '');
    if (!in_array('provider_recommendations', $have, true)) {
        check('Optional: provider recommendations table', true, 'Not needed to sign in. Run migrations/001_provider_recommendations.sql when you want managers to recommend providers.');
    }

    if (in_array('users', $have, true)) {
        $roleType = (string)$pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'")->fetchColumn();
        $oldRole = strpos($roleType, "'user'") !== false;
        check('Customer role name is up to date', !$oldRole && strpos($roleType, "'customer'") !== false,
              $oldRole ? 'Your database still calls customers "user", but the pages now expect "customer", so customers cannot sign in. Fix: in the project folder run  C:\\xampp\\php\\php.exe database\\migrate_customer_role.php'
                       : 'roles: ' . $roleType);
        $hasApp = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'provider_profiles' AND column_name = 'application_status'")->fetchColumn();
        check('Provider sign-up and approval is set up', (bool)$hasApp,
              $hasApp ? 'provider_profiles has the application columns' : 'Providers cannot sign up yet. Fix: in the project folder run  C:\\xampp\\php\\php.exe database\\migrate_provider_applications.php');
        $hasSub = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'service_submissions'")->fetchColumn();
        check('Providers can propose new services', (bool)$hasSub,
              $hasSub ? 'the service_submissions table exists' : 'Providers cannot propose services yet. Fix: in the project folder run  C:\\xampp\\php\\php.exe database\\migrate_service_submissions.php');
        $accounts = $pdo->query(
            "SELECT u.id, u.email, u.role, u.is_active, u.area_id, a.barangay, a.city_municipality
             FROM users u LEFT JOIN areas a ON a.id = u.area_id ORDER BY FIELD(u.role, 'admin', 'manager', 'customer', 'user', 'provider'), u.id LIMIT 60"
        )->fetchAll();
        check('Accounts in this database', (bool)$accounts, $accounts ? count($accounts) . ' shown below' : 'None yet. Run create_admin.php (README step 4).');
    }
}

$_SESSION['check_count'] = ($_SESSION['check_count'] ?? 0) + 1;
$savePath = session_save_path() ?: sys_get_temp_dir();
check('Sessions can be saved', is_writable($savePath), 'folder: ' . $savePath . (is_writable($savePath) ? '' : ' is not writable, so sign-in cannot be remembered'));
check('Browser keeps the session cookie', $_SESSION['check_count'] > 1, $_SESSION['check_count'] > 1 ? 'seen ' . $_SESSION['check_count'] . ' times' : 'Reload this page once. If this stays red, the browser is blocking cookies for localhost.');

// What would happen if this person signed in on the home page?
function verdict(array $u): array {
    $role = $u['role'];
    if (!$u['is_active'])                    return ['bad', 'The account is deactivated, so sign-in is refused. An admin can activate it on the Accounts page.'];
    if ($role === 'user')                    return ['bad', 'Role "user" is the old name for customers. Run database\\migrate_customer_role.php once.'];
    if ($role === 'provider')                return ['bad', 'Providers cannot sign in yet: there are no provider pages. The message "not available yet" is expected.'];
    if ($role === 'manager' && !$u['area_id']) return ['bad', 'This manager has no barangay. A manager covers the city of their barangay, so the manager pages send them back to sign-in. Edit the account on the admin Accounts page and choose a barangay.'];
    if ($role === 'manager' && !$u['barangay']) return ['bad', 'This manager\'s barangay (id ' . (int)$u['area_id'] . ') is no longer in the areas table. Edit the account and choose a barangay again.'];
    $where = ['admin' => 'the admin dashboard', 'manager' => 'the manager dashboard for ' . $u['city_municipality'], 'customer' => 'the customer home page'][$role] ?? 'the home page';
    return ['ok', 'Signs in and opens ' . $where . '.'];
}

$result = null;
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = $pdo->prepare("SELECT u.id, u.role, u.is_active, u.area_id, u.password_hash, a.barangay, a.city_municipality FROM users u LEFT JOIN areas a ON a.id = u.area_id WHERE u.email = ?");
    $st->execute([trim($_POST['email'] ?? '')]);
    $u = $st->fetch();
    if (!$u)                                                      $result = ['bad', 'No account has that email in database "' . DB_NAME . '". Check the spelling, or you are looking at a different database.'];
    elseif (!password_verify($_POST['password'] ?? '', $u['password_hash'])) $result = ['bad', 'The email exists (role: ' . $u['role'] . ') but the password does not match.'];
    else                                                          $result = verdict($u);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign-in check</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem;color:#1c1f24}
ul{padding:0} li{margin:.4rem 0;list-style:none} .ok{color:#14532d} .bad{color:#7f1d1d;font-weight:600}
small{display:block;color:#555;font-weight:400} input,button{font:inherit;padding:.45rem .6rem} form{display:grid;gap:.6rem;max-width:360px}
.box{border:1px solid #ccc;border-radius:8px;padding:1rem;margin-top:1.5rem} table{border-collapse:collapse;width:100%;font-size:.9rem} td,th{border-bottom:1px solid #ddd;padding:.3rem .5rem;text-align:left}
</style></head><body>
<h1>Sign-in check</h1>
<ul>
<?php foreach ($rows as [$label, $ok, $detail]): ?>
  <li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '&#10003;' : '&#10007;' ?> <?= h($label) ?><?php if ($detail !== ''): ?><small><?= h($detail) ?></small><?php endif; ?></li>
<?php endforeach; ?>
</ul>

<?php if ($accounts): ?>
<div class="box"><h2>Accounts and what happens when they sign in</h2>
<table><tr><th>Email</th><th>Role</th><th>Barangay / city</th><th>Result</th></tr>
<?php foreach ($accounts as $u): [$k, $t] = verdict($u); ?>
  <tr><td><?= h($u['email']) ?></td><td><?= h($u['role']) ?></td><td><?= h($u['barangay'] ? $u['barangay'] . ', ' . $u['city_municipality'] : '-') ?></td><td class="<?= $k ?>"><?= h($t) ?></td></tr>
<?php endforeach; ?></table></div>
<?php endif; ?>

<div class="box">
  <h2>Test an email and password</h2>
  <p>This only checks them against the database. It does not sign you in, and nothing is saved.</p>
  <?php if ($result): ?><p class="<?= $result[0] ?>"><?= h($result[1]) ?></p><?php endif; ?>
  <form method="post">
    <input type="email" name="email" placeholder="Email" required value="<?= h($_POST['email'] ?? '') ?>">
    <input type="password" name="password" placeholder="Password" required>
    <button>Test</button>
  </form>
</div>
<p><small>Delete <code>check.php</code> when you are finished.</small></p>
</body></html>
