<?php
// Sign-in page for customers, providers, managers and admins. Each one is sent to their own page after logging in.
require_once __DIR__ . '/includes/site.php';

$landing = ['admin' => 'admin/dashboard.php', 'manager' => 'manager/dashboard.php', 'provider' => 'provider/dashboard.php', 'customer' => 'home.php'];
$me = $_SESSION['user'] ?? null;
if ($me && isset($landing[$me['role']])) {   // already signed in
    header('Location: ' . $landing[$me['role']]);
    exit;
}

$error = '';
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
            $error = 'Email or password is incorrect.';
        } elseif (isset($landing[$user['role']])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'role' => $user['role'], 'name' => $user['full_name']];
            if ($user['role'] !== 'customer') {
                log_action('login', 'user', (int)$user['id'], ucfirst($user['role']) . ' signed in');
            }
            header('Location: ' . $landing[$user['role']]);
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
<?php public_footer();
