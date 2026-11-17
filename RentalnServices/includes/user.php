<?php
// Customer app helpers: the signed-in customer, the shared page frame with the bottom navigation, and small icons.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/areas.php';
require_once __DIR__ . '/site.php';

const USER_TAGLINE = 'Your everyday service partner';

// The signed-in customer (with their area). Anyone else is sent to the sign-in section on the home page,
// or to their own dashboard if they are an admin or a manager.
function require_user(): array {
    static $u = null;
    if ($u !== null) {
        return $u;
    }
    if (($_SESSION['user']['role'] ?? '') === 'customer') {
        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.email, u.phone, u.street, u.area_id, a.province, a.city_municipality AS city, a.barangay
             FROM users u LEFT JOIN areas a ON a.id = u.area_id
             WHERE u.id = ? AND u.role = 'customer' AND u.is_active = 1"
        );
        $st->execute([$_SESSION['user']['id'] ?? 0]);
        if ($row = $st->fetch()) {
            return $u = $row;
        }
        $_SESSION = [];   // deactivated or removed
    }
    $role = $_SESSION['user']['role'] ?? '';
    header('Location: ' . (in_array($role, ['admin', 'manager'], true) ? $role . '/dashboard.php' : 'login.php'));
    exit;
}

// Customers get their own view. Anyone else (not signed in, or another role) can browse ONE area as a guest,
// reached from the landing page as ?city=Province|City, and sees the same lists.
function browse_user(): array {
    if (($_SESSION['user']['role'] ?? '') === 'customer') {
        return require_user();
    }
    return ['id' => 0, 'guest' => true, 'full_name' => '', 'province' => '', 'city' => '', 'barangay' => '', 'area_id' => null];
}

// [province, city] from ?city=Province|City when that city exists, else null.
function browse_city(PDO $pdo): ?array {
    $p = parse_city_key($_GET['city'] ?? '');
    if (!$p) return null;
    $st = $pdo->prepare("SELECT 1 FROM areas WHERE province = ? AND city_municipality = ? LIMIT 1");
    $st->execute($p);
    return $st->fetchColumn() ? $p : null;
}

