<?php
// Tiny JSON answer for pay_wait.php: {"state":"paid"|"pending"|"expired"}. Checks with Xendit, and creates the booking once paid.
require_once __DIR__ . '/includes/user.php';
require_once __DIR__ . '/includes/xendit.php';
$me = require_user();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$cid = (int)($_GET['c'] ?? 0);
$st = db()->prepare("SELECT * FROM checkouts WHERE id = ? AND user_id = ?");
$st->execute([$cid, $me['id']]);
$c = $st->fetch();
$state = 'pending';
if (!$c) {
    $state = 'expired';
} elseif ($c['status'] === 'paid') {
    $state = 'paid';
} elseif ($c['provider_session_id'] && xendit_enabled() && ($invoice = xendit_get_invoice($c['provider_session_id']))) {
    if (finalize_checkout(db(), $cid, $invoice)) {
        $state = 'paid';
    } elseif (($invoice['status'] ?? '') === 'EXPIRED') {
        db()->prepare("UPDATE checkouts SET status = 'cancelled' WHERE id = ? AND status = 'open'")->execute([$cid]);
        $state = 'expired';
    }
}
echo json_encode(['state' => $state]);
