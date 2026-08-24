<?php

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_NAME === '' || DB_USER === '') {
        throw new RuntimeException('MySQL database configuration is incomplete.');
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function maybePurgeExpired(): void {
    if (random_int(1, 100) !== 1) {
        return;
    }

    try {
        db()->exec('DELETE FROM diagnostics WHERE expires_at < UTC_TIMESTAMP()');
        $stmt = db()->prepare('DELETE FROM rate_limits WHERE window_started_at < :stale_before');
        $stmt->execute([':stale_before' => time() - 86400]);
    } catch (Throwable $e) {
        // Do not break the public IP checker if database cleanup fails.
    }
}

function enforceRateLimit(string $action, int $limit, int $windowSeconds): void {
    $clientKey = hash('sha256', requestIp() ?? 'unknown');
    $now = time();
    $cutoff = $now - $windowSeconds;
    $stmt = db()->prepare(<<<'SQL'
INSERT INTO rate_limits (client_key, rate_action, window_started_at, request_count)
VALUES (:client_key, :rate_action, :insert_now, 1)
ON DUPLICATE KEY UPDATE
    request_count = IF(window_started_at <= :count_cutoff, 1, request_count + 1),
    window_started_at = IF(window_started_at <= :window_cutoff, :update_now, window_started_at)
SQL);
    $stmt->execute([
        ':client_key' => $clientKey,
        ':rate_action' => $action,
        ':insert_now' => $now,
        ':window_cutoff' => $cutoff,
        ':update_now' => $now,
        ':count_cutoff' => $cutoff,
    ]);

    $stmt = db()->prepare('SELECT request_count, window_started_at FROM rate_limits WHERE client_key = :client_key AND rate_action = :rate_action');
    $stmt->execute([':client_key' => $clientKey, ':rate_action' => $action]);
    $row = $stmt->fetch();
    if ($row && (int)$row['request_count'] > $limit) {
        $retryAfter = max(1, ((int)$row['window_started_at'] + $windowSeconds) - $now);
        header('Retry-After: ' . $retryAfter);
        jsonResponse(['success' => false, 'message' => 'Too many requests. Please try again later.'], 429);
    }
}
