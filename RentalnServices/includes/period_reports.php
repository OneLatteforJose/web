<?php
// Monthly (default) and weekly reports, shared by the admin, manager and provider sides.
//   Weekly:  the person picks a date; the report covers that day and the 6 days after it (7 days in total).
//   Monthly: the person picks a month; the report covers that whole month.
// Bookings and service requests are counted by the day they were created, payments by the day they were paid.
require_once __DIR__ . '/areas.php';

// Reads ?view=weekly|monthly plus ?date= or ?month= from the query string.
function period_request(): array {
    $view  = ($_GET['view'] ?? 'monthly') === 'weekly' ? 'weekly' : 'monthly';   // the monthly report is the default
    $today = new DateTimeImmutable('today');
    if ($view === 'weekly') {
        $raw = (string)($_GET['date'] ?? '');
        $d   = DateTime::createFromFormat('Y-m-d', $raw);
        $from = ($d && $d->format('Y-m-d') === $raw) ? new DateTimeImmutable($raw) : $today;
        $last = $from->modify('+6 days');   // 7 days in total, the selected day included
        $label = $from->format('Y') === $last->format('Y')
            ? $from->format('M j') . ' to ' . $last->format('M j, Y')
            : $from->format('M j, Y') . ' to ' . $last->format('M j, Y');
        $prev = $from->modify('-7 days')->format('Y-m-d');
        $next = $from->modify('+7 days')->format('Y-m-d');
    } else {
        $raw  = (string)($_GET['month'] ?? '');
        $from = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw) ? new DateTimeImmutable($raw . '-01') : $today->modify('first day of this month');
        $last = $from->modify('last day of this month');
        $label = $from->format('F Y');
        $prev = $from->modify('-1 month')->format('Y-m');
        $next = $from->modify('+1 month')->format('Y-m');
    }
    return [
        'view'  => $view,
        'from'  => $from->format('Y-m-d'),
        'to'    => $last->format('Y-m-d'),
        'start' => $from->format('Y-m-d') . ' 00:00:00',
        'end'   => $last->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        'days'  => (int)$from->diff($last)->days + 1,
        'label' => $label,
        'prev'  => $prev,
        'next'  => $next,
    ];
}

// Query string for a link to a period view. $keep = extra parameters to carry along (for example the city filter).
function period_link(string $view, array $p, array $keep = []): string {
    $q = ['view' => $view];
    if ($view === 'weekly')  $q['date']  = $p['from'];
    if ($view === 'monthly') $q['month'] = substr($p['from'], 0, 7);
    return '?' . http_build_query(array_filter($q + $keep, fn($v) => $v !== ''));
}

// The top navigation: Weekly report | Monthly report (| Custom range, for the admin's older date-range page).
function period_tabs(string $active, array $p, array $keep = [], bool $custom = false): void {
    // The reports live on the dashboard now; only the admin's older "Custom range / CSV" page is still its own page.
    echo '<div class="tabs" role="navigation" aria-label="Report period">'
       . '<a href="dashboard.php' . e(period_link('monthly', $p, $keep)) . '#report" class="' . ($active === 'monthly' ? 'on' : '') . '">Monthly report</a>'
       . '<a href="dashboard.php' . e(period_link('weekly', $p, $keep)) . '#report" class="' . ($active === 'weekly' ? 'on' : '') . '">Weekly report</a>';
    if ($custom) {
        echo '<a href="reports.php?view=range' . ($keep ? '&amp;' . e(http_build_query($keep)) : '') . '" class="' . ($active === 'range' ? 'on' : '') . '">Custom range / CSV</a>';
    }
    echo '</div>';
}

// One row per day of the period, from three date => value maps.
function period_daily_rows(array $p, array $books, array $reqs, array $pays): array {
    $rows = [];
    $d = new DateTimeImmutable($p['from']);
    for ($i = 0; $i < $p['days']; $i++, $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $rows[] = ['day' => $d->format('D, M j'), 'bookings' => (int)($books[$k] ?? 0), 'requests' => (int)($reqs[$k] ?? 0), 'paid' => peso($pays[$k] ?? 0)];
    }
    return $rows;
}

function period_status_rows(array $bookings, array $requests): array {
    $rows = [];
    foreach (['pending', 'approved', 'rejected', 'cancelled', 'completed'] as $s) {
        $rows[] = ['status' => ucfirst($s), 'bookings' => (int)($bookings[$s] ?? 0), 'requests' => (int)($requests[$s] ?? 0)];
    }
    return $rows;
}

