<?php
// Fills the `areas` table with every barangay in the Philippines.
// Source: Philippine Statistics Authority, Philippine Standard Geographic Code (PSGC), September 2026 publication
// (psa.gov.ph/classification/psgc), converted to database/psgc_areas.csv.
//
// Run it once from the project folder (XAMPP example):
//     C:\xampp\php\php.exe database\import_psgc.php
// Options:
//     --prune   afterwards, delete areas you typed in by hand (no PSGC code) that nothing uses yet
//
// Safe to run again: rows are matched by PSGC code or by name, so nothing is duplicated, and names are refreshed.
// Naming: "City of Ilagan" is stored as "Ilagan City" (so cities sort under their own name), and the cities of the
// National Capital Region are stored under the province name "Metro Manila".

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../includes/db.php';

$pdo  = db();
$file = __DIR__ . '/psgc_areas.csv';
$prune = in_array('--prune', $argv, true);
if (!is_file($file)) {
    fwrite(STDERR, "Missing $file\n");
    exit(1);
}

// Older databases do not have the psgc_code column yet.
$has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'areas' AND column_name = 'psgc_code'")->fetchColumn();
if (!$has) {
    echo "Adding the psgc_code column to areas...\n";
    $pdo->exec("ALTER TABLE areas ADD COLUMN psgc_code CHAR(10) NULL DEFAULT NULL AFTER barangay, ADD UNIQUE INDEX uq_psgc_code (psgc_code)");
}

$before = (int)$pdo->query("SELECT COUNT(*) FROM areas")->fetchColumn();
$fh = fopen($file, 'r');
fgetcsv($fh, 0, ',', '"', '');   // header row

const BATCH = 500;
$tail = " ON DUPLICATE KEY UPDATE psgc_code = VALUES(psgc_code), province = VALUES(province),
          city_municipality = VALUES(city_municipality), barangay = VALUES(barangay)";
$rows = 0;
$batch = [];
$flush = function () use (&$batch, $pdo, $tail) {
    if (!$batch) return;
    $ph = implode(',', array_fill(0, count($batch), '(?,?,?,?)'));
    $pdo->prepare("INSERT INTO areas (psgc_code, province, city_municipality, barangay) VALUES $ph" . $tail)->execute(array_merge(...$batch));
    $batch = [];
};

$pdo->beginTransaction();
try {
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($r) < 4 || $r[0] === '') continue;
        $batch[] = [$r[0], $r[1], $r[2], $r[3]];
        $rows++;
        if (count($batch) >= BATCH) $flush();
    }
    $flush();
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed, nothing was changed: " . $e->getMessage() . "\n");
    exit(1);
}
fclose($fh);

$after = (int)$pdo->query("SELECT COUNT(*) FROM areas")->fetchColumn();
echo "Read $rows barangays from the file. Added " . ($after - $before) . " new rows; the rest already existed and were matched or refreshed.\n";

if ($prune) {
    $n = $pdo->exec(
        "DELETE FROM areas WHERE psgc_code IS NULL
           AND NOT EXISTS (SELECT 1 FROM users WHERE users.area_id = areas.id)
           AND NOT EXISTS (SELECT 1 FROM vehicles WHERE vehicles.area_id = areas.id)
           AND NOT EXISTS (SELECT 1 FROM services WHERE services.area_id = areas.id)
           AND NOT EXISTS (SELECT 1 FROM bookings WHERE bookings.area_id = areas.id)
           AND NOT EXISTS (SELECT 1 FROM service_requests WHERE service_requests.area_id = areas.id)"
    );
    echo "Pruned $n hand-typed areas that nothing used.\n";
}
$left = (int)$pdo->query("SELECT COUNT(*) FROM areas WHERE psgc_code IS NULL")->fetchColumn();
if ($left > 0) {
    echo "$left areas have no PSGC code (typed in by hand). If they duplicate real places, use the Areas page to remove them, or re-run with --prune.\n";
}
echo "Total areas now: $after\n";
