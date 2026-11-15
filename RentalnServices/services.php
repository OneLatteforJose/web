<?php
require_once __DIR__ . '/includes/user.php';
$me = require_user();
$pdo = db();

if (($_GET['tab'] ?? '') === 'vehicles') {   // old links: the vehicles tab is now its own page
    $keep = array_filter(array_intersect_key($_GET, array_flip(['q', 'type', 'where', 'page'])), fn($v) => $v !== '');
    header('Location: vehicles.php' . ($keep ? '?' . http_build_query($keep) : ''));
    exit;
}
$q      = trim($_GET['q'] ?? '');
$q      = function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60);
$like   = '%' . addcslashes($q, '%_\\') . '%';
$groups = service_groups();
$group  = array_key_exists($_GET['group'] ?? '', $groups) ? $_GET['group'] : '';
$per    = 12;
$page   = max(1, (int)($_GET['page'] ?? 1));
$cityP  = [$me['province'] ?? '', $me['city'] ?? ''];
$rows   = [];
$total  = 0;

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

$qs = function (array $over) use ($q, $group): string {
    $p = array_filter(array_merge(['q' => $q, 'group' => $group], $over), fn($v) => $v !== '');
    return '?' . http_build_query($p);
};

user_header('Services', 'services.php');
?>
<form method="get" class="find" style="margin-bottom:.7rem">
  <?= uicon('search') ?>
  <?php if ($group !== ''): ?><input type="hidden" name="group" value="<?= e($group) ?>"><?php endif; ?>
  <input name="q" value="<?= e($q) ?>" maxlength="60" placeholder="What service do you need?" aria-label="Search">
</form>

<div class="chips">
  <a href="<?= e($qs(['group' => '', 'page' => ''])) ?>" class="<?= $group === '' ? 'on' : '' ?>">All</a>
  <?php foreach ($groups as $k => [$label]): ?><a href="<?= e($qs(['group' => $k, 'page' => ''])) ?>" class="<?= $group === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty"><?= $me['city'] ? 'No services match in ' . e($me['city']) . '.' : 'Add your address in <a href="profile.php">Profile</a> to see services near you.' ?></p>
<?php else: ?>
<div class="list">
  <?php foreach ($rows as $s): ?>
    <a class="row" href="service.php?id=<?= (int)$s['id'] ?>">
      <span class="ic"><?= uicon(category_icon($s['category'])) ?></span>
      <span class="grow"><span class="t"><?= e($s['title']) ?></span><br><span class="d"><?= e(category_label($s['category'])) ?>, <?= e($s['barangay']) ?></span><br><?= stars($s['rating'] !== null ? (float)$s['rating'] : null, (int)$s['reviews']) ?></span>
      <span class="end"><strong><?= e(peso($s['rate'])) ?></strong></span>
    </a>
  <?php endforeach; ?>
</div>
<?php upager($total, $per, $page); endif;
user_footer();
