<?php
// Read-only JSON for the province -> city -> barangay pickers. No sign-in needed: it only returns public place names.
//   ?level=province                          -> ["Abra", "Agusan del Norte", ...]
//   ?level=city&province=Isabela             -> [{"key": "Isabela|Ilagan City", "name": "Ilagan City"}, ...]
//   ?level=barangay&province=..&city=..      -> [{"id": 123, "name": "Calamagui"}, ...]
//   ?level=search&q=ilag                     -> [{"key": "Isabela|Ilagan City", "name": "Ilagan City", "province": "Isabela"}, ...]  (max 15)
// A city key is "Province|City" (see includes/areas.php). The unique index (province, city_municipality, barangay)
// serves all three queries, and answers are cacheable because the places rarely change.
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo '[]';
    exit;
}

$clip = fn(string $k) => function_exists('mb_substr') ? mb_substr(trim((string)($_GET[$k] ?? '')), 0, 100) : substr(trim((string)($_GET[$k] ?? '')), 0, 100);
$level = $_GET['level'] ?? '';
$province = $clip('province');
$city = $clip('city');

try {
    $pdo = db();
    $out = [];
    if ($level === 'province') {
        $out = $pdo->query("SELECT province FROM areas GROUP BY province ORDER BY province")->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($level === 'search') {
        // Cities and municipalities whose name contains the text, names that start with it first. Needs 2+ characters.
        $q = $clip('q');
        if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) >= 2) {
            $safe = addcslashes($q, '%_\\');
            $st = $pdo->prepare("SELECT province, city_municipality FROM areas WHERE city_municipality LIKE ?
                                 GROUP BY province, city_municipality
                                 ORDER BY (city_municipality LIKE ?) DESC, city_municipality, province LIMIT 15");
            $st->execute(['%' . $safe . '%', $safe . '%']);
            foreach ($st->fetchAll() as $r) {
                $out[] = ['key' => $r['province'] . '|' . $r['city_municipality'], 'name' => $r['city_municipality'], 'province' => $r['province']];
            }
        }
    } elseif ($level === 'city' && $province !== '') {
        $st = $pdo->prepare("SELECT city_municipality FROM areas WHERE province = ? GROUP BY city_municipality ORDER BY city_municipality");
        $st->execute([$province]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $out[] = ['key' => $province . '|' . $name, 'name' => $name];
        }
    } elseif ($level === 'barangay' && $province !== '' && $city !== '') {
        $st = $pdo->prepare("SELECT id, barangay AS name FROM areas WHERE province = ? AND city_municipality = ? ORDER BY barangay");
        $st->execute([$province, $city]);
        foreach ($st->fetchAll() as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }
    }
    header('Cache-Control: public, max-age=3600');
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load areas.']);
}
