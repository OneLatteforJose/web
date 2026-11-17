<?php
require_once __DIR__ . '/includes/user.php';
$me = browse_user();
$pdo = db();
$guest = !empty($me['guest']);
$area  = browse_city($pdo);   // ?city=Province|City from the landing page
if ($guest && !$area) { header('Location: index.php#services'); exit; }
$cityKey = $area ? city_key($area[0], $area[1]) : '';

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
$cityP  = $area ?: [$me['province'] ?? '', $me['city'] ?? ''];
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

$qs = function (array $over) use ($q, $group, $cityKey): string {
    $p = array_filter(array_merge(['q' => $q, 'group' => $group, 'city' => $cityKey], $over), fn($v) => $v !== '');
    return '?' . http_build_query($p);
};

user_header($area ? 'Services in ' . $area[1] : 'Services', 'services.php', $guest ? ['guest' => true, 'back' => 'index.php#services'] : ($area ? ['back' => 'index.php#services'] : []));
if ($guest): ?><p class="muted" style="margin:0 0 .7rem"><?= e($area[1]) ?>, <?= e($area[0]) ?>. <a href="login.php">Log in</a> or <a href="register.php">sign up</a> to book.</p><?php endif;
?>
<form method="get" class="find" style="margin-bottom:.7rem">
  <?= uicon('search') ?>
  <?php if ($group !== ''): ?><input type="hidden" name="group" value="<?= e($group) ?>"><?php endif; ?>
  <?php if ($cityKey !== ''): ?><input type="hidden" name="city" value="<?= e($cityKey) ?>"><?php endif; ?>
  <input name="q" value="<?= e($q) ?>" maxlength="60" placeholder="What service do you need?" aria-label="Search">
</form>

<style>
/* category tiles: one row, a gap before the list, and the chosen one (All by default) filled green */
.cats.six{grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:1.4rem}
.cats.six .cat.on{background:#177a4b;border-color:#177a4b;color:#fff;font-weight:600}
.cats.six .cat.on .ic{background:rgba(255,255,255,.22);color:#fff}
@media (prefers-color-scheme:dark){.cats.six .cat.on{background:#3dbb78;border-color:#3dbb78;color:#06130c}.cats.six .cat.on .ic{background:rgba(6,19,12,.2);color:#06130c}}
</style>
<div class="cats six">
  <a class="cat<?= $group === '' ? ' on' : '' ?>" href="<?= e($qs(['group' => '', 'page' => ''])) ?>"<?= $group === '' ? ' aria-current="true"' : '' ?>><span class="ic"><?= uicon('services') ?></span>All</a>
  <?php foreach ($groups as $k => [$label, $ic]): ?>
    <a class="cat<?= $group === $k ? ' on' : '' ?>" href="<?= e($qs(['group' => $k, 'page' => ''])) ?>"<?= $group === $k ? ' aria-current="true"' : '' ?>><span class="ic"><?= uicon($ic) ?></span><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty"><?= $area ? 'No services match in ' . e($area[1]) . '.' : ($me['city'] ? 'No services match in ' . e($me['city']) . '.' : 'Add your address in <a href="profile.php">Profile</a> to see services near you.') ?></p>
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
