<?php
// Shared by every page in /provider. A provider only ever sees their own vehicles, services and jobs.
// "Their jobs" are: bookings of one of their vehicles, bookings where one of their driver services is the driver,
// and requests for one of their services. Nothing is ever read by an id from the URL without that ownership check.
require_once __DIR__ . '/site.php';      // auth.php (session, csrf, log_action) and service_categories()
require_once __DIR__ . '/areas.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/profile.php';

// Re-checks the database on every request: a deactivated provider, or one whose application is not approved, is locked out at once.
// Returns ['id', 'full_name', 'area_id', 'province', 'city', 'barangay', 'business_name'].
function require_provider(): array {
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    if (($_SESSION['user']['role'] ?? '') === 'provider') {
        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.area_id, a.province, a.city_municipality AS city, a.barangay, pp.business_name
             FROM users u LEFT JOIN areas a ON a.id = u.area_id LEFT JOIN provider_profiles pp ON pp.user_id = u.id
             WHERE u.id = ? AND u.role = 'provider' AND u.is_active = 1 AND COALESCE(pp.application_status, 'approved') = 'approved'"
        );
        $st->execute([$_SESSION['user']['id'] ?? 0]);
        if ($row = $st->fetch()) {
            return $p = $row;
        }
        $_SESSION = [];   // deactivated, removed or not approved
    }
    deny_access('provider');
}

function prov_value(PDO $pdo, string $sql, array $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
}

// Approved jobs starting within the next 3 days (shown in the bell).
function provider_soon(PDO $pdo, int $me): int {
    return (int)prov_value($pdo,
        "SELECT (SELECT COUNT(*) FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id LEFT JOIN services ds ON ds.id = b.driver_service_id
                  WHERE b.status = 'approved' AND b.start_at >= NOW() AND b.start_at < NOW() + INTERVAL 3 DAY AND (v.provider_id = ? OR ds.provider_id = ?))
              + (SELECT COUNT(*) FROM service_requests r JOIN services s ON s.id = r.service_id
                  WHERE r.status = 'approved' AND r.scheduled_at >= NOW() AND r.scheduled_at < NOW() + INTERVAL 3 DAY AND s.provider_id = ?)",
        [$me, $me, $me]);
}

function provider_header(string $title, string $active = ''): void {
    $p = require_provider();
    $soon = provider_soon(db(), (int)$p['id']);
    shell_header($title, $active, [
        'dashboard.php' => ['Dashboard', 'home'],
        'jobs.php'      => ['Jobs', 'calendar'],
        'vehicles.php'  => ['Vehicles', 'car'],
        'services.php'  => ['Services', 'wrench'],
        'earnings.php'  => ['Earnings', 'chart'],
    ], [
        'suffix'    => 'Provider',
        'name'      => $p['business_name'] ?: $p['full_name'],
        'city'      => $p['city'] ? $p['city'] . ', ' . $p['province'] : '',
        'bell'      => $soon,
        'bellHref'  => 'jobs.php?status=approved',
        'bellLabel' => 'jobs in the next 3 days',
    ]);
}

// All of this provider's jobs as one list. Bind the same id six times (see provider_job_params()).
// Columns: kind, id, status, at, title, customer, customer_phone, total, created_at, yours
function provider_jobs_sql(): string {
    return "(SELECT 'booking' AS kind, b.id, b.status, b.start_at AS at, v.model AS title, u.full_name AS customer, u.phone AS customer_phone,
                    b.total_price AS total, b.created_at,
                    CASE WHEN v.provider_id = ? AND (ds.provider_id = ? OR (b.with_driver = 1 AND b.driver_service_id IS NULL)) THEN 'Vehicle and driver' WHEN v.provider_id = ? THEN 'Vehicle' ELSE 'Driver' END AS yours
             FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id JOIN users u ON u.id = b.user_id LEFT JOIN services ds ON ds.id = b.driver_service_id
             WHERE v.provider_id = ? OR ds.provider_id = ?)
            UNION ALL
            (SELECT 'request' AS kind, r.id, r.status, r.scheduled_at AS at, s.title, u.full_name, u.phone,
                    r.total_price, r.created_at, 'Service' AS yours
             FROM service_requests r JOIN services s ON s.id = r.service_id JOIN users u ON u.id = r.user_id
             WHERE s.provider_id = ?)";
}
function provider_job_params(int $me): array {
    return [$me, $me, $me, $me, $me, $me];
}

// "Maria Santos" => "Maria S." Customer contact details are only shown once a manager has approved the job.
function provider_customer(array $job): string {
    if (in_array($job['status'], ['approved', 'completed'], true)) {
        return $job['customer'];
    }
    $parts = preg_split('/\s+/', trim($job['customer']));
    return $parts[0] . (count($parts) > 1 ? ' ' . strtoupper(substr(end($parts), 0, 1)) . '.' : '');
}

function fmt_when(?string $dt): string {
    return $dt ? date('M j, Y g:i A', strtotime($dt)) : '-';
}

// Barangays of the provider's own city: [['id' => .., 'barangay' => ..], ...]. A new service can be offered in any of them.
function provider_barangays(PDO $pdo, array $p): array {
    $st = $pdo->prepare("SELECT id, barangay FROM areas WHERE province = ? AND city_municipality = ? ORDER BY barangay");
    $st->execute([$p['province'], $p['city']]);
    return $st->fetchAll();
}

// [waiting for approval, rejected] service submissions of this provider. 0, 0 if the table is not created yet.
function provider_submission_counts(PDO $pdo, int $me): array {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(status = 'pending'), 0), COALESCE(SUM(status = 'rejected'), 0) FROM service_submissions WHERE provider_id = ?");
        $st->execute([$me]);
        return array_map('intval', $st->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        return [0, 0];
    }
}
