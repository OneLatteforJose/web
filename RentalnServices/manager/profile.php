<?php
require_once __DIR__ . '/../includes/manager.php';
require_once __DIR__ . '/../includes/profile.php';
$m = require_manager();
$pdo = db();
$errDetails = $errPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'details') {
        // Name, email, phone and street. The city a manager covers is set by an admin, so it is not editable here.
        $errDetails = save_own_details($pdo, (int)$m['id'], $_POST, ['street' => true]);
        if ($errDetails === '') {
            log_action('profile.update', 'user', (int)$m['id'], 'Manager updated their own details');
            flash('Your details were updated.');
            header('Location: profile.php');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'password') {
        $errPassword = change_own_password($pdo, (int)$m['id'], (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
        if ($errPassword === '') {
            log_action('password.change', 'user', (int)$m['id'], 'Manager changed their own password');
            flash('Password changed.');
            header('Location: profile.php');
            exit;
        }
    }
}
$st = $pdo->prepare("SELECT full_name, email, phone, street FROM users WHERE id = ?");
$st->execute([$m['id']]);
$me = $st->fetch();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'details' && $errDetails) {
    $me = array_merge($me, array_intersect_key($_POST, array_flip(['full_name', 'email', 'phone', 'street'])));
}

manager_header('My profile', '');
if ($errDetails) echo '<p class="err">' . e($errDetails) . '</p>';
?>
<h2>My details</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="details">
  <label>Full name <input name="full_name" required maxlength="150" value="<?= e($me['full_name']) ?>"></label>
  <label>Email <input type="email" name="email" required maxlength="150" value="<?= e($me['email']) ?>"></label>
  <label>Phone <input type="tel" name="phone" maxlength="30" value="<?= e($me['phone'] ?? '') ?>"></label>
  <label>Street / address <input name="street" maxlength="255" value="<?= e($me['street'] ?? '') ?>"></label>
  <label>City covered <input value="<?= e($m['city'] . ', ' . $m['province']) ?>" disabled></label>
  <label>Current password <span style="font-weight:400">(only needed to change your email)</span> <input type="password" name="current_password" autocomplete="current-password"></label>
  <label>&nbsp;<button>Save details</button></label>
</form>
<p class="muted">The city you cover is set by an admin. Ask an admin if it needs to change.</p>

<h2>Change password</h2>
<?php if ($errPassword) echo '<p class="err">' . e($errPassword) . '</p>'; ?>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <label>Current password <input type="password" name="current" autocomplete="current-password" required></label>
  <label>New password (at least 8 characters) <input type="password" name="new" autocomplete="new-password" minlength="8" maxlength="72" required></label>
  <label>Confirm new password <input type="password" name="confirm" autocomplete="new-password" minlength="8" maxlength="72" required></label>
  <label>&nbsp;<button>Change password</button></label>
</form>
<?php
page_footer();
