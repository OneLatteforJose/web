<?php
require_once __DIR__ . '/../includes/provider.php';
require_once __DIR__ . '/../includes/decisions.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];

$type = ($_GET['type'] ?? '') === 'request' ? 'request' : 'booking';
$id = (int)($_GET['id'] ?? 0);

if ($type === 'booking') {
    $st = $pdo->prepare(
        "SELECT b.*, v.model AS title, v.type AS vtype, v.plate_no, v.provider_id AS vprov, ds.provider_id AS dprov, ds.title AS driver_title, dp.full_name AS driver_name,
                u.full_name AS customer, u.phone AS customer_phone, a.barangay, a.city_municipality AS city
         FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.user_id
         LEFT JOIN services ds ON ds.id = b.driver_service_id LEFT JOIN users dp ON dp.id = ds.provider_id LEFT JOIN areas a ON a.id = b.area_id
         WHERE b.id = ? AND (v.provider_id = ? OR ds.provider_id = ?)"
    );
    $st->execute([$id, $me, $me]);
} else {
    $st = $pdo->prepare(
        "SELECT r.*, s.title, s.category, s.provider_id AS sprov, u.full_name AS customer, u.phone AS customer_phone, a.barangay, a.city_municipality AS city
         FROM service_requests r JOIN services s ON s.id = r.service_id JOIN users u ON u.id = r.user_id LEFT JOIN areas a ON a.id = r.area_id
         WHERE r.id = ? AND s.provider_id = ?"
    );
    $st->execute([$id, $me]);
}
$j = $st->fetch();
if (!$j) {
    flash('That job was not found among yours.');
    header('Location: jobs.php');
    exit;
}
$canDecide = $type === 'booking' ? (int)$j['vprov'] === $me : true;   // the owner of the vehicle / service decides; a driver-only job is the owner's call
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canDecide) {
    csrf_check();
    [$msg, $err] = decide_order($pdo, $type, $id, $_POST['action'] ?? '', $me, ['note' => $_POST['note'] ?? '']);
    if ($msg !== '') {
        flash($msg);
        header('Location: job.php?type=' . $type . '&id=' . $id);
        exit;
    }
    $st->execute($type === 'booking' ? [$id, $me, $me] : [$id, $me]);
    $j = $st->fetch();
}
$known = in_array($j['status'], ['approved', 'completed'], true);   // customer contact details only after approval
$mine = $type === 'booking' ? (int)$j['vprov'] === $me : true;       // owns the vehicle / service (a driver-only job has no payment or review for them)
$yours = $type === 'request' ? 'Service' : ((int)$j['vprov'] === $me ? ((int)$j['dprov'] === $me ? 'Vehicle and driver' : 'Vehicle') : 'Driver');

$payments = $review = [];
if ($mine) {
    $col = $type === 'booking' ? 'booking_id' : 'service_request_id';
    $pay = $pdo->prepare("SELECT id, amount, method, reference_no, status, paid_at FROM payments WHERE $col = ? ORDER BY id");
    $pay->execute([$id]);
    $payments = $pay->fetchAll();
    foreach ($payments as &$r) { $r['amount'] = peso($r['amount']); $r['status'] = ucfirst($r['status']); $r['method'] = $r['method'] ?: '-'; }
    unset($r);
    $rv = $pdo->prepare("SELECT rating, comment FROM reviews WHERE $col = ?");
    $rv->execute([$id]);
    $review = $rv->fetch() ?: [];
}

provider_header(($type === 'booking' ? 'Vehicle booking #' : 'Service request #') . $id, 'jobs.php');
$where = implode(', ', array_filter([$j['barangay'] ?? '', $j['city'] ?? '']));
?>
<p><a href="jobs.php">&larr; Back to jobs</a></p>
<?php if ($err) echo '<p class="err">' . e($err) . '</p>'; ?>
<div class="stats">
  <?= stat_card('calendar', ucfirst($j['status']), 'Status', $j['status'] === 'pending' ? 'waiting for approval' : '') ?>
  <?= stat_card('chart', peso($j['total_price']), 'Total price', 'paid online by the customer') ?>
  <?= stat_card('briefcase', $yours, 'Your part', '') ?>
</div>
<h2>Details</h2>
<?php
if ($type === 'booking') {
    render_kv([
        'Vehicle'  => ucfirst($j['vtype']) . ': ' . $j['title'] . ' (' . $j['plate_no'] . ')',
        'Starts'   => fmt_when($j['start_at']),
        'Ends'     => fmt_when($j['end_at']),
        'Driver'   => $j['with_driver'] ? ($j['driver_name'] ? $j['driver_name'] . ' (' . $j['driver_title'] . ')' : ((int)$j['vprov'] === (int)$me ? 'You drive: you own the vehicle' : 'The vehicle owner drives')) : 'Drive yourself',
        'Customer' => provider_customer($j) . ($known && $j['customer_phone'] ? ', ' . $j['customer_phone'] : ''),
        'Pickup'   => $known ? ($j['pickup_street'] ?: '-') : 'Shown once a manager approves the booking',
        'Area'     => $where,
        'Submitted' => fmt_when($j['created_at']),
    ]);
} else {
    render_kv([
        'Service'  => $j['title'] . ' (' . ucfirst(str_replace('_', ' ', $j['category'])) . ')',
        'When'     => fmt_when($j['scheduled_at']),
        'Customer' => provider_customer($j) . ($known && $j['customer_phone'] ? ', ' . $j['customer_phone'] : ''),
        'Location' => $known ? implode(', ', array_filter([$j['service_street'], $where])) : 'Shown once a manager approves the request',
        'Submitted' => fmt_when($j['created_at']),
    ]);
}
if ($canDecide) {
    $startAt = $type === 'booking' ? $j['start_at'] : $j['scheduled_at'];
    echo '<h2>Decision</h2><div class="panel">';
    if ($j['status'] === 'pending') {
        echo '<div class="actions"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="approve"><button>Approve</button></form>'
           . '<form method="post" onsubmit="return confirm(\'Reject this job? The customer is refunded.\')">' . csrf_field() . '<input type="hidden" name="action" value="reject">'
           . '<label>Note (optional) <input name="note" maxlength="150"></label><button class="danger">Reject</button></form></div>';
    } elseif ($j['status'] === 'approved' && strtotime($startAt) <= time()) {
        echo '<form method="post" class="actions" onsubmit="return confirm(\'Mark this job as completed?\')">' . csrf_field() . '<input type="hidden" name="action" value="complete"><button>Mark completed</button></form>';
    } elseif ($j['status'] === 'approved') {
        echo '<p class="muted" style="margin:0">Approved. You can mark it completed once it has started.</p>';
    } else {
        echo '<p class="muted" style="margin:0">This job is ' . e($j['status']) . ' and can no longer be changed.</p>';
    }
    echo '</div>';
}
if ($mine) {
    echo '<h2>Payments</h2>';
    render_table(['id' => '#', 'amount' => 'Amount', 'method' => 'Method', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments);
    if ($review) {
        echo '<h2>Customer review</h2>';
        render_kv(['Rating' => $review['rating'] . ' / 5', 'Comment' => $review['comment']]);
    }
}
echo '<p class="muted">The manager of ' . e($j['city'] ?: 'the city') . ' and the admin can also approve, reject and complete jobs.</p>';
page_footer();
