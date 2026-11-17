<?php
// Xendit sends the customer back here (success and failure redirect). We never trust the redirect itself:
// the payment is checked with Xendit before a booking is created.
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
$me = require_user();
$pdo = db();

$cid = (int)($_GET['c'] ?? 0);
$st = $pdo->prepare("SELECT * FROM service_checkouts WHERE id = ? AND user_id = ?");
$st->execute([$cid, $me['id']]);
$c = $st->fetch();
if (!$c) {
    flash('We could not find that payment.');
    header('Location: bookings.php');
    exit;
}

function go_to_request(int $requestId): void {
    flash('Payment received. Your request is waiting for your area manager to confirm.');
    header('Location: order.php?type=request&id=' . $requestId);
    exit;
}

if ($c['status'] === 'paid' && $c['service_request_id']) {
    go_to_request((int)$c['service_request_id']);
}

$invoice = $c['provider_session_id'] && xendit_enabled() ? xendit_get_invoice($c['provider_session_id']) : null;
if ($invoice && ($requestId = finalize_service_checkout($pdo, $cid, $invoice))) {
    go_to_request($requestId);
}

if (!empty($_GET['cancelled'])) {
    $pdo->prepare("UPDATE service_checkouts SET status = 'cancelled' WHERE id = ? AND status = 'open'")->execute([$cid]);
    flash('Payment cancelled. Nothing was charged.');
    header('Location: service.php?id=' . (int)$c['service_id']);
    exit;
}

// Paid on Xendit's side but not confirmed yet (e.g. GCash still processing), or not paid.
user_header('Payment', 'bookings.php');
?>
<section class="panel">
  <h2>We have not received your payment yet</h2>
  <p>If you just paid, it can take a moment to confirm. Check again in a few seconds. If you were charged and your request still does not appear, contact us with reference <strong>SV-<?= $cid ?></strong>.</p>
  <p><a class="btn sm" href="spay_return.php?c=<?= $cid ?>">Check again</a>
     <a class="btn sm alt" href="service.php?id=<?= (int)$c['service_id'] ?>">Back to the service</a></p>
</section>
<?php user_footer();
