<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$jobs = provider_jobs_sql();
$jp = provider_job_params($me);

$count = fn(string $where) => (int)prov_value($pdo, "SELECT COUNT(*) FROM ($jobs) j WHERE $where", $jp);
$pending = $count("j.status = 'pending'");
$soon = $count("j.status = 'approved' AND j.at >= NOW() AND j.at < NOW() + INTERVAL 7 DAY");

[$vTotal, $vAvail] = array_map('intval', $pdo->query("SELECT COUNT(*), COALESCE(SUM(status = 'available'), 0) FROM vehicles WHERE provider_id = $me")->fetch(PDO::FETCH_NUM));
[$sTotal, $sAvail] = array_map('intval', $pdo->query("SELECT COUNT(*), COALESCE(SUM(status = 'available'), 0) FROM services WHERE provider_id = $me")->fetch(PDO::FETCH_NUM));

$monthStart = date('Y-m-01 00:00:00');
$monthEnd = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d H:i:s');
$paid = (float)prov_value($pdo,
    "SELECT COALESCE(SUM(p.amount), 0) FROM payments p
     LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN vehicles v ON v.id = b.vehicle_id
     LEFT JOIN service_requests r ON r.id = p.service_request_id LEFT JOIN services s ON s.id = r.service_id
     WHERE p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? AND (v.provider_id = ? OR s.provider_id = ?)",
    [$monthStart, $monthEnd, $me, $me]);

$st = $pdo->prepare(
    "SELECT ROUND(AVG(x.rating), 1) AS avg, COUNT(*) AS c FROM (
        SELECT rv.rating FROM reviews rv JOIN bookings b ON b.id = rv.booking_id JOIN vehicles v ON v.id = b.vehicle_id WHERE v.provider_id = ?
        UNION ALL
        SELECT rv.rating FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id JOIN services s ON s.id = r.service_id WHERE s.provider_id = ?) x"
);
$st->execute([$me, $me]);
$rate = $st->fetch();

$st = $pdo->prepare("SELECT j.* FROM ($jobs) j WHERE j.status = 'approved' AND j.at >= NOW() ORDER BY j.at LIMIT 6");
$st->execute($jp);
$coming = $st->fetchAll();

$st = $pdo->prepare(
    "(SELECT rv.id, rv.rating, rv.comment, rv.created_at, u.full_name, v.model AS item FROM reviews rv JOIN users u ON u.id = rv.user_id
        JOIN bookings b ON b.id = rv.booking_id JOIN vehicles v ON v.id = b.vehicle_id WHERE v.provider_id = ?)
     UNION ALL
     (SELECT rv.id, rv.rating, rv.comment, rv.created_at, u.full_name, s.title FROM reviews rv JOIN users u ON u.id = rv.user_id
        JOIN service_requests r ON r.id = rv.service_request_id JOIN services s ON s.id = r.service_id WHERE s.provider_id = ?)
     ORDER BY created_at DESC, id DESC LIMIT 5"
);
$st->execute([$me, $me]);
$reviews = $st->fetchAll();

$kind = ['booking' => ['calendar', 'Vehicle booking', 'booking'], 'request' => ['wrench', 'Service request', 'request']];

provider_header('Dashboard', 'dashboard.php');
if ($vTotal + $sTotal === 0) {
    echo '<p class="note">You have no vehicles or services listed yet. You can propose a service on the <a href="services.php">Services</a> page, and the manager of ' . e($p['city'] ?: 'your city') . ' or an admin approves it. The manager adds vehicles for you.</p>';
}
[$subWaiting, $subRejected] = provider_submission_counts($pdo, $me);
if ($subWaiting + $subRejected > 0) {
    echo '<p class="note">' . ($subWaiting ? '<b>' . plural($subWaiting, 'service') . '</b> waiting for approval. ' : '')
       . ($subRejected ? '<b>' . plural($subRejected, 'submission') . '</b> not approved: see the reason on the <a href="services.php">Services</a> page.' : '') . '</p>';
}
echo '<div class="stats">'
   . stat_card('calendar', $soon, 'Coming up', 'approved, next 7 days', false, 'jobs.php?status=approved')
   . stat_card('calendar', $pending, 'Waiting for approval', 'a manager decides these', $pending > 0, 'jobs.php?status=pending')
   . stat_card('car', $vAvail, 'Vehicles available', 'of ' . plural($vTotal, 'vehicle'), false, 'vehicles.php')
   . stat_card('wrench', $sAvail, 'Services available', 'of ' . plural($sTotal, 'service'), false, 'services.php')
   . stat_card('chart', peso($paid), 'Payments this month', 'paid by customers', false, 'earnings.php')
   . stat_card('user', $rate['c'] ? $rate['avg'] . ' / 5' : 'No ratings', 'Average rating', $rate['c'] ? plural((int)$rate['c'], 'review') : 'customers rate completed jobs', false)
   . '</div>';
?>
<div class="two">
  <section class="panel">
    <div class="panel-head"><h3>Coming up</h3><a class="viewall" href="jobs.php?status=approved">All jobs &rarr;</a></div>
    <div class="feed">
      <?php if (!$coming) echo '<p class="muted">Nothing is scheduled yet.</p>'; ?>
      <?php foreach ($coming as $j): [$ic, $label, $t] = $kind[$j['kind']]; ?>
        <a class="item" href="job.php?type=<?= $t ?>&amp;id=<?= (int)$j['id'] ?>"><span class="ic"><?= icon($ic) ?></span>
          <span><span class="t"><?= e($label) ?> #<?= (int)$j['id'] ?></span><br><span class="d"><?= e($j['title'] . ' - ' . provider_customer($j)) ?></span></span>
          <time><?= e(date('M j, g:i A', strtotime($j['at']))) ?></time></a>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="panel">
    <div class="panel-head"><h3>Latest reviews</h3></div>
    <div class="feed">
      <?php if (!$reviews) echo '<p class="muted">No reviews yet.</p>'; ?>
      <?php foreach ($reviews as $r): ?>
        <div class="item"><span class="ic"><?= icon('user') ?></span>
          <span><span class="t"><?= e(str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating'])) ?> <?= e($r['item']) ?></span><br>
            <span class="d"><?= e(trim(explode(' ', $r['full_name'])[0]) . ($r['comment'] ? ': ' . $r['comment'] : '')) ?></span></span>
          <time><?= e(substr($r['created_at'], 0, 10)) ?></time></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php page_footer();
