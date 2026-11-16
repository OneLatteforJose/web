<?php
// One of the customer's own bookings (type=booking) or service requests (type=request).
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
$me = require_user();
$pdo = db();

$type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'request' ? 'request' : 'booking';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$self = 'order.php?type=' . $type . '&id=' . $id;

$load = function () use ($pdo, $type, $id, $me) {
    if ($type === 'booking') {
        $sql = "SELECT b.*, v.model AS title, v.type AS vtype, v.plate_no, p.full_name AS provider, p.phone AS provider_phone,
                       dp.full_name AS driver_name, a.city_municipality AS city
                FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users p ON p.id = v.provider_id
                LEFT JOIN services ds ON ds.id = b.driver_service_id LEFT JOIN users dp ON dp.id = ds.provider_id
                LEFT JOIN areas a ON a.id = b.area_id WHERE b.id = ? AND b.user_id = ?";
    } else {
        $sql = "SELECT r.*, s.title, s.category, p.full_name AS provider, p.phone AS provider_phone, a.barangay, a.city_municipality AS city
                FROM service_requests r JOIN services s ON s.id = r.service_id JOIN users p ON p.id = s.provider_id
                LEFT JOIN areas a ON a.id = r.area_id WHERE r.id = ? AND r.user_id = ?";
    }
    $st = $pdo->prepare($sql);
    $st->execute([$id, $me['id']]);
    return $st->fetch();
};
$o = $load();
if (!$o) {
    flash('We could not find that booking.');
    header('Location: bookings.php');
    exit;
}
$table = $type === 'booking' ? 'bookings' : 'service_requests';
$payCol = $type === 'booking' ? 'booking_id' : 'service_request_id';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE $table SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
            $upd->execute([$id, $me['id']]);
            if ($upd->rowCount() === 1) {
                $pdo->commit();
                $refunded = true;
                if ($type === 'booking') {
                    $refunded = refund_booking_payments($pdo, $id);   // real refund through Xendit
                } else {
                    $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE $payCol = ? AND status = 'paid'")->execute([$id]);
                }
                flash($refunded ? 'Cancelled. Any online payment has been refunded.' : 'Cancelled, but we could not send your refund automatically. We will process it and contact you.');
                header('Location: ' . $self);
                exit;
            }
            $pdo->rollBack();
            $err = 'Only a booking that is still waiting for approval can be cancelled.';
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'Could not cancel. Please try again.';
        }
    }

    if ($action === 'review') {
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '') ?: null;
        if ($o['status'] !== 'completed') {
            $err = 'You can review it once it is completed.';
        } elseif ($rating < 1 || $rating > 5) {
            $err = 'Choose a rating from 1 to 5.';
        } elseif ($comment !== null && strlen($comment) > 1000) {
            $err = 'Your comment is too long (1000 characters at most).';
        } else {
            try {
                $pdo->prepare("INSERT INTO reviews (user_id, " . ($type === 'booking' ? 'booking_id' : 'service_request_id') . ", rating, comment) VALUES (?,?,?,?)")
                    ->execute([$me['id'], $id, $rating, $comment]);
                flash('Thank you for your review.');
                header('Location: ' . $self);
                exit;
            } catch (PDOException $ex) {
                $err = $ex->getCode() === '23000' ? 'You already reviewed this.' : 'Could not save your review.';
            }
        }
    }
    $o = $load() ?: $o;
}

$pay = $pdo->prepare("SELECT amount, method, status FROM payments WHERE $payCol = ? ORDER BY id");
$pay->execute([$id]);
$payments = $pay->fetchAll();
$rev = $pdo->prepare("SELECT rating, comment FROM reviews WHERE " . ($type === 'booking' ? 'booking_id' : 'service_request_id') . " = ?");
$rev->execute([$id]);
$review = $rev->fetch();

