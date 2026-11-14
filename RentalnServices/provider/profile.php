<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$errDetails = $errPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'details') {
        $biz   = trim($_POST['business_name'] ?? '');
        $about = trim($_POST['about'] ?? '');
        if (profile_len($biz) > 150) {
            $errDetails = 'The business name is too long (up to 150 characters).';
        } elseif (profile_len($about) > 500) {
            $errDetails = 'The description is too long (up to 500 characters).';
        } else {
            // Name, email, phone and street. The barangay a provider belongs to is set by an admin or manager.
            $errDetails = save_own_details($pdo, $me, $_POST, ['street' => true]);
            if ($errDetails === '') {
                $pdo->prepare("INSERT INTO provider_profiles (user_id, business_name, about) VALUES (?,?,?)
                               ON DUPLICATE KEY UPDATE business_name = VALUES(business_name), about = VALUES(about)")
                    ->execute([$me, $biz !== '' ? $biz : null, $about !== '' ? $about : null]);
                log_action('profile.update', 'user', $me, 'Provider updated their own details');
                flash('Your details were updated.');
                header('Location: profile.php');
                exit;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'password') {
        $errPassword = change_own_password($pdo, $me, (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
        if ($errPassword === '') {
            log_action('password.change', 'user', $me, 'Provider changed their own password');
            flash('Password changed.');
            header('Location: profile.php');
            exit;
        }
    }
}
$st = $pdo->prepare("SELECT u.full_name, u.email, u.phone, u.street, pp.business_name, pp.about FROM users u LEFT JOIN provider_profiles pp ON pp.user_id = u.id WHERE u.id = ?");
$st->execute([$me]);
$me_ = $st->fetch();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'details' && $errDetails) {
    $me_ = array_merge($me_, array_intersect_key($_POST, array_flip(['full_name', 'email', 'phone', 'street', 'business_name', 'about'])));
}

provider_header('My profile', '');
if ($errDetails) echo '<p class="err">' . e($errDetails) . '</p>';
?>
<h2>My details</h2>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="details">
  <label>Full name <input name="full_name" required maxlength="150" value="<?= e($me_['full_name']) ?>"></label>
  <label>Business or trade name <input name="business_name" maxlength="150" value="<?= e($me_['business_name'] ?? '') ?>"></label>
  <label>Email <input type="email" name="email" required maxlength="150" value="<?= e($me_['email']) ?>"></label>
  <label>Phone <input type="tel" name="phone" maxlength="30" value="<?= e($me_['phone'] ?? '') ?>"></label>
  <label>Street / address <input name="street" maxlength="255" value="<?= e($me_['street'] ?? '') ?>"></label>
  <label>Barangay <input value="<?= e(trim(($p['barangay'] ?? '') . ($p['city'] ? ', ' . $p['city'] . ', ' . $p['province'] : ''))) ?>" disabled></label>
  <label style="grid-column:1/-1">About what you offer <textarea name="about" rows="3" maxlength="500"><?= e($me_['about'] ?? '') ?></textarea></label>
  <label>Current password <span style="font-weight:400">(only needed to change your email)</span> <input type="password" name="current_password" autocomplete="current-password"></label>
  <label>&nbsp;<button>Save details</button></label>
</form>
<p class="muted">Your barangay is set by an admin or manager. Ask them if it needs to change.</p>

<h2>Change password</h2>
<?php if ($errPassword) echo '<p class="err">' . e($errPassword) . '</p>'; ?>
<form method="post" class="box">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <label>Current password <input type="password" name="current" autocomplete="current-password" required></label>
  <label>New password (at least 8 characters) <input type="password" name="new" autocomplete="new-password" minlength="8" maxlength="72" required></label>
  <label>Confirm new password <input type="password" name="confirm" autocomplete="new-password" minlength="8" maxlength="72" required></label>
  <label>&nbsp;<button>Change password</button></label>
</form>
<?php page_footer();
