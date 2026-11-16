<?php
require_once __DIR__ . '/../includes/provider.php';
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
<div class="stats">
  <?= stat_card('calendar', ucfirst($j['status']), 'Status', $j['status'] === 'pending' ? 'waiting for a manager' : '') ?>
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
if ($mine) {
    echo '<h2>Payments</h2>';
    render_table(['id' => '#', 'amount' => 'Amount', 'method' => 'Method', 'status' => 'Status', 'paid_at' => 'Paid at'], $payments);
    if ($review) {
        echo '<h2>Customer review</h2>';
        render_kv(['Rating' => $review['rating'] . ' / 5', 'Comment' => $review['comment']]);
    }
}
echo '<p class="muted">The manager of ' . e($j['city'] ?: 'the city') . ' approves, rejects and completes jobs. If something looks wrong, contact them.</p>';
page_footer();
