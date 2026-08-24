<?php

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function limitText(string $value, int $maxLength): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($characters)) {
        return implode('', array_slice($characters, 0, $maxLength));
    }
    return substr($value, 0, $maxLength);
}

function textLength(string $value): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($characters) ? count($characters) : strlen($value);
}

function sendSecurityHeaders(): void {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function formatPortSpeed($speed): string {
    $mbps = (float)$speed;
    if ($mbps >= 1000000) {
        return rtrim(rtrim(number_format($mbps / 1000000, 6, '.', ''), '0'), '.') . ' Tbps';
    }
    if ($mbps >= 1000) {
        return rtrim(rtrim(number_format($mbps / 1000, 6, '.', ''), '0'), '.') . ' Gbps';
    }
    return rtrim(rtrim(number_format($mbps, 6, '.', ''), '0'), '.') . ' Mbps';
}

function utcDateTime(?int $timestamp = null): string {
    return gmdate('Y-m-d H:i:s', $timestamp ?? time());
}

function formatUtcDateTime(string $value, string $format): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$date) {
        return $value;
    }
    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
}

function jsonResponse($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function textResponse(string $text, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

function requestIp(): ?string {
    if (TRUST_CLOUDFLARE && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidate = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    $candidate = $_SERVER['REMOTE_ADDR'] ?? null;
    return $candidate && filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
}

function randomToken(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function referenceCode(): string {
    return strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
}

function appSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('cornq_myip');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function assetUrl(string $path): string {
    $path = ltrim($path, '/');
    if (!preg_match('#^[a-zA-Z0-9_./-]+$#', $path)) {
        throw new InvalidArgumentException('Invalid asset path.');
    }

    $file = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $version = is_file($file) ? (string)filemtime($file) : '1';
    return '/' . $path . '?v=' . rawurlencode($version);
}
