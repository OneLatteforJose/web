<?php
// One-time upgrade that lets providers propose new services for approval.
// It creates the service_submissions table. Nothing else changes, and existing services are untouched.
//
// Run it once from the project folder (XAMPP example):
//     C:\xampp\php\php.exe database\migrate_service_submissions.php
// Safe to run again. New installs do not need it, because booking_and_services.sql already creates the table.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../includes/db.php';

$pdo = db();
$exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'service_submissions'")->fetchColumn();
if ($exists) {
    echo "Already up to date: the service_submissions table exists.\n";
    exit(0);
}
$services = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'services'")->fetchColumn();
if (!$services) {
    fwrite(STDERR, "Could not find the services table. Is the database imported?\n");
    exit(1);
}
$cs = "CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
try {
    $pdo->exec("CREATE TABLE `service_submissions` (
      `id` int NOT NULL AUTO_INCREMENT,
      `provider_id` int NOT NULL,
      `area_id` int NOT NULL,
      `category` enum('handyman','plumbing','cleaning','electrical','beauty','laundry','gardening','driver','body_massage') $cs NOT NULL,
      `title` varchar(150) $cs NOT NULL,
      `description` text $cs NULL,
      `rate` decimal(10, 2) NOT NULL,
      `status` enum('pending','approved','rejected') $cs NOT NULL DEFAULT 'pending',
      `review_note` varchar(255) $cs NULL DEFAULT NULL,
      `reviewed_by` int NULL DEFAULT NULL,
      `reviewed_at` datetime NULL DEFAULT NULL,
      `service_id` int NULL DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      INDEX `idx_provider_status` (`provider_id`, `status`, `id`),
      INDEX `idx_area_status` (`area_id`, `status`, `id`),
      INDEX `idx_status` (`status`, `id`),
      INDEX `reviewed_by` (`reviewed_by`),
      INDEX `service_id` (`service_id`),
      CONSTRAINT `service_submissions_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
      CONSTRAINT `service_submissions_ibfk_2` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
      CONSTRAINT `service_submissions_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
      CONSTRAINT `service_submissions_ibfk_4` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
    ) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic");
} catch (Throwable $e) {
    fwrite(STDERR, "Upgrade failed: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Done. Providers can now propose new services; a manager or admin approves them before they go live.\n";
