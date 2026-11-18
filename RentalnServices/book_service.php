<?php
// Book a service in three steps: 1) when and where, 2) review and confirm, 3) pay online.
// Visitors who are not logged in can do steps 1 and 2; they log in or sign up before paying (their choices are kept in the session).
// Nothing is saved as a request until Xendit confirms the payment (see spay_return.php); then the manager / provider approves it.
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
require_once __DIR__ . '/includes/booking_rules.php';
$me = browse_user();
$guest = !empty($me['guest']);
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT s.*, p.full_name AS provider, pp.business_name, a.barangay, a.city_municipality AS city, a.province
     FROM services s JOIN users p ON p.id = s.provider_id AND p.is_active = 1
     LEFT JOIN provider_profiles pp ON pp.user_id = p.id JOIN areas a ON a.id = s.area_id WHERE s.id = ?"
);
$st->execute([$id]);
$s = $st->fetch();
if (!$s || $s['status'] !== 'available') {
    flash('That service is not available right now.');
    header('Location: services.php');
    exit;
}
if (!$guest && !$me['area_id']) {
    flash('Add your address first. The provider needs to know where to come.');
    header('Location: profile.php');
    exit;
}
if (!$guest && ($me['province'] !== $s['province'] || $me['city'] !== $s['city'])) {
    flash('This service is only offered in ' . $s['city'] . '. Services can be requested in your own city.');
    header('Location: service.php?id=' . $id);
    exit;
}

$err = '';
$step = 'form';   // form | review
$in = ['when' => '', 'street' => (string)($me['street'] ?? '')];
$resumeUrl = 'book_service.php?id=' . $id . '&resume=1';

$validate = function (array $in) use ($s): array {
    $when = DateTime::createFromFormat('Y-m-d\TH:i', $in['when']);
    $earliest = earliest_pickup();
    $total = round((float)$s['rate'], 2);
    if (!$when) return [null, 'Choose when you want the service.'];
    if ($when < $earliest) return [null, 'A service can be booked ' . BOOKING_LEAD_HOURS . ' hours ahead or more. The earliest time right now is ' . $earliest->format('M j, Y g:i A') . '.'];
    if (strlen($in['street']) > 255) return [null, 'The address is too long.'];
    if ($total < 20) return [null, 'The price is below the minimum online payment (' . peso(20) . ').'];
    return [['whenDt' => $when, 'when' => $when->format('Y-m-d H:i:s'), 'total' => $total], null];
};
$calc = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'review';
    $in = ['when' => trim($_POST['when'] ?? ''), 'street' => trim($_POST['street'] ?? '')];
    if ($action !== 'edit') {
        [$calc, $err] = $validate($in);
        if ($calc) {
            $_SESSION['drafts']['s' . $id] = $in;   // kept while the visitor logs in or signs up
            $step = 'review';
            if ($action === 'pay') {
                if ($guest) {
                    $err = 'Log in or sign up to pay.';
                } elseif (!xendit_enabled()) {
                    $err = 'Online payment is not set up yet. Please try again later.';
                } else {
                    $now = new DateTime();
                    $pdo->prepare("UPDATE service_checkouts SET status = 'cancelled' WHERE user_id = ? AND service_id = ? AND status = 'open'")->execute([$me['id'], $id]);
                    $pdo->prepare(
                        "INSERT INTO service_checkouts (user_id, service_id, area_id, service_street, scheduled_at, total_price, expires_at)
                         VALUES (?,?,?,?,?,?,?)"
                    )->execute([$me['id'], $id, $me['area_id'], $in['street'] ?: null, $calc['when'], $calc['total'], (clone $now)->modify('+45 minutes')->format('Y-m-d H:i:s')]);
                    $cid = (int)$pdo->lastInsertId();
                    $session = xendit_create_invoice($cid, $calc['total'], 'Service: ' . $s['title'], $s['title'] . ', ' . $calc['whenDt']->format('M j, Y g:i A'), (string)$me['full_name'], (string)$me['email'], 'SV', 'spay_return.php');
                    if ($session) {
                        unset($_SESSION['drafts']['s' . $id]);
                        $pdo->prepare("UPDATE service_checkouts SET provider_session_id = ? WHERE id = ?")->execute([$session['id'], $cid]);
                        header('Location: ' . ($session['redirects'] ? $session['url'] : 'spay_wait.php?c=' . $cid));
                        exit;
                    }
                    $pdo->prepare("UPDATE service_checkouts SET status = 'failed' WHERE id = ?")->execute([$cid]);
                    $err = 'We could not open the payment page. Nothing was charged. Please try again.';
                    if (xendit_last_error() !== '' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
                        $err .= ' [Shown only on this computer] ' . xendit_last_error();
                    }
                }
            }
        }
    }
} elseif (!empty($_GET['resume']) && !empty($_SESSION['drafts']['s' . $id])) {
    // Back from logging in / signing up: show the saved choices for one last confirmation.
    $in = $_SESSION['drafts']['s' . $id] + $in;
    [$calc, $err] = $validate($in);
    if ($calc) $step = 'review';
}

