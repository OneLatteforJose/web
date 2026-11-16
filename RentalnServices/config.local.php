<?php
// Copy this file to config.local.php and change the values. config.local.php is read by includes/db.php.
// Keep config.local.php out of version control and out of any public download.
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'booking_and_services');
define('DB_USER', 'root');
define('DB_PASS', '');

// Online payment (Xendit, https://dashboard.xendit.co > Settings > Developers > API keys). Use the TEST/development key
// (starts with xnd_development_) until you go live. Give the key "Money-in products: Write" permission.
define('XENDIT_SECRET_KEY', 'xnd_development_yPQKRZfhFXxP87CsYn6RKWO0dUihzay8hyrY3kgFlCQM2cJUJYPtYmlmVhzBT4q');
define('XENDIT_CALLBACK_TOKEN', '');   // optional, only for xendit_webhook.php (needs a public HTTPS address)
// define('APP_URL', 'https://your-site.example/RentalnServices');   // only if the automatic address is wrong
