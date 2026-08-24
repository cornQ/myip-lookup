<?php

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
