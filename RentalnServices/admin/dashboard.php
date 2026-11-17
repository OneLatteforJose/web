<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/areas.php';
require_once __DIR__ . '/../includes/applications.php';
require_once __DIR__ . '/../includes/period_reports.php';
require_admin();
$pdo = db();

$waitingApplications = pending_applications($pdo);
$waitingServices = pending_service_submissions($pdo);

// After the PSGC import almost every city has no manager, which is normal. Only warn about the cities where something
// already exists (an account, vehicle, service, booking or request) but nobody can approve it.
$noManager = $pdo->query(
    "SELECT a.province, a.city_municipality AS name
     FROM (SELECT area_id FROM users WHERE area_id IS NOT NULL AND role <> 'admin'
           UNION SELECT area_id FROM vehicles UNION SELECT area_id FROM services
           UNION SELECT area_id FROM bookings UNION SELECT area_id FROM service_requests) used
     JOIN areas a ON a.id = used.area_id
     LEFT JOIN (SELECT DISTINCT ma.province, ma.city_municipality
                FROM users u JOIN areas ma ON ma.id = u.area_id
                WHERE u.role = 'manager' AND u.is_active = 1) m
            ON m.province = a.province AND m.city_municipality = a.city_municipality
     WHERE m.province IS NULL
     GROUP BY a.province, a.city_municipality ORDER BY a.province, a.city_municipality"
)->fetchAll();

page_header('Dashboard', 'dashboard.php');

if ($waitingApplications > 0) {
    echo '<p class="note"><b>' . plural($waitingApplications, 'provider application') . '</b> waiting for approval. <a href="applications.php">Review them</a></p>';
}
if ($waitingServices > 0) {
    echo '<p class="note"><b>' . plural($waitingServices, 'new service') . '</b> proposed by providers, waiting for approval. <a href="applications.php?type=services">Review them</a></p>';
}
if ($noManager) {
    echo '<p class="err">These cities already have accounts, vehicles, services or bookings but no active manager, so nobody can approve requests there: '
       . e(implode(', ', array_map(fn($c) => $c['name'], array_slice($noManager, 0, 10))))
       . (count($noManager) > 10 ? ' and ' . (count($noManager) - 10) . ' more' : '') . '. <a href="accounts.php">Add a manager</a></p>';
}

?>
<?php
// Weekly / monthly report (moved here from the Reports page). All cities, or one city.
$period = period_request();
$repCity = parse_city_key($_GET['city'] ?? '') ? (string)$_GET['city'] : '';
$rep = period_report_area($pdo, $period, $repCity, true);
?>
<span id="report"></span>
<?php
render_period_report($period, $rep, [
    'keep'    => ['city' => $repCity],
    'custom'  => true,
    'scope'   => $repCity !== '' ? 'Filtered to one city.' : 'All cities.',
    'filters' => function () use ($repCity) {
        area_picker(['api' => '../api/areas.php', 'js' => '../assets/areas.js', 'city' => $repCity, 'barangay' => false,
                     'province_label' => 'All provinces', 'city_label' => 'All cities']);
    },
]);
page_footer();
