<?php
// Sign-up for customers (instant) and providers (the account waits, switched off, until a manager of the city or an admin approves it).
// The role is decided here from ?as=, never read as a role from the form.
require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/areas.php';

$signedInAs = $_SESSION['user']['role'] ?? '';
$landing = ['admin' => 'admin/dashboard.php', 'manager' => 'manager/dashboard.php', 'customer' => 'home.php'];
if (isset($landing[$signedInAs])) {
    header('Location: ' . $landing[$signedInAs]);
    exit;
}

$pdo = db();
$hasAreas = db_has_areas($pdo);
$err = '';
$as = ($_GET['as'] ?? '') === 'provider' ? 'provider' : 'customer';
$submitted = $as === 'provider' && isset($_GET['submitted']);
$f = ['full_name' => '', 'email' => '', 'phone' => '', 'city' => '', 'area_id' => 0, 'street' => '', 'business_name' => '', 'about' => ''];

$len = fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['website'] ?? '') !== '') {   // hidden field that only bots fill in
        exit;
    }
    $f = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email'     => trim($_POST['email'] ?? ''),
        'phone'     => trim($_POST['phone'] ?? ''),
        'city'      => (string)($_POST['city'] ?? ''),
        'area_id'   => (int)($_POST['area_id'] ?? 0),
        'street'    => trim($_POST['street'] ?? ''),
        'business_name' => trim($_POST['business_name'] ?? ''),
        'about'     => trim($_POST['about'] ?? ''),
    ];
    $pass = $_POST['password'] ?? '';

    $areaOk = $pdo->prepare("SELECT 1 FROM areas WHERE id = ?");
    $areaOk->execute([$f['area_id']]);

    if ($f['full_name'] === '' || $len($f['full_name']) > 150) {
        $err = 'Enter your full name.';
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || $len($f['email']) > 150) {
        $err = 'Enter a valid email address.';
    } elseif ($as === 'provider' && $f['phone'] === '') {
        $err = 'Enter a phone number so the manager can reach you.';
    } elseif ($f['phone'] !== '' && !preg_match('/^[0-9]{11}$/', $f['phone'])) {
        $err = 'Enter an 11-digit phone number, for example 09171234567' . ($as === 'provider' ? '.' : ', or leave it empty.');
    } elseif ($as === 'provider' && ($len($f['business_name']) < 2 || $len($f['business_name']) > 150)) {
        $err = 'Enter your business or trade name (2 to 150 characters). Use your own name if you work alone.';
    } elseif ($as === 'provider' && ($len($f['about']) < 10 || $len($f['about']) > 500)) {
        $err = 'Tell us what you will offer, in 10 to 500 characters. For example: 2 vans for rent with driver, or house cleaning.';
    } elseif (!$areaOk->fetchColumn()) {
        $err = 'Select your city and barangay.';
    } elseif ($f['street'] === '' || $len($f['street']) > 255) {
        $err = 'Enter your street or house address.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) {
        $err = 'Password must be 8 to 72 characters.';
    } elseif ($pass !== ($_POST['confirm_password'] ?? '')) {
        $err = 'The two passwords do not match.';
    } else {
        try {
            if ($as === 'provider') {
                // Switched off (is_active = 0) and "pending" until a manager of this city or an admin approves.
                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO users (role, full_name, email, phone, password_hash, area_id, street, is_active)
                     VALUES ('provider', ?, ?, ?, ?, ?, ?, 0)"
                )->execute([$f['full_name'], $f['email'], $f['phone'], password_hash($pass, PASSWORD_DEFAULT), $f['area_id'], $f['street']]);
                $pdo->prepare("INSERT INTO provider_profiles (user_id, business_name, about, application_status) VALUES (?, ?, ?, 'pending')")
                    ->execute([(int)$pdo->lastInsertId(), $f['business_name'], $f['about']]);
                $pdo->commit();
                header('Location: register.php?as=provider&submitted=1');   // no sign-in: there is nothing to open until approval
                exit;
            }
            $pdo->prepare(
                "INSERT INTO users (role, full_name, email, phone, password_hash, area_id, street)
                 VALUES ('customer', ?, ?, ?, ?, ?, ?)"
            )->execute([$f['full_name'], $f['email'], $f['phone'] ?: null, password_hash($pass, PASSWORD_DEFAULT), $f['area_id'], $f['street']]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'role' => 'customer', 'name' => $f['full_name']];
            flash('Your account is ready.');
            header('Location: home.php');
            exit;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = $ex->getCode() === '23000' ? 'An account with that email already exists. Try logging in instead.' : 'Could not create the account. Please try again.';
        }
    }
}

