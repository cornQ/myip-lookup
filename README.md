# CORNQ MyIP

A lightweight PHP and MySQL application for public IPv4/IPv6 lookup and
temporary, read-only network snapshot links.

The reference deployment is [myip.cornq.net](https://myip.cornq.net/).

## Features

- Public IPv4 and IPv6 detection
- ISP, ASN, organization, country, region, domain, and timezone lookup
- PeeringDB IX/IXP addresses and formatted port speeds
- One-click copy controls for IP addresses
- Temporary sharable snapshots with 1, 7, or 30-day expiry
- Optional notes with a 500-character limit
- Private, high-entropy result URLs
- Reverse DNS and capture metadata
- MySQL/InnoDB storage with automatic expiry cleanup
- Per-client rate limits for public APIs and link creation
- Consent-based legacy browser and cURL diagnostic routes
- Responsive interface, SEO metadata, sitemap, robots, and `llms.txt`

## Requirements

- PHP 8.0+
- PHP cURL extension
- PHP PDO MySQL extension (`pdo_mysql`)
- MySQL 5.7+ or MySQL 8.x
- HTTPS
- Apache `mod_rewrite`, OpenLiteSpeed/LiteSpeed rewrite support, or equivalent
  Nginx routing

## Installation

1. Clone or download the project into the site document root.
2. Create an empty MySQL database.
3. Import [schema.sql](schema.sql) using phpMyAdmin, Webuzo, or the MySQL CLI.
4. Copy the example configuration:

   ```bash
   cp config.example.php config.php
   ```

5. Edit `config.php` with the deployment URL and MySQL credentials:

   ```php
   define('BASE_URL', 'https://myip.example.com');

   $dbHost = 'localhost';
   $dbPort = 3306;
   $dbName = 'account_database';
   $dbUser = 'account_database_user';
   $dbPassword = 'use-a-strong-password';
   ```

6. Grant the runtime database user only the permissions it needs:

   ```sql
   GRANT SELECT, INSERT, UPDATE, DELETE
   ON account_database.*
   TO 'account_database_user'@'localhost';
   ```

7. Confirm the homepage lookup, sharable-link creation, result view, and expiry
   behavior.

`config.php` is ignored by Git. Do not commit it, database exports, live result
URLs, or diagnostic data.

## Web server routing

The included `.htaccess` supports Apache and LiteSpeed/OpenLiteSpeed. It routes
clean URLs to `index.php` and blocks access to configuration and database-like
files.

For Nginx, use the equivalent of:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/(config\.php|data(?:/|$)) {
    deny all;
}
```

Keep the normal PHP-FPM location block for `.php` files.

## Cloudflare

When Cloudflare proxies the site, protect the origin from direct access and set:

```php
define('TRUST_CLOUDFLARE', true);
```

This lets IP-integrity checks and per-client rate limits use
`CF-Connecting-IP`. Do not enable it on an origin that accepts arbitrary direct
traffic, because that header would be spoofable.

## Privacy model

The normal homepage lookup is not saved. Selecting **Generate Sharable Link**
explicitly stores the visitor's detected IPv4/IPv6 network snapshot, browser
user-agent, approximate IP-derived network/location information, PTR records,
and capture time until the selected expiry.

Anyone with the generated result URL can view that saved snapshot. Opening the
result URL does not capture the recipient's IP. Report deletion is available
only in the creator's current browser session. Expired records are periodically
deleted during later requests.

Legacy `/check/...` links display a consent screen before collecting browser
network information. Running a generated cURL capture command is itself an
explicit submission action.

## Configuration

Public endpoint limits are defined in `config.php`:

```php
define('RATE_LIMIT_INFO_PER_MINUTE', 60);
define('RATE_LIMIT_IX_PER_MINUTE', 60);
define('RATE_LIMIT_SHARES_PER_HOUR', 10);
```

The maximum capture history per diagnostic token is controlled by
`MAX_CAPTURES_PER_LINK`.

## Security

Review [SECURITY.md](SECURITY.md) before reporting a vulnerability. Keep
production credentials in the ignored `config.php` or a deployment-specific
secret manager.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Licensed under the [MIT License](LICENSE).

