<?php
// Xendit (GCash, Maya, GrabPay, ShopeePay, cards) through the Invoice API: Xendit hosts the payment page.
// Docs: https://docs.xendit.co. Keys live in config.local.php (see config.example.php). Use the test ("development") key while building.
require_once __DIR__ . '/db.php';

function xendit_enabled(): bool {
    return defined('XENDIT_SECRET_KEY') && XENDIT_SECRET_KEY !== '';
}

// Public address of the project folder, e.g. http://localhost/RentalnServices. Xendit sends the customer back here.
function app_url(): string {
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}

// Returns [httpStatus, decodedJson]. Status 0 means Xendit could not be reached.
function xendit_request(string $method, string $path, ?array $body = null): array {
    $ch = curl_init('https://api.xendit.co' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERPWD        => XENDIT_SECRET_KEY . ':',   // secret key as the username, empty password
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $GLOBALS['xendit_last_error'] = '';
    if ($raw === false) {
        $GLOBALS['xendit_last_error'] = 'Could not reach Xendit: ' . curl_error($ch);
        error_log('Xendit request failed: ' . curl_error($ch));
    }
    curl_close($ch);
    return [$code, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}

// Creates a hosted payment page. Returns ['id' => invoice id, 'url' => page address, 'redirects' => bool] or null.
// 'redirects' is true when Xendit will send the customer back to pay_return.php after paying. Xendit may refuse a return
// address like http://localhost, so we then retry without it ('redirects' false) and the site waits on pay_wait.php instead.
function xendit_create_invoice(int $checkoutId, float $amount, string $itemName, string $description, string $payerName, string $payerEmail): ?array {
    $base = app_url();
    $body = [
        'external_id'      => 'RS-' . $checkoutId,
        'amount'           => $amount,                  // pesos, not centavos
        'currency'         => 'PHP',
        'description'      => $description,
        'invoice_duration' => 2700,                     // 45 minutes, the same time the dates are held
        'items'            => [['name' => $itemName, 'quantity' => 1, 'price' => $amount]],
        'metadata'         => ['checkout_id' => (string)$checkoutId],
    ];
    if ($payerEmail !== '') {
        $body['payer_email'] = $payerEmail;
        $body['customer'] = ['given_names' => $payerName !== '' ? $payerName : 'Customer', 'email' => $payerEmail];
    }
    $withRedirects = $body + [
        'success_redirect_url' => $base . '/pay_return.php?c=' . $checkoutId,
        'failure_redirect_url' => $base . '/pay_return.php?c=' . $checkoutId . '&cancelled=1',
    ];
    foreach ([true, false] as $useRedirects) {
        [$code, $res] = xendit_request('POST', '/v2/invoices', $useRedirects ? $withRedirects : $body);
        if ($code >= 200 && $code < 300 && !empty($res['id']) && !empty($res['invoice_url'])) {
            return ['id' => $res['id'], 'url' => $res['invoice_url'], 'redirects' => $useRedirects];
        }
        error_log('Xendit create invoice failed (' . $code . ', redirects ' . ($useRedirects ? 'on' : 'off') . '): ' . json_encode($res));
        if ($code > 0) {
            $GLOBALS['xendit_last_error'] = 'Xendit answered HTTP ' . $code . ' ' . ($res['error_code'] ?? '') . ': ' . ($res['message'] ?? json_encode($res));
        }
        if ($code !== 400) {
            break;   // only a validation complaint (for example about the return address) is worth a second try
        }
    }
    return null;
}

// What went wrong in the last Xendit call (empty if nothing).
function xendit_last_error(): string {
    return (string)($GLOBALS['xendit_last_error'] ?? '');
}

function xendit_get_invoice(string $invoiceId): ?array {
    [$code, $res] = xendit_request('GET', '/v2/invoices/' . rawurlencode($invoiceId));
    return $code === 200 && !empty($res['id']) ? $res : null;
}

function method_label(?string $m): string {
    $k = strtoupper((string)$m);
    return ['GCASH' => 'GCash', 'PAYMAYA' => 'Maya', 'GRABPAY' => 'GrabPay', 'SHOPEEPAY' => 'ShopeePay', 'CREDIT_CARD' => 'Card', 'CARDS' => 'Card', 'EWALLET' => 'E-wallet'][$k] ?? ($m ? ucfirst(strtolower($m)) : 'Online');
}

// ['id' => invoice id, 'amount' => pesos, 'method' => 'GCash'...] when the invoice is paid, otherwise null.
function xendit_paid(array $invoice): ?array {
    if (!in_array($invoice['status'] ?? '', ['PAID', 'SETTLED'], true)) {
        return null;
    }
    return [
        'id'     => $invoice['id'],
        'amount' => (float)($invoice['paid_amount'] ?? $invoice['amount'] ?? 0),
        'method' => method_label($invoice['payment_channel'] ?? $invoice['payment_method'] ?? ''),
    ];
}

// Turns a paid checkout into a real booking (status pending, for the area manager) plus its paid payment.
// Safe to call many times and from two places at once (the return page and the webhook): the checkout row is locked.
// Returns the booking id, or null if the checkout is not paid / not found.
function finalize_checkout(PDO $pdo, int $checkoutId, array $invoice): ?int {
    $paid = xendit_paid($invoice);
    if (!$paid) {
        return null;
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM checkouts WHERE id = ? FOR UPDATE");
        $st->execute([$checkoutId]);
        $c = $st->fetch();
        if (!$c || $c['provider_session_id'] !== $paid['id']) {
            $pdo->rollBack();
            return null;
        }
        if ($c['status'] === 'paid' && $c['booking_id']) {
            $pdo->commit();
            return (int)$c['booking_id'];
        }
        if (abs($paid['amount'] - (float)$c['total_price']) > 0.009) {
            error_log("Xendit amount mismatch on checkout #$checkoutId: paid {$paid['amount']}, expected " . $c['total_price']);
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare(
            "INSERT INTO bookings (user_id, vehicle_id, area_id, pickup_street, start_at, end_at, with_driver, total_price, status)
             VALUES (?,?,?,?,?,?,?,?, 'pending')"
        )->execute([$c['user_id'], $c['vehicle_id'], $c['area_id'], $c['pickup_street'], $c['start_at'], $c['end_at'], $c['with_driver'], $c['total_price']]);
        $bookingId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO payments (booking_id, amount, method, reference_no, status, paid_at) VALUES (?,?,?,?, 'paid', NOW())")
            ->execute([$bookingId, $c['total_price'], $paid['method'], $paid['id']]);
        $pdo->prepare("UPDATE checkouts SET status = 'paid', booking_id = ? WHERE id = ?")->execute([$bookingId, $checkoutId]);
        $pdo->commit();
        return $bookingId;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('finalize_checkout failed: ' . $ex->getMessage());
        return null;
    }
}

// Refunds every paid payment of one booking. Online payments (reference = a Xendit invoice id) are refunded through Xendit first;
// the row is marked refunded only when Xendit accepts it. Returns false if any refund could not be made
// (some e-wallet channels cannot be refunded by API; refund those from the Xendit dashboard).
function refund_booking_payments(PDO $pdo, int $bookingId): bool {
    $st = $pdo->prepare("SELECT id, amount, reference_no FROM payments WHERE booking_id = ? AND status = 'paid'");
    $st->execute([$bookingId]);
    $allOk = true;
    foreach ($st->fetchAll() as $p) {
        $online = (bool)preg_match('/^[0-9a-f]{24}$/', (string)$p['reference_no']);   // a Xendit invoice id
        if ($online) {
            if (!xendit_enabled()) { $allOk = false; continue; }
            [$code, $res] = xendit_request('POST', '/refunds', [
                'invoice_id'   => $p['reference_no'],
                'reference_id' => 'refund-booking-' . $bookingId . '-' . $p['id'],
                'amount'       => (float)$p['amount'],
                'currency'     => 'PHP',
                'reason'       => 'REQUESTED_BY_CUSTOMER',
            ]);
            if ($code < 200 || $code >= 300) {
                error_log("Xendit refund failed for booking #$bookingId ($code): " . json_encode($res));
                $allOk = false;
                continue;
            }
        }
        $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE id = ?")->execute([$p['id']]);
    }
    return $allOk;
}
