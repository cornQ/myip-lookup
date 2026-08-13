<?php

// CORNQ MyIP configuration

define('APP_NAME', 'CORNQ What\'s My IP');
define('BASE_URL', 'https://myip.example.com');
define('CORNQ_URL', 'https://cornq.com');

// Enable only when Cloudflare proxies the site and direct origin access is blocked.
define('TRUST_CLOUDFLARE', false);

// MySQL connection - copy this file to config.php and enter your own details.
$dbHost = 'localhost';
$dbPort = 3306;
$dbName = 'YOUR_DATABASE_NAME';
$dbUser = 'YOUR_DATABASE_USERNAME';
$dbPassword = 'YOUR_DATABASE_PASSWORD';

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASSWORD', $dbPassword);

unset($dbHost, $dbPort, $dbName, $dbUser, $dbPassword);

define('MAX_CAPTURES_PER_LINK', 20);
define('RATE_LIMIT_INFO_PER_MINUTE', 60);
define('RATE_LIMIT_IX_PER_MINUTE', 60);
define('RATE_LIMIT_SHARES_PER_HOUR', 10);

