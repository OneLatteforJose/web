<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$jobs = provider_jobs_sql();
$jp = provider_job_params($me);

$type = $_GET['type'] ?? '';
$kinds = ['bookings' => 'booking', 'requests' => 'request'];
$statuses = ['pending', 'approved', 'completed', 'rejected', 'cancelled'];
$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$where = ' WHERE 1 = 1';
$params = $jp;
if (isset($kinds[$type])) { $where .= ' AND j.kind = ?'; $params[] = $kinds[$type]; } else { $type = ''; }
if ($status !== '') { $where .= ' AND j.status = ?'; $params[] = $status; }

$pg = paginate((int)prov_value($pdo, "SELECT COUNT(*) FROM ($jobs) j$where", $params), 25);
$st = $pdo->prepare("SELECT j.* FROM ($jobs) j$where ORDER BY j.at DESC, j.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$st->execute($params);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $r['what']     = ($r['kind'] === 'booking' ? 'Vehicle booking' : 'Service request');
    $r['when']     = fmt_when($r['at']);
    $r['who']      = provider_customer($r);
    $r['amount']   = peso($r['total']);
    $r['status']   = ucfirst($r['status']);
}
unset($r);

provider_header('Jobs', 'jobs.php');
echo '<p class="muted">Bookings of your vehicles, bookings where you are the driver, and requests for your services. A manager approves every job.</p>';
echo '<div class="tabs"><a href="?' . e(http_build_query(['status' => $status])) . '" class="' . ($type === '' ? 'on' : '') . '">All</a>'
   . '<a href="?' . e(http_build_query(['type' => 'bookings', 'status' => $status])) . '" class="' . ($type === 'bookings' ? 'on' : '') . '">Vehicle bookings</a>'
   . '<a href="?' . e(http_build_query(['type' => 'requests', 'status' => $status])) . '" class="' . ($type === 'requests' ? 'on' : '') . '">Service requests</a></div>';
echo '<div class="tabs"><a href="?' . e(http_build_query(['type' => $type])) . '" class="' . ($status === '' ? 'on' : '') . '">Any status</a>';
foreach ($statuses as $s) echo '<a href="?' . e(http_build_query(['type' => $type, 'status' => $s])) . '" class="' . ($status === $s ? 'on' : '') . '">' . ucfirst($s) . '</a>';
echo '</div>';
render_table(
    ['id' => '#', 'what' => 'Type', 'title' => 'For', 'when' => 'When', 'who' => 'Customer', 'yours' => 'Your part', 'amount' => 'Total', 'status' => 'Status'],
    $rows,
    ['id' => fn($r) => 'job.php?type=' . $r['kind'] . '&id=' . $r['id']]
);
render_pager($pg);
page_footer();
