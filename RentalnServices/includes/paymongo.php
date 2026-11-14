<?php
// PayMongo (GCash, Maya, cards) over plain cURL. Docs: https://developers.paymongo.com
// Keys live in config.local.php (see config.example.php). Use the sk_test_ key while developing.
require_once __DIR__ . '/db.php';

function paymongo_enabled(): bool {
    return defined('PAYMONGO_SECRET_KEY') && PAYMONGO_SECRET_KEY !== '';
}

// Public address of the project folder, e.g. http://localhost/RentalnServices. PayMongo sends the customer back here.
function app_url(): string {
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}

// Returns [httpStatus, decodedJson]. Status 0 means PayMongo could not be reached.
function paymongo_request(string $method, string $path, ?array $body = null): array {
    $ch = curl_init('https://api.paymongo.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        error_log('PayMongo request failed: ' . curl_error($ch));
    }
    curl_close($ch);
    return [$code, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}

// Creates a hosted checkout page. Returns ['id' => 'cs_...', 'url' => '...'] or null.
function paymongo_create_session(int $checkoutId, int $amountCentavos, string $name, string $description, array $billing): ?array {
    $base = app_url();
    [$code, $res] = paymongo_request('POST', '/checkout_sessions', ['data' => ['attributes' => [
        'billing'              => $billing,
        'send_email_receipt'   => true,
        'show_description'     => true,
        'show_line_items'      => true,
        'description'          => $description,
        'reference_number'     => 'RS-' . $checkoutId,
        'line_items'           => [['currency' => 'PHP', 'amount' => $amountCentavos, 'name' => $name, 'quantity' => 1, 'description' => $description]],
        'payment_method_types' => ['gcash', 'paymaya', 'card', 'grab_pay'],
        'success_url'          => $base . '/pay_return.php?c=' . $checkoutId,
        'cancel_url'           => $base . '/pay_return.php?c=' . $checkoutId . '&cancelled=1',
        'metadata'             => ['checkout_id' => (string)$checkoutId],
    ]]]);
    $url = $res['data']['attributes']['checkout_url'] ?? null;
    $id = $res['data']['id'] ?? null;
    if ($code >= 200 && $code < 300 && $url && $id) {
        return ['id' => $id, 'url' => $url];
    }
    error_log('PayMongo create session failed (' . $code . '): ' . json_encode($res));
    return null;
}

function paymongo_get_session(string $sessionId): ?array {
    [$code, $res] = paymongo_request('GET', '/checkout_sessions/' . rawurlencode($sessionId));
    return $code === 200 && !empty($res['data']) ? $res['data'] : null;
}

// The paid payment inside a checkout session, or null if nothing was paid yet.
// ['id' => 'pay_...', 'amount' => centavos, 'method' => 'gcash'|'paymaya'|'card'|...]
function paymongo_paid_payment(array $session): ?array {
    foreach ($session['attributes']['payments'] ?? [] as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') {
            return [
                'id'     => $p['id'],
                'amount' => (int)($p['attributes']['amount'] ?? 0),
                'method' => $p['attributes']['source']['type'] ?? 'online',
            ];
        }
    }
    return null;
}

function method_label(?string $m): string {
    return ['gcash' => 'GCash', 'paymaya' => 'Maya', 'card' => 'Card', 'grab_pay' => 'GrabPay'][$m ?? ''] ?? ($m ?: 'Online');
}

// Turns a paid checkout into a real booking (status pending, for the area manager) plus its paid payment.
// Safe to call many times and from two places at once (the return page and the webhook): the checkout row is locked.
// Returns the booking id, or null if the checkout is not paid / not found.
function finalize_checkout(PDO $pdo, int $checkoutId, array $session): ?int {
    $paid = paymongo_paid_payment($session);
    if (!$paid) {
        return null;
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM checkouts WHERE id = ? FOR UPDATE");
        $st->execute([$checkoutId]);
        $c = $st->fetch();
        if (!$c) {
            $pdo->rollBack();
            return null;
        }
        if ($c['status'] === 'paid' && $c['booking_id']) {
            $pdo->commit();
            return (int)$c['booking_id'];
        }
        if ($paid['amount'] !== (int)round($c['total_price'] * 100)) {
            error_log("PayMongo amount mismatch on checkout #$checkoutId: paid {$paid['amount']}, expected " . $c['total_price']);
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare(
            "INSERT INTO bookings (user_id, vehicle_id, area_id, pickup_street, start_at, end_at, with_driver, total_price, status)
             VALUES (?,?,?,?,?,?,?,?, 'pending')"
        )->execute([$c['user_id'], $c['vehicle_id'], $c['area_id'], $c['pickup_street'], $c['start_at'], $c['end_at'], $c['with_driver'], $c['total_price']]);
        $bookingId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payments (booking_id, amount, method, reference_no, status, paid_at) VALUES (?,?,?,?, 'paid', NOW())")
            ->execute([$bookingId, $c['total_price'], method_label($paid['method']), $paid['id']]);
        $pdo->prepare("UPDATE checkouts SET status = 'paid', booking_id = ? WHERE id = ?")->execute([$bookingId, $checkoutId]);
        $pdo->commit();
        return $bookingId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('finalize_checkout failed: ' . $ex->getMessage());
        return null;
    }
}

// Refunds every paid payment of one booking. Online payments (reference pay_...) are refunded through PayMongo first;
// the row is marked refunded only when PayMongo accepts it. Returns false if any refund could not be made.
function refund_booking_payments(PDO $pdo, int $bookingId): bool {
    $st = $pdo->prepare("SELECT id, amount, reference_no FROM payments WHERE booking_id = ? AND status = 'paid'");
    $st->execute([$bookingId]);
    $allOk = true;
    foreach ($st->fetchAll() as $p) {
        $online = str_starts_with((string)$p['reference_no'], 'pay_');
        if ($online) {
            if (!paymongo_enabled()) { $allOk = false; continue; }
            [$code, $res] = paymongo_request('POST', '/refunds', ['data' => ['attributes' => [
                'amount'     => (int)round($p['amount'] * 100),
                'payment_id' => $p['reference_no'],
                'reason'     => 'requested_by_customer',
                'notes'      => 'Booking #' . $bookingId,
            ]]]);
            if ($code < 200 || $code >= 300) {
                error_log("PayMongo refund failed for booking #$bookingId ($code): " . json_encode($res));
                $allOk = false;
                continue;
            }
        }
        $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE id = ?")->execute([$p['id']]);
    }
    return $allOk;
}
