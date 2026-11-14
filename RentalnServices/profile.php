<?php
require_once __DIR__ . '/includes/user.php';
$me = require_user();
$pdo = db();
$err = '';
$len = fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $area = (int)($_POST['area_id'] ?? 0);
        $street = trim($_POST['street'] ?? '');
        $ok = $pdo->prepare("SELECT 1 FROM areas WHERE id = ?");
        $ok->execute([$area]);
        if ($name === '' || $len($name) > 150) {
            $err = 'Enter your full name.';
        } elseif ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $phone)) {
            $err = 'Enter a valid phone number, or leave it empty.';
        } elseif (!$ok->fetchColumn()) {
            $err = 'Select your city and barangay.';
        } elseif ($street === '' || $len($street) > 255) {
            $err = 'Enter your street or house address.';
        } else {
            $pdo->prepare("UPDATE users SET full_name = ?, phone = ?, area_id = ?, street = ? WHERE id = ? AND role = 'customer'")
                ->execute([$name, $phone ?: null, $area, $street, $me['id']]);
            $_SESSION['user']['name'] = $name;
            flash('Profile updated.');
            header('Location: profile.php');
            exit;
        }
    }

    if ($action === 'password') {
        $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$me['id']]);
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($_POST['current_password'] ?? '', (string)$st->fetchColumn())) {
            $err = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8 || strlen($new) > 72) {
            $err = 'New password must be 8 to 72 characters.';
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $err = 'The two new passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('Password changed.');
            header('Location: profile.php');
            exit;
        }
    }
}
$v = fn(string $k, $fallback = '') => ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'details') ? ($_POST[$k] ?? '') : $fallback;
$myCity = $me['city'] ? city_key($me['province'], $me['city']) : '';

user_header('Profile', 'profile.php');
if ($err) echo '<p class="alert">' . e($err) . '</p>';
?>
<div class="cols two">
<div class="main">
<section class="panel" style="display:flex;gap:.8rem;align-items:center">
  <span class="ic" style="width:48px;height:48px;border-radius:50%"><?= uicon('user') ?></span>
  <div><strong><?= e($me['full_name']) ?></strong><br><span class="muted"><?= e($me['email']) ?></span></div>
</section>

<section class="panel">
  <h2>My details</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="details">
    <label>Full name <input name="full_name" required maxlength="150" value="<?= e($v('full_name', $me['full_name'])) ?>"></label>
    <label>Phone <input type="tel" name="phone" maxlength="30" value="<?= e($v('phone', (string)$me['phone'])) ?>"></label>
    <?php area_picker(['api' => 'api/areas.php', 'js' => 'assets/areas.js', 'city' => $v('city', $myCity), 'area_id' => (int)$v('area_id', (int)$me['area_id']), 'required' => true]); ?>
    <label>Street / house address <input name="street" required maxlength="255" value="<?= e($v('street', (string)$me['street'])) ?>"></label>
    <button class="btn">Save changes</button>
  </form>
</section>

</div>
<div class="side">
<section class="panel">
  <h2>Change password</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password <input type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
    <label>Confirm new password <input type="password" name="confirm_password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
    <button class="btn alt">Change password</button>
  </form>
</section>

<a class="btn danger" href="logout.php"><?= uicon('logout') ?> Log out</a>
</div>
</div>
<?php user_footer();
