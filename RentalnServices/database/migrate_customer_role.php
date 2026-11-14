<?php
// One-time upgrade for databases created before the role "user" was renamed to "customer".
// The four roles are now: admin, manager, provider, customer.
//
// Run it once from the project folder (XAMPP example):
//     C:\xampp\php\php.exe database\migrate_customer_role.php
// Safe to run again: if the column is already up to date it does nothing. New installs do not need it,
// because booking_and_services.sql already uses "customer".

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line.');
}
require __DIR__ . '/../includes/db.php';

$pdo = db();
$type = $pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'")->fetchColumn();
if ($type === false) {
    fwrite(STDERR, "Could not find users.role. Is the database imported?\n");
    exit(1);
}
if (strpos($type, "'customer'") !== false && strpos($type, "'user'") === false) {
    echo "Already up to date: roles are $type\n";
    exit(0);
}

$col = "CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL";
try {
    // Three steps, so no row ever holds a value its column does not allow: allow both names, rename the rows, drop the old name.
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','provider','user','customer') $col");
    $n = $pdo->exec("UPDATE users SET role = 'customer' WHERE role = 'user'");
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','provider','customer') $col");
} catch (Throwable $e) {
    fwrite(STDERR, "Upgrade failed: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Done. $n account(s) changed from user to customer. Roles are now: admin, manager, provider, customer.\n";
