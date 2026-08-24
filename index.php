<?php
require __DIR__ . '/app/bootstrap.php';

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
            'share_url' => rtrim(BASE_URL, '/') . '/result/' . $private,
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
    textResponse("MyIP Network Diagnostic\nReference: {$diag['reference_code']}\nCaptured IP: {$ip}\nISP: {$isp}\nASN: {$asnLabel}\nStatus: Received\n");
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

    require APP_ROOT . '/app/views/diagnostic.php';
    exit;
}

// Legacy generator URLs return visitors to the homepage.
if ($path === '/share' || $path === '/share/' || $route === 'share') {
    header('Location: ' . BASE_URL . '/');
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

    require APP_ROOT . '/app/views/result.php';
    exit;
}

// Main public What's My IP page — preserves all existing features.
appSession();
require APP_ROOT . '/app/views/home.php';
