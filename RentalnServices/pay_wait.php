<?php
// Used when Xendit cannot send the customer back to this site (for example on http://localhost).
// The customer pays on Xendit's page in a new tab; this page asks Xendit every few seconds and continues by itself once it is paid.
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
$me = require_user();
$pdo = db();

$cid = (int)($_GET['c'] ?? 0);
$st = $pdo->prepare("SELECT * FROM checkouts WHERE id = ? AND user_id = ?");
$st->execute([$cid, $me['id']]);
$c = $st->fetch();
if (!$c || !$c['provider_session_id']) {
    flash('We could not find that payment.');
    header('Location: bookings.php');
    exit;
}
if ($c['status'] === 'paid' && $c['booking_id']) {
    header('Location: pay_return.php?c=' . $cid);
    exit;
}
$invoice = xendit_enabled() ? xendit_get_invoice($c['provider_session_id']) : null;
$payUrl = $invoice['invoice_url'] ?? '';

user_header('Complete your payment', 'vehicles.php');
?>
<section class="panel" style="max-width:560px">
  <h2>Pay on Xendit, then come back here</h2>
  <p>Total: <strong><?= e(peso($c['total_price'])) ?></strong>. Open the payment page, pay with GCash, Maya or a card, and keep this page open. It continues by itself as soon as the payment is confirmed.</p>
  <?php if ($payUrl): ?>
    <p><a class="btn" id="open" href="<?= e($payUrl) ?>" target="_blank" rel="noopener">Open payment page</a></p>
  <?php else: ?>
    <p class="alert">We could not load the payment page. Go back and try again.</p>
  <?php endif; ?>
  <p class="hint" id="state">Waiting for your payment...</p>
  <p><a class="btn sm alt" href="pay_return.php?c=<?= $cid ?>&amp;cancelled=1">Cancel and go back</a></p>
</section>
<script>
(function () {
  var state = document.getElementById('state'), tries = 0;
  function check() {
    fetch('pay_status.php?c=<?= $cid ?>', {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.state === 'paid') { state.textContent = 'Payment received. Taking you to your booking...'; location.href = 'pay_return.php?c=<?= $cid ?>'; return; }
        if (j.state === 'expired') { state.textContent = 'This payment page has expired. Nothing was charged. Please go back and book again.'; return; }
        schedule();
      })
      .catch(schedule);
  }
  function schedule() { if (++tries < 900) setTimeout(check, 3000); }
  var open = document.getElementById('open');
  if (open) open.addEventListener('click', function () { state.textContent = 'Waiting for your payment...'; });
  schedule();
})();
</script>
<?php user_footer();
