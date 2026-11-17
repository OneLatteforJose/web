<?php
require_once __DIR__ . '/../includes/manager.php';
require_once __DIR__ . '/../includes/period_reports.php';
$m = require_manager();
$pdo = db();
[$cond, $cp] = city_filter($m['key'], 'a');

$pTotal = (int)db_value($pdo,
    "SELECT COUNT(*) FROM users u JOIN areas a ON a.id = u.area_id LEFT JOIN provider_profiles pp ON pp.user_id = u.id
     WHERE u.role = 'provider' AND COALESCE(pp.application_status, 'approved') = 'approved'$cond", $cp);
$waitingApplications = pending_applications($pdo, $m['key']);
$waitingServices = pending_service_submissions($pdo, $m['key']);

manager_header('Dashboard', 'dashboard.php');
if ($waitingApplications > 0) {
    echo '<p class="note"><b>' . plural($waitingApplications, 'provider application') . '</b> in ' . e($m['city']) . ' waiting for you. <a href="applications.php">Review them</a></p>';
}
if ($waitingServices > 0) {
    echo '<p class="note"><b>' . plural($waitingServices, 'new service') . '</b> proposed by providers in ' . e($m['city']) . ', waiting for you. <a href="applications.php?type=services">Review them</a></p>';
}
if ($pTotal === 0) {
    echo '<p class="note">No providers live in ' . e($m['city']) . ' yet. An admin creates provider accounts; once one exists you can add their vehicles and services here.</p>';
}
?>
<?php
// Weekly / monthly report for this manager's city (moved here from the Reports page).
$period = period_request();
$rep = period_report_area($pdo, $period, $m['key'], false);
?>
<span id="report"></span>
<?php
render_period_report($period, $rep, ['scope' => 'Numbers for ' . $m['city'] . ', ' . $m['province'] . '.']);
page_footer();
