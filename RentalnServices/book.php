<?php
// Book a vehicle in three steps: 1) trip details, 2) review and confirm, 3) pay online.
// Visitors who are not logged in can do steps 1 and 2; they log in or sign up before paying (their choices are kept in the session).
// Nothing is saved as a booking until Xendit confirms the payment (see pay_return.php); then the manager / provider approves it.
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
require_once __DIR__ . '/includes/booking_rules.php';
$me = browse_user();
$guest = !empty($me['guest']);
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT v.*, p.full_name AS provider, pp.business_name, a.barangay, a.city_municipality AS city, a.province
     FROM vehicles v JOIN users p ON p.id = v.provider_id AND p.is_active = 1
     LEFT JOIN provider_profiles pp ON pp.user_id = p.id JOIN areas a ON a.id = v.area_id WHERE v.id = ?"
);
$st->execute([$id]);
$v = $st->fetch();
$av = $v ? vehicle_availability($v, vehicle_states($pdo, [$id])[$id] ?? null) : null;
if (!$v || $av['code'] !== 'available') {
    flash($v ? $av['note'] : 'That vehicle is not available right now.');
    header('Location: vehicles.php');
    exit;
}
if (!$guest && !$me['area_id']) {
    flash('Add your address first. The manager needs to know where to bring or meet you.');
    header('Location: profile.php');
    exit;
}

const MAX_DAYS = 30;
const GRACE_HOURS = 2;          // a return up to this long after each 24 hours is still charged as the same day
const MIN_RENTAL_HOURS = 24;   // the return time must be at least this long after the pick-up
$err = '';
$step = 'form';   // form | review
$in = ['start' => '', 'end' => '', 'street' => (string)($me['street'] ?? ''), 'driver' => ''];
$resumeUrl = 'book.php?id=' . $id . '&resume=1';