public_header($as === 'provider' ? 'Become a provider' : 'Create an account');
?>
<style>
.formcard select,.formcard textarea{font:inherit;padding:.65rem .75rem;border:1px solid #9fb0a5;border-radius:8px;background:#fff;color:var(--ink)}
.hp{position:absolute;left:-9999px;height:0;overflow:hidden}
.signtabs{display:flex;gap:.5rem;margin:0 0 1.2rem}
.signtabs a{padding:.45rem 1rem;border:1px solid #9fb0a5;border-radius:999px;text-decoration:none;color:var(--ink);font-weight:600}
/* intro on the left, the sign-up box on the right (stacked on small screens) */
.regwrap{grid-template-columns:1fr minmax(0,560px);align-items:start}
.regwrap .formcard{width:100%;justify-self:end}
@media (max-width:860px){.regwrap{grid-template-columns:1fr}.regwrap .formcard{justify-self:stretch}}
.signtabs a[aria-current=page]{background:var(--road,#1f6f4a);border-color:var(--road,#1f6f4a);color:#fff}
</style>
<section class="section">
  <div class="wrap loginwrap regwrap">
    <div>
      <div class="signtabs" role="navigation" aria-label="Account type">
        <a href="register.php"<?= $as === 'customer' ? ' aria-current="page"' : '' ?>>I want to book</a>
        <a href="register.php?as=provider"<?= $as === 'provider' ? ' aria-current="page"' : '' ?>>I want to offer a vehicle or service</a>
      </div>
      <?php if ($submitted): ?>
        <h2>Application received</h2>
        <p class="intro">Thank you. The manager of your city or an admin will check your details and decide. You can <a href="login.php">log in</a> with your email and password at any time to see where your application stands.</p>
      <?php elseif ($as === 'provider'): ?>
        <h2>Become a provider</h2>
        <p class="intro">Tell us about you and what you offer. A manager in your city or an admin checks every application before the account is switched on. Already registered? <a href="login.php">Log in</a>.</p>
      <?php else: ?>
        <h2>Create an account</h2>
        <p class="intro">Sign up to book vehicles or request services in your area. Already registered? <a href="login.php">Log in</a>.</p>
      <?php endif; ?>
    </div>
    <?php if ($submitted): ?>
      <p><a class="btn btn-road" href="index.php">Back to the home page</a></p>
    <?php elseif (!$hasAreas): ?>
      <p class="alert" role="alert">Sign-up is not open yet because no service areas have been added.</p>
    <?php else: ?>
    <form method="post" action="register.php<?= $as === 'provider' ? '?as=provider' : '' ?>" class="formcard" autocomplete="on">
      <?php if ($err): ?><p class="alert" role="alert"><?= e($err) ?></p><?php endif; ?>
      <?= csrf_field() ?>
      <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label>Full name <input name="full_name" autocomplete="name" required maxlength="150" value="<?= e($f['full_name']) ?>"></label>
      <?php if ($as === 'provider'): ?>
        <label>Business or trade name <input name="business_name" required maxlength="150" value="<?= e($f['business_name']) ?>"></label>
      <?php endif; ?>
      <label>Email <input type="email" name="email" autocomplete="email" required maxlength="150" value="<?= e($f['email']) ?>"></label>
      <label>Phone<?= $as === 'provider' ? '' : ' (optional)' ?> <input type="tel" name="phone" autocomplete="tel" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" title="11 digits, for example 09171234567" placeholder="09XXXXXXXXX" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"<?= $as === 'provider' ? ' required' : '' ?> value="<?= e($f['phone']) ?>"></label>
      <?php area_picker(['api' => 'api/areas.php', 'js' => 'assets/areas.js', 'city' => $f['city'], 'area_id' => $f['area_id'], 'required' => true]); ?>
      <label>Street / house address <input name="street" autocomplete="street-address" required maxlength="255" value="<?= e($f['street']) ?>"></label>
      <?php if ($as === 'provider'): ?>
        <label>What will you offer? <textarea name="about" rows="3" required minlength="10" maxlength="500" placeholder="For example: 2 vans for rent with driver, or house cleaning and laundry pickup"><?= e($f['about']) ?></textarea></label>
      <?php endif; ?>
      <label>Password (at least 8 characters) <input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <label>Confirm password <input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
      <button class="btn btn-road"><?= $as === 'provider' ? 'Send application' : 'Create account' ?></button>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php public_footer();
