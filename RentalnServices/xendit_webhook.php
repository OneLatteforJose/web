<?php
// Optional but recommended: Xendit calls this when an invoice is paid, even if the customer closed the browser before
// returning to the site. It needs a public HTTPS address (Xendit cannot reach http://localhost; use a tunnel such as ngrok
// while testing). In the Xendit dashboard: Settings > Developers > Callbacks > "Invoices paid", set
// https://YOUR-SITE/xendit_webhook.php, and copy the "Callback verification token" into config.local.php as XENDIT_CALLBACK_TOKEN.
require_once __DIR__ . '/includes/xendit.php';

$token = defined('XENDIT_CALLBACK_TOKEN') ? XENDIT_CALLBACK_TOKEN : '';
if ($token === '' || !hash_equals($token, $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '')) {
    http_response_code(403);
    exit('bad token');
}

$event = json_decode(file_get_contents('php://input') ?: '', true);
$invoiceId = is_array($event) ? (string)($event['id'] ?? '') : '';
if ($invoiceId !== '' && in_array($event['status'] ?? '', ['PAID', 'SETTLED'], true)) {
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM checkouts WHERE provider_session_id = ?");
    $st->execute([$invoiceId]);
    $cid = (int)$st->fetchColumn();
    // Ask Xendit again instead of trusting the payload.
    if ($cid && ($invoice = xendit_get_invoice($invoiceId))) {
        finalize_checkout($pdo, $cid, $invoice);
    }
    $st = $pdo->prepare("SELECT id FROM service_checkouts WHERE provider_session_id = ?");
    $st->execute([$invoiceId]);
    $sid = (int)$st->fetchColumn();
    if ($sid && ($invoice = xendit_get_invoice($invoiceId))) {
        finalize_service_checkout($pdo, $sid, $invoice);
    }
}
http_response_code(200);
echo 'ok';