// Checks the trip details. Returns [$calc, $error]; $calc = start, end, days, total (strings / numbers) when everything is fine.
$validate = function (array $in) use ($pdo, $id, $v, $me): array {
    $start = DateTime::createFromFormat('Y-m-d\TH:i', $in['start']);
    $end = DateTime::createFromFormat('Y-m-d\TH:i', $in['end']);
    $now = new DateTime();
    $earliest = earliest_pickup($now);
    if (!$start || !$end) return [null, 'Choose when you want the vehicle and when you will return it.'];
    if ($start < $earliest) return [null, 'Pick-up is available ' . BOOKING_LEAD_HOURS . ' hours after you book: ' . lead_text() . '. The earliest pick-up right now is ' . $earliest->format('M j, Y g:i A') . '.'];
    if ($end <= $start) return [null, 'The return time must be after the start time.'];
    if ($end->getTimestamp() - $start->getTimestamp() < MIN_RENTAL_HOURS * 3600) return [null, 'Return on requires at least ' . MIN_RENTAL_HOURS . ' hours of use. The earliest return for this pick-up is ' . (clone $start)->modify('+' . MIN_RENTAL_HOURS . ' hours')->format('M j, Y g:i A') . '.'];
    if (strlen($in['street']) > 255) return [null, 'The pickup address is too long.'];
    $days = max(1, (int)ceil(($end->getTimestamp() - $start->getTimestamp() - GRACE_HOURS * 3600) / 86400));   // 24 h + 2 h grace = 1 day; past 26 h a 2nd day starts
    if ($days > MAX_DAYS) return [null, 'You can rent a vehicle for up to ' . MAX_DAYS . ' days at a time.'];
    $s = $start->format('Y-m-d H:i:s');
    $e = $end->format('Y-m-d H:i:s');
    $total = round($days * (float)$v['rate_per_day'], 2);
    // Dates are taken if the manager already approved a booking, or if someone else is paying for them right now.
    $busy = $pdo->prepare(
        "SELECT (SELECT COUNT(*) FROM bookings WHERE vehicle_id = :v1 AND status = 'approved' AND start_at < :e1 AND end_at > :s1)
              + (SELECT COUNT(*) FROM checkouts WHERE vehicle_id = :v2 AND status = 'open' AND expires_at > :n AND start_at < :e2 AND end_at > :s2 AND user_id <> :u)"
    );
    $busy->execute([':v1' => $id, ':e1' => $e, ':s1' => $s, ':v2' => $id, ':n' => $now->format('Y-m-d H:i:s'), ':e2' => $e, ':s2' => $s, ':u' => $me['id']]);
    if ((int)$busy->fetchColumn() > 0) return [null, 'This vehicle is already taken for some of those dates. Try other dates.'];
    if ($total < 20) return [null, 'The total is below the minimum online payment (' . peso(20) . ').'];
    return [['start' => $s, 'end' => $e, 'startDt' => $start, 'endDt' => $end, 'days' => $days, 'total' => $total], null];
};
$calc = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'review';
    $in = [
        'start'  => trim($_POST['start'] ?? ''),
        'end'    => trim($_POST['end'] ?? ''),
        'street' => trim($_POST['street'] ?? ''),
        'driver' => !empty($_POST['with_driver']) ? '1' : '',
    ];
    if ($v['own_driver_id']) $in['driver'] = '1';
    if ($action !== 'edit') {
        [$calc, $err] = $validate($in);
        if ($calc) {
            $_SESSION['drafts']['v' . $id] = $in;   // kept while the visitor logs in or signs up
            $step = 'review';
            if ($action === 'pay') {
                if ($guest) {
                    $err = 'Log in or sign up to pay.';
                } elseif (!xendit_enabled()) {
                    $err = 'Online payment is not set up yet. Please try again later.';
                } else {
                    $now = new DateTime();
                    // The customer's earlier unpaid attempts for this vehicle no longer need to hold the dates.
                    $pdo->prepare("UPDATE checkouts SET status = 'cancelled' WHERE user_id = ? AND vehicle_id = ? AND status = 'open'")->execute([$me['id'], $id]);
                    $pdo->prepare(
                        "INSERT INTO checkouts (user_id, vehicle_id, area_id, pickup_street, start_at, end_at, with_driver, total_price, expires_at)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    )->execute([$me['id'], $id, $me['area_id'], $in['street'] ?: null, $calc['start'], $calc['end'], $in['driver'] ? 1 : 0, $calc['total'], (clone $now)->modify('+45 minutes')->format('Y-m-d H:i:s')]);
                    $cid = (int)$pdo->lastInsertId();

                    $desc = $calc['days'] . ($calc['days'] === 1 ? ' day' : ' days') . ', ' . $calc['startDt']->format('M j, Y g:i A') . ' to ' . $calc['endDt']->format('M j, Y g:i A');
                    $session = xendit_create_invoice($cid, $calc['total'], ucfirst($v['type']) . ': ' . $v['model'], $desc, (string)$me['full_name'], (string)$me['email']);
                    if ($session) {
                        unset($_SESSION['drafts']['v' . $id]);
                        $pdo->prepare("UPDATE checkouts SET provider_session_id = ? WHERE id = ?")->execute([$session['id'], $cid]);
                        header('Location: ' . ($session['redirects'] ? $session['url'] : 'pay_wait.php?c=' . $cid));
                        exit;
                    }
                    $pdo->prepare("UPDATE checkouts SET status = 'failed' WHERE id = ?")->execute([$cid]);
                    $err = 'We could not open the payment page. Nothing was charged. Please try again.';
                    if (xendit_last_error() !== '' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
                        $err .= ' [Shown only on this computer] ' . xendit_last_error();   // the reason, for whoever sets up the site
                    }
                }
            }
        }
    }
} elseif (!empty($_GET['resume']) && !empty($_SESSION['drafts']['v' . $id])) {
    // Back from logging in / signing up: show the saved choices for one last confirmation.
    $in = $_SESSION['drafts']['v' . $id] + $in;
    [$calc, $err] = $validate($in);
    if ($calc) $step = 'review';
}

$earliest = earliest_pickup();
$minStart = $earliest->format('Y-m-d\TH:i');
// Pre-fill the pick-up with the earliest allowed time, rounded up to the next quarter hour so it is still valid a few minutes later.
$suggest = clone $earliest;
$suggest->modify('+' . ((15 - (int)$suggest->format('i') % 15) % 15) . ' minutes');
$hdr = ['back' => 'vehicle.php?id=' . $id . ($guest ? '&city=' . urlencode(city_key($v['province'], $v['city'])) : '')] + ($guest ? ['guest' => true] : []);
user_header($step === 'review' ? 'Confirm your booking' : 'Book ' . $v['model'], 'vehicles.php', $hdr);
if ($err) echo '<p class="alert" role="alert">' . e($err) . '</p>';
if (!$guest && !xendit_enabled()) echo '<p class="alert">Online payment is not set up yet, so booking is switched off.</p>';
if ($step === 'review'):
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="start" value="' . e($in['start']) . '"><input type="hidden" name="end" value="' . e($in['end']) . '"><input type="hidden" name="street" value="' . e($in['street']) . '">'
            . ($in['driver'] ? '<input type="hidden" name="with_driver" value="1">' : '');
    $nextQ = urlencode($resumeUrl);
