<?php
// Optional but recommended: PayMongo calls this when a payment succeeds, even if the customer closed the browser
// before returning to the site. Needs a public HTTPS address (it cannot reach http://localhost; use a tunnel such as
// ngrok while testing). Register https://YOUR-SITE/paymongo_webhook.php in the PayMongo dashboard for the event
// checkout_session.payment.paid and put the signing secret in config.local.php as PAYMONGO_WEBHOOK_SECRET.
require_once __DIR__ . '/includes/paymongo.php';

$raw = file_get_contents('php://input') ?: '';
$secret = defined('PAYMONGO_WEBHOOK_SECRET') ? PAYMONGO_WEBHOOK_SECRET : '';
$event = json_decode($raw, true);

// Header looks like: t=1496734173,te=<test signature>,li=<live signature>
$parts = [];
foreach (explode(',', $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '') as $kv) {
    [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
    $parts[$k] = $v;
}
$live = !empty($event['data']['attributes']['livemode']);
$expected = hash_hmac('sha256', ($parts['t'] ?? '') . '.' . $raw, $secret);
if ($secret === '' || !is_array($event) || !hash_equals($expected, $parts[$live ? 'li' : 'te'] ?? '')) {
    http_response_code(400);
    exit('bad signature');
}

if (($event['data']['attributes']['type'] ?? '') === 'checkout_session.payment.paid') {
    $sessionId = $event['data']['attributes']['data']['id'] ?? '';
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM checkouts WHERE provider_session_id = ?");
    $st->execute([$sessionId]);
    $cid = (int)$st->fetchColumn();
    // Ask PayMongo again instead of trusting the payload.
    if ($cid && ($session = paymongo_get_session($sessionId))) {
        finalize_checkout($pdo, $cid, $session);
    }
}
http_response_code(200);
echo 'ok';
