<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_once __DIR__ . '/../includes/applications.php';
require_admin();
$pdo = db();
$err = '';
// Two inboxes: providers who signed up, and new services that providers propose.
$type = ($_GET['type'] ?? 'providers') === 'services' ? 'services' : 'providers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminId = (int)$_SESSION['user']['id'];   // an admin can decide any city
    [$ok, $msg] = $type === 'services' ? handle_service_submission_post($pdo, $adminId, null) : handle_application_post($pdo, $adminId, null);
    if ($ok) {
        flash($msg);
        header('Location: applications.php?' . http_build_query(array_filter(['type' => $type, 'status' => $_GET['status'] ?? '', 'city' => $_GET['city'] ?? '', 'q' => $_GET['q'] ?? ''])));
        exit;
    }
    $err = $msg;
}

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
$city = parse_city_key($_GET['city'] ?? '') ? (string)$_GET['city'] : '';
$q = trim($_GET['q'] ?? '');
$opts = ['status' => $status, 'city' => $city, 'q' => $q];
[$rows, $pg] = $type === 'services' ? service_submissions_page($pdo, $opts) : applications_page($pdo, $opts);

page_header('Applications', 'applications.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
render_inbox_tabs($type, pending_applications($pdo), pending_service_submissions($pdo), array_filter(['status' => $status === 'pending' ? '' : $status, 'city' => $city, 'q' => $q]));
?>
<?php if ($type === 'services'): ?>
<p class="muted">Providers propose new services here. Nothing is added to the catalog until the manager of the city or an admin approves it; an approved service becomes available to customers straight away, and a rejected one stays out and the provider sees your reason.</p>
<?php else: ?>
<p class="muted">Providers sign up on their own and wait here. The manager of their city or an admin can approve or reject each one. An approved account is switched on; a rejected one stays off and the applicant sees your reason when they try to sign in.</p>
<?php endif; ?>
<div class="tabs">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
    <a href="?<?= e(http_build_query(array_filter(['type' => $type, 'status' => $k, 'city' => $city, 'q' => $q]))) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="box">
  <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
  <?php area_picker(['api' => '../api/areas.php', 'js' => '../assets/areas.js', 'city' => $city, 'barangay' => false,
                     'province_label' => 'All provinces', 'city_label' => 'All cities']); ?>
  <label>Search <?= $type === 'services' ? 'service, provider or business' : 'name, email or business' ?> <input name="q" value="<?= e($q) ?>"></label>
  <label>&nbsp;<button>Filter</button></label>
</form>
<?php
if ($type === 'services') render_service_submissions($rows, true); else render_applications($rows, true);
render_pager($pg);
page_footer();