$messages = [
    'pending'   => 'Waiting for your area manager to confirm.',
    'approved'  => 'Confirmed by your area manager.',
    'completed' => 'Done. We hope it went well.',
    'rejected'  => 'The manager could not accept this.',
    'cancelled' => 'You cancelled this.',
];
if ($o['status'] === 'rejected' && array_filter($payments, fn($p) => $p['status'] === 'refunded')) {
    $messages['rejected'] .= ' Your online payment was refunded.';
}
$when = $type === 'booking' ? date('D, M j, Y g:i A', strtotime($o['start_at'])) . ' to ' . date('D, M j, Y g:i A', strtotime($o['end_at'])) : date('D, M j, Y g:i A', strtotime($o['scheduled_at']));

user_header($o['title'], 'bookings.php', ['back' => 'bookings.php']);
if ($err) echo '<p class="alert">' . e($err) . '</p>';
?>
<div class="cols order">
<section class="panel wide">
  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem"><?= status_chip($o['status']) ?><span class="muted"><?= $type === 'booking' ? 'Vehicle booking' : 'Service request' ?> #<?= $id ?></span></div>
  <p style="margin:.2rem 0 0"><?= e($messages[$o['status']] ?? '') ?></p>
</section>

<section class="panel main">
  <h2><?= $type === 'booking' ? 'Trip' : 'Service' ?></h2>
  <dl class="kv">
    <?php if ($type === 'booking'): ?>
      <dt>Vehicle</dt><dd><?= e(ucfirst($o['vtype']) . ': ' . $o['title'] . ' (' . $o['plate_no'] . ')') ?></dd>
      <dt>When</dt><dd><?= e($when) ?></dd>
      <dt>Pickup</dt><dd><?= e($o['pickup_street'] ?: '-') ?></dd>
      <dt>Driver</dt><dd><?= $o['with_driver'] ? e($o['driver_name'] ?: $o['provider'] . ' (the vehicle owner)' . ($o['status'] === 'pending' ? ', unless the manager assigns another driver' : '')) : 'Drive yourself' ?></dd>
    <?php else: ?>
      <dt>Service</dt><dd><?= e($o['title'] . ' (' . category_label($o['category']) . ')') ?></dd>
      <dt>When</dt><dd><?= e($when) ?></dd>
      <dt>Where</dt><dd><?= e(implode(', ', array_filter([$o['service_street'], $o['barangay'], $o['city']]))) ?></dd>
    <?php endif; ?>
    <dt>Provider</dt><dd><?= e($o['provider']) ?><?= $o['provider_phone'] ? ' (' . e($o['provider_phone']) . ')' : '' ?></dd>
    <dt>Total</dt><dd><strong><?= e(peso($o['total_price'])) ?></strong></dd>
  </dl>
</section>

<div class="side">
<?php if ($payments): ?>
<section class="panel">
  <h2>Payment</h2>
  <dl class="kv">
    <?php foreach ($payments as $p): ?><dt><?= e($p['method'] ?: 'Online') ?></dt><dd><?= e(peso($p['amount'])) ?>, <?= e($p['status']) ?></dd><?php endforeach; ?>
  </dl>
</section>
<?php endif; ?>

<?php if ($o['status'] === 'pending'): ?>
<form method="post" onsubmit="return confirm('Cancel this? Any online payment will be refunded.')">
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= $id ?>">
  <button class="btn danger" name="action" value="cancel">Cancel</button>
</form>
<?php elseif ($o['status'] === 'completed'): ?>
  <?php if ($review): ?>
    <section class="panel"><h2>Your review</h2><?= stars((float)$review['rating'], 1) ?><?php if ($review['comment']): ?><p style="margin:.4rem 0 0"><?= e($review['comment']) ?></p><?php endif; ?></section>
  <?php else: ?>
    <section class="panel">
      <h2>How was it?</h2>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= $id ?>">
        <label>Rating
          <select name="rating" required>
            <option value="">Choose</option>
            <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> <?= $i === 1 ? 'star' : 'stars' ?></option><?php endfor; ?>
          </select>
        </label>
        <label>Comment (optional) <textarea name="comment" rows="3" maxlength="1000"></textarea></label>
        <button class="btn" name="action" value="review">Send review</button>
      </form>
    </section>
  <?php endif; ?>
<?php endif; ?>
</div>
</div>
<?php user_footer();
