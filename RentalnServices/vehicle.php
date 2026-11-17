<?php
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/booking_rules.php';
$me = require_user();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT v.*, p.full_name AS provider, pp.business_name, a.barangay, a.city_municipality AS city,
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS reviews
     FROM vehicles v JOIN users p ON p.id = v.provider_id AND p.is_active = 1 LEFT JOIN provider_profiles pp ON pp.user_id = p.id JOIN areas a ON a.id = v.area_id
     WHERE v.id = ?"
);
$st->execute([$id]);
$v = $st->fetch();
if (!$v) {
    flash('That vehicle is no longer listed.');
    header('Location: vehicles.php');
    exit;
}
$st = $pdo->prepare(
    "SELECT rv.rating, rv.comment, rv.created_at, u.full_name FROM reviews rv
     JOIN bookings b ON b.id = rv.booking_id JOIN users u ON u.id = rv.user_id WHERE b.vehicle_id = ? ORDER BY rv.id DESC LIMIT 5"
);
$st->execute([$id]);
$reviews = $st->fetchAll();
$state = vehicle_states($pdo, [$id])[$id] ?? null;
$av = vehicle_availability($v, $state);
// Approved trips still to come: the vehicle can be booked for other dates, so just tell the customer.
$ub = $pdo->prepare("SELECT start_at, end_at FROM bookings WHERE vehicle_id = ? AND status = 'approved' AND end_at > ? ORDER BY start_at LIMIT 5");
$ub->execute([$id, date('Y-m-d H:i:s')]);
$booked = $ub->fetchAll();

user_header($v['model'], 'vehicles.php', ['back' => 'vehicles.php']);
?>
<?php if ($av['code'] !== 'available'): ?>
<p class="alert" role="status"><strong><?= e($av['label']) ?>.</strong> <?= e($av['note']) ?></p>
<?php endif; ?>
<div class="cols">
<section class="panel main">
  <div style="display:flex;gap:.8rem;align-items:center;margin-bottom:.7rem">
    <span class="ic"><?= uicon('car') ?></span>
    <div><strong><?= e(ucfirst($v['type'])) ?></strong><br><?= stars($v['rating'] !== null ? (float)$v['rating'] : null, (int)$v['reviews']) ?></div>
    <div style="margin-left:auto;text-align:right"><span class="price-big"><?= e(peso($v['rate_per_day'])) ?></span><br><span class="muted">per day</span></div>
  </div>
  <dl class="kv">
    <dt>Capacity</dt><dd><?= e($v['capacity'] ?: '-') ?></dd>
    <dt>Driver</dt><dd><?= $v['own_driver_id'] ? 'A driver is available with this vehicle' : 'Drive it yourself, or ask for a driver when booking (the vehicle owner drives)' ?></dd>
    <dt>Provider</dt><dd><?= e($v['business_name'] ?: $v['provider']) ?></dd>
    <dt>Based in</dt><dd><?= e($v['barangay'] . ', ' . $v['city']) ?></dd>
    <dt>Availability</dt><dd><?= $av['code'] === 'available' ? 'Available, pick your dates when booking' : e($av['label']) ?></dd>
    <?php if ($booked): ?><dt>Already booked</dt><dd><?php foreach ($booked as $b): ?><?= e(date('M j, g:i A', strtotime($b['start_at']))) ?> to <?= e(date('M j, g:i A', strtotime($b['end_at']))) ?><br><?php endforeach; ?></dd><?php endif; ?>
  </dl>
</section>
<div class="side">
<p class="hint" style="margin-top:0">Vehicles can be rented from any city. The manager of the city where the vehicle is based confirms the booking.</p>
<?php if ($av['code'] !== 'available'): ?>
<button class="btn" disabled><?= $av['code'] === 'maintenance' ? 'Under maintenance' : ($av['code'] === 'rented' ? 'Already rented' : 'Not available') ?></button>
<?php else: ?>
<a class="btn" href="book.php?id=<?= (int)$v['id'] ?>">Book now</a>
<p class="hint">Pay online with GCash, Maya or a card. You are refunded in full if the manager cannot accept the booking.</p>
<p class="hint">Pick-up is available <?= BOOKING_LEAD_HOURS ?> hours after you book: <?= e(lead_text()) ?>.</p>
<?php endif; ?>
</div>

<div class="more">
<div class="sec"><h2>Reviews</h2></div>
<?php if (!$reviews): ?><p class="empty">No reviews yet.</p><?php else: ?>
<div class="panel">
  <?php foreach ($reviews as $r): ?>
    <div class="reviewer"><strong><?= e(short_name($r['full_name'])) ?></strong> <span class="rate"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.8 6.3.7-4.7 4.3 1.3 6.2L12 16.9 6.4 20l1.3-6.2L3 9.5l6.3-.7z"/></svg><?= (int)$r['rating'] ?></span>
      <span class="muted"> <?= e(substr($r['created_at'], 0, 10)) ?></span><?php if ($r['comment']): ?><br><?= e($r['comment']) ?><?php endif; ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
<?php user_footer();
