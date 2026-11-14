<?php
require_once __DIR__ . '/../includes/provider.php';
$p = require_provider();
$pdo = db();
$me = (int)$p['id'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $to = $_POST['status'] ?? '';
    $st = $pdo->prepare("SELECT id, title FROM services WHERE id = ? AND provider_id = ?");
    $st->execute([$id, $me]);
    $s = $st->fetch();
    if (!$s) {
        $err = 'That service was not found among yours.';
    } elseif (!in_array($to, ['available', 'unavailable'], true)) {
        $err = 'Choose available or unavailable.';
    } elseif ($to === 'unavailable' && (prov_value($pdo, "SELECT 1 FROM service_requests WHERE service_id = ? AND status IN ('pending','approved') AND scheduled_at > NOW() LIMIT 1", [$id])
            || prov_value($pdo, "SELECT 1 FROM bookings WHERE driver_service_id = ? AND status IN ('pending','approved') AND end_at > NOW() LIMIT 1", [$id]))) {
        $err = $s['title'] . ' still has pending or upcoming approved jobs, so it cannot be marked unavailable yet. Ask the manager if a job has to change.';
    } else {
        $pdo->prepare("UPDATE services SET status = ? WHERE id = ? AND provider_id = ?")->execute([$to, $id, $me]);
        log_action('service.status', 'service', $id, 'Provider set ' . $s['title'] . ' to ' . $to);
        flash($s['title'] . ' is now ' . $to . '.');
        header('Location: services.php');
        exit;
    }
}

$cats = service_categories();
$st = $pdo->prepare(
    "SELECT s.id, s.category, s.title, s.rate, s.status, a.barangay,
            (SELECT COUNT(*) FROM service_requests r WHERE r.service_id = s.id AND r.status IN ('pending','approved') AND r.scheduled_at > NOW())
          + (SELECT COUNT(*) FROM bookings b WHERE b.driver_service_id = s.id AND b.status IN ('pending','approved') AND b.end_at > NOW()) AS open_jobs,
            (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS rating,
            (SELECT COUNT(*) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS reviews
     FROM services s LEFT JOIN areas a ON a.id = s.area_id WHERE s.provider_id = ? ORDER BY s.title"
);
$st->execute([$me]);
$rows = $st->fetchAll();

provider_header('Services', 'services.php');
if ($err) echo '<p class="err">' . e($err) . '</p>';
echo '<p class="muted">The services you offer. Switch one to unavailable when you cannot take new requests. The manager of ' . e($p['city'] ?: 'your city') . ' adds new services and edits the details.</p>';
if (!$rows) {
    echo '<p class="note">No services are listed under your name yet. Ask the manager to add them.</p>';
} else { ?>
<div class="scroll"><table>
  <thead><tr><th>Service</th><th>Category</th><th>Barangay</th><th>Rate</th><th>Rating</th><th>Open jobs</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $on = $r['status'] !== 'unavailable'; ?>
    <tr>
      <td><?= e($r['title']) ?></td>
      <td><?= e($cats[$r['category']][0] ?? ucfirst(str_replace('_', ' ', $r['category']))) ?></td>
      <td><?= e($r['barangay'] ?: '-') ?></td>
      <td><?= e(peso($r['rate'])) ?></td>
      <td><?= $r['reviews'] ? e($r['rating'] . ' (' . $r['reviews'] . ')') : '-' ?></td>
      <td><?= (int)$r['open_jobs'] ?></td>
      <td><span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
      <td>
        <form method="post" style="margin:0" <?= $on ? 'onsubmit="return confirm(\'Mark this service unavailable? Customers will not be able to request it.\')"' : '' ?>>
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