$earliest = earliest_pickup();
$minWhen = $earliest->format('Y-m-d\TH:i');
$suggest = clone $earliest;
$suggest->modify('+' . ((15 - (int)$suggest->format('i') % 15) % 15) . ' minutes');
$hdr = ['back' => 'service.php?id=' . $id . ($guest ? '&city=' . urlencode(city_key($s['province'], $s['city'])) : '')] + ($guest ? ['guest' => true] : []);
user_header($step === 'review' ? 'Confirm your booking' : 'Book ' . $s['title'], 'services.php', $hdr);
if ($err) echo '<p class="alert" role="alert">' . e($err) . '</p>';
if ($step === 'review'):
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="when" value="' . e($in['when']) . '"><input type="hidden" name="street" value="' . e($in['street']) . '">';
    $nextQ = urlencode($resumeUrl);
?>
<div class="cols two">
<section class="panel main">
  <h2>Please confirm your booking</h2>
  <dl class="kv">
    <dt>Service</dt><dd><?= e($s['title']) ?></dd>
    <dt>Provider</dt><dd><?= e($s['business_name'] ?: $s['provider']) ?></dd>
    <dt>When</dt><dd><?= e($calc['whenDt']->format('M j, Y g:i A')) ?></dd>
    <dt>Where</dt><dd><?= e($in['street'] !== '' ? $in['street'] : ($guest ? 'Your address when you sign up' : $me['barangay'] . ', ' . $me['city'])) ?></dd>
    <dt>Total</dt><dd><strong><?= e(peso($calc['total'])) ?></strong></dd>
  </dl>
  <p class="hint">After you pay, the provider and the area manager review your request. If it cannot be accepted, or you cancel while it is pending, you are refunded in full.</p>
  <?php if ($guest): ?>
    <p class="notice">To pay, please log in or create an account. Your booking details are saved. This service is offered in <?= e($s['city']) ?>, so your account address must be in <?= e($s['city']) ?>.</p>
    <p style="display:flex;gap:.6rem;flex-wrap:wrap">
      <a class="btn" href="login.php?next=<?= $nextQ ?>">Log in to pay</a>
      <a class="btn alt" href="register.php?next=<?= $nextQ ?>">Sign up to pay</a>
    </p>
    <form method="post"><?= $hidden ?><button class="btn alt" name="action" value="edit" formnovalidate>Change details</button></form>
  <?php else: ?>
    <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
      <?= $hidden ?><input type="hidden" name="action" value="pay">
      <button class="btn"<?= xendit_enabled() ? '' : ' disabled' ?>>Confirm and pay <?= e(peso($calc['total'])) ?></button>
      <button class="btn alt" name="action" value="edit" formnovalidate>Change details</button>
    </form>
    <p class="hint">You pay with GCash, Maya or a card on Xendit's secure page.</p>
  <?php endif; ?>
</section>
</div>
<?php user_footer(); exit; endif;
?>
<div class="cols two">
<section class="panel main">
  <h2>When and where</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="review">
    <label>Service date and time <input type="datetime-local" name="when" value="<?= e($in['when'] !== '' ? $in['when'] : $suggest->format('Y-m-d\TH:i')) ?>" min="<?= e($minWhen) ?>" required></label>
    <p class="hint" style="margin:-.4rem 0 0">Earliest: <strong><?= e($earliest->format('M j, Y g:i A')) ?></strong>.</p>
    <label>Service address (street, house no.) <input type="text" name="street" maxlength="255" value="<?= e($in['street']) ?>" placeholder="<?= $guest ? 'House no., street' : 'Barangay: ' . e($me['barangay']) . ', ' . e($me['city']) ?>"></label>
    <button class="btn">Review booking</button>
    <p class="hint" style="margin:0"><?= $guest ? 'You can look everything over first. You log in or sign up only when you are ready to pay.' : 'Next you review the booking, then pay with GCash, Maya or a card on Xendit\'s secure page.' ?></p>
  </form>
</section>
<div class="side">
  <section class="panel">
    <h2><?= e($s['title']) ?></h2>
    <dl class="kv">
      <dt>Provider</dt><dd><?= e($s['business_name'] ?: $s['provider']) ?></dd>
      <dt>Area</dt><dd><?= e($s['barangay'] . ', ' . $s['city']) ?></dd>
      <dt>Total</dt><dd><strong><?= e(peso($s['rate'])) ?></strong></dd>
    </dl>
    <p class="hint">If the manager rejects the request, or you cancel while it is pending, the full amount is refunded.</p>
  </section>
</div>
</div>
<?php user_footer();
