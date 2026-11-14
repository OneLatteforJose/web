<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$err = '';

// A provider can switch their own vehicle between available and unavailable. The manager adds and edits the details.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $to = $_POST['status'] ?? '';
    $st = $pdo->prepare("SELECT id, model, plate_no FROM vehicles WHERE id = ? AND provider_id = ?");
    $st->execute([$id, $me]);
    $v = $st->fetch();
    if (!$v) {
        $err = 'That vehicle was not found among yours.';
    } elseif (!in_array($to, ['available', 'unavailable'], true)) {
        $err = 'Choose available or unavailable.';
    } elseif ($to === 'unavailable' && prov_value($pdo, "SELECT 1 FROM bookings WHERE vehicle_id = ? AND status IN ('pending','approved') AND end_at > NOW() LIMIT 1", [$id])) {
        $err = $v['model'] . ' still has pending or upcoming approved bookings, so it cannot be marked unavailable yet. Ask the manager if a booking has to change.';
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
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id WHERE b.vehicle_id = v.id) AS reviews
     FROM vehicles v LEFT JOIN areas a ON a.id = v.area_id LEFT JOIN services ds ON ds.id = v.own_driver_id
     WHERE v.provider_id = ? ORDER BY v.model"
);
$st->execute([$me]);
$rows = $st->fetchAll();

provider_header('Vehicles', 'vehicles.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
echo '<p class="muted">Your vehicles. Switch one to unavailable when it is out of service. The manager of ' . e($p['city'] ?: 'your city') . ' adds new vehicles and edits the details.</p>';
if (!$rows) {
    echo '<p class="note">No vehicles are listed under your name yet. Ask the manager to add them.</p>';
} else { ?>
<div class="scroll"><table>
  <thead><tr><th>Vehicle</th><th>Plate</th><th>Barangay</th><th>Rate / day</th><th>Own driver</th><th>Rating</th><th>Open bookings</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $on = $r['status'] !== 'unavailable'; ?>
    <tr>
      <td><?= e(ucfirst($r['type']) . ': ' . $r['model']) ?></td>
      <td><?= e($r['plate_no']) ?></td>
      <td><?= e($r['barangay'] ?: '-') ?></td>
      <td><?= e(peso($r['rate_per_day'])) ?></td>
      <td><?= e($r['own_driver'] ?: '-') ?></td>
      <td><?= $r['reviews'] ? e($r['rating'] . ' (' . $r['reviews'] . ')') : '-' ?></td>
      <td><?= (int)$r['open_jobs'] ?></td>
      <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
      <td>
        <form method="post" style="margin:0" <?= $on ? 'onsubmit="return confirm(\'Mark this vehicle unavailable? Customers will not be able to book it.\')"' : '' ?>>
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $on ? 'unavailable' : 'available' ?>">
          <button class="alt"><?= $on ? 'Mark unavailable' : 'Mark available' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php }
page_footer();
