<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A manager can only decide applications from their own city; anything else is "not found".
    [$ok, $msg] = handle_application_post($pdo, (int)$m['id'], $m['key']);
    if ($ok) {
        flash($msg);
        header('Location: applications.php?' . http_build_query(array_filter(['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? ''])));
        exit;
    }
    $err = $msg;
}

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
$q = trim($_GET['q'] ?? '');
[$rows, $pg] = applications_page($pdo, ['status' => $status, 'city' => $m['key'], 'q' => $q]);

manager_header('Provider applications', 'applications.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
?>
<p class="muted">Providers in <?= e($m['city']) ?> who signed up and are waiting for you. Check their details, then approve or reject. An admin can also decide them; whoever acts first decides. A rejection needs a short reason, which the applicant will see.</p>
<div class="tabs">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
    <a href="?<?= e(http_build_query(array_filter(['status' => $k, 'q' => $q]))) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="box" style="grid-template-columns:1fr auto">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input name="q" value="<?= e($q) ?>" placeholder="Search name, email or business" aria-label="Search applications">
  <button>Search</button>
</form>
<?php
render_applications($rows, false);
render_pager($pg);
page_footer();
