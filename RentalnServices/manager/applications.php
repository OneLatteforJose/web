<?php
require_once __DIR__ . '/../includes/manager.php';
$m = require_manager();
$pdo = db();
$err = '';
// Two inboxes: providers who signed up in this city, and new services that providers in this city propose.
$type = ($_GET['type'] ?? 'providers') === 'services' ? 'services' : 'providers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A manager can only decide items from their own city; anything else is "not found".
    [$ok, $msg] = $type === 'services' ? handle_service_submission_post($pdo, (int)$m['id'], $m['key']) : handle_application_post($pdo, (int)$m['id'], $m['key']);
    if ($ok) {
        flash($msg);
        header('Location: applications.php?' . http_build_query(array_filter(['type' => $type, 'status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? ''])));
        exit;
    }
    $err = $msg;
}

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
$q = trim($_GET['q'] ?? '');
$opts = ['status' => $status, 'city' => $m['key'], 'q' => $q];
[$rows, $pg] = $type === 'services' ? service_submissions_page($pdo, $opts) : applications_page($pdo, $opts);

manager_header('Applications', 'applications.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
render_inbox_tabs($type, pending_applications($pdo, $m['key']), pending_service_submissions($pdo, $m['key']), array_filter(['status' => $status === 'pending' ? '' : $status, 'q' => $q]));
?>
<?php if ($type === 'services'): ?>
<p class="muted">New services that providers in <?= e($m['city']) ?> propose. Check the category, rate and details, then approve or reject. Nothing is added to the catalog until you or an admin approves it; whoever acts first decides. A rejection needs a short reason, which the provider will see.</p>
<?php else: ?>
<p class="muted">Providers in <?= e($m['city']) ?> who signed up and are waiting for you. Check their details, then approve or reject. An admin can also decide them; whoever acts first decides. A rejection needs a short reason, which the applicant will see.</p>
<?php endif; ?>
<div class="tabs">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
    <a href="?<?= e(http_build_query(array_filter(['type' => $type, 'status' => $k, 'q' => $q]))) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<form method="get" class="box" style="grid-template-columns:1fr auto">
  <input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
  <input name="q" value="<?= e($q) ?>" placeholder="<?= $type === 'services' ? 'Search service, provider or business' : 'Search name, email or business' ?>" aria-label="Search">
  <button>Search</button>
</form>
<?php
if ($type === 'services') render_service_submissions($rows, false); else render_applications($rows, false);
render_pager($pg);
page_footer();
