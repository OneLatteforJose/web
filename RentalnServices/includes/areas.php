<?php
// Helpers for the single `areas` table (province + city/municipality + barangay).
// A "city" is not its own table: it is every areas row that shares the same province and city_municipality.
// The unique index (province, city_municipality, barangay) already serves lookups by city, so no extra index is needed.

const CITY_SEP = '|';

function city_key(string $province, string $city): string {
    return $province . CITY_SEP . $city;
}

// 'Nueva Vizcaya|Solano' => ['Nueva Vizcaya', 'Solano'], or null when the text is not a valid key.
function parse_city_key(?string $key): ?array {
    $parts = explode(CITY_SEP, (string)$key, 2);
    return (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') ? $parts : null;
}

// SQL condition + parameters that limit a query to one city, using the areas row aliased as $alias.
// An empty or invalid key means "all cities": the returned condition is empty.
function city_filter(?string $key, string $alias = 'a'): array {
    $p = parse_city_key($key);
    return $p ? [" AND $alias.province = ? AND $alias.city_municipality = ?", $p] : ['', []];
}

// Province -> city -> barangay dropdowns, filled on demand from api/areas.php by assets/areas.js (the full list is about
// 42,000 barangays, far too many to put in a page). Posts `city` (a "Province|City" key) and, when $o['barangay'] is true,
// `area_id` (the barangay). The province select has no name: it only narrows the list.
//   api, js        paths to api/areas.php and assets/areas.js from the page (e.g. '../api/areas.php')
//   city           selected city key, or ''        province   selected province (default: taken from the city key)
//   area_id        selected barangay id            barangay   show the barangay select (default true)
//   required       require the selects (forms); leave false for filters, where "All" is a valid choice
//   province_label, city_label  placeholder texts (default "Select province" / "Select city")
function area_picker(array $o = []): void {
    static $scriptWritten = false;
    $api = $o['api'] ?? 'api/areas.php';
    $js = $o['js'] ?? 'assets/areas.js';
    $withBarangay = $o['barangay'] ?? true;
    $req = !empty($o['required']) ? ' required' : '';
    $key = (string)($o['city'] ?? '');
    $parts = parse_city_key($key);
    $province = (string)($o['province'] ?? ($parts[0] ?? ''));
    $provLabel = $o['province_label'] ?? 'Select province';
    $cityLabel = $o['city_label'] ?? 'Select city';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // display:contents lets the labels sit directly in the surrounding form grid.
    echo '<div class="area-picker" style="display:contents" data-api="' . $h($api) . '" data-province="' . $h($province) . '" data-city="' . $h($key)
       . '" data-area="' . (int)($o['area_id'] ?? 0) . '" data-province-label="' . $h($provLabel) . '" data-city-label="' . $h($cityLabel) . '">';
    echo '<label>' . $h($o['label_province'] ?? 'Province') . '<select data-level="province"' . $req . '><option value="">' . $h($provLabel) . '</option>'
       . ($province !== '' ? '<option value="' . $h($province) . '" selected>' . $h($province) . '</option>' : '') . '</select></label>';
    echo '<label>' . $h($o['label_city'] ?? 'City / Municipality') . '<select data-level="city" name="city"' . $req . '><option value="">' . $h($cityLabel) . '</option>'
       . ($parts ? '<option value="' . $h($key) . '" selected>' . $h($parts[1]) . '</option>' : '') . '</select></label>';
    if ($withBarangay) {
        echo '<label>' . $h($o['label_barangay'] ?? 'Barangay') . '<select data-level="barangay" name="area_id"' . $req . '><option value="">Select barangay</option></select></label>';
    }
    echo '</div>';
    if (!$scriptWritten) {
        echo '<script src="' . $h($js) . '" defer></script>';
        $scriptWritten = true;
    }
}

function db_has_areas(PDO $pdo): bool {
    return (bool)$pdo->query("SELECT 1 FROM areas LIMIT 1")->fetchColumn();
}

// The city key an areas row belongs to, or null if the row does not exist.
function city_key_of_area(PDO $pdo, int $areaId): ?string {
    $st = $pdo->prepare("SELECT province, city_municipality FROM areas WHERE id = ?");
    $st->execute([$areaId]);
    $r = $st->fetch();
    return $r ? city_key($r['province'], $r['city_municipality']) : null;
}

// Does the city of areas row $areaId already have an active manager (ignoring user $exceptUserId)?
// Call it inside a transaction: it first locks the city's areas rows, so two admins creating
// or activating managers for the same city at the same moment cannot both pass the check.
function manager_conflict(PDO $pdo, int $areaId, int $exceptUserId = 0): bool {
    $lock = $pdo->prepare(
        "SELECT a2.id FROM areas a1
         JOIN areas a2 ON a2.province = a1.province AND a2.city_municipality = a1.city_municipality
         WHERE a1.id = ? FOR UPDATE"
    );
    $lock->execute([$areaId]);
    $lock->fetchAll();
    $st = $pdo->prepare(
        "SELECT 1 FROM users u
         JOIN areas ua ON ua.id = u.area_id
         JOIN areas t  ON t.id = ? AND t.province = ua.province AND t.city_municipality = ua.city_municipality
         WHERE u.role = 'manager' AND u.is_active = 1 AND u.id <> ? LIMIT 1"
    );
    $st->execute([$areaId, $exceptUserId]);
    return (bool)$st->fetchColumn();
}

// How many rows reference this single areas row (an area in use cannot be deleted: the foreign keys are RESTRICT).
function area_usage_sql(string $ref): string {
    return "(SELECT COUNT(*) FROM users WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM vehicles WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM services WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM bookings WHERE area_id = $ref)
          + (SELECT COUNT(*) FROM service_requests WHERE area_id = $ref)";
}

function area_place(?string $barangay, ?string $city, ?string $street = null): string {
    return implode(', ', array_filter([$street, $barangay, $city]));
}
