<?php
// Sign-in page for customers, providers, managers and admins. Each one is sent to their own page after logging in.
require_once __DIR__ . '/includes/site.php';

$landing = ['admin' => 'admin/dashboard.php', 'manager' => 'manager/dashboard.php', 'provider' => 'provider/dashboard.php', 'customer' => 'home.php'];
$me = $_SESSION['user'] ?? null;
$next = safe_next($_POST['next'] ?? $_GET['next'] ?? '');
if ($me && isset($landing[$me['role']])) {   // already signed in
    header('Location: ' . ($me['role'] === 'customer' && $next !== '' ? $next : $landing[$me['role']]));
    exit;
}

$error = '';
$notice = null;   // popup shown to a deactivated manager/provider or a blocked customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare(
        "SELECT u.id, u.role, u.full_name, u.password_hash, u.is_active, pp.application_status, pp.review_note, a.city_municipality AS city
         FROM users u LEFT JOIN provider_profiles pp ON pp.user_id = u.id LEFT JOIN areas a ON a.id = u.area_id
         WHERE u.email = ?"
    );
    $st->execute([trim($_POST['email'] ?? '')]);
    $user = $st->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        // Only someone who knows the password is told where a provider application stands.
        if ($user['role'] === 'provider' && ($user['application_status'] ?? '') === 'pending') {
            $error = 'Your provider application is waiting for approval by the manager of ' . ($user['city'] ?: 'your city') . ' or an admin. You can log in once it is approved.';
        } elseif ($user['role'] === 'provider' && ($user['application_status'] ?? '') === 'rejected') {
            $error = 'Your provider application was not approved.' . ($user['review_note'] ? ' Reason: ' . $user['review_note'] : '');
        } elseif (!$user['is_active']) {
            // Only reached with the right password, so the status of an account is not shown to strangers.
            $blocked = $user['role'] === 'customer';
            $notice = [
                'title' => $blocked ? 'Your account has been blocked' : 'Your account has been deactivated',
                'text'  => ($blocked ? 'This customer account is blocked' : 'This ' . $user['role'] . ' account is deactivated') . ' and cannot log in.',
                'word'  => $blocked ? 'blocked' : 'deactivated',
            ];
        } elseif (isset($landing[$user['role']])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'role' => $user['role'], 'name' => $user['full_name']];
            if ($user['role'] !== 'customer') {
                log_action('login', 'user', (int)$user['id'], ucfirst($user['role']) . ' signed in');
            }
            header('Location: ' . ($user['role'] === 'customer' && $next !== '' ? $next : $landing[$user['role']]));
            exit;
        } else {
            $error = 'This account cannot sign in yet.';
        }
    } else {
        $error = 'Email or password is incorrect.';
    }
}

public_header('Log in', false, '', 'loginpage');
?>
<section class="section">
  <div class="wrap loginwrap">
    <div>
      <h2>Log in</h2>
      <p class="intro">Customers, providers, managers and admins all log in here. You will be taken to your own page.</p>
    </div>
    <?php login_form($error); ?>
  </div>
</section>
<?php if ($notice): ?>
<div id="acct-notice" role="alertdialog" aria-modal="true" aria-labelledby="acct-notice-title" aria-describedby="acct-notice-text"
     style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:1000">
  <div style="background:#fff;border-radius:14px;max-width:26rem;width:100%;padding:1.5rem 1.6rem;box-shadow:0 20px 50px rgba(0,0,0,.3)">
    <h3 id="acct-notice-title" style="margin:0 0 .6rem;color:#9b1c1c"><?= e($notice['title']) ?></h3>
    <p id="acct-notice-text" style="margin:0 0 .8rem"><?= e($notice['text']) ?> If you think this is a mistake, please email the admin:</p>
    <p style="margin:0 0 1.2rem;font-weight:700"><a href="mailto:<?= e(ADMIN_CONTACT_EMAIL) ?>?subject=<?= rawurlencode('My account is ' . $notice['word']) ?>"><?= e(ADMIN_CONTACT_EMAIL) ?></a></p>
    <button type="button" class="btn btn-road" id="acct-notice-close">OK</button>
  </div>
</div>
<script>
(function () {
  var box = document.getElementById('acct-notice'), btn = document.getElementById('acct-notice-close');
  function close() { box.remove(); }
  btn.addEventListener('click', close);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') close(); });
  btn.focus();
})();
</script>
<?php endif; ?>
<?php public_footer();
