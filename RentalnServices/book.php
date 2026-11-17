<?php
// Book a vehicle and pay for it online. Nothing is saved as a booking until Xendit confirms the payment (see pay_return.php).
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
require_once __DIR__ . '/includes/booking_rules.php';
$me = require_user();
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare(
    "SELECT v.*, p.full_name AS provider, pp.business_name, a.barangay, a.city_municipality AS city
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
if (!$me['area_id']) {
    flash('Add your address first. The manager needs to know where to bring or meet you.');
    header('Location: profile.php');
    exit;
}

const MAX_DAYS = 30;
$err = '';
$in = ['start' => '', 'end' => '', 'street' => (string)($me['street'] ?? ''), 'driver' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = [
        'start'  => trim($_POST['start'] ?? ''),
        'end'    => trim($_POST['end'] ?? ''),
        'street' => trim($_POST['street'] ?? ''),
        'driver' => !empty($_POST['with_driver']) ? '1' : '',
    ];
    $start = DateTime::createFromFormat('Y-m-d\TH:i', $in['start']);
    $end = DateTime::createFromFormat('Y-m-d\TH:i', $in['end']);
    $now = new DateTime();
    $earliest = earliest_pickup($now);

    if (!xendit_enabled()) {
        $err = 'Online payment is not set up yet. Please try again later.';
    } elseif (!$start || !$end) {
        $err = 'Choose when you want the vehicle and when you will return it.';
    } elseif ($start < $earliest) {
        $err = 'Pick-up is available ' . BOOKING_LEAD_HOURS . ' hours after you book: ' . lead_text() . '. The earliest pick-up right now is ' . $earliest->format('M j, Y g:i A') . '.';
    } elseif ($end <= $start) {
        $err = 'The return time must be after the start time.';
    } elseif (strlen($in['street']) > 255) {
        $err = 'The pickup address is too long.';
    } else {
        $days = max(1, (int)ceil(($end->getTimestamp() - $start->getTimestamp()) / 86400));
        if ($days > MAX_DAYS) {
            $err = 'You can rent a vehicle for up to ' . MAX_DAYS . ' days at a time.';
        } else {
            $s = $start->format('Y-m-d H:i:s');
            $e = $end->format('Y-m-d H:i:s');
            $total = round($days * (float)$v['rate_per_day'], 2);
            $nowSql = $now->format('Y-m-d H:i:s');
            // Dates are taken if the manager already approved a booking, or if someone else is paying for them right now.
            $busy = $pdo->prepare(
                "SELECT (SELECT COUNT(*) FROM bookings WHERE vehicle_id = :v1 AND status = 'approved' AND start_at < :e1 AND end_at > :s1)
                      + (SELECT COUNT(*) FROM checkouts WHERE vehicle_id = :v2 AND status = 'open' AND expires_at > :n AND start_at < :e2 AND end_at > :s2 AND user_id <> :u)"
            );
            $busy->execute([':v1' => $id, ':e1' => $e, ':s1' => $s, ':v2' => $id, ':n' => $nowSql, ':e2' => $e, ':s2' => $s, ':u' => $me['id']]);
            if ((int)$busy->fetchColumn() > 0) {
                $err = 'This vehicle is already taken for some of those dates. Try other dates.';
            } elseif ($total < 20) {
                $err = 'The total is below the minimum online payment (' . peso(20) . ').';
            } else {
                // The customer's earlier unpaid attempts for this vehicle no longer need to hold the dates.
                $pdo->prepare("UPDATE checkouts SET status = 'cancelled' WHERE user_id = ? AND vehicle_id = ? AND status = 'open'")->execute([$me['id'], $id]);
                $pdo->prepare(
                    "INSERT INTO checkouts (user_id, vehicle_id, area_id, pickup_street, start_at, end_at, with_driver, total_price, expires_at)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([$me['id'], $id, $me['area_id'], $in['street'] ?: null, $s, $e, $in['driver'] ? 1 : 0, $total, (clone $now)->modify('+45 minutes')->format('Y-m-d H:i:s')]);
                $cid = (int)$pdo->lastInsertId();

                $desc = $days . ($days === 1 ? ' day' : ' days') . ', ' . $start->format('M j, Y g:i A') . ' to ' . $end->format('M j, Y g:i A');
                $session = xendit_create_invoice($cid, $total, ucfirst($v['type']) . ': ' . $v['model'], $desc, (string)$me['full_name'], (string)$me['email']);
                if ($session) {
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

$earliest = earliest_pickup();
$minStart = $earliest->format('Y-m-d\TH:i');
// Pre-fill the pick-up with the earliest allowed time, rounded up to the next quarter hour so it is still valid a few minutes later.
$suggest = clone $earliest;
$suggest->modify('+' . ((15 - (int)$suggest->format('i') % 15) % 15) . ' minutes');
user_header('Book ' . $v['model'], 'vehicles.php', ['back' => 'vehicle.php?id=' . $id]);
if ($err) echo '<p class="alert" role="alert">' . e($err) . '</p>';
if (!xendit_enabled()) echo '<p class="alert">Online payment is not set up yet, so booking is switched off.</p>';
?>
<div class="cols two">
<section class="panel main">
  <h2>Your trip</h2>
  <form method="post" class="stack" id="bookform">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label>Pick up on <input type="datetime-local" name="start" value="<?= e($in['start'] !== '' ? $in['start'] : $suggest->format('Y-m-d\TH:i')) ?>" min="<?= e($minStart) ?>" required></label>
    <p class="hint" style="margin:-.4rem 0 0">Earliest pick-up: <strong><?= e($earliest->format('M j, Y g:i A')) ?></strong>. Pick-up starts <?= BOOKING_LEAD_HOURS ?> hours after you book: <?= e(lead_text()) ?>.</p>
    <label>Return on <input type="datetime-local" name="end" value="<?= e($in['end']) ?>" min="<?= e($minStart) ?>" required></label>
    <label>Pickup address (street, house no.) <input type="text" name="street" maxlength="255" value="<?= e($in['street']) ?>" placeholder="Barangay: <?= e($me['barangay']) ?>, <?= e($me['city']) ?>"></label>
    <label style="display:flex;gap:.5rem;align-items:center;font-size:.95rem;color:inherit">
      <input type="checkbox" name="with_driver" value="1" <?= $in['driver'] ? 'checked' : '' ?><?= $v['own_driver_id'] ? ' checked onclick="return false"' : '' ?>>
      <?= $v['own_driver_id'] ? 'A driver comes with this vehicle' : 'I need a driver (the vehicle owner drives)' ?>
    </label>
    <button class="btn"<?= xendit_enabled() ? '' : ' disabled' ?>>Continue to payment</button>
    <p class="hint" style="margin:0">You pay with GCash, Maya or a card on Xendit's secure page. Your booking is sent to the manager after the payment goes through.</p>
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
    <p class="hint">Charged per 24 hours, rounded up. If the manager rejects the booking, or you cancel while it is pending, the full amount is refunded.</p>
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
    if (isNaN(s) || isNaN(e) || e <= s) { out.textContent = 'Pick your dates'; return; }
    var d = Math.max(1, Math.ceil((e - s) / 864e5));
    out.textContent = d > max ? 'Up to ' + max + ' days' : '\u20B1' + (d * rate).toLocaleString('en-PH', {minimumFractionDigits: (d * rate) % 1 ? 2 : 0}) + ' (' + d + (d === 1 ? ' day)' : ' days)');
  }
  f.start.addEventListener('input', function () { lead(); upd(); }); f.end.addEventListener('input', upd); lead(); upd();
})();
</script>
<?php user_footer();
