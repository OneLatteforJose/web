<?php
// Vehicles for rent. Can be rented from any city; the default is the customer's own city.
require_once __DIR__ . '/includes/user.php';
$me = browse_user();
$pdo = db();
$guest = !empty($me['guest']);
$area  = browse_city($pdo);   // ?city=Province|City from the landing page
if ($guest && !$area) { header('Location: index.php#vehicles'); exit; }
$cityKey = $area ? city_key($area[0], $area[1]) : '';

$q      = trim($_GET['q'] ?? '');
$q      = function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60);
$like   = '%' . addcslashes($q, '%_\\') . '%';
$types  = vehicle_types();
$vtype  = array_key_exists($_GET['type'] ?? '', $types) ? $_GET['type'] : '';
$scope  = ($_GET['where'] ?? '') === 'all' ? 'all' : 'mine';
$per    = 12;
$page   = max(1, (int)($_GET['page'] ?? 1));

$where = "v.status <> 'unavailable' AND p.is_active = 1";
$params = [];
if ($area) { $where .= " AND a.province = ? AND a.city_municipality = ?"; $params = $area; }
elseif ($scope === 'mine') { $where .= " AND a.province = ? AND a.city_municipality = ?"; $params = [$me['province'] ?? '', $me['city'] ?? '']; }
if ($vtype !== '') { $where .= " AND v.type = ?"; $params[] = $vtype; }
if ($q !== '') { $where .= " AND (v.model LIKE ? OR v.type LIKE ?)"; array_push($params, $like, $like); }
$from = "FROM vehicles v JOIN users p ON p.id = v.provider_id JOIN areas a ON a.id = v.area_id WHERE $where";
$c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($params); $total = (int)$c->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $per)));
$st = $pdo->prepare(
    "SELECT v.id, v.type, v.model, v.capacity, v.rate_per_day, v.own_driver_id, v.status, a.barangay, a.city_municipality AS city,
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS reviews
     $from ORDER BY v.model LIMIT $per OFFSET " . (($page - 1) * $per)
);
$st->execute($params);
$rows = $st->fetchAll();
$states = vehicle_states($pdo, array_column($rows, 'id'));

$qs = function (array $over) use ($q, $vtype, $scope, $cityKey): string {
    $p = array_filter(array_merge(['q' => $q, 'type' => $vtype, 'where' => $scope === 'all' ? 'all' : '', 'city' => $cityKey], $over), fn($v) => $v !== '');
    return '?' . http_build_query($p);
};

user_header($area ? 'Vehicles in ' . $area[1] : 'Vehicles', 'vehicles.php', $guest ? ['guest' => true, 'back' => 'index.php#vehicles'] : ($area ? ['back' => 'index.php#vehicles'] : []));
if ($guest): ?><p class="muted" style="margin:0 0 .7rem"><?= e($area[1]) ?>, <?= e($area[0]) ?>. Pick one and book it now. You log in or <a href="register.php">sign up</a> only when you pay.</p><?php endif;
?>
<form method="get" class="find" style="margin-bottom:.7rem">
  <?= uicon('search') ?>
  <?php if ($vtype !== ''): ?><input type="hidden" name="type" value="<?= e($vtype) ?>"><?php endif; ?>
  <?php if ($scope === 'all'): ?><input type="hidden" name="where" value="all"><?php endif; ?>
  <?php if ($cityKey !== ''): ?><input type="hidden" name="city" value="<?= e($cityKey) ?>"><?php endif; ?>
  <input name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Search vehicles" aria-label="Search vehicles">
</form>

<div class="chips">
  <a href="<?= e($qs(['type' => '', 'page' => ''])) ?>" class="<?= $vtype === '' ? 'on' : '' ?>">All</a>
  <?php foreach ($types as $k => [$label]): ?><a href="<?= e($qs(['type' => $k, 'page' => ''])) ?>" class="<?= $vtype === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
  <?php if (!$area): ?><a href="<?= e($qs(['where' => $scope === 'all' ? '' : 'all', 'page' => ''])) ?>" class="<?= $scope === 'all' ? 'on' : '' ?>">All cities</a><?php endif; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty">No vehicles match<?= $area ? ' in ' . e($area[1]) . '.' : '' ?><?= !$area && $scope === 'mine' && $me['city'] ? ' in ' . e($me['city']) . '. <a href="' . e($qs(['where' => 'all'])) . '">Try all cities</a>' : '.' ?></p>
<?php else: ?>
<div class="list">
  <?php foreach ($rows as $v): $av = vehicle_availability($v, $states[(int)$v['id']] ?? null); ?>
    <a class="row" href="vehicle.php?id=<?= (int)$v['id'] ?><?= $guest && $area ? '&amp;city=' . e(urlencode(city_key($area[0], $area[1]))) : '' ?>"<?= $av['code'] === 'available' ? '' : ' style="opacity:.78"' ?>>
      <span class="ic"><?= uicon('car') ?></span>
      <span class="grow"><span class="t"><?= e($v['model']) ?></span><br><span class="d"><?= e(ucfirst($v['type'])) ?><?= $v['capacity'] ? ', ' . e($v['capacity']) : '' ?>, <?= e($v['city']) ?><?= $v['own_driver_id'] ? ', driver available' : '' ?></span><br><?php if ($av['code'] !== 'available'): ?><span class="chip st-<?= $av['code'] === 'maintenance' ? 'pending' : 'rejected' ?>"><?= e($av['label']) ?></span><br><?php endif; ?><?= stars($v['rating'] !== null ? (float)$v['rating'] : null, (int)$v['reviews']) ?></span>
      <span class="end"><strong><?= e(peso($v['rate_per_day'])) ?></strong><br><span class="muted">per day</span></span>
    </a>
  <?php endforeach; ?>
</div>
<?php upager($total, $per, $page); endif;
user_footer();
