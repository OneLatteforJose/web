<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/profile.php';
require_admin();
$pdo = db();
$me = (int)$_SESSION['user']['id'];
$errDetails = $errPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'details') {
        $errDetails = save_own_details($pdo, $me, $_POST);
        if ($errDetails === '') {
            log_action('profile.update', 'user', $me, 'Admin updated their own details');
            flash('Your details were updated.');
            header('Location: profile.php');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'password') {
        $errPassword = change_own_password($pdo, $me, (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
        if ($errPassword === '') {
            log_action('admin.password_change', 'user', $me, 'Admin changed their own password');
            flash('Password changed.');
            header('Location: profile.php');
            exit;
        }
    }
}
$st = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
$st->execute([$me]);
$admin = $st->fetch();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'details' && $errDetails) {
    $admin = array_merge($admin, array_intersect_key($_POST, array_flip(['full_name', 'email', 'phone'])));
}

page_header('My profile', 'profile.php');
if ($errDetails) echo '<p class="err">' . e($errDetails) . '</p>';
?>
<h2>My details</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="details">
  <label>Full name <input name="full_name" required maxlength="150" value="<?= e($admin['full_name']) ?>"></label>
  <label>Email <input type="email" name="email" required maxlength="150" value="<?= e($admin['email']) ?>"></label>
  <label>Phone <input type="tel" name="phone" maxlength="30" value="<?= e($admin['phone'] ?? '') ?>"></label>
  <label>Current password <span style="font-weight:400">(only needed to change your email)</span> <input type="password" name="current_password" autocomplete="current-password"></label>
  <label>&nbsp;<button>Save details</button></label>
</form>

<h2>Change password</h2>
<?php if ($errPassword) echo '<p class="err">' . e($errPassword) . '</p>'; ?>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
  <label>New password (at least 8 characters) <input type="password" name="new" minlength="8" maxlength="72" required autocomplete="new-password"></label>
  <label>Confirm new password <input type="password" name="confirm" minlength="8" maxlength="72" required autocomplete="new-password"></label>
  <label>&nbsp;<button>Change password</button></label>
</form>
<?php page_footer();
