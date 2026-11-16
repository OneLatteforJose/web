<?php
// Xendit sends the customer back here (success and failure redirect). We never trust the redirect itself:
// the payment is checked with Xendit before a booking is created.
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
$me = require_user();
$pdo = db();

$cid = (int)($_GET['c'] ?? 0);
$st = $pdo->prepare("SELECT * FROM checkouts WHERE id = ? AND user_id = ?");
$st->execute([$cid, $me['id']]);
$c = $st->fetch();
if (!$c) {
    flash('We could not find that payment.');
    header('Location: bookings.php');
    exit;
}

function go_to_booking(int $bookingId): void {
    flash('Payment received. Your booking is waiting for your area manager to confirm.');
    header('Location: order.php?type=booking&id=' . $bookingId);
    exit;
}

if ($c['status'] === 'paid' && $c['booking_id']) {
    go_to_booking((int)$c['booking_id']);
}

$invoice = $c['provider_session_id'] && xendit_enabled() ? xendit_get_invoice($c['provider_session_id']) : null;
if ($invoice && ($bookingId = finalize_checkout($pdo, $cid, $invoice))) {
    go_to_booking($bookingId);
}

if (!empty($_GET['cancelled'])) {
    $pdo->prepare("UPDATE checkouts SET status = 'cancelled' WHERE id = ? AND status = 'open'")->execute([$cid]);
    flash('Payment cancelled. Nothing was charged.');
    header('Location: vehicle.php?id=' . (int)$c['vehicle_id']);
    exit;
}

// Paid on Xendit's side but not confirmed yet (e.g. GCash still processing), or not paid.
user_header('Payment', 'bookings.php');
?>
<section class="panel">
  <h2>We have not received your payment yet</h2>
  <p>If you just paid, it can take a moment to confirm. Check again in a few seconds. If you were charged and your booking still does not appear, contact us with reference <strong>RS-<?= $cid ?></strong>.</p>
  <p><a class="btn sm" href="pay_return.php?c=<?= $cid ?>">Check again</a>
     <a class="btn sm alt" href="vehicle.php?id=<?= (int)$c['vehicle_id'] ?>">Back to the vehicle</a></p>
</section>
<?php user_footer();
