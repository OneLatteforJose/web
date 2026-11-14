<?php
require_once __DIR__ . '/includes/site.php';

// Sign-in. The form is the "Log in" section at the bottom of this page (there is no separate login page).
$landing = ['admin' => 'admin/dashboard.php', 'manager' => 'manager/dashboard.php', 'provider' => 'provider/dashboard.php', 'customer' => 'home.php'];
$me = $_SESSION['user'] ?? null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$me) {
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

$vehicles = vehicle_types();
$services = service_categories();
$heroServices = array_slice($services, 0, 5);

public_header('Vehicles and home services', true);
?>
<section class="hero">
  <div class="wrap">
    <div>
      <h1>Rent a vehicle. Book a helper. Handled in your area.</h1>
      <p class="lead">Choose a car, van or truck with or without a driver, or request plumbing, cleaning, massage and more. A manager in your area confirms every booking, and you pay online.</p>
      <div class="actions">
        <a class="btn btn-signal" href="#login">Log in to book</a>
        <a class="btn btn-ghost" href="#vehicles">See what you can book</a>
      </div>
    </div>

    <div class="passes">
      <article class="pass">
        <div class="stub">Rent</div>
        <div class="body">
          <h2>Book a vehicle</h2>
          <p>Cars, vans and trucks, with or without a driver.</p>
          <ul class="chips">
            <?php foreach ($vehicles as [$label]): ?><li><?= e($label) ?></li><?php endforeach; ?>
          </ul>
          <a class="more" href="#login">Log in to book a vehicle</a>
        </div>
      </article>
      <article class="pass">
        <div class="stub">Book</div>
        <div class="body">
          <h2>Request a service</h2>
          <p>Local providers for your home and personal needs.</p>
          <ul class="chips">
            <?php foreach ($heroServices as [$label]): ?><li><?= e($label) ?></li><?php endforeach; ?>
            <li>and more</li>
          </ul>
          <a class="more" href="#login">Log in to request a service</a>
        </div>
      </article>
    </div>
  </div>
</section>

<section class="section" id="vehicles">
  <div class="wrap">
    <h2>Vehicles for rent</h2>
    <p class="intro">Pick the vehicle that fits the trip, then decide whether you want to drive it yourself.</p>
    <ul class="rows">
      <?php foreach ($vehicles as [$label, $desc]): ?>
        <li>
          <strong><?= e($label) ?></strong>
          <span><?= e($desc) ?></span>
          <span class="tags"><span class="tag">Drive yourself</span><span class="tag">With a driver</span></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">A driver comes from the vehicle owner, or your area manager assigns one.</p>
  </div>
</section>

<section class="section" id="services">
  <div class="wrap">
    <h2>Services at your door</h2>
    <p class="intro">Request a service from providers who work in your area.</p>
    <ul class="svc">
      <?php foreach ($services as [$label, $desc]): ?>
        <li><strong><?= e($label) ?></strong><span><?= e($desc) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section" id="how">
  <div class="wrap">
    <h2>How it works</h2>
    <p class="intro">From request to review in four steps.</p>
    <ol class="steps">
      <li><strong>Create an account</strong><span>Sign up, then choose to book a vehicle or request a service.</span></li>
      <li><strong>Pick a time and pay</strong><span>We check that the vehicle or provider is available. You pay online.</span></li>
      <li><strong>Your manager approves</strong><span>The manager for your area reviews and approves your booking or request.</span></li>
      <li><strong>Enjoy, then review</strong><span>Once it is done, rate the vehicle or service.</span></li>
    </ol>
  </div>
</section>

<section class="section" id="area">
  <div class="wrap">
    <h2>Built around your area</h2>
    <div class="triple">
      <div><h3>Local managers</h3><p>Every booking and request is reviewed by a manager who covers your area.</p></div>
      <div><h3>Recommended providers</h3><p>Providers are recommended by area managers and activated by the admin.</p></div>
      <div><h3>Honest ratings</h3><p>Only people who actually used a vehicle or service can review it.</p></div>
    </div>
  </div>
</section>

<section class="section" id="partners">
  <div class="wrap">
    <div class="callout">
      <h2>Own a vehicle or offer a service?</h2>
      <p>Apply to join as a provider. The manager of your city or an admin checks every application. Once it is approved, you can list your vehicles and services and start receiving bookings.</p>
      <p><a class="btn btn-road" href="register.php?as=provider">Apply as a provider</a></p>
    </div>
  </div>
</section>

<section class="section" id="login">
  <div class="wrap loginwrap">
    <?php if ($me): ?>
      <div>
        <h2>You are signed in</h2>
        <p class="intro">Signed in as <?= e($me['name']) ?>.</p>
        <p>
          <a class="btn btn-road" href="<?= e($landing[$me['role']] ?? 'index.php') ?>"><?= $me['role'] === 'customer' ? 'My account' : 'Open my dashboard' ?></a>
          <a href="logout.php" style="margin-left:1rem;font-weight:600">Log out</a>
        </p>
      </div>
    <?php else: ?>
      <div>
        <h2>Log in</h2>
        <p class="intro">Customers, providers, managers and admins all log in here. You will be taken to your own page.</p>
      </div>
      <?php login_form($error); ?>
    <?php endif; ?>
  </div>
</section>
<?php public_footer();
