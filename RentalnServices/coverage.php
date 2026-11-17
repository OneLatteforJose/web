<?php
// Public page behind the "Rent a vehicle" and "Book a Service" boxes on the home page.
//   coverage.php?for=vehicles            -> municipalities/cities that already have an active manager and at least one active provider
//   coverage.php?for=vehicles&city=P|C   -> the vehicles and services that city offers right now
require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/areas.php';   // parse_city_key(), city_key()
$pdo = db();

$for  = ($_GET['for'] ?? '') === 'services' ? 'services' : 'vehicles';
$types = vehicle_types();
$cats  = service_categories();


$cities = covered_cities($pdo);
$picked = null;
if ($parts = parse_city_key($_GET['city'] ?? '')) {
    foreach ($cities as $c) {
        if ($c['province'] === $parts[0] && $c['city'] === $parts[1]) { $picked = $c; break; }
    }
}

$vehicles = $services = [];
if ($picked) {
    $st = $pdo->prepare(
        "SELECT v.id, v.type, v.model, v.capacity, v.rate_per_day, v.own_driver_id, a.barangay, pp.business_name, p.full_name AS provider
         FROM vehicles v JOIN users p ON p.id = v.provider_id AND p.is_active = 1 LEFT JOIN provider_profiles pp ON pp.user_id = p.id
         JOIN areas a ON a.id = v.area_id
         WHERE v.status <> 'unavailable' AND a.province = ? AND a.city_municipality = ? ORDER BY v.model"
    );
    $st->execute([$picked['province'], $picked['city']]);
    $vehicles = $st->fetchAll();
    $st = $pdo->prepare(
        "SELECT s.id, s.title, s.description, s.category, s.rate, a.barangay, pp.business_name, p.full_name AS provider
         FROM services s JOIN users p ON p.id = s.provider_id AND p.is_active = 1 LEFT JOIN provider_profiles pp ON pp.user_id = p.id
         JOIN areas a ON a.id = s.area_id
         WHERE s.status = 'available' AND a.province = ? AND a.city_municipality = ? ORDER BY s.title"
    );
    $st->execute([$picked['province'], $picked['city']]);
    $services = $st->fetchAll();
}

$self = fn(array $q) => 'coverage.php?' . http_build_query($q);
public_header($picked ? $picked['city'] : 'Areas we cover', false, '', 'coverpage');
?>
<section class="section">
  <div class="wrap">
  <?php if (!$picked): ?>
    <p class="crumb"><a href="index.php">Home</a></p>
    <h2><?= $for === 'vehicles' ? 'Rent a vehicle: choose your area' : 'Book a service: choose your area' ?></h2>
    <p class="intro">These cities and municipalities already have a manager and providers. Pick one to see what is available there.</p>
    <div class="chips2">
      <a href="<?= e($self(['for' => 'vehicles'])) ?>" class="<?= $for === 'vehicles' ? 'on' : '' ?>">Vehicles</a>
      <a href="<?= e($self(['for' => 'services'])) ?>" class="<?= $for === 'services' ? 'on' : '' ?>">Services</a>
    </div>
    <?php if (!$cities): ?>
      <p class="note">No area is open yet. Please check back soon.</p>
    <?php else: ?>
    <ul class="areas">
      <?php foreach ($cities as $c): ?>
        <li><a href="<?= e($self(['for' => $for, 'city' => city_key($c['province'], $c['city'])])) ?>">
          <strong><?= e($c['city']) ?></strong>
          <span class="muted"><?= e($c['province']) ?></span>
          <span class="meta">Manager: <?= e($c['managers']) ?></span>
          <span class="meta"><?= (int)$c['providers'] ?> provider<?= (int)$c['providers'] === 1 ? '' : 's' ?> &middot;
            <?= (int)$c['vehicles'] ?> vehicle<?= (int)$c['vehicles'] === 1 ? '' : 's' ?> &middot;
            <?= (int)$c['services'] ?> service<?= (int)$c['services'] === 1 ? '' : 's' ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  <?php else: ?>
    <p class="crumb"><a href="index.php">Home</a> / <a href="<?= e($self(['for' => $for])) ?>">Areas</a></p>
    <h2><?= e($picked['city']) ?>, <?= e($picked['province']) ?></h2>
    <p class="intro">Manager: <?= e($picked['managers']) ?>. <?= (int)$picked['providers'] ?> provider<?= (int)$picked['providers'] === 1 ? '' : 's' ?> serve this area.
      <a href="login.php">Log in</a> or <a href="register.php">sign up</a> to book.</p>
    <?php $key = city_key($picked['province'], $picked['city']); ?>
    <div class="chips2">
      <a href="<?= e($self(['for' => 'vehicles', 'city' => $key])) ?>" class="<?= $for === 'vehicles' ? 'on' : '' ?>">Vehicles (<?= count($vehicles) ?>)</a>
      <a href="<?= e($self(['for' => 'services', 'city' => $key])) ?>" class="<?= $for === 'services' ? 'on' : '' ?>">Services (<?= count($services) ?>)</a>
    </div>

    <?php if ($for === 'vehicles'): ?>
      <?php if (!$vehicles): ?><p class="note">No vehicles are listed in this area yet.</p><?php else: ?>
      <ul class="offers">
        <?php foreach ($vehicles as $v): ?>
          <li>
            <a href="vehicle.php?id=<?= (int)$v['id'] ?>">
              <strong><?= e($v['model']) ?></strong>
              <span class="muted"><?= e($types[$v['type']][0] ?? ucfirst($v['type'])) ?><?= $v['capacity'] ? ', ' . e($v['capacity']) : '' ?><?= $v['own_driver_id'] ? ', driver available' : '' ?></span>
              <span class="meta"><?= e($v['barangay']) ?> &middot; by <?= e($v['business_name'] ?: $v['provider']) ?></span>
              <span class="price"><?= e(peso($v['rate_per_day'])) ?> <span class="muted">per day</span></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    <?php else: ?>
      <?php if (!$services): ?><p class="note">No services are listed in this area yet.</p><?php else: ?>
      <ul class="offers">
        <?php foreach ($services as $s): ?>
          <li>
            <a href="service.php?id=<?= (int)$s['id'] ?>">
              <strong><?= e($s['title']) ?></strong>
              <span class="muted"><?= e($cats[$s['category']][0] ?? ucfirst(str_replace('_', ' ', $s['category']))) ?></span>
              <span class="meta"><?= e($s['barangay']) ?> &middot; by <?= e($s['business_name'] ?: $s['provider']) ?></span>
              <span class="price"><?= $s['rate'] !== null ? e(peso($s['rate'])) : 'Ask for a quote' ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</section>
<?php public_footer();
