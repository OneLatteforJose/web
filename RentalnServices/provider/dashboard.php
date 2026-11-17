<?php
require_once __DIR__ . '/../includes/provider.php';
require_once __DIR__ . '/../includes/period_reports.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];

[$vTotal] = array_map('intval', $pdo->query("SELECT COUNT(*) FROM vehicles WHERE provider_id = $me")->fetch(PDO::FETCH_NUM));
[$sTotal] = array_map('intval', $pdo->query("SELECT COUNT(*) FROM services WHERE provider_id = $me")->fetch(PDO::FETCH_NUM));

provider_header('Dashboard', 'dashboard.php');
if ($vTotal + $sTotal === 0) {
    echo '<p class="note">You have no vehicles or services listed yet. You can propose a service on the <a href="services.php">Services</a> page, and the manager of ' . e($p['city'] ?: 'your city') . ' or an admin approves it. The manager adds vehicles for you.</p>';
}
[$subWaiting, $subRejected] = provider_submission_counts($pdo, $me);
if ($subWaiting + $subRejected > 0) {
    echo '<p class="note">' . ($subWaiting ? '<b>' . plural($subWaiting, 'service') . '</b> waiting for approval. ' : '')
       . ($subRejected ? '<b>' . plural($subRejected, 'submission') . '</b> not approved: see the reason on the <a href="services.php">Services</a> page.' : '') . '</p>';
}
?>
<?php
// Weekly / monthly report for this provider (moved here from the Reports page).
$period = period_request();
$rep = period_report_provider($pdo, $period, $me);
?>
<span id="report"></span>
<?php
render_period_report($period, $rep, ['scope' => 'Numbers for your own vehicles and services. Driver-only jobs are not included.']);
page_footer();
