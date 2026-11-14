<?php
require_once __DIR__ . '/includes/user.php';
$me = require_user();
$pdo = db();
$first = explode(' ', trim($me['full_name']))[0];
$groups = service_groups();
$tiles = [];
foreach (['home', 'cleaning', 'personal', 'outdoor'] as $k) {
    $tiles[] = [$groups[$k][0], $groups[$k][1], 'services.php?group=' . $k];
}
$tiles[] = ['Vehicles', 'car', 'services.php?tab=vehicles'];

$popular = $me['city'] ? popular_services($pdo, $me['province'], $me['city']) : [];

// The customer's next bookings and requests (waiting for approval, or approved and still ahead).
$st = $pdo->prepare(
    "SELECT x.* FROM (
        (SELECT 'booking' AS kind, b.id, v.model AS title, b.status, b.start_at AS at FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id
          WHERE b.user_id = ? AND b.status IN ('pending', 'approved') AND b.end_at >= NOW())
        UNION ALL
        (SELECT 'request' AS kind, r.id, s.title, r.status, r.scheduled_at AS at FROM service_requests r JOIN services s ON s.id = r.service_id
          WHERE r.user_id = ? AND r.status IN ('pending', 'approved') AND r.scheduled_at >= NOW())
     ) x ORDER BY x.at LIMIT 3"
);
$st->execute([$me['id'], $me['id']]);
$upcoming = $st->fetchAll();

user_header(SITE_NAME, 'home.php', ['brand' => true]);
?>
<section class="hero">
  <div class="hero-text">
    <span class="hi">Hi, <?= e($first) ?></span>
    <h2>Your services, one destination.</h2>
    <p>Find trusted people for your everyday needs, or rent a vehicle, in just a few clicks.</p>
  </div>
  <div class="hero-find">
  <form action="services.php" method="get">
    <label class="find"><?= uicon('search') ?><input name="q" maxlength="60" placeholder="What service do you need?" aria-label="Search services"></label>
    <button class="btn">Find services <?= uicon('arrow') ?></button>
  </form>
  <p class="where"><?= uicon('pin') ?><span>
    <?php if ($me['city']): ?><?= e($me['city'] . ', ' . $me['province']) ?>. Every booking is confirmed by your area manager.
    <?php else: ?>Add your address in <a href="profile.php">Profile</a> to see services near you.<?php endif; ?>
  </span></p>
  </div>
</section>

<?php if ($upcoming): ?>
<div class="sec"><h2>Coming up</h2><a href="bookings.php">All bookings</a></div>
<div class="list">
  <?php foreach ($upcoming as $u): ?>
    <a class="row" href="order.php?type=<?= e($u['kind']) ?>&amp;id=<?= (int)$u['id'] ?>">
      <span class="ic"><?= uicon($u['kind'] === 'booking' ? 'car' : 'wrench') ?></span>
      <span class="grow"><span class="t"><?= e($u['title']) ?></span><br><span class="d"><?= e(date('D, M j, g:i A', strtotime($u['at']))) ?></span></span>
      <span class="end"><?= status_chip($u['status']) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sec"><h2>Browse categories</h2><a href="services.php">View all</a></div>
<div class="cats">
  <?php foreach ($tiles as [$label, $ic, $href]): ?>
    <a class="cat" href="<?= e($href) ?>"><span class="ic"><?= uicon($ic) ?></span><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="sec"><h2>Popular services</h2><a href="services.php">See all</a></div>
<?php if (!$popular): ?>
  <p class="empty"><?= $me['city'] ? 'No services are listed in ' . e($me['city']) . ' yet. Check back soon, or look at vehicles.' : 'Services appear here once you add your address.' ?></p>
<?php else: ?>
<div class="grid2">
  <?php foreach ($popular as $s): ?>
    <a class="card" href="service.php?id=<?= (int)$s['id'] ?>">
      <span class="ic"><?= uicon(category_icon($s['category'])) ?></span>
      <h3><?= e($s['title']) ?></h3>
      <p><?= e(snippet($s['description'], 48) ?: category_label($s['category'])) ?></p>
      <?= stars($s['rating'] !== null ? (float)$s['rating'] : null, (int)$s['reviews']) ?>
      <span class="price">From <?= e(peso($s['rate'])) ?></span>
      <span class="go">Book now</span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif;
user_footer();
