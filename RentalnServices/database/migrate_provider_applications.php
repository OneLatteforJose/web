<?php
// One-time upgrade that lets providers sign up and wait for approval.
// It adds these columns to provider_profiles: about, application_status, reviewed_by, reviewed_at, review_note.
// Every provider that already exists is marked "approved", so nothing changes for them.
//
// Run it once from the project folder (XAMPP example):
//     C:\xampp\php\php.exe database\migrate_provider_applications.php
// Safe to run again: it only adds what is missing. New installs do not need it, because
// booking_and_services.sql already includes these columns.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../includes/db.php';

$pdo = db();
$have = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'provider_profiles'")->fetchAll(PDO::FETCH_COLUMN);
if (!$have) {
    fwrite(STDERR, "Could not find the provider_profiles table. Is the database imported?\n");
    exit(1);
}
$idx = $pdo->query("SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'provider_profiles'")->fetchAll(PDO::FETCH_COLUMN);
$fks = $pdo->query("SELECT constraint_name FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'provider_profiles' AND constraint_type = 'FOREIGN KEY'")->fetchAll(PDO::FETCH_COLUMN);

$cs = "CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
$parts = [];
$wanted = [
    'about'              => "varchar(500) $cs NULL DEFAULT NULL",
    'application_status' => "enum('pending','approved','rejected') $cs NOT NULL DEFAULT 'approved'",
    'reviewed_by'        => "int NULL DEFAULT NULL",
    'reviewed_at'        => "datetime NULL DEFAULT NULL",
    'review_note'        => "varchar(255) $cs NULL DEFAULT NULL",
];
foreach ($wanted as $col => $ddl) {
    if (!in_array($col, $have, true)) {
        $parts[] = "ADD COLUMN `$col` $ddl";
    }
}
if (!in_array('idx_application_status', $idx, true)) $parts[] = "ADD INDEX `idx_application_status` (`application_status`)";
if (!in_array('reviewed_by', $idx, true)) $parts[] = "ADD INDEX `reviewed_by` (`reviewed_by`)";
if (!in_array('provider_profiles_ibfk_3', $fks, true)) {
    $parts[] = "ADD CONSTRAINT `provider_profiles_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT";
}

if (!$parts) {
    echo "Already up to date: provider applications are ready.\n";
    exit(0);
}
try {
    $pdo->exec("ALTER TABLE provider_profiles " . implode(', ', $parts));
} catch (Throwable $e) {
    fwrite(STDERR, "Upgrade failed: " . $e->getMessage() . "\n");
    exit(1);
}
$n = (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles")->fetchColumn();
echo "Done. Provider applications are ready. The $n existing provider(s) are marked approved.\n";
