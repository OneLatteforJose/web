<?php
require_once __DIR__ . '/includes/user.php';
$me = require_user();
$pdo = db();

$tab    = ($_GET['tab'] ?? '') === 'vehicles' ? 'vehicles' : 'services';
$q      = trim($_GET['q'] ?? '');
$q      = function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60);
$like   = '%' . addcslashes($q, '%_\\') . '%';
$groups = service_groups();
$group  = array_key_exists($_GET['group'] ?? '', $groups) ? $_GET['group'] : '';
$types  = vehicle_types();
$vtype  = array_key_exists($_GET['type'] ?? '', $types) ? $_GET['type'] : '';
$scope  = ($_GET['where'] ?? '') === 'all' ? 'all' : 'mine';
$per    = 12;
$page   = max(1, (int)($_GET['page'] ?? 1));
$cityP  = [$me['province'] ?? '', $me['city'] ?? ''];
$rows   = [];
$total  = 0;

if ($tab === 'services') {
    $where = "s.status = 'available' AND p.is_active = 1 AND a.province = ? AND a.city_municipality = ?";
    $params = $cityP;
    if ($group !== '') {
        $cats = $groups[$group][2];
        $where .= ' AND s.category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
        $params = array_merge($params, $cats);
    }
    if ($q !== '') {
        $where .= " AND (s.title LIKE ? OR s.description LIKE ? OR REPLACE(s.category, '_', ' ') LIKE ?)";
        array_push($params, $like, $like, $like);
    }
    $from = "FROM services s JOIN users p ON p.id = s.provider_id JOIN areas a ON a.id = s.area_id WHERE $where";
    $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($params); $total = (int)$c->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / $per)));
    $st = $pdo->prepare(
        "SELECT s.id, s.title, s.description, s.category, s.rate, a.barangay,
                (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS rating,
                (SELECT COUNT(*) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS reviews
         $from ORDER BY s.title LIMIT $per OFFSET " . (($page - 1) * $per)
    );
    $st->execute($params);
    $rows = $st->fetchAll();
} else {
    // Vehicles can be rented from any city; the default is the customer's own city.
    $where = "v.status <> 'unavailable' AND p.is_active = 1";
    $params = [];
    if ($scope === 'mine') { $where .= " AND a.province = ? AND a.city_municipality = ?"; $params = $cityP; }
    if ($vtype !== '') { $where .= " AND v.type = ?"; $params[] = $vtype; }
    if ($q !== '') { $where .= " AND (v.model LIKE ? OR v.type LIKE ?)"; array_push($params, $like, $like); }
    $from = "FROM vehicles v JOIN users p ON p.id = v.provider_id JOIN areas a ON a.id = v.area_id WHERE $where";
    $c = $pdo->prepare("SELECT COUNT(*) $from"); $c->execute($params); $total = (int)$c->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / $per)));
    $st = $pdo->prepare(
        "SELECT v.id, v.type, v.model, v.capacity, v.rate_per_day, v.own_driver_id, a.barangay, a.city_municipality AS city,
                (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS rating,
                (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS reviews
         $from ORDER BY v.model LIMIT $per OFFSET " . (($page - 1) * $per)
    );
    $st->execute($params);
    $rows = $st->fetchAll();
}

$qs = function (array $over) use ($tab, $q, $group, $vtype, $scope): string {
    $p = array_filter(array_merge(['tab' => $tab === 'vehicles' ? 'vehicles' : '', 'q' => $q, 'group' => $group, 'type' => $vtype, 'where' => $scope === 'all' ? 'all' : ''], $over), fn($v) => $v !== '');
    return '?' . http_build_query($p);
};

user_header('Services', 'services.php');
?>
<div class="seg">
  <a href="services.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="<?= $tab === 'services' ? 'on' : '' ?>">Services</a>
  <a href="services.php?tab=vehicles<?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>" class="<?= $tab === 'vehicles' ? 'on' : '' ?>">Vehicles</a>
</div>
<form method="get" class="find" style="margin-bottom:.7rem">
  <?= uicon('search') ?>
  <?php if ($tab === 'vehicles'): ?><input type="hidden" name="tab" value="vehicles"><?php endif; ?>
  <?php foreach (['group' => $group, 'type' => $vtype] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
  <?php if ($tab === 'vehicles' && $scope === 'all'): ?><input type="hidden" name="where" value="all"><?php endif; ?>
  <input name="q" value="<?= e($q) ?>" maxlength="60" placeholder="<?= $tab === 'vehicles' ? 'Search vehicles' : 'What service do you need?' ?>" aria-label="Search">
</form>

<div class="chips">
  <?php if ($tab === 'services'): ?>
    <a href="<?= e($qs(['group' => '', 'page' => ''])) ?>" class="<?= $group === '' ? 'on' : '' ?>">All</a>
    <?php foreach ($groups as $k => [$label]): ?><a href="<?= e($qs(['group' => $k, 'page' => ''])) ?>" class="<?= $group === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
  <?php else: ?>
    <a href="<?= e($qs(['type' => '', 'page' => ''])) ?>" class="<?= $vtype === '' ? 'on' : '' ?>">All</a>
    <?php foreach ($types as $k => [$label]): ?><a href="<?= e($qs(['type' => $k, 'page' => ''])) ?>" class="<?= $vtype === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
    <a href="<?= e($qs(['where' => $scope === 'all' ? '' : 'all', 'page' => ''])) ?>" class="<?= $scope === 'all' ? 'on' : '' ?>">All cities</a>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty">
    <?php if ($tab === 'services'): ?>
      <?= $me['city'] ? 'No services match in ' . e($me['city']) . '.' : 'Add your address in <a href="profile.php">Profile</a> to see services near you.' ?>
    <?php else: ?>
      No vehicles match<?= $scope === 'mine' && $me['city'] ? ' in ' . e($me['city']) . '. <a href="' . e($qs(['where' => 'all'])) . '">Try all cities</a>' : '.' ?>
    <?php endif; ?>
  </p>
<?php else: ?>
<div class="list">
  <?php if ($tab === 'services'): foreach ($rows as $s): ?>
    <a class="row" href="service.php?id=<?= (int)$s['id'] ?>">
      <span class="ic"><?= uicon(category_icon($s['category'])) ?></span>
      <span class="grow"><span class="t"><?= e($s['title']) ?></span><br><span class="d"><?= e(category_label($s['category'])) ?>, <?= e($s['barangay']) ?></span><br><?= stars($s['rating'] !== null ? (float)$s['rating'] : null, (int)$s['reviews']) ?></span>
      <span class="end"><strong><?= e(peso($s['rate'])) ?></strong></span>
    </a>
  <?php endforeach; else: foreach ($rows as $v): ?>
    <a class="row" href="vehicle.php?id=<?= (int)$v['id'] ?>">
      <span class="ic"><?= uicon('car') ?></span>
      <span class="grow"><span class="t"><?= e($v['model']) ?></span><br><span class="d"><?= e(ucfirst($v['type'])) ?><?= $v['capacity'] ? ', ' . e($v['capacity']) : '' ?>, <?= e($v['city']) ?><?= $v['own_driver_id'] ? ', driver available' : '' ?></span><br><?= stars($v['rating'] !== null ? (float)$v['rating'] : null, (int)$v['reviews']) ?></span>
      <span class="end"><strong><?= e(peso($v['rate_per_day'])) ?></strong><br><span class="muted">per day</span></span>
    </a>
  <?php endforeach; endif; ?>
</div>
<?php upager($total, $per, $page); endif;
user_footer();