?>
<div class="cols two">
<section class="panel main">
  <h2>Please confirm your booking</h2>
  <dl class="kv">
    <dt>Vehicle</dt><dd><?= e(ucfirst($v['type']) . ': ' . $v['model']) ?></dd>
    <dt>Provider</dt><dd><?= e($v['business_name'] ?: $v['provider']) ?></dd>
    <dt>Pick up</dt><dd><?= e($calc['startDt']->format('M j, Y g:i A')) ?></dd>
    <dt>Return</dt><dd><?= e($calc['endDt']->format('M j, Y g:i A')) ?> (<?= (int)$calc['days'] ?> <?= $calc['days'] === 1 ? 'day' : 'days' ?> charged)</dd>
    <dt>Driver</dt><dd><?= $in['driver'] ? ($v['own_driver_id'] ? "The vehicle's own driver" : 'The vehicle owner drives') : 'Drive it yourself' ?></dd>
    <dt>Pickup address</dt><dd><?= e($in['street'] !== '' ? $in['street'] : ($guest ? 'Your address when you sign up' : $me['barangay'] . ', ' . $me['city'])) ?></dd>
    <dt>Total</dt><dd><strong><?= e(peso($calc['total'])) ?></strong></dd>
  </dl>
  <p class="hint">After you pay, the manager and the provider review your booking. If it cannot be accepted, or you cancel while it is pending, you are refunded in full.</p>
  <?php if ($guest): ?>
    <p class="notice">To pay, please log in or create an account. Your booking details are saved.</p>
    <p style="display:flex;gap:.6rem;flex-wrap:wrap">
      <a class="btn" href="login.php?next=<?= $nextQ ?>">Log in to pay</a>
      <a class="btn alt" href="register.php?next=<?= $nextQ ?>">Sign up to pay</a>
    </p>
  <?php else: ?>
    <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
      <?= $hidden ?><input type="hidden" name="action" value="pay">
      <button class="btn"<?= xendit_enabled() ? '' : ' disabled' ?>>Confirm and pay <?= e(peso($calc['total'])) ?></button>
      <button class="btn alt" name="action" value="edit" formnovalidate>Change details</button>
    </form>
    <p class="hint">You pay with GCash, Maya or a card on Xendit's secure page.</p>
  <?php endif; ?>
  <?php if ($guest): ?>
    <form method="post"><?= $hidden ?><button class="btn alt" name="action" value="edit" formnovalidate>Change details</button></form>
  <?php endif; ?>
</section>
</div>
<?php user_footer(); exit; endif;
?>
<div class="cols two">
<section class="panel main">
  <h2>Your trip</h2>
  <form method="post" class="stack" id="bookform">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="review">
    <label>Pick up on <input type="datetime-local" name="start" value="<?= e($in['start'] !== '' ? $in['start'] : $suggest->format('Y-m-d\TH:i')) ?>" min="<?= e($minStart) ?>" required></label>
    <p class="hint" style="margin:-.4rem 0 0">Earliest pick-up: <strong><?= e($earliest->format('M j, Y g:i A')) ?></strong>. Pick-up starts <?= BOOKING_LEAD_HOURS ?> hours after you book: <?= e(lead_text()) ?>.</p>
    <label>Return on <input type="datetime-local" name="end" value="<?= e($in['end']) ?>" min="<?= e($minStart) ?>" required></label>
    <p class="alert" id="minuse" role="alert" hidden style="margin:-.4rem 0 0">Return on requires at least <?= MIN_RENTAL_HOURS ?> hours of use. Please choose a return time that is <?= MIN_RENTAL_HOURS ?> hours or more after pick-up.</p>
    <p class="hint" style="margin:-.4rem 0 0">A rental is at least <?= MIN_RENTAL_HOURS ?> hours. You get <?= GRACE_HOURS ?> more hours to return: up to <?= MIN_RENTAL_HOURS + GRACE_HOURS ?> hours is charged as 1 day. A 2nd day is charged only after <?= MIN_RENTAL_HOURS + GRACE_HOURS ?> hours.</p>
    <p class="notice" id="graceinfo" hidden style="margin:-.4rem 0 0"></p>
    <label>Pickup address (street, house no.) <input type="text" name="street" maxlength="255" value="<?= e($in['street']) ?>" placeholder="<?= $guest ? 'House no., street' : 'Barangay: ' . e($me['barangay']) . ', ' . e($me['city']) ?>"></label>
    <label style="display:flex;gap:.5rem;align-items:center;font-size:.95rem;color:inherit">
      <input type="checkbox" name="with_driver" value="1" <?= $in['driver'] ? 'checked' : '' ?><?= $v['own_driver_id'] ? ' checked onclick="return false"' : '' ?>>
      <?= $v['own_driver_id'] ? 'A driver comes with this vehicle' : 'I need a driver (the vehicle owner drives)' ?>
    </label>
    <button class="btn">Review booking</button>
    <p class="hint" style="margin:0"><?= $guest ? 'You can look everything over first. You log in or sign up only when you are ready to pay.' : 'Next you review the booking, then pay with GCash, Maya or a card on Xendit\'s secure page.' ?></p>
  </form>