// Report for the admin (all cities, or one city) and for a manager (always their own city: pass the city key).
function period_report_area(PDO $pdo, array $p, string $city = '', bool $accounts = false): array {
    $range = [$p['start'], $p['end']];
    $hasCity = $city !== '';
    [$cond, $cp] = city_filter($city, 'a');
    $run = function (string $sql, array $params) use ($pdo): PDOStatement {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    };
    $bookJoin = $hasCity ? ' JOIN areas a ON a.id = b.area_id' : '';
    $reqJoin  = $hasCity ? ' JOIN areas a ON a.id = r.area_id' : '';
    $payJoin  = $hasCity ? ' LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN service_requests r ON r.id = p.service_request_id JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)' : '';
    $revJoin  = $hasCity ? ' LEFT JOIN bookings b ON b.id = rv.booking_id LEFT JOIN service_requests r ON r.id = rv.service_request_id JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id)' : '';
    $userJoin = $hasCity ? ' JOIN areas a ON a.id = u.area_id' : '';
    $args = array_merge($range, $cp);

    $bookings = $run("SELECT b.status, COUNT(*) FROM bookings b$bookJoin WHERE b.created_at >= ? AND b.created_at < ?$cond GROUP BY b.status", $args)->fetchAll(PDO::FETCH_KEY_PAIR);
    $requests = $run("SELECT r.status, COUNT(*) FROM service_requests r$reqJoin WHERE r.created_at >= ? AND r.created_at < ?$cond GROUP BY r.status", $args)->fetchAll(PDO::FETCH_KEY_PAIR);
    $payTotal = fn(string $status) => (float)$run("SELECT COALESCE(SUM(p.amount), 0) FROM payments p$payJoin WHERE p.status = ? AND p.paid_at >= ? AND p.paid_at < ?$cond", array_merge([$status], $range, $cp))->fetchColumn();
    $paid = $payTotal('paid');
    $refunded = $payTotal('refunded');
    $rating = $run("SELECT ROUND(AVG(rv.rating), 2) AS avg, COUNT(*) AS c FROM reviews rv$revJoin WHERE rv.created_at >= ? AND rv.created_at < ?$cond", $args)->fetch();

    $cards = [
        ['Bookings', array_sum($bookings), 'vehicle bookings made'],
        ['Service requests', array_sum($requests), 'requests made'],
        ['Payments received', peso($paid), 'paid online'],
        ['Refunded', peso($refunded), 'rejected or cancelled'],
        ['Average rating', $rating['c'] ? $rating['avg'] . ' / 5' : 'No reviews', $rating['c'] ? plural((int)$rating['c'], 'review') : 'in this period'],
    ];
    if ($accounts) {
        $acc = $run("SELECT u.role, COUNT(*) FROM users u$userJoin WHERE u.role <> 'admin' AND u.created_at >= ? AND u.created_at < ?$cond GROUP BY u.role", $args)->fetchAll(PDO::FETCH_KEY_PAIR);
        $cards[] = ['New accounts', array_sum($acc), (int)($acc['customer'] ?? 0) . ' customers, ' . (int)($acc['provider'] ?? 0) . ' providers, ' . (int)($acc['manager'] ?? 0) . ' managers'];
    }

    $daily = null;
    if ($p['view'] === 'weekly') {
        $db = $run("SELECT DATE(b.created_at) AS d, COUNT(*) FROM bookings b$bookJoin WHERE b.created_at >= ? AND b.created_at < ?$cond GROUP BY d", $args)->fetchAll(PDO::FETCH_KEY_PAIR);
        $dr = $run("SELECT DATE(r.created_at) AS d, COUNT(*) FROM service_requests r$reqJoin WHERE r.created_at >= ? AND r.created_at < ?$cond GROUP BY d", $args)->fetchAll(PDO::FETCH_KEY_PAIR);
        $dp = $run("SELECT DATE(p.paid_at) AS d, SUM(p.amount) FROM payments p$payJoin WHERE p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ?$cond GROUP BY d", $args)->fetchAll(PDO::FETCH_KEY_PAIR);
        $daily = period_daily_rows($p, $db, $dr, $dp);
    }

    $byCity = null;
    if (!$hasCity) {   // the admin looking at every city: where the activity was
        $map = [];
        $collect = function (PDOStatement $st, string $field) use (&$map) {
            foreach ($st->fetchAll() as $r) {
                $k = city_key($r['province'], $r['city_municipality']);
                $map[$k] ??= ['city' => $r['city_municipality'] . ', ' . $r['province'], 'bookings' => 0, 'requests' => 0, 'revenue' => 0.0];
                $map[$k][$field] = $r['n'] + 0;
            }
        };
        $collect($run("SELECT a.province, a.city_municipality, COUNT(*) AS n FROM bookings b JOIN areas a ON a.id = b.area_id WHERE b.created_at >= ? AND b.created_at < ? GROUP BY a.province, a.city_municipality", $range), 'bookings');
        $collect($run("SELECT a.province, a.city_municipality, COUNT(*) AS n FROM service_requests r JOIN areas a ON a.id = r.area_id WHERE r.created_at >= ? AND r.created_at < ? GROUP BY a.province, a.city_municipality", $range), 'requests');
        $collect($run("SELECT a.province, a.city_municipality, SUM(p.amount) AS n FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN service_requests r ON r.id = p.service_request_id JOIN areas a ON a.id = COALESCE(b.area_id, r.area_id) WHERE p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? GROUP BY a.province, a.city_municipality", $range), 'revenue');
        usort($map, fn($x, $y) => [$y['revenue'], $y['bookings'], $x['city']] <=> [$x['revenue'], $x['bookings'], $y['city']]);
        $byCity = array_slice($map, 0, 50);
        foreach ($byCity as &$row) $row['revenue'] = peso($row['revenue']);
        unset($row);
    }

    $topVehicles = $run(
        "SELECT v.model, v.plate_no, COUNT(b.id) AS bookings, COALESCE(SUM(b.total_price), 0) AS value
         FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id$bookJoin
         WHERE b.created_at >= ? AND b.created_at < ? AND b.status IN ('approved', 'completed')$cond
         GROUP BY v.id, v.model, v.plate_no ORDER BY bookings DESC, value DESC LIMIT 5", $args)->fetchAll();
    foreach ($topVehicles as &$row) $row['value'] = peso($row['value']);
    unset($row);
    $topServices = $run(
        "SELECT s.title, s.category, COUNT(r.id) AS requests, COALESCE(SUM(r.total_price), 0) AS value
         FROM service_requests r JOIN services s ON s.id = r.service_id$reqJoin
         WHERE r.created_at >= ? AND r.created_at < ? AND r.status IN ('approved', 'completed')$cond
         GROUP BY s.id, s.title, s.category ORDER BY requests DESC, value DESC LIMIT 5", $args)->fetchAll();
    foreach ($topServices as &$row) { $row['value'] = peso($row['value']); $row['category'] = ucfirst(str_replace('_', ' ', $row['category'])); }
    unset($row);

    return ['cards' => $cards, 'status' => period_status_rows($bookings, $requests), 'daily' => $daily, 'byCity' => $byCity,
            'topVehicles' => $topVehicles, 'topServices' => $topServices];
}

// Report for one provider: their own vehicles and services. Driver-only jobs are left out, as on the Earnings page,
// because the payment covers the whole booking and nothing records the driver's share.
function period_report_provider(PDO $pdo, array $p, int $me): array {
    $range = [$p['start'], $p['end']];
    $run = function (string $sql, array $params) use ($pdo): PDOStatement {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    };
    $payFrom = "FROM payments p LEFT JOIN bookings b ON b.id = p.booking_id LEFT JOIN vehicles v ON v.id = b.vehicle_id
                LEFT JOIN service_requests r ON r.id = p.service_request_id LEFT JOIN services s ON s.id = r.service_id
                WHERE (v.provider_id = ? OR s.provider_id = ?)";

    $bookings = $run("SELECT b.status, COUNT(*) FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE v.provider_id = ? AND b.created_at >= ? AND b.created_at < ? GROUP BY b.status", array_merge([$me], $range))->fetchAll(PDO::FETCH_KEY_PAIR);
    $requests = $run("SELECT r.status, COUNT(*) FROM service_requests r JOIN services s ON s.id = r.service_id WHERE s.provider_id = ? AND r.created_at >= ? AND r.created_at < ? GROUP BY r.status", array_merge([$me], $range))->fetchAll(PDO::FETCH_KEY_PAIR);
    $payTotal = fn(string $status) => (float)$run("SELECT COALESCE(SUM(p.amount), 0) $payFrom AND p.status = ? AND p.paid_at >= ? AND p.paid_at < ?", array_merge([$me, $me, $status], $range))->fetchColumn();
    $paid = $payTotal('paid');
    $refunded = $payTotal('refunded');
    $rating = $run(
        "SELECT ROUND(AVG(x.rating), 2) AS avg, COUNT(*) AS c FROM (
            SELECT rv.rating FROM reviews rv JOIN bookings b ON b.id = rv.booking_id JOIN vehicles v ON v.id = b.vehicle_id WHERE v.provider_id = ? AND rv.created_at >= ? AND rv.created_at < ?
            UNION ALL
            SELECT rv.rating FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id JOIN services s ON s.id = r.service_id WHERE s.provider_id = ? AND rv.created_at >= ? AND rv.created_at < ?) x",
        array_merge([$me], $range, [$me], $range))->fetch();

    $cards = [
        ['Bookings of your vehicles', array_sum($bookings), 'made in this period'],
        ['Service requests', array_sum($requests), 'made in this period'],
        ['Payments received', peso($paid), 'paid by customers'],
        ['Refunded', peso($refunded), 'rejected or cancelled'],
        ['Average rating', $rating['c'] ? $rating['avg'] . ' / 5' : 'No reviews', $rating['c'] ? plural((int)$rating['c'], 'review') : 'in this period'],
    ];

    $daily = null;
    if ($p['view'] === 'weekly') {
        $db = $run("SELECT DATE(b.created_at) AS d, COUNT(*) FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id WHERE v.provider_id = ? AND b.created_at >= ? AND b.created_at < ? GROUP BY d", array_merge([$me], $range))->fetchAll(PDO::FETCH_KEY_PAIR);
        $dr = $run("SELECT DATE(r.created_at) AS d, COUNT(*) FROM service_requests r JOIN services s ON s.id = r.service_id WHERE s.provider_id = ? AND r.created_at >= ? AND r.created_at < ? GROUP BY d", array_merge([$me], $range))->fetchAll(PDO::FETCH_KEY_PAIR);
        $dp = $run("SELECT DATE(p.paid_at) AS d, SUM(p.amount) $payFrom AND p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? GROUP BY d", array_merge([$me, $me], $range))->fetchAll(PDO::FETCH_KEY_PAIR);
        $daily = period_daily_rows($p, $db, $dr, $dp);
    }

    $topVehicles = $run(
        "SELECT v.model, v.plate_no, COUNT(b.id) AS bookings, COALESCE(SUM(b.total_price), 0) AS value
         FROM bookings b JOIN vehicles v ON v.id = b.vehicle_id
         WHERE v.provider_id = ? AND b.created_at >= ? AND b.created_at < ? AND b.status IN ('approved', 'completed')
         GROUP BY v.id, v.model, v.plate_no ORDER BY bookings DESC, value DESC LIMIT 5", array_merge([$me], $range))->fetchAll();
    foreach ($topVehicles as &$row) $row['value'] = peso($row['value']);
    unset($row);
    $topServices = $run(
        "SELECT s.title, s.category, COUNT(r.id) AS requests, COALESCE(SUM(r.total_price), 0) AS value
         FROM service_requests r JOIN services s ON s.id = r.service_id
         WHERE s.provider_id = ? AND r.created_at >= ? AND r.created_at < ? AND r.status IN ('approved', 'completed')
         GROUP BY s.id, s.title, s.category ORDER BY requests DESC, value DESC LIMIT 5", array_merge([$me], $range))->fetchAll();
    foreach ($topServices as &$row) { $row['value'] = peso($row['value']); $row['category'] = ucfirst(str_replace('_', ' ', $row['category'])); }
    unset($row);

    return ['cards' => $cards, 'status' => period_status_rows($bookings, $requests), 'daily' => $daily, 'byCity' => null,
            'topVehicles' => $topVehicles, 'topServices' => $topServices];
}

// Everything below the page header: the tabs, the date or month picker, then the report itself.
// $o: 'keep' => extra query parameters to carry in links (the city filter), 'filters' => callable printing extra form fields,
//     'custom' => show the "Custom range" tab, 'scope' => a sentence saying whose numbers these are.
function render_period_report(array $p, array $data, array $o = []): void {
    $keep = $o['keep'] ?? [];
    period_tabs($p['view'], $p, $keep, !empty($o['custom']));
    $weekly = $p['view'] === 'weekly';
    $pkey = $weekly ? 'date' : 'month';
    $nav = fn(string $to) => e('?' . http_build_query(array_filter(['view' => $p['view'], $pkey => $to] + $keep, fn($v) => $v !== '')) . '#report');
    ?>
<style>
.period-btn{display:inline-block;padding:.5rem 1rem;border:1px solid var(--line,#cfd8d2);border-radius:8px;background:var(--card,#fff);color:inherit;text-decoration:none;font-weight:600;cursor:pointer;white-space:nowrap}
.period-btn:hover{border-color:var(--green,#177a4b);color:var(--green-d,#0e5a37)}
.period-nav{display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end}
</style>
<form method="get" action="#report" class="box period-nav" id="period-form">
  <input type="hidden" name="view" value="<?= e($p['view']) ?>">
  <?php if ($weekly): ?>
    <label>Start date <input type="date" name="date" value="<?= e($p['from']) ?>" required onchange="if (this.value) this.form.submit()"></label>
  <?php else: ?>
    <label>Month <input type="month" name="month" value="<?= e(substr($p['from'], 0, 7)) ?>" placeholder="YYYY-MM" pattern="\d{4}-(0[1-9]|1[0-2])" required onchange="if (/^\d{4}-\d{2}$/.test(this.value)) this.form.submit()"></label>
  <?php endif; ?>
  <?php if (!empty($o['filters'])) ($o['filters'])(); ?>
  <label>&nbsp;<a class="period-btn" href="<?= $nav($p['prev']) ?>">&larr; Previous <?= $weekly ? 'week' : 'month' ?></a></label>
  <label>&nbsp;<a class="period-btn" href="<?= $nav($p['next']) ?>">Next <?= $weekly ? 'week' : 'month' ?> &rarr;</a></label>
</form>
<script>
// No "Show report" button: picking a date, month or city reloads the report straight away.
document.getElementById('period-form').addEventListener('change', function (ev) {
  if (ev.target.name === 'city') this.submit();
});
</script>
<h2><?= $weekly ? 'Weekly report' : 'Monthly report' ?>: <?= e($p['label']) ?></h2>
<p class="muted"><?= $weekly ? 'The selected date and the 6 days after it, ' . $p['days'] . ' days in total.' : 'The whole month, ' . $p['days'] . ' days.' ?>
  <?= e($o['scope'] ?? '') ?> Bookings and requests are counted by the day they were made. Payments are counted by the day they were paid.</p>

<div class="grid">
  <?php foreach ($data['cards'] as [$label, $value, $sub]): ?>
    <div class="card"><div class="n" style="font-size:<?= strlen((string)$value) > 14 ? '1.05rem' : '1.7rem' ?>"><?= e($value) ?></div><div class="l"><?= e($label) ?></div><div class="muted" style="font-size:.8rem"><?= e($sub) ?></div></div>
  <?php endforeach; ?>
</div>

<?php if ($data['daily'] !== null): ?>
<h2>Day by day</h2>
<?php render_table(['day' => 'Day', 'bookings' => 'Bookings', 'requests' => 'Service requests', 'paid' => 'Payments received'], $data['daily']); ?>
<?php endif; ?>

<h2>By status</h2>
<?php render_table(['status' => 'Status', 'bookings' => 'Bookings', 'requests' => 'Service requests'], $data['status']); ?>

<?php if ($data['byCity'] !== null): ?>
<h2>By city</h2>
<p class="muted">Cities with activity in this period, top 50 by payments received.</p>
<?php render_table(['city' => 'City', 'bookings' => 'Bookings', 'requests' => 'Service requests', 'revenue' => 'Payments received'], $data['byCity']); ?>
<?php endif; ?>

<h2>Most booked vehicles</h2>
<?php render_table(['model' => 'Vehicle', 'plate_no' => 'Plate', 'bookings' => 'Bookings', 'value' => 'Booked value'], $data['topVehicles']); ?>

<h2>Most requested services</h2>
<?php render_table(['title' => 'Service', 'category' => 'Category', 'requests' => 'Requests', 'value' => 'Requested value'], $data['topServices']); ?>
<?php
}
