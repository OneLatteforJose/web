<?php
require_once __DIR__ . '/includes/user.php';
$me = require_user();
$pdo = db();

$tab = $_GET['tab'] ?? '';
$tab = in_array($tab, ['vehicles', 'services'], true) ? $tab : '';
$kind = ['vehicles' => 'booking', 'services' => 'request'][$tab] ?? '';
$per = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$union = "(SELECT 'booking' AS kind, b.id, v.model AS title, b.status, b.start_at AS at, b.total_price, b.created_at
             FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE b.user_id = :u1)
          UNION ALL
          (SELECT 'request' AS kind, r.id, s.title, r.status, r.scheduled_at AS at, r.total_price, r.created_at
             FROM service_requests r JOIN services s ON s.id = r.service_id WHERE r.user_id = :u2)";
$filter = $kind !== '' ? " WHERE x.kind = :k" : '';
$bind = [':u1' => $me['id'], ':u2' => $me['id']] + ($kind !== '' ? [':k' => $kind] : []);

$c = $pdo->prepare("SELECT COUNT(*) FROM ($union) x$filter"); $c->execute($bind);
$total = (int)$c->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $per)));
$st = $pdo->prepare("SELECT x.* FROM ($union) x$filter ORDER BY x.created_at DESC, x.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($bind);
$rows = $st->fetchAll();

user_header('Bookings', 'bookings.php');
?>
<div class="seg">
  <a href="bookings.php" class="<?= $tab === '' ? 'on' : '' ?>">All</a>
  <a href="bookings.php?tab=vehicles" class="<?= $tab === 'vehicles' ? 'on' : '' ?>">Vehicles</a>
  <a href="bookings.php?tab=services" class="<?= $tab === 'services' ? 'on' : '' ?>">Services</a>
</div>
<?php if (!$rows): ?>
  <p class="empty">Nothing here yet. When you book a vehicle or request a service, it shows up here.<br><br><a class="btn sm" href="services.php">Browse services</a></p>
<?php else: ?>
<div class="list">
  <?php foreach ($rows as $r): ?>
    <a class="row" href="order.php?type=<?= e($r['kind']) ?>&amp;id=<?= (int)$r['id'] ?>">
      <span class="ic"><?= uicon($r['kind'] === 'booking' ? 'car' : 'wrench') ?></span>
      <span class="grow"><span class="t"><?= e($r['title']) ?></span><br><span class="d"><?= e(date('M j, Y g:i A', strtotime($r['at']))) ?></span></span>
      <span class="end"><?= status_chip($r['status']) ?><br><strong><?= e(peso($r['total_price'])) ?></strong></span>
    </a>
  <?php endforeach; ?>
</div>
<?php upager($total, $per, $page); endif;
user_footer();