</section>
<div class="side">
  <section class="panel">
    <h2><?= e($v['model']) ?></h2>
    <dl class="kv">
      <dt>Type</dt><dd><?= e(ucfirst($v['type'])) ?></dd>
      <dt>Provider</dt><dd><?= e($v['business_name'] ?: $v['provider']) ?></dd>
      <dt>Based in</dt><dd><?= e($v['barangay'] . ', ' . $v['city']) ?></dd>
      <dt>Rate</dt><dd><?= e(peso($v['rate_per_day'])) ?> per day</dd>
      <dt>Total</dt><dd><strong id="total">Pick your dates</strong></dd>
    </dl>
    <p class="hint">Charged per 24 hours, with <?= GRACE_HOURS ?> hours to return: <?= 24 + GRACE_HOURS ?> hours in total are still 1 day, and a 2nd day is charged after that. If the manager rejects the booking, or you cancel while it is pending, the full amount is refunded.</p>
  </section>
</div>
</div>
<script>
(function () {
  var rate = <?= json_encode((float)$v['rate_per_day']) ?>, max = <?= MAX_DAYS ?>;
  var f = document.getElementById('bookform'), out = document.getElementById('total');
  var minStart = <?= json_encode($minStart) ?>, minLabel = <?= json_encode($earliest->format('M j, g:i A')) ?>;
  // Pick-up must be at least <?= BOOKING_LEAD_HOURS ?> hours away (the server checks again), and the return cannot be before the pick-up.
  function lead() {
    var s = f.start.value;
    f.start.setCustomValidity(s && s < minStart ? 'Pick-up must be at least <?= BOOKING_LEAD_HOURS ?> hours from now. The earliest is ' + minLabel + '.' : '');
    f.end.min = s && s > minStart ? s : minStart;
  }
  function upd() {
    var s = new Date(f.start.value), e = new Date(f.end.value);
    var short = !isNaN(s) && !isNaN(e) && (e - s) < <?= MIN_RENTAL_HOURS ?> * 36e5;
    document.getElementById('minuse').hidden = !short;
    f.end.setCustomValidity(short ? 'Return on requires at least <?= MIN_RENTAL_HOURS ?> hours of use.' : '');
    var gi = document.getElementById('graceinfo');
    if (!isNaN(s) && !isNaN(e) && !short && e > s) {
      var dd = Math.max(1, Math.ceil(((e - s) - <?= GRACE_HOURS ?> * 36e5) / 864e5));
      var nextDay = new Date(s.getTime() + (dd * 24 + <?= GRACE_HOURS ?>) * 36e5);
      gi.textContent = dd + (dd === 1 ? ' day' : ' days') + ' charged. Return by ' + nextDay.toLocaleString('en-PH', {month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}) + ' to avoid an extra day (' + <?= GRACE_HOURS ?> + ' hours after the ' + dd * 24 + '-hour mark).';
      gi.hidden = false;
    } else { gi.hidden = true; }
    if (isNaN(s) || isNaN(e) || e <= s || short) { out.textContent = 'Pick your dates'; return; }
    var d = Math.max(1, Math.ceil(((e - s) - <?= GRACE_HOURS ?> * 36e5) / 864e5));
    out.textContent = d > max ? 'Up to ' + max + ' days' : '\u20B1' + (d * rate).toLocaleString('en-PH', {minimumFractionDigits: (d * rate) % 1 ? 2 : 0}) + ' (' + d + (d === 1 ? ' day)' : ' days)');
  }
  f.start.addEventListener('input', function () { lead(); upd(); }); f.end.addEventListener('input', upd); lead(); upd();
})();
</script>
<?php user_footer();
