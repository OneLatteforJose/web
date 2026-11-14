<?php
// Copy this file to config.local.php and change the values. config.local.php is read by includes/db.php.
// Keep config.local.php out of version control and out of any public download.
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'booking_and_services');
define('DB_USER', 'root');
define('DB_PASS', '');

// Online payment (PayMongo, https://dashboard.paymongo.com/developers). Use the sk_test_ key until you go live.
define('PAYMONGO_SECRET_KEY', 'sk_test_xxxxxxxxxxxxxxxxxxxxxxxx');
define('PAYMONGO_WEBHOOK_SECRET', '');   // optional, only for paymongo_webhook.php (needs a public HTTPS address)
// define('APP_URL', 'https://your-site.example/RentalnServices');   // only if the automatic address is wrong
