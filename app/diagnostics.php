<?php

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
