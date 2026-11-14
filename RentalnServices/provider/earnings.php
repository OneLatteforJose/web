<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];

// Payments customers made for this provider's own vehicles and services. Driver-only jobs are not counted here,
// because the payment covers the whole booking and nothing records the driver's share.
$from = "FROM payments p
         LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN vehicles v ON v.id = b.vehicle_id
         LEFT JOIN service_requests r ON r.id = p.service_request_id LEFT JOIN services s ON s.id = r.service_id
         WHERE (v.provider_id = ? OR s.provider_id = ?)";
$q = fn(string $extra, array $more = []) => [$extra, array_merge([$me, $me], $more)];

$sum = function (string $status, ?string $start = null, ?string $end = null) use ($pdo, $from, $me): float {
    $sql = "SELECT COALESCE(SUM(p.amount), 0) $from AND p.status = ?";
    $params = [$me, $me, $status];
    if ($start) { $sql .= " AND p.paid_at >= ? AND p.paid_at < ?"; array_push($params, $start, $end); }
    return (float)prov_value($pdo, $sql, $params);
};
$thisStart = date('Y-m-01 00:00:00');
$nextStart = (new DateTimeImmutable($thisStart))->modify('+1 month')->format('Y-m-d H:i:s');
$lastStart = (new DateTimeImmutable($thisStart))->modify('-1 month')->format('Y-m-d H:i:s');
$thisMonth = $sum('paid', $thisStart, $nextStart);
$lastMonth = $sum('paid', $lastStart, $thisStart);
$allTime   = $sum('paid');
$refunded  = $sum('refunded');

// Last six months, newest first, split into vehicles and services.
$sixStart = (new DateTimeImmutable($thisStart))->modify('-5 months')->format('Y-m-d H:i:s');
$st = $pdo->prepare(
    "SELECT DATE_FORMAT(p.paid_at, '%Y-%m') AS ym,
            COALESCE(SUM(CASE WHEN b.id IS NOT NULL THEN p.amount END), 0) AS vehicles,
            COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN p.amount END), 0) AS services
     $from AND p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? GROUP BY ym"
);
$st->execute([$me, $me, $sixStart, $nextStart]);
$byMonth = [];
foreach ($st->fetchAll() as $r) $byMonth[$r['ym']] = $r;
$months = [];
for ($i = 0; $i < 6; $i++) {
    $d = (new DateTimeImmutable($thisStart))->modify("-$i months");
    $m = $byMonth[$d->format('Y-m')] ?? ['vehicles' => 0, 'services' => 0];
    $months[] = ['month' => $d->format('F Y'), 'vehicles' => peso($m['vehicles']), 'services' => peso($m['services']), 'total' => peso($m['vehicles'] + $m['services'])];
}

$pg = paginate((int)prov_value($pdo, "SELECT COUNT(*) $from", [$me, $me]), 20);
$st = $pdo->prepare(
    "SELECT p.id, p.amount, p.method, p.status, p.paid_at, b.id AS bid, r.id AS rid, COALESCE(v.model, s.title) AS item
     $from ORDER BY COALESCE(p.paid_at, '1970-01-01') DESC, p.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}"
);
$st->execute([$me, $me]);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $r['for']    = $r['bid'] ? 'Booking #' . $r['bid'] : 'Request #' . $r['rid'];
    $r['when']   = fmt_when($r['paid_at']);
    $r['amount'] = peso($r['amount']);
    $r['method'] = $r['method'] ?: '-';
    $r['status'] = ucfirst($r['status']);
}
unset($r);

provider_header('Earnings', 'earnings.php');
echo '<div class="stats">'
   . stat_card('chart', peso($thisMonth), 'This month', 'paid by customers')
   . stat_card('chart', peso($lastMonth), 'Last month', 'paid by customers')
   . stat_card('chart', peso($allTime), 'All time', 'paid by customers')
   . stat_card('calendar', peso($refunded), 'Refunded', 'rejected or cancelled jobs')
   . '</div>';
echo '<p class="muted">These are the amounts customers paid online for your own vehicles and services. Any fee between you and the platform is not subtracted. Driver-only jobs are not included.</p>';
echo '<h2>Last six months</h2>';
render_table(['month' => 'Month', 'vehicles' => 'Vehicle rentals', 'services' => 'Services', 'total' => 'Total'], $months);
echo '<h2>Payments</h2>';
render_table(
    ['when' => 'Paid at', 'for' => 'For', 'item' => 'Item', 'method' => 'Method', 'amount' => 'Amount', 'status' => 'Status'],
    $rows,
    ['for' => fn($r) => 'job.php?type=' . ($r['bid'] ? 'booking&id=' . $r['bid'] : 'request&id=' . $r['rid'])]
);
render_pager($pg);
page_footer();
