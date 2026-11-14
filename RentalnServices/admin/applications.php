<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_once __DIR__ . '/../includes/applications.php';
require_admin();
$pdo = db();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $msg] = handle_application_post($pdo, (int)$_SESSION['user']['id'], null);   // an admin can decide any city
    if ($ok) {
        flash($msg);
        header('Location: applications.php?' . http_build_query(array_filter(['status' => $_GET['status'] ?? '', 'city' => $_GET['city'] ?? '', 'q' => $_GET['q'] ?? ''])));
        exit;
    }
    $err = $msg;
}

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
$city = parse_city_key($_GET['city'] ?? '') ? (string)$_GET['city'] : '';
$q = trim($_GET['q'] ?? '');
[$rows, $pg] = applications_page($pdo, ['status' => $status, 'city' => $city, 'q' => $q]);

page_header('Provider applications', 'applications.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p class="muted">Providers sign up on their own and wait here. The manager of their city or an admin can approve or reject each one. An approved account is switched on; a rejected one stays off and the applicant sees your reason when they try to sign in.</p>
<div class="tabs">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
    <a href="?<?= e(http_build_query(array_filter(['status' => $k, 'city' => $city, 'q' => $q]))) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="box">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <?php area_picker(['api' => '../api/areas.php', 'js' => '../assets/areas.js', 'city' => $city, 'barangay' => false,
                     'province_label' => 'All provinces', 'city_label' => 'All cities']); ?>
  <label>Search name, email or business <input name="q" value="<?= e($q) ?>"></label>
  <label>&nbsp;<button>Filter</button></label>
</form>
<?php
render_applications($rows, true);
render_pager($pg);
page_footer();
