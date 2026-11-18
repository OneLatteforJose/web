<?php
// Approve / reject / complete a vehicle booking or a service request. Used by the manager, admin and provider pages,
// so every role follows the same rules. The CALLER must already have checked that the person may act on this item.
require_once __DIR__ . '/xendit.php';

// One value from a query (db_value() only exists on the manager pages, so this file has its own).
function decide_value(PDO $pdo, string $sql, array $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

// $type: 'booking' | 'request'.  $action: approve | reject | complete.  $opt: ['driver' => service id (optional), 'note' => text].
// Returns [message, error]; exactly one of them is non-empty.
function decide_order(PDO $pdo, string $type, int $id, string $action, int $actorId, array $opt = []): array {
    $table = $type === 'booking' ? 'bookings' : 'service_requests';
    $label = $type === 'booking' ? 'Booking' : 'Request';
    $pdo->beginTransaction();
    $err = $done = '';
    try {
        if ($type === 'booking') {
            $st = $pdo->prepare("SELECT b.*, v.status AS vehicle_status, v.own_driver_id, a.province AS area_province, a.city_municipality AS area_city
                                 FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN areas a ON a.id = b.area_id WHERE b.id = ? FOR UPDATE");
        } else {
            $st = $pdo->prepare("SELECT r.*, s.status AS service_status FROM service_requests r JOIN services s ON s.id = r.service_id WHERE r.id = ? FOR UPDATE");
        }
        $st->execute([$id]);
        $o = $st->fetch();
        if (!$o) {
            $err = "$label not found.";
        } elseif ($action === 'approve') {
            if ($o['status'] !== 'pending') {
                $err = "Only pending " . ($type === 'booking' ? 'bookings' : 'requests') . " can be approved.";
            } elseif ($type === 'booking') {
                if (in_array($o['vehicle_status'], ['unavailable', 'maintenance'], true)) {
                    $err = 'This vehicle is marked ' . $o['vehicle_status'] . '. Change its status on the Vehicles page first.';
                } elseif ($clash = decide_value($pdo, "SELECT id FROM bookings WHERE vehicle_id = ? AND status = 'approved' AND id <> ? AND start_at < ? AND end_at > ? LIMIT 1",
                                            [$o['vehicle_id'], $id, $o['end_at'], $o['start_at']])) {
                    $err = "These dates overlap approved booking #$clash for the same vehicle.";
                } else {
                    $driver = $o['driver_service_id'] ? (int)$o['driver_service_id'] : null;
                    if ($o['with_driver'] && !$driver) {
                        if ($o['own_driver_id']) {
                            $driver = (int)$o['own_driver_id'];
                        } else {
                            $driver = (int)($opt['driver'] ?? 0) ?: null;   // none chosen: the vehicle owner drives
                            if ($driver && !decide_value($pdo,
                                "SELECT 1 FROM services s JOIN areas a ON a.id = s.area_id
                                 WHERE s.id = ? AND s.category = 'driver' AND s.status = 'available' AND a.province = ? AND a.city_municipality = ?",
                                [$driver, $o['area_province'], $o['area_city']])) {
                                $err = 'Choose an available driver from the booking\'s city, or leave it as the vehicle owner.';
                            }
                        }
                    }
                    if (!$err) {
                        $pdo->prepare("UPDATE bookings SET status = 'approved', reviewed_by = ?, driver_service_id = ? WHERE id = ?")->execute([$actorId, $driver, $id]);
                        $done = "Booking #$id approved.";
                    }
                }
            } elseif ($o['service_status'] === 'unavailable') {
                $err = 'This service is marked unavailable. Change its status on the Services page first.';
            } else {
                $pdo->prepare("UPDATE service_requests SET status = 'approved', reviewed_by = ? WHERE id = ?")->execute([$actorId, $id]);
                $done = "Request #$id approved.";
            }
        } elseif ($action === 'reject') {
            if ($o['status'] !== 'pending') {
                $err = "Only pending " . ($type === 'booking' ? 'bookings' : 'requests') . " can be rejected.";
            } else {
                $pdo->prepare("UPDATE $table SET status = 'rejected', reviewed_by = ? WHERE id = ?")->execute([$actorId, $id]);
                $done = "$label #$id rejected.";
            }
        } elseif ($action === 'complete') {
            $when = $type === 'booking' ? 'start_at' : 'scheduled_at';
            $u = $pdo->prepare("UPDATE $table SET status = 'completed' WHERE id = ? AND status = 'approved' AND $when <= NOW()");
            $u->execute([$id]);
            if ($u->rowCount()) {
                $done = "$label #$id marked completed.";
            } else {
                $err = $type === 'booking' ? 'Only approved bookings that have already started can be completed.'
                                           : 'Only approved requests whose scheduled time has passed can be completed.';
            }
        } else {
            $err = 'Unknown action.';
        }
    } catch (Throwable $ex) {
        error_log('decide_order failed: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
        $err = 'Something went wrong. Please try again.';
    }
    if ($done === '') {
        $pdo->rollBack();
        return ['', $err ?: 'Nothing changed.'];
    }
    $pdo->commit();
    $note = trim((string)($opt['note'] ?? ''));
    log_action("$type.$action", $type, $id, ($type === 'booking' ? 'Booking' : 'Service request') . " #$id " . ['approve' => 'approved', 'reject' => 'rejected', 'complete' => 'completed'][$action] . ($action === 'reject' && $note !== '' ? ": $note" : ''));
    if ($action === 'reject') {   // real refund through Xendit
        $ok = $type === 'booking' ? refund_booking_payments($pdo, $id) : refund_service_payments($pdo, $id);
        $done .= $ok ? ' The customer was refunded.' : ' The online refund did not go through; refund it from the Xendit dashboard.';
    }
    return [$done, ''];
}
