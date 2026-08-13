<?php
require __DIR__ . '/config.php';

date_default_timezone_set('Asia/Dhaka');

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

function remoteJson(string $url): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'CORNQ-MyIP/1.0 (+https://myip.cornq.net/)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $code < 200 || $code >= 300) {
        return null;
    }

    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : null;
}

function ipInfo(?string $ip): ?array {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    return remoteJson('https://ipwho.is/' . rawurlencode($ip));
}

function ixInfo(?int $asn): array {
    if (!$asn) {
        return [];
    }
    $result = remoteJson('https://www.peeringdb.com/api/netixlan?asn=' . $asn);
    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

function reverseDns(?string $ip): ?string {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    $host = @gethostbyaddr($ip);
    if (!$host || $host === $ip) {
        return null;
    }
    return $host;
}

function buildNetworkSnapshot(?string $ipv4, ?string $ipv6): array {
    $info4 = ipInfo($ipv4);
    $info6 = ipInfo($ipv6);
    $preferred = $info4 ?: $info6;
    $asn = (int)($preferred['connection']['asn'] ?? 0);

    return [
        'info4' => $info4,
        'info6' => $info6,
        'ix' => ixInfo($asn),
        'ptr4' => reverseDns($ipv4),
        'ptr6' => reverseDns($ipv6),
    ];
}

function insertCapture(PDO $pdo, int $diagnosticId, string $source, ?string $ipv4, ?string $ipv6, ?string $requestIp, array $snapshot, ?string $capturedAt = null): void {
    $stmt = $pdo->prepare('INSERT INTO captures (diagnostic_id,captured_at,source,ipv4,ipv6,request_ip,ptr4,ptr6,ipv4_info_json,ipv6_info_json,ix_json,user_agent) VALUES (:diagnostic_id,:captured_at,:source,:ipv4,:ipv6,:request_ip,:ptr4,:ptr6,:info4,:info6,:ix,:ua)');
    $stmt->execute([
        ':diagnostic_id' => $diagnosticId,
        ':captured_at' => $capturedAt ?? utcDateTime(),
        ':source' => $source,
        ':ipv4' => $ipv4,
        ':ipv6' => $ipv6,
        ':request_ip' => $requestIp,
        ':ptr4' => $snapshot['ptr4'] ?? null,
        ':ptr6' => $snapshot['ptr6'] ?? null,
        ':info4' => !empty($snapshot['info4']) ? json_encode($snapshot['info4'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ':info6' => !empty($snapshot['info6']) ? json_encode($snapshot['info6'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ':ix' => json_encode($snapshot['ix'] ?? [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ':ua' => limitText((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 1000),
    ]);
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

function findDiagnosticByPublic(string $token): ?array {
    $stmt = db()->prepare('SELECT * FROM diagnostics WHERE public_token = :token AND expires_at >= UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function findDiagnosticByPrivate(string $token): ?array {
    $stmt = db()->prepare('SELECT * FROM diagnostics WHERE private_token = :token AND expires_at >= UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
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

function renderHeader(string $title = "What's My IP?", string $description = 'Check your public IPv4 and IPv6 address, ISP, ASN, location and Internet Exchange information.', bool $noindex = false): void {
    $fullTitle = h($title . ' | CORNQ');
    $desc = h($description);
    $robots = $noindex ? 'noindex, nofollow, noarchive' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$fullTitle}</title>
<meta name="description" content="{$desc}">
<meta name="keywords" content="what is my ip, whats my ip, my ip address, ipv4, ipv6, ip checker, ip lookup, isp lookup, asn lookup, ix lookup, ixp lookup, internet exchange, public ip, CORNQ">
<meta name="author" content="CORNQ">
<meta name="application-name" content="CORNQ What's My IP">
<meta name="robots" content="{$robots}">
<meta name="googlebot" content="{$robots}">
<meta name="referrer" content="no-referrer">
<link rel="canonical" href="https://myip.cornq.net/">
<meta property="og:type" content="website">
<meta property="og:title" content="What's My IP? - IPv4, IPv6, ISP, ASN & IX Lookup">
<meta property="og:description" content="Instantly check your IPv4, IPv6, ISP, ASN, location and Internet Exchange peering information.">
<meta property="og:url" content="https://myip.cornq.net/">
<meta property="og:site_name" content="CORNQ What's My IP">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="What's My IP? | CORNQ">
<meta name="twitter:description" content="Check your IPv4, IPv6, ISP, ASN, location and IX/IXP information instantly.">
<meta name="theme-color" content="#182c45">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebApplication",
  "name": "CORNQ What's My IP",
  "url": "https://myip.cornq.net/",
  "description": "A free IP and network information lookup tool that displays public IPv4, IPv6, ISP, ASN, organization, location, timezone and Internet Exchange peering information.",
  "applicationCategory": "UtilityApplication",
  "operatingSystem": "Any",
  "isAccessibleForFree": true,
  "provider": {"@type":"Organization","name":"CORNQ","url":"https://cornq.com"},
  "featureList": ["Public IPv4 detection","Public IPv6 detection","ISP lookup","ASN lookup","Network organization lookup","Country lookup","City and region lookup","Timezone lookup","Internet Exchange lookup","IXP peering IPv4 lookup","IXP peering IPv6 lookup","Consent-based sharable diagnostic links"]
}
</script>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f7f9;font-family:Arial,sans-serif;color:#181818}.container{max-width:900px;margin:auto;padding:48px 20px 0}h1{text-align:center;margin:0 0 14px}.intro{max-width:700px;margin:0 auto 30px;text-align:center;color:#666;line-height:1.6}.ip-main{text-align:center;background:#fff;padding:35px;border-radius:12px;margin-bottom:20px}.label{font-size:13px;color:#777;margin-bottom:8px}.main-ip{font-size:38px;font-weight:700;word-break:break-all}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:15px}.card,.panel{background:#fff;border-radius:10px;padding:20px}.card .value{font-size:17px;font-weight:600;word-break:break-all}.full{grid-column:1/-1}.ix-item{padding:15px 0;border-bottom:1px solid #eee}.ix-item:last-child{border-bottom:0}.ix-name{font-weight:700;margin-bottom:8px}.ix-ip{font-family:monospace;margin:5px 0;word-break:break-all}.loading,.muted{color:#888}.not-available{color:#999}.actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin:22px 0 4px}.btn{display:inline-block;border:0;border-radius:8px;padding:11px 16px;font-weight:700;cursor:pointer;text-decoration:none;font-size:14px}.btn.primary{background:#182c45;color:#fff}.btn.secondary{background:#e9edf2;color:#182c45}.btn.danger{background:#a32121;color:#fff}.narrow{max-width:620px;margin:40px auto;padding:0 20px}.panel h2{margin-top:0}.form-label{display:block;font-size:14px;font-weight:700;margin:14px 0 7px}.input,select,textarea{width:100%;padding:11px 12px;border:1px solid #ccd2d8;border-radius:8px;font:inherit;margin-bottom:14px}.alert{padding:12px 14px;border-radius:8px;margin:12px 0}.alert.success{background:#eaf6ee;color:#176b35}.alert.error{background:#fdecec;color:#8b1c1c}.notice{background:#fff8dc;border:1px solid #eadca5;padding:14px;border-radius:9px;line-height:1.55}.codebox{font-family:monospace;background:#111827;color:#f7f7f7;padding:12px;border-radius:8px;word-break:break-all;margin:8px 0}.result-block{margin:18px 0}.result-table{width:100%;border-collapse:collapse}.result-table td{padding:9px 7px;border-bottom:1px solid #eee;vertical-align:top}.result-table td:first-child{width:180px;color:#666;font-size:14px}.capture{border:1px solid #e3e6ea;border-radius:10px;padding:16px;margin:14px 0}.capture h3{margin:0 0 12px}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.footer{margin-top:45px;padding:25px 20px;text-align:center;font-size:14px;color:#666;border-top:1px solid #e4e4e4;background:#fff}.footer a{color:#182c45;font-weight:700;text-decoration:none}.footer a:hover{text-decoration:underline}@media(max-width:650px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.main-ip{font-size:28px}.result-table td:first-child{width:115px}.container{padding-top:32px}}
</style>
<style>
.share-output{margin-top:22px;padding-top:20px;border-top:1px solid #e4e7eb;text-align:left}.share-output .codebox{margin:8px 0 10px}.btn:disabled{opacity:.6;cursor:not-allowed}.value-with-copy{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.value-with-copy>span,.value-with-copy>.value{min-width:0;word-break:break-all}.btn.copy-ip-button{padding:4px 8px;font-size:10px;font-weight:600;white-space:nowrap}.result-table .value-with-copy{justify-content:space-between}.modal-backdrop{position:fixed;inset:0;z-index:1000;background:rgba(15,23,42,.58);display:flex;align-items:center;justify-content:center;padding:20px}.modal-backdrop[hidden],.share-output[hidden],.alert[hidden]{display:none}.modal-panel{position:relative;width:100%;max-width:520px;background:#fff;border-radius:12px;padding:26px;box-shadow:0 22px 60px rgba(0,0,0,.24)}.modal-panel h2{margin:0 35px 20px 0}.modal-close{position:absolute;top:12px;right:14px;border:0;background:transparent;color:#555;font-size:28px;line-height:1;cursor:pointer}.modal-panel textarea.input{min-height:105px;resize:vertical;margin-bottom:5px}.field-counter{text-align:right;color:#888;font-size:12px;margin:0 2px 14px}.optional-label{font-weight:400}.modal-panel .btn.primary{width:100%;margin-top:2px}.modal-shake{animation:modalShake .32s ease-in-out}@keyframes modalShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-9px)}40%{transform:translateX(8px)}60%{transform:translateX(-6px)}80%{transform:translateX(4px)}}@media(max-width:650px){.modal-panel{padding:22px 18px}.result-table .value-with-copy{align-items:flex-start}}
</style>
</head>
<body>
HTML;
}

function renderFooter(): void {
    echo <<<'HTML'
<script>
window.cornqCopyText = async function(text, button) {
    text = String(text || '').trim();
    if (!text) return false;

    let copied = false;

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            copied = true;
        }
    } catch (e) {
        copied = false;
    }

    if (!copied) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            copied = document.execCommand('copy');
        } catch (e) {
            copied = false;
        }
        document.body.removeChild(textarea);
    }

    if (button) {
        const original = button.dataset.originalText || button.textContent;
        button.dataset.originalText = original;
        button.textContent = copied ? 'Copied!' : 'Copy failed';
        setTimeout(() => { button.textContent = original; }, 1500);
    }

    return copied;
};
</script>
<footer class="footer">IP &amp; Network Lookup Tool powered by <a href="https://cornq.com" target="_blank" rel="noopener">CORNQ</a></footer></body></html>
HTML;
}

sendSecurityHeaders();
maybePurgeExpired();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = is_string($_GET['route'] ?? null) ? $_GET['route'] : '';
$routeToken = is_string($_GET['token'] ?? null) ? strtolower($_GET['token']) : '';
$routeKey = is_string($_GET['key'] ?? null) ? strtolower($_GET['key']) : '';

// Existing public API: IP information lookup.
if (isset($_GET['api']) && $_GET['api'] === 'info') {
    enforceRateLimit('ip_info', RATE_LIMIT_INFO_PER_MINUTE, 60);
    $ip = $_GET['ip'] ?? '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        jsonResponse(['success' => false, 'message' => 'Invalid IP'], 400);
    }
    jsonResponse(ipInfo($ip) ?? ['success' => false], 200);
}

// Existing public API: PeeringDB IX/IXP lookup.
if (isset($_GET['api']) && $_GET['api'] === 'ix') {
    enforceRateLimit('ix_info', RATE_LIMIT_IX_PER_MINUTE, 60);
    $asnInput = is_scalar($_GET['asn'] ?? null) ? (string)$_GET['asn'] : '';
    $asn = (int)preg_replace('/[^0-9]/', '', $asnInput);
    jsonResponse(['data' => ixInfo($asn)]);
}

// Public AJAX endpoint used by the homepage sharable-link modal.
if (isset($_GET['api']) && $_GET['api'] === 'create_share') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'POST required'], 405);
    }

    appSession();
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrf = $payload['csrf'] ?? null;
    if (!is_string($csrf) || $csrf === '' || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        jsonResponse(['success' => false, 'message' => 'Invalid request token. Please refresh and try again.'], 403);
    }
    enforceRateLimit('create_share', RATE_LIMIT_SHARES_PER_HOUR, 3600);

    $expiryValue = $payload['expiry_days'] ?? null;
    if ((!is_string($expiryValue) && !is_int($expiryValue)) || !in_array((string)$expiryValue, ['1', '7', '30'], true)) {
        jsonResponse(['success' => false, 'message' => 'Please select a valid link expiry.'], 422);
    }
    $days = (int)$expiryValue;
    $noteValue = $payload['note'] ?? '';
    if (!is_string($noteValue)) {
        jsonResponse(['success' => false, 'message' => 'Notes must be plain text.'], 422);
    }
    $noteInput = trim($noteValue);
    if (textLength($noteInput) > 500) {
        jsonResponse(['success' => false, 'message' => 'Notes cannot exceed 500 characters.'], 422);
    }
    $note = limitText($noteInput, 500);
    $ipv4 = filter_var($payload['ipv4'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null;
    $ipv6 = filter_var($payload['ipv6'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: null;
    if (!$ipv4 && !$ipv6) {
        jsonResponse(['success' => false, 'message' => 'Please wait until your IP address is detected.'], 422);
    }
    $observedIp = requestIp();
    if (!$observedIp || !in_array($observedIp, array_filter([$ipv4, $ipv6]), true)) {
        jsonResponse(['success' => false, 'message' => 'The detected IP does not match this request. Please refresh and check the proxy configuration.'], 422);
    }

    // Release the session lock while remote IP, IX and PTR lookups run.
    session_write_close();

    try {
        $public = randomToken(16);
        $private = randomToken(24);
        $reference = referenceCode();
        $createdAt = utcDateTime();
        $expiresAt = utcDateTime(time() + ($days * 86400));
        $snapshot = buildNetworkSnapshot($ipv4, $ipv6);

        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO diagnostics (public_token,private_token,reference_code,note,created_at,expires_at) VALUES (:public,:private,:reference,:note,:created,:expires)');
        $stmt->execute([
            ':public' => $public,
            ':private' => $private,
            ':reference' => $reference,
            ':note' => $note,
            ':created' => $createdAt,
            ':expires' => $expiresAt,
        ]);
        $diagnosticId = (int)$pdo->lastInsertId();
        insertCapture($pdo, $diagnosticId, 'Shared snapshot', $ipv4, $ipv6, $observedIp, $snapshot, $createdAt);
        $pdo->commit();

        appSession();
        if (!isset($_SESSION['owned_diagnostics']) || !is_array($_SESSION['owned_diagnostics'])) {
            $_SESSION['owned_diagnostics'] = [];
        }
        $_SESSION['owned_diagnostics'][$private] = time();
        if (count($_SESSION['owned_diagnostics']) > 50) {
            array_shift($_SESSION['owned_diagnostics']);
        }
        jsonResponse([
            'success' => true,
            'share_url' => BASE_URL . '/result.php?key=' . $private,
            'reference' => $reference,
        ]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['success' => false, 'message' => 'Unable to create sharable link. Please try again.'], 500);
    }
}

// Browser capture endpoint after explicit consent. Supports clean URLs and no-rewrite query fallback.
$browserCaptureToken = '';
if (preg_match('#^/api/capture/([a-f0-9]{32})$#', $path, $m)) {
    $browserCaptureToken = $m[1];
} elseif ($route === 'api_capture' && preg_match('/^[a-f0-9]{32}$/', $routeToken)) {
    $browserCaptureToken = $routeToken;
}
if ($browserCaptureToken !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'POST required'], 405);
    }
    $diag = findDiagnosticByPublic($browserCaptureToken);
    if (!$diag) {
        jsonResponse(['success' => false, 'message' => 'Diagnostic link is invalid or expired.'], 404);
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $ipv4 = filter_var($payload['ipv4'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null;
    $ipv6 = filter_var($payload['ipv6'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: null;

    if (!$ipv4 && !$ipv6) {
        jsonResponse(['success' => false, 'message' => 'No valid public IP address was detected.'], 422);
    }
    $observedIp = requestIp();
    if (!$observedIp || !in_array($observedIp, array_filter([$ipv4, $ipv6]), true)) {
        jsonResponse(['success' => false, 'message' => 'The detected IP does not match this request.'], 422);
    }

    $countStmt = db()->prepare('SELECT COUNT(*) FROM captures WHERE diagnostic_id = :id');
    $countStmt->execute([':id' => $diag['id']]);
    if ((int)$countStmt->fetchColumn() >= MAX_CAPTURES_PER_LINK) {
        jsonResponse(['success' => false, 'message' => 'Capture limit reached for this link.'], 429);
    }

    $snapshot = buildNetworkSnapshot($ipv4, $ipv6);
    insertCapture(db(), (int)$diag['id'], 'Browser', $ipv4, $ipv6, $observedIp, $snapshot);

    jsonResponse(['success' => true, 'reference' => $diag['reference_code']]);
}

// curl/server capture endpoint. The act of running curl is the user's explicit action.
$serverCaptureToken = '';
if (preg_match('#^/capture/([a-f0-9]{32})$#', $path, $m)) {
    $serverCaptureToken = $m[1];
} elseif ($route === 'capture' && preg_match('/^[a-f0-9]{32}$/', $routeToken)) {
    $serverCaptureToken = $routeToken;
}
if ($serverCaptureToken !== '') {
    $diag = findDiagnosticByPublic($serverCaptureToken);
    if (!$diag) {
        textResponse("Diagnostic link is invalid or expired.\n", 404);
    }

    $ip = requestIp();
    if (!$ip) {
        textResponse("Unable to detect request IP.\n", 422);
    }

    $countStmt = db()->prepare('SELECT COUNT(*) FROM captures WHERE diagnostic_id = :id');
    $countStmt->execute([':id' => $diag['id']]);
    if ((int)$countStmt->fetchColumn() >= MAX_CAPTURES_PER_LINK) {
        textResponse("Capture limit reached for this link.\n", 429);
    }

    $ipv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    $ipv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $ip : null;
    $snapshot = buildNetworkSnapshot($ipv4, $ipv6);
    insertCapture(db(), (int)$diag['id'], 'curl / Server', $ipv4, $ipv6, $ip, $snapshot);
    $info = $snapshot['info4'] ?: $snapshot['info6'] ?: [];

    $isp = $info['connection']['isp'] ?? 'Unknown';
    $asnLabel = !empty($info['connection']['asn']) ? 'AS' . $info['connection']['asn'] : 'Unknown';
    textResponse("CORNQ Network Diagnostic\nReference: {$diag['reference_code']}\nCaptured IP: {$ip}\nISP: {$isp}\nASN: {$asnLabel}\nStatus: Received\n");
}

// Client-facing diagnostic consent page. Supports clean URLs and no-rewrite query fallback.
$checkToken = '';
if (preg_match('#^/check/([a-f0-9]{32})$#', $path, $m)) {
    $checkToken = $m[1];
} elseif ($route === 'check' && preg_match('/^[a-f0-9]{32}$/', $routeToken)) {
    $checkToken = $routeToken;
}
if ($checkToken !== '') {
    $diag = findDiagnosticByPublic($checkToken);
    if (!$diag) {
        renderHeader('Diagnostic Link Expired', 'This diagnostic link is invalid or expired.', true);
        echo '<div class="narrow"><div class="panel"><h2>Diagnostic link unavailable</h2><p>This link is invalid or has expired. Please generate a new sharable link.</p></div></div>';
        renderFooter();
        exit;
    }

    renderHeader('CORNQ Network Diagnostic', 'Consent-based network diagnostic for CORNQ support troubleshooting.', true);
    $token = h($diag['public_token']);
    $ref = h($diag['reference_code']);
    $expires = h(formatUtcDateTime($diag['expires_at'], 'd M Y, h:i A'));
    echo <<<HTML
<div class="narrow">
  <div class="panel">
    <h2>CORNQ Network Diagnostic</h2>
    <p class="muted">Reference: <strong>{$ref}</strong> &nbsp;•&nbsp; Expires: {$expires}</p>
    <div class="notice">
      To help CORNQ troubleshoot an IP reputation, delisting, connectivity, or server issue, this tool can collect your public IPv4/IPv6 address and related network information such as ISP, ASN, approximate IP-based location, reverse DNS, IX/IXP information, browser user-agent, and capture time.
      <br><br>Nothing is submitted until you click <strong>Share Network Information</strong> below.
    </div>
    <div class="actions"><button id="shareBtn" class="btn primary">Share Network Information</button></div>
    <div id="status" style="text-align:center;margin-top:14px"></div>

    <div class="result-block">
      <h3>Checking a server?</h3>
      <p class="muted">Run these from the affected server. Run both if the server has dual-stack connectivity.</p>
      <div class="codebox">curl -4 'https://myip.cornq.net/capture.php?token={$token}'</div>
      <div class="codebox">curl -6 'https://myip.cornq.net/capture.php?token={$token}'</div>
    </div>
  </div>
</div>
<script>
const btn=document.getElementById('shareBtn');
const statusBox=document.getElementById('status');
async function detect(url, timeout=6000){
  const controller=new AbortController();
  const timer=setTimeout(()=>controller.abort(),timeout);
  try{const r=await fetch(url,{signal:controller.signal,cache:'no-store'});if(!r.ok)throw new Error();return (await r.json()).ip||null;}catch(e){return null;}finally{clearTimeout(timer);}
}
btn.addEventListener('click',async()=>{
  btn.disabled=true; statusBox.innerHTML='<span class="loading">Detecting and sharing network information…</span>';
  const [ipv4,ipv6]=await Promise.all([
    detect('https://api.ipify.org?format=json'),
    detect('https://api6.ipify.org?format=json')
  ]);
  try{
    const r=await fetch('/api-capture.php?token={$token}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ipv4,ipv6})});
    const data=await r.json();
    if(!r.ok||!data.success) throw new Error(data.message||'Unable to submit diagnostic data.');
    statusBox.innerHTML='<div class="alert success">Network information shared successfully.<br>Reference: <strong>'+data.reference+'</strong></div>';
    btn.style.display='none';
  }catch(e){statusBox.innerHTML='<div class="alert error">'+String(e.message||e)+'</div>';btn.disabled=false;}
});
</script>
HTML;
    renderFooter();
    exit;
}

// Legacy generator URLs return visitors to the homepage modal.
if ($path === '/share' || $path === '/share/' || $route === 'share') {
    header('Location: ' . BASE_URL . '/?generate=1');
    exit;
}

// Private result page. Supports clean URLs and no-rewrite query fallback.
$resultKey = '';
if (preg_match('#^/result/([a-f0-9]{48})$#', $path, $m)) {
    $resultKey = $m[1];
} elseif ($route === 'result' && preg_match('/^[a-f0-9]{48}$/', $routeKey)) {
    $resultKey = $routeKey;
}
if ($resultKey !== '') {
    appSession();
    $diag = findDiagnosticByPrivate($resultKey);
    if (!$diag) {
        renderHeader('Diagnostic Result Unavailable', 'Diagnostic result is invalid or expired.', true);
        echo '<div class="narrow"><div class="panel"><h2>Result unavailable</h2><p>This diagnostic result is invalid or has expired.</p></div></div>';
        renderFooter();
        exit;
    }
    $isOwner = isset($_SESSION['owned_diagnostics']) && is_array($_SESSION['owned_diagnostics']) && isset($_SESSION['owned_diagnostics'][$resultKey]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
        $csrf = $_POST['csrf'] ?? null;
        if (!$isOwner || !is_string($csrf) || $csrf === '' || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
            http_response_code(403);
            textResponse("Invalid request token.\n", 403);
        }
        $stmt = db()->prepare('DELETE FROM diagnostics WHERE id = :id');
        $stmt->execute([':id' => $diag['id']]);
        unset($_SESSION['owned_diagnostics'][$resultKey]);
        renderHeader('Diagnostic Deleted', 'The diagnostic report was deleted.', true);
        echo '<div class="narrow"><div class="panel"><div class="alert success">Diagnostic report deleted.</div><a class="btn primary" href="/share.php">Generate Another Sharable Link</a></div></div>';
        renderFooter();
        exit;
    }

    $stmt = db()->prepare('SELECT * FROM captures WHERE diagnostic_id = :id ORDER BY id DESC');
    $stmt->execute([':id' => $diag['id']]);
    $captures = $stmt->fetchAll();

    renderHeader('Diagnostic Result ' . $diag['reference_code'], 'Private CORNQ network diagnostic result.', true);
    echo '<div class="container"><h1>Diagnostic Result</h1><p class="intro">Reference <strong>' . h($diag['reference_code']) . '</strong> • Created ' . h(formatUtcDateTime($diag['created_at'], 'd M Y, h:i A')) . ' • Expires ' . h(formatUtcDateTime($diag['expires_at'], 'd M Y, h:i A')) . '</p>';
    if ($diag['note']) echo '<div class="panel" style="margin-bottom:15px"><strong>Note:</strong> ' . nl2br(h($diag['note'])) . '</div>';
    echo '<div class="toolbar"><a class="btn secondary" href="/share.php">Generate New Sharable Link</a><button class="btn secondary" onclick="copyReport()">Copy Full Report</button>';
    if ($isOwner) echo '<form method="post" onsubmit="return confirm(\'Delete this report permanently?\')" style="display:inline"><input type="hidden" name="delete_report" value="1"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><button class="btn danger" type="submit">Delete Report</button></form>';
    echo '</div>';

    if (!$captures) {
        echo '<div class="panel"><div class="loading">Waiting for client/server diagnostic data…</div></div>';
    } else {
        echo '<div id="reportText">';
        foreach ($captures as $index => $cap) {
            $info4 = json_decode($cap['ipv4_info_json'] ?: 'null', true);
            $info6 = json_decode($cap['ipv6_info_json'] ?: 'null', true);
            $info = $info4 ?: $info6 ?: [];
            $conn = $info['connection'] ?? [];
            $ix = json_decode($cap['ix_json'] ?: '[]', true) ?: [];
            echo '<div class="capture"><h3>Capture #' . h((string)(count($captures)-$index)) . ' — ' . h($cap['source']) . '</h3><table class="result-table">';
            $rows = [
                'Captured' => formatUtcDateTime($cap['captured_at'], 'd M Y, h:i:s A'),
                'IPv4' => $cap['ipv4'] ?: 'Not detected',
                'IPv6' => $cap['ipv6'] ?: 'Not detected',
                'IPv4 PTR' => $cap['ptr4'] ?: 'Not found',
                'IPv6 PTR' => $cap['ptr6'] ?: 'Not found',
                'ISP' => $conn['isp'] ?? 'Unknown',
                'ASN' => !empty($conn['asn']) ? 'AS'.$conn['asn'] : 'Unknown',
                'Organization' => $conn['org'] ?? 'Unknown',
                'ISP Domain' => $conn['domain'] ?? 'Unknown',
                'Country' => trim(($info['country'] ?? '') . (!empty($info['country_code']) ? ' (' . $info['country_code'] . ')' : '')) ?: 'Unknown',
                'City / Region' => implode(', ', array_filter([$info['city'] ?? null, $info['region'] ?? null])) ?: 'Unknown',
                'Timezone' => $info['timezone']['id'] ?? 'Unknown',
                'User-Agent' => $cap['user_agent'] ?: 'Unknown',
            ];
            foreach ($rows as $label => $value) {
                echo '<tr><td>' . h($label) . '</td><td>';
                if (in_array($label, ['IPv4', 'IPv6'], true) && filter_var($value, FILTER_VALIDATE_IP)) {
                    echo '<div class="value-with-copy"><span>' . h($value) . '</span><button class="btn secondary copy-ip-button" type="button" data-copy="' . h($value) . '" onclick="cornqCopyText(this.dataset.copy,this)">Copy</button></div>';
                } else {
                    echo h($value);
                }
                echo '</td></tr>';
            }
            echo '</table>';
            echo '<h4>IX / IXP Peering</h4>';
            if (!$ix) {
                echo '<div class="muted">No PeeringDB IX information found for the detected ASN.</div>';
            } else {
                foreach ($ix as $item) {
                    echo '<div class="ix-item"><div class="ix-name">' . h($item['name'] ?? ('IX #' . ($item['ix_id'] ?? ''))) . '</div>';
                    if (!empty($item['ipaddr4'])) echo '<div class="ix-ip value-with-copy"><span>IPv4: ' . h($item['ipaddr4']) . '</span><button class="btn secondary copy-ip-button" type="button" data-copy="' . h($item['ipaddr4']) . '" onclick="cornqCopyText(this.dataset.copy,this)">Copy</button></div>';
                    if (!empty($item['ipaddr6'])) echo '<div class="ix-ip value-with-copy"><span>IPv6: ' . h($item['ipaddr6']) . '</span><button class="btn secondary copy-ip-button" type="button" data-copy="' . h($item['ipaddr6']) . '" onclick="cornqCopyText(this.dataset.copy,this)">Copy</button></div>';
                    if (!empty($item['speed'])) echo '<div class="label">Port: ' . h(formatPortSpeed($item['speed'])) . '</div>';
                    echo '</div>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div><script>function copyReport(){const el=document.getElementById("reportText");if(!el)return;const clone=el.cloneNode(true);clone.querySelectorAll(".copy-ip-button").forEach(button=>button.remove());cornqCopyText("CORNQ Network Diagnostic\\nReference: ' . h($diag['reference_code']) . '\\n\\n"+clone.innerText);}</script>';
    renderFooter();
    exit;
}

// Main public What's My IP page — preserves all existing features.
appSession();
renderHeader("What's My IP? - IPv4, IPv6, ISP, ASN & IX Lookup", 'Check your public IPv4 and IPv6 address, ISP, ASN, organization, location, timezone and Internet Exchange peering information instantly.');
echo '<div id="shareConfig" data-csrf="' . h($_SESSION['csrf']) . '" hidden></div>';
echo <<<'HTML'
<div class="container">
  <h1>What's My IP?</h1>
  <p class="intro">Check your public IPv4 and IPv6 address, ISP, ASN, network location and Internet Exchange (IX/IXP) information.</p>

  <div class="ip-main">
    <div class="label">Your Public IPv4</div>
    <div id="ipv4" class="main-ip loading">Detecting...</div>
    <div class="actions">
      <button id="copyIp" class="btn secondary" type="button">Copy IPv4</button>
      <button id="shareLinkBtn" class="btn primary" type="button" disabled>Generate Sharable Link</button>
    </div>
    <div id="shareOutput" class="share-output" hidden>
      <div class="label">Shareable Link</div>
      <div id="shareUrlBox" class="codebox"></div>
      <button id="copyShareBox" class="btn secondary" type="button">Copy Link</button>
    </div>
  </div>

  <div class="grid">
    <div class="card"><div class="label">IPv6</div><div class="value-with-copy"><div id="ipv6" class="value loading">Detecting...</div><button id="copyIpv6" class="btn secondary copy-ip-button" type="button" disabled>Copy</button></div></div>
    <div class="card"><div class="label">ISP</div><div id="isp" class="value">-</div></div>
    <div class="card"><div class="label">ASN</div><div id="asn" class="value">-</div></div>
    <div class="card"><div class="label">Organization</div><div id="org" class="value">-</div></div>
    <div class="card"><div class="label">Country</div><div id="country" class="value">-</div></div>
    <div class="card"><div class="label">City / Region</div><div id="location" class="value">-</div></div>
    <div class="card"><div class="label">ISP Domain</div><div id="domain" class="value">-</div></div>
    <div class="card"><div class="label">Timezone</div><div id="timezone" class="value">-</div></div>
    <div class="card full"><div class="label">Internet Exchange / IX Peering IPs</div><div id="ix-list" class="loading">Detecting IX connections...</div></div>
  </div>
</div>

<div id="shareModal" class="modal-backdrop" hidden>
  <div id="shareModalPanel" class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="shareModalTitle">
    <button id="closeShareModal" class="modal-close" type="button" aria-label="Close">&times;</button>
    <h2 id="shareModalTitle">Generate Sharable Link</h2>
    <p class="muted">Generating a link saves your detected network details and browser information until the selected expiry. Anyone with the link can view the saved snapshot.</p>
    <form id="shareForm">
      <label class="form-label" for="shareExpiry">Link expires after</label>
      <select id="shareExpiry" required>
        <option value="1">1 day</option>
        <option value="7" selected>7 days</option>
        <option value="30">30 days</option>
      </select>
      <label class="form-label" for="shareNote">Notes <span class="muted optional-label">(optional)</span></label>
      <textarea id="shareNote" class="input" maxlength="500" rows="4" placeholder="Example: Spamhaus delisting - Ticket #1234"></textarea>
      <div id="shareNoteCounter" class="field-counter">0 / 500</div>
      <div id="shareError" class="alert error" hidden></div>
      <button id="createShareBtn" class="btn primary" type="submit">Generate Sharable Link</button>
    </form>
  </div>
</div>
<script>
const $=id=>document.getElementById(id);
async function detect(url,timeout=6000){const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),timeout);try{const r=await fetch(url,{signal:controller.signal,cache:'no-store'});if(!r.ok)throw new Error();return (await r.json()).ip||null;}catch(e){return null;}finally{clearTimeout(timer)}}
async function getIPInfo(ip){try{const r=await fetch('/?api=info&ip='+encodeURIComponent(ip),{cache:'no-store'});return await r.json()}catch(e){return null}}
async function getIX(asn){try{const r=await fetch('/?api=ix&asn='+encodeURIComponent(asn),{cache:'no-store'});const data=await r.json();return data.data||[]}catch(e){return []}}
function escapeHTML(v){return String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;')}
function formatSpeed(speed){if(speed>=1000000)return(speed/1000000)+' Tbps';if(speed>=1000)return(speed/1000)+' Gbps';return speed+' Mbps'}
function displayInfo(info){if(!info||info.success===false)return;const c=info.connection||{};$('isp').textContent=c.isp||'-';$('asn').textContent=c.asn?'AS'+c.asn:'-';$('org').textContent=c.org||'-';$('domain').textContent=c.domain||'-';$('country').textContent=info.country?(info.country+' ('+info.country_code+')'):'-';$('location').textContent=[info.city,info.region].filter(Boolean).join(', ')||'-';$('timezone').textContent=(info.timezone&&(info.timezone.id||info.timezone.utc))||'-'}
function displayIX(records){const c=$('ix-list');c.classList.remove('loading');if(!records.length){c.innerHTML='<span class="not-available">No IX information found in PeeringDB.</span>';return}c.innerHTML='';records.forEach(ix=>{const item=document.createElement('div');item.className='ix-item';let html='<div class="ix-name">'+escapeHTML(ix.name||('IX #'+(ix.ix_id||'')))+'</div>';if(ix.ipaddr4)html+='<div class="ix-ip value-with-copy"><span>IPv4: '+escapeHTML(ix.ipaddr4)+'</span><button class="btn secondary copy-ip-button" type="button" data-copy="'+escapeHTML(ix.ipaddr4)+'" onclick="cornqCopyText(this.dataset.copy,this)">Copy</button></div>';if(ix.ipaddr6)html+='<div class="ix-ip value-with-copy"><span>IPv6: '+escapeHTML(ix.ipaddr6)+'</span><button class="btn secondary copy-ip-button" type="button" data-copy="'+escapeHTML(ix.ipaddr6)+'" onclick="cornqCopyText(this.dataset.copy,this)">Copy</button></div>';if(ix.speed)html+='<div class="label">Port: '+formatSpeed(ix.speed)+'</div>';item.innerHTML=html;c.appendChild(item)})}
let detectedIPv4=null,detectedIPv6=null;
async function load(){const [ipv4,ipv6]=await Promise.all([detect('https://api.ipify.org?format=json'),detect('https://api6.ipify.org?format=json')]);detectedIPv4=ipv4;detectedIPv6=ipv6;$('ipv4').textContent=ipv4||'Not available';$('ipv4').classList.remove('loading');$('ipv6').textContent=ipv6||'No IPv6 detected';$('ipv6').classList.remove('loading');$('copyIpv6').disabled=!ipv6;const lookupIP=ipv4||ipv6;if(!lookupIP){$('ix-list').textContent='Unable to detect connection.';return}$('shareLinkBtn').disabled=false;const info=await getIPInfo(lookupIP);displayInfo(info);if(info&&info.connection&&info.connection.asn)displayIX(await getIX(info.connection.asn));else $('ix-list').textContent='ASN unavailable.'}
$('copyIp').addEventListener('click',function(){const ip=$('ipv4').textContent.trim();if(ip&&ip!=='Detecting...'&&ip!=='Not available'){cornqCopyText(ip,this);}});
$('copyIpv6').addEventListener('click',function(){const ip=$('ipv6').textContent.trim();if(ip&&ip!=='Detecting...'&&ip!=='No IPv6 detected'){cornqCopyText(ip,this);}});
let generatedShareUrl='';
const shareModal=$('shareModal');
const shareModalPanel=$('shareModalPanel');
function openShareModal(){shareModal.hidden=false;$('shareError').hidden=true;$('shareExpiry').focus()}
function closeShareModal(){shareModal.hidden=true}
function shakeShareModal(){shareModalPanel.classList.remove('modal-shake');void shareModalPanel.offsetWidth;shareModalPanel.classList.add('modal-shake')}
$('shareLinkBtn').addEventListener('click',function(){if(generatedShareUrl){cornqCopyText(generatedShareUrl,this)}else{openShareModal()}});
$('copyShareBox').addEventListener('click',function(){if(generatedShareUrl)cornqCopyText(generatedShareUrl,this)});
$('closeShareModal').addEventListener('click',closeShareModal);
shareModal.addEventListener('click',e=>{if(e.target===shareModal)shakeShareModal()});
shareModalPanel.addEventListener('animationend',()=>shareModalPanel.classList.remove('modal-shake'));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!shareModal.hidden){e.preventDefault();shakeShareModal()}});
$('shareNote').addEventListener('input',function(){$('shareNoteCounter').textContent=this.value.length+' / 500'});
$('shareForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const submit=$('createShareBtn');const error=$('shareError');submit.disabled=true;submit.textContent='Generating...';error.hidden=true;
  try{
    const r=await fetch('/?api=create_share',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:$('shareConfig').dataset.csrf,note:$('shareNote').value,expiry_days:$('shareExpiry').value,ipv4:detectedIPv4,ipv6:detectedIPv6})});
    const data=await r.json();if(!r.ok||!data.success)throw new Error(data.message||'Unable to create sharable link.');
    generatedShareUrl=data.share_url;$('shareUrlBox').textContent=generatedShareUrl;$('shareOutput').hidden=false;$('shareLinkBtn').textContent='Copy Shareable Link';closeShareModal();
  }catch(err){error.textContent=String(err.message||err);error.hidden=false}
  finally{submit.disabled=false;submit.textContent='Generate Sharable Link'}
});
if(new URLSearchParams(location.search).get('generate')==='1'){openShareModal();history.replaceState({},'',location.pathname)}
load();
</script>
HTML;
renderFooter();
