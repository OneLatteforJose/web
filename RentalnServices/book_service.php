<?php
// Book a service and pay for it online. Nothing is saved as a request until Xendit confirms the payment (see spay_return.php).
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
require_once __DIR__ . '/includes/booking_rules.php';
$me = require_user();
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
if (!$me['area_id']) {
    flash('Add your address first. The provider needs to know where to come.');
    header('Location: profile.php');
    exit;
}
if ($me['province'] !== $s['province'] || $me['city'] !== $s['city']) {
    flash('This service is only offered in ' . $s['city'] . '.');
    header('Location: service.php?id=' . $id);
    exit;
}

$err = '';
$in = ['when' => '', 'street' => (string)($me['street'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = ['when' => trim($_POST['when'] ?? ''), 'street' => trim($_POST['street'] ?? '')];
    $when = DateTime::createFromFormat('Y-m-d\TH:i', $in['when']);
    $now = new DateTime();
    $earliest = earliest_pickup($now);
    $total = round((float)$s['rate'], 2);

    if (!xendit_enabled()) {
        $err = 'Online payment is not set up yet. Please try again later.';
    } elseif (!$when) {
        $err = 'Choose when you want the service.';
    } elseif ($when < $earliest) {
        $err = 'A service can be booked ' . BOOKING_LEAD_HOURS . ' hours ahead or more. The earliest time right now is ' . $earliest->format('M j, Y g:i A') . '.';
    } elseif (strlen($in['street']) > 255) {
        $err = 'The address is too long.';
    } elseif ($total < 20) {
        $err = 'The price is below the minimum online payment (' . peso(20) . ').';
    } else {
        $pdo->prepare("UPDATE service_checkouts SET status = 'cancelled' WHERE user_id = ? AND service_id = ? AND status = 'open'")->execute([$me['id'], $id]);
        $pdo->prepare(
            "INSERT INTO service_checkouts (user_id, service_id, area_id, service_street, scheduled_at, total_price, expires_at)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$me['id'], $id, $me['area_id'], $in['street'] ?: null, $when->format('Y-m-d H:i:s'), $total, (clone $now)->modify('+45 minutes')->format('Y-m-d H:i:s')]);
        $cid = (int)$pdo->lastInsertId();

        $session = xendit_create_invoice($cid, $total, 'Service: ' . $s['title'], $s['title'] . ', ' . $when->format('M j, Y g:i A'), (string)$me['full_name'], (string)$me['email'], 'SV', 'spay_return.php');
        if ($session) {
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

$earliest = earliest_pickup();
$minWhen = $earliest->format('Y-m-d\TH:i');
$suggest = clone $earliest;
$suggest->modify('+' . ((15 - (int)$suggest->format('i') % 15) % 15) . ' minutes');
user_header('Book ' . $s['title'], 'services.php', ['back' => 'service.php?id=' . $id]);
if ($err) echo '<p class="alert" role="alert">' . e($err) . '</p>';
?>
<div class="cols two">
<section class="panel main">
  <h2>When and where</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label>Service date and time <input type="datetime-local" name="when" value="<?= e($in['when'] !== '' ? $in['when'] : $suggest->format('Y-m-d\TH:i')) ?>" min="<?= e($minWhen) ?>" required></label>
    <p class="hint" style="margin:-.4rem 0 0">Earliest: <strong><?= e($earliest->format('M j, Y g:i A')) ?></strong>.</p>
    <label>Service address (street, house no.) <input type="text" name="street" maxlength="255" value="<?= e($in['street']) ?>" placeholder="Barangay: <?= e($me['barangay']) ?>, <?= e($me['city']) ?>"></label>
    <button class="btn"<?= xendit_enabled() ? '' : ' disabled' ?>>Continue to payment</button>
    <p class="hint" style="margin:0">You pay with GCash, Maya or a card on Xendit's secure page. Your request is sent to the manager after the payment goes through.</p>
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
