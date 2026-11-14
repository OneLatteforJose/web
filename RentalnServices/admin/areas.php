<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_admin();
$pdo = db();
$err = '';

// Split a textarea into unique, non-empty barangay names (max 100 characters each).
function barangay_names(string $text): array {
    $cut = fn($n) => function_exists('mb_substr') ? mb_substr($n, 0, 100) : substr($n, 0, 100);
    return array_values(array_unique(array_map($cut, array_filter(array_map('trim', preg_split('/\R/', $text))))));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // A city only exists through its barangays, so adding a city means adding its first barangays.
    if ($action === 'add') {
        $province = trim($_POST['province'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $names    = barangay_names($_POST['names'] ?? '');
        if ($province === '' || $name === '') {
            $err = 'Province and city/municipality are required.';
        } elseif (!$names) {
            $err = 'Enter at least one barangay name.';
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO areas (province, city_municipality, barangay) VALUES (?, ?, ?)");
            $added = 0;
            $pdo->beginTransaction();
            foreach ($names as $n) {
                $ins->execute([$province, $name, $n]);
                $added += $ins->rowCount();
            }
            $pdo->commit();
            $first = $pdo->prepare("SELECT MIN(id) FROM areas WHERE province = ? AND city_municipality = ?");
            $first->execute([$province, $name]);
            log_action('area.add', 'area', (int)$first->fetchColumn(), "Added $added barangay(s) to $name, $province");
            flash($added . ' barangay(s) added to ' . $name . '.');
            header('Location: areas.php?key=' . urlencode(city_key($province, $name)));
            exit;
        }
    }
}

// One city at a time: it is picked with the province/city dropdowns or the search box (both just open ?key=Province|City),
// so this page never lists the 1,640+ cities and its cost does not grow with the data.
$key = $_GET['key'] ?? '';
$sel = parse_city_key($key);
$city = null;
if ($sel) {
    $args = $sel;
    $scalar = function (string $sql) use ($pdo, $args) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    };
    $barangays = (int)$scalar("SELECT COUNT(*) FROM areas WHERE province = ? AND city_municipality = ?");
    if ($barangays > 0) {
        $mg = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.phone FROM users u JOIN areas ua ON ua.id = u.area_id
                             WHERE u.role = 'manager' AND u.is_active = 1 AND ua.province = ? AND ua.city_municipality = ? LIMIT 1");
        $mg->execute($args);
        $city = [
            'province'   => $sel[0],
            'name'       => $sel[1],
            'barangays'  => $barangays,
            'manager'    => $mg->fetch() ?: null,
            'inactive'   => (int)$scalar("SELECT COUNT(*) FROM users u JOIN areas ua ON ua.id = u.area_id
                                          WHERE u.role = 'manager' AND u.is_active = 0 AND ua.province = ? AND ua.city_municipality = ?"),
            'providers'  => (int)$scalar("SELECT COUNT(*) FROM users u JOIN areas ua ON ua.id = u.area_id LEFT JOIN provider_profiles pp ON pp.user_id = u.id
                                          WHERE u.role = 'provider' AND COALESCE(pp.application_status, 'approved') = 'approved' AND ua.province = ? AND ua.city_municipality = ?"),
            'customers'  => (int)$scalar("SELECT COUNT(*) FROM users u JOIN areas ua ON ua.id = u.area_id
                                          WHERE u.role = 'customer' AND ua.province = ? AND ua.city_municipality = ?"),
        ];
    } else {
        $err = $err ?: 'That city was not found.';
    }
}
$totals = $pdo->query("SELECT COUNT(*) AS barangays, COUNT(DISTINCT province, city_municipality) AS cities FROM areas")->fetch();
$cityKey = $city ? city_key($city['province'], $city['name']) : '';
$posted = ($_POST['action'] ?? '') === 'add';

page_header('Areas', 'areas.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p class="muted">A manager covers one city or municipality. Pick a place to see who manages it and to assign a manager.
  <?= number_format((int)$totals['cities']) ?> cities and municipalities, <?= number_format((int)$totals['barangays']) ?> barangays.
  <?php if (!$totals['barangays']): ?>Run <code>database/import_psgc.php</code> to load every barangay in the Philippines.<?php endif; ?></p>

<h2>Select a city or municipality</h2>
<form class="box" onsubmit="return false">
  <?php area_picker(['api' => '../api/areas.php', 'js' => '../assets/areas.js', 'city' => $cityKey, 'barangay' => false,
                     'province_label' => 'Select province', 'city_label' => 'Select city or municipality']); ?>
  <div class="area-search" data-api="../api/areas.php" data-target="areas.php?key=" style="grid-column:1/-1">
    <label for="area-q">Or search by name</label>
    <input id="area-q" type="search" autocomplete="off" placeholder="Type at least 2 letters, for example Ilagan" aria-describedby="area-q-status">
    <ul hidden></ul>
    <span id="area-q-status" class="sr-only" data-status role="status" aria-live="polite"></span>
  </div>
</form>
<script src="../assets/area-search.js" defer></script>
<script>
// Choosing a city in the dropdown opens its details.
document.addEventListener('change', function (e) {
  var el = e.target;
  if (el.matches && el.matches('.area-picker [data-level=city]') && el.value) {
    location.href = 'areas.php?key=' + encodeURIComponent(el.value);
  }
});
</script>

<?php if ($city): ?>
<section class="panel">
  <div class="cityhead">
    <h3><?= e($city['name']) ?>, <?= e($city['province']) ?></h3>
    <div class="acts">
      <?php if (!$city['manager']): ?>
        <a class="btn-link" href="accounts.php?add=manager&amp;city=<?= e(urlencode($cityKey)) ?>#add">Assign a manager</a>
      <?php endif; ?>
      <a class="btn-link alt" href="area.php?key=<?= e(urlencode($cityKey)) ?>">Manage barangays</a>
    </div>
  </div>
  <div class="rows">
    <div class="row"><span>Manager</span>
      <b><?php if ($city['manager']): ?>
        <a href="account.php?id=<?= (int)$city['manager']['id'] ?>"><?= e($city['manager']['full_name']) ?></a>
        <span class="muted" style="font-weight:400"> <?= e($city['manager']['email']) ?></span>
      <?php else: ?>
        <span class="muted" style="font-weight:400">No manager yet<?= $city['inactive'] ? ' (' . $city['inactive'] . ' deactivated)' : '' ?></span>
      <?php endif; ?></b></div>
    <div class="row"><span>Barangays</span><b><?= number_format($city['barangays']) ?></b></div>
    <div class="row"><span>Providers</span><b><?= number_format($city['providers']) ?></b></div>
    <div class="row"><span>Customers</span><b><?= number_format($city['customers']) ?></b></div>
  </div>
  <?php if (!$city['manager']): ?>
    <p class="muted" style="margin-bottom:0">Until a manager is assigned, nobody can approve bookings or service requests in this city.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<details class="panel" <?= $posted ? 'open' : '' ?>>
  <summary><b>Can't find a place? Add one manually</b></summary>
  <p class="muted">Every barangay in the Philippines is already loaded, so this is only for places that are missing.</p>
  <form method="post" class="box" style="margin-bottom:0">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Province <input name="province" required value="<?= e($posted ? ($_POST['province'] ?? '') : '') ?>"></label>
    <label>City / Municipality <input name="name" required value="<?= e($posted ? ($_POST['name'] ?? '') : '') ?>"></label>
    <label>Barangays (one per line)
      <textarea name="names" rows="4" required><?= e($posted ? ($_POST['names'] ?? '') : '') ?></textarea>
    </label>
    <label>&nbsp;<button>Add</button></label>
  </form>
</details>
<?php
page_footer();