function uicon(string $name): string {
    static $p = [
        'home'     => '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'services' => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'pin'      => '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'wrench'   => '<path d="M14.5 6.5a4 4 0 0 0 4.9 4.9L21 13l-3 3-1.6-1.6a4 4 0 0 0-1.1.7L8 21l-3-3 5.9-6.3a4 4 0 0 0 .7-1.1L9.9 9.1l3-3z"/>',
        'sparkle'  => '<path d="M12 3l1.8 4.7 4.7 1.8-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
        'heart'    => '<path d="M12 20s-7-4.6-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.4-7 10-7 10z"/>',
        'leaf'     => '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14z"/><path d="M5 19c3-4 6-6 9-8"/>',
        'car'      => '<path d="M3 13l2-6h14l2 6v5H3z"/><circle cx="7.5" cy="18" r="1.3"/><circle cx="16.5" cy="18" r="1.3"/><path d="M3 13h18"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back'     => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'logout'   => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="M16 8l4 4-4 4M20 12H9"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function stars(?float $rating, int $reviews): string {
    if (!$reviews) {
        return '<span class="rate new">New</span>';
    }
    return '<span class="rate"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.8 6.3.7-4.7 4.3 1.3 6.2L12 16.9 6.4 20l1.3-6.2L3 9.5l6.3-.7z"/></svg>'
         . e(number_format($rating, 1)) . ' <span class="muted">(' . $reviews . ($reviews === 1 ? ' review' : ' reviews') . ')</span></span>';
}

// Service categories grouped for browsing: key => [label, icon, [categories]]
function service_groups(): array {
    return [
        'home'     => ['Home services', 'wrench',  ['handyman', 'plumbing', 'electrical']],
        'cleaning' => ['Cleaning',      'sparkle', ['cleaning', 'laundry']],
        'personal' => ['Personal care', 'heart',   ['beauty', 'body_massage']],
        'outdoor'  => ['Outdoor',       'leaf',    ['gardening']],
        'drivers'  => ['Drivers',       'user',    ['driver']],
    ];
}

function category_icon(string $category): string {
    foreach (service_groups() as [, $icon, $cats]) {
        if (in_array($category, $cats, true)) return $icon;
    }
    return 'wrench';
}

function category_label(string $category): string {
    return service_categories()[$category][0] ?? ucfirst(str_replace('_', ' ', $category));
}

// Real-time availability of vehicles, measured against the server clock (APP_TIMEZONE) at the moment of the request.
// Returns [vehicle_id => ['rented_until' => datetime|null, 'next_start' => datetime|null]] from approved bookings:
// rented_until = a trip that is running right now, next_start = the next approved trip that has not begun.
function vehicle_states(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare(
        "SELECT vehicle_id, MIN(CASE WHEN start_at <= ? THEN end_at END) AS rented_until, MIN(CASE WHEN start_at > ? THEN start_at END) AS next_start
         FROM bookings WHERE status = 'approved' AND end_at > ? AND vehicle_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY vehicle_id"
    );
    $st->execute(array_merge([$now, $now, $now], $ids));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['vehicle_id']] = $r;
    return $out;
}

// What a customer is told about one vehicle: ['code' => available|rented|maintenance|unavailable, 'label' => .., 'note' => ..].
// "rented" = an approved trip is running right now (or the manager flagged it booked); it is not bookable until it is back.
function vehicle_availability(array $v, ?array $state): array {
    $fmt = fn(string $dt): string => date('M j, Y g:i A', strtotime($dt));
    if ($v['status'] === 'maintenance') {
        return ['code' => 'maintenance', 'label' => 'Under maintenance', 'note' => 'This vehicle is under maintenance and cannot be booked until the provider puts it back in service.'];
    }
    if ($v['status'] === 'unavailable') {
        return ['code' => 'unavailable', 'label' => 'Unavailable', 'note' => 'This vehicle is not available right now.'];
    }
    if (!empty($state['rented_until'])) {
        return ['code' => 'rented', 'label' => 'Unavailable: already rented', 'note' => 'Already rented. It is expected back on ' . $fmt($state['rented_until']) . '.'];
    }
    if ($v['status'] === 'booked') {
        return ['code' => 'rented', 'label' => 'Unavailable: already rented', 'note' => 'Already rented.'];
    }
    return ['code' => 'available', 'label' => 'Available', 'note' => ''];
}

function status_chip(string $status): string {
    return '<span class="chip st-' . e($status) . '">' . e(ucfirst($status)) . '</span>';
}

// "Maria Dela Cruz" => "Maria D." (other customers' names are shortened in reviews)
function short_name(string $full): string {
    $parts = preg_split('/\s+/', trim($full));
    $last = count($parts) > 1 ? ' ' . strtoupper(substr(end($parts), 0, 1)) . '.' : '';
    return $parts[0] . $last;
}

// $opts: 'brand' => true shows the app name and tagline instead of a page title; 'back' => link for a back arrow.
// The navigation is printed once. CSS puts it at the bottom on phones and in the top bar on larger screens.
function user_header(string $title, string $active, array $opts = []): void {
    $nav = ['home.php' => ['Home', 'home'], 'services.php' => ['Services', 'services'], 'vehicles.php' => ['Vehicles', 'car'], 'bookings.php' => ['Bookings', 'calendar'], 'profile.php' => ['Profile', 'user']];
    if (!empty($opts['guest'])) {   // a visitor browsing one area
        $nav = ['index.php' => ['Home', 'home'], 'login.php' => ['Log in', 'user'], 'register.php' => ['Sign up', 'user']];
    }
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title === SITE_NAME ? SITE_NAME : $title . ' - ' . SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css"></head>
<body>
<div class="shell">
  <div class="topbar">
    <header class="utop">
      <?php if (!empty($opts['back'])): ?><a class="iconbtn" href="<?= e($opts['back']) ?>" aria-label="Back"><?= uicon('back') ?></a><?php endif; ?>
      <?php if (!empty($opts['brand'])): ?>
        <a class="brand" href="home.php">
          <svg viewBox="0 0 48 48" width="40" height="40" aria-hidden="true"><rect width="48" height="48" rx="12" fill="#0e5a37"/><path d="M24 9c-6 0-10.5 4.5-10.5 10.5C13.5 27 24 39 24 39s10.5-12 10.5-19.5C34.5 13.5 30 9 24 9z" fill="#f5c542"/><circle cx="24" cy="19.5" r="4" fill="#0e5a37"/></svg>
          <span><strong><?= e(SITE_NAME) ?></strong><small><?= e(USER_TAGLINE) ?></small></span>
        </a>
        <h1 class="sr-only"><?= e($title) ?></h1>
      <?php else: ?>
        <h1><?= e($title) ?></h1>
      <?php endif; ?>
    </header>
    <nav class="bnav" aria-label="Main">
      <?php foreach ($nav as $href => [$label, $ic]): ?>
        <a href="<?= $href ?>"<?= $active === $href ? ' class="on" aria-current="page"' : '' ?>><?= uicon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <main class="umain">
<?php
    if (!empty($_SESSION['flash'])) {
        echo '<p class="notice">' . e($_SESSION['flash']) . '</p>';
        unset($_SESSION['flash']);
    }
}

function user_footer(): void {
    ?>
  </main>
</div>
</body></html>
<?php
}

// Simple previous / next pager that keeps the other query parameters.
function upager(int $total, int $per, int $page): void {
    $pages = max(1, (int)ceil($total / $per));
    if ($pages <= 1) return;
    $q = $_GET;
    $link = function (int $n, string $label) use ($q): string { $q['page'] = $n; return '<a href="?' . e(http_build_query($q)) . '">' . $label . '</a>'; };
    echo '<div class="pager">' . ($page > 1 ? $link($page - 1, '&larr; Previous') : '<span></span>')
       . '<span class="muted">Page ' . $page . ' of ' . $pages . '</span>' . ($page < $pages ? $link($page + 1, 'Next &rarr;') : '<span></span>') . '</div>';
}

function snippet(?string $s, int $n = 60): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    if ($len <= $n) return $s;
    return rtrim(function_exists('mb_substr') ? mb_substr($s, 0, $n - 1) : substr($s, 0, $n - 1)) . "\u{2026}";
}

// Services of one city that can be booked now, best first. Rating is the average of the customers' reviews.
function popular_services(PDO $pdo, string $province, string $city, int $limit = 4): array {
    $st = $pdo->prepare(
        "SELECT s.id, s.title, s.description, s.category, s.rate,
                (SELECT COUNT(*) FROM service_requests r WHERE r.service_id = s.id AND r.status IN ('approved', 'completed')) AS done,
                (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS rating,
                (SELECT COUNT(*) FROM reviews rv JOIN service_requests r ON r.id = rv.service_request_id WHERE r.service_id = s.id) AS reviews
         FROM services s JOIN users p ON p.id = s.provider_id AND p.is_active = 1 JOIN areas a ON a.id = s.area_id
         WHERE s.status = 'available' AND a.province = ? AND a.city_municipality = ?
         ORDER BY done DESC, rating DESC, s.id DESC LIMIT " . (int)$limit
    );
    $st->execute([$province, $city]);
    return $st->fetchAll();
}
