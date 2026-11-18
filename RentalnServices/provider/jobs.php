<?php
require_once __DIR__ . '/../includes/provider.php';
require_once __DIR__ . '/../includes/decisions.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$flashErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {   // quick approve / reject from the waiting list
    csrf_check();
    $k = ($_POST['kind'] ?? '') === 'request' ? 'request' : 'booking';
    $jid = (int)($_POST['id'] ?? 0);
    $own = $k === 'booking'
        ? prov_value($pdo, "SELECT 1 FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE b.id = ? AND v.provider_id = ?", [$jid, $me])
        : prov_value($pdo, "SELECT 1 FROM service_requests r JOIN services s ON s.id = r.service_id WHERE r.id = ? AND s.provider_id = ?", [$jid, $me]);
    if (!$own) {
        $flashErr = 'You can only decide jobs for your own vehicles and services.';
    } else {
        [$msg, $flashErr] = decide_order($pdo, $k, $jid, in_array($_POST['action'] ?? '', ['approve', 'reject'], true) ? $_POST['action'] : '', $me);
        if ($msg !== '') {
            flash($msg);
            header('Location: jobs.php?status=pending');
            exit;
        }
    }
}
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

// Pending jobs the provider owns (their vehicle or service), oldest first.
$waiting = $pdo->prepare(
    "(SELECT 'booking' AS kind, b.id, v.model AS title, u.full_name AS customer, b.start_at AS at, b.total_price, b.created_at
      FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.user_id WHERE b.status = 'pending' AND v.provider_id = ?)
     UNION ALL
     (SELECT 'request', r.id, s.title, u.full_name, r.scheduled_at, r.total_price, r.created_at
      FROM service_requests r JOIN services s ON s.id = r.service_id JOIN users u ON u.id = r.user_id WHERE r.status = 'pending' AND s.provider_id = ?)
     ORDER BY created_at"
);
$waiting->execute([$me, $me]);
$waiting = $waiting->fetchAll();

provider_header('Jobs', 'jobs.php');
if ($flashErr) echo '<p class="err">' . e($flashErr) . '</p>';
if ($waiting) {
    echo '<h2>Waiting for your approval (' . count($waiting) . ')</h2><div class="scroll"><table><thead><tr><th>Type</th><th>For</th><th>Customer</th><th>When</th><th>Total</th><th>Decision</th></tr></thead><tbody>';
    foreach ($waiting as $w) {
        $form = fn(string $a, string $label, string $cls = '', string $ask = '') => '<form method="post" style="display:inline"' . ($ask ? ' onsubmit="return confirm(\'' . $ask . '\')"' : '') . '>'
            . csrf_field() . '<input type="hidden" name="kind" value="' . e($w['kind']) . '"><input type="hidden" name="id" value="' . (int)$w['id'] . '"><input type="hidden" name="action" value="' . $a . '"><button class="' . $cls . '">' . $label . '</button></form> ';
        echo '<tr><td>' . ($w['kind'] === 'booking' ? 'Vehicle booking' : 'Service request') . '</td><td><a href="job.php?type=' . e($w['kind']) . '&amp;id=' . (int)$w['id'] . '">' . e($w['title']) . '</a></td>'
           . '<td>' . e(provider_customer(['status' => 'pending', 'customer' => $w['customer']])) . '</td><td>' . e(fmt_when($w['at'])) . '</td><td>' . e(peso($w['total_price'])) . '</td>'
           . '<td>' . $form('approve', 'Approve') . $form('reject', 'Reject', 'danger', 'Reject this job? The customer is refunded.') . '</td></tr>';
    }
    echo '</tbody></table></div><h2>All jobs</h2>';
}
echo '<p class="muted">Bookings of your vehicles, bookings where you are the driver, and requests for your services. You can approve or reject pending jobs for your own vehicles and services; the area manager and admin can too.</p>';
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
