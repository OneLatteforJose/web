<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$err = '';

// A provider can switch their own vehicle between available, unavailable and under maintenance. The manager adds and edits the details.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $to = $_POST['status'] ?? '';
    $st = $pdo->prepare("SELECT id, model, plate_no FROM vehicles WHERE id = ? AND provider_id = ?");
    $st->execute([$id, $me]);
    $v = $st->fetch();
    if (!$v) {
        $err = 'That vehicle was not found among yours.';
    } elseif (!in_array($to, ['available', 'unavailable', 'maintenance'], true)) {
        $err = 'Choose available, unavailable or maintenance.';
    } elseif ($to !== 'available' && prov_value($pdo, "SELECT 1 FROM bookings WHERE vehicle_id = ? AND status IN ('pending','approved') AND end_at > NOW() LIMIT 1", [$id])) {
        $err = $v['model'] . ' is rented or has bookings that have not ended (pending ones need your reply first), so it cannot be taken out of service yet. Ask the manager if a booking has to change.';
    } else {
        $pdo->prepare("UPDATE vehicles SET status = ? WHERE id = ? AND provider_id = ?")->execute([$to, $id, $me]);
        log_action('vehicle.status', 'vehicle', $id, 'Provider set ' . $v['model'] . ' (' . $v['plate_no'] . ') to ' . $to);
        flash($v['model'] . ' is now ' . $to . '.');
        header('Location: vehicles.php');
        exit;
    }
}

$st = $pdo->prepare(
    "SELECT v.id, v.type, v.model, v.plate_no, v.rate_per_day, v.status, a.barangay, ds.title AS own_driver,
            (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id AND b.status IN ('pending','approved') AND b.end_at > NOW()) AS open_jobs,
            (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id AND b.status = 'pending' AND b.end_at > NOW()) AS pending_jobs,
            (SELECT MIN(b.end_at) FROM bookings b WHERE b.vehicle_id = v.id AND b.status = 'approved' AND b.start_at <= NOW() AND b.end_at > NOW()) AS rented_until,
            (SELECT MIN(b.start_at) FROM bookings b WHERE b.vehicle_id = v.id AND b.status = 'approved' AND b.start_at > NOW()) AS next_start,
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS reviews
     FROM vehicles v LEFT JOIN areas a ON a.id = v.area_id LEFT JOIN services ds ON ds.id = v.own_driver_id
     WHERE v.provider_id = ? ORDER BY v.model"
);
$st->execute([$me]);
$rows = $st->fetchAll();

provider_header('Vehicles', 'vehicles.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
echo '<p class="muted">Your vehicles. Switch one to unavailable when it is out of service. A vehicle that is rented or has bookings cannot be switched until the bookings end. The manager of ' . e($p['city'] ?: 'your city') . ' adds new vehicles and edits the details.</p>';
if (!$rows) {
    echo '<p class="note">No vehicles are listed under your name yet. Ask the manager to add them.</p>';
} else { ?>
<style>
  .content{max-width:none;padding-right:1.5rem}   /* stretch to the right edge, in line with the profile menu above */
  .vtable{table-layout:auto}
  .vtable th,.vtable td{white-space:normal;padding:.65rem .55rem;vertical-align:middle}
  .vtable td.act{white-space:nowrap;text-align:right}
  .vtable td.act form{display:inline-flex;gap:.35rem;flex-wrap:nowrap;margin:0}
  .vtable td.act button{padding:.4rem .7rem;font-size:.85rem;white-space:nowrap}
</style>
<div class="scroll" style="overflow:visible"><table class="vtable">
  <thead><tr><th>Vehicle</th><th>Plate</th><th>Barangay</th><th>Rate / day</th><th>Own driver</th><th>Rating</th><th>Bookings</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    $on = !in_array($r['status'], ['unavailable', 'maintenance'], true);
    $locked = $on && (int)$r['open_jobs'] > 0;   // rented or booked: it cannot be taken out of service until the bookings end
    if ($r['rented_until'])      { $why = 'Rented until ' . fmt_when($r['rented_until']); }
    elseif ($r['next_start'])    { $why = 'Next booking starts ' . fmt_when($r['next_start']); }
    elseif ($r['pending_jobs'])  { $why = plural((int)$r['pending_jobs'], 'booking') . ' waiting for approval'; }
    else                         { $why = ''; }
?>
    <tr>
      <td><?= e(ucfirst($r['type']) . ': ' . $r['model']) ?></td>
      <td><?= e($r['plate_no']) ?></td>
      <td><?= e($r['barangay'] ?: '-') ?></td>
      <td><?= e(peso($r['rate_per_day'])) ?></td>
      <td><?= e($r['own_driver'] ?: '-') ?></td>
      <td><?= $r['reviews'] ? e($r['rating'] . ' (' . $r['reviews'] . ')') : '-' ?></td>
      <td><?= (int)$r['open_jobs'] ?></td>
      <td><?php if ($on && $r['rented_until']): ?><span class="badge badge-booked">Rented now</span><?php else: ?><span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status'] === 'maintenance' ? 'Under maintenance' : ucfirst($r['status'])) ?></span><?php endif; ?>
        <?php if ($locked && $why): ?><br><span class="muted" style="font-size:.85rem"><?= e($why) ?></span><?php endif; ?></td>
      <td class="act">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php if ($locked): ?>
            <button class="alt" type="button" disabled title="This vehicle is rented or booked, so it cannot be taken out of service until the bookings end.">Unavailable</button>
            <button class="alt" type="button" disabled title="This vehicle is rented or booked, so it cannot be taken out of service until the bookings end.">Maintenance</button>
          <?php elseif ($on): ?>
            <button class="alt" name="status" value="unavailable" onclick="return confirm('Mark this vehicle unavailable? Customers will not be able to book it.')">Unavailable</button>
            <button class="alt" name="status" value="maintenance" onclick="return confirm('Put this vehicle under maintenance? Customers will see a maintenance notice and cannot book it.')">Maintenance</button>
          <?php else: ?>
            <button class="alt" name="status" value="available">Mark available</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php }
page_footer();
