<?php

renderHeader('Diagnostic Result ' . $diag['reference_code'], 'Private MyIP network diagnostic result.', true);
    echo '<div id="resultConfig" data-reference="' . h($diag['reference_code']) . '" hidden></div><div class="container"><h1>Diagnostic Result</h1><p class="intro result-meta">Reference <strong>' . h($diag['reference_code']) . '</strong> • Created ' . h(formatUtcDateTime($diag['created_at'], 'd M Y, h:i A')) . ' • Expires ' . h(formatUtcDateTime($diag['expires_at'], 'd M Y, h:i A')) . '</p>';
    if ($diag['note']) echo '<div class="panel result-note"><strong>Note:</strong> ' . nl2br(h($diag['note'])) . '</div>';
    echo '<div class="toolbar"><a class="btn secondary" href="/share.php">Check My IP</a><button id="copyReportButton" class="btn secondary" type="button">Copy Full Report</button>';
    if ($isOwner) echo '<form id="deleteReportForm" class="inline-form" method="post"><input type="hidden" name="delete_report" value="1"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><button class="btn danger" type="submit">Delete Report</button></form>';
    echo '</div>';

    if (!$captures) {
        echo '<div class="panel"><div class="loading">Waiting for client/server diagnostic data…</div></div>';
    } else {
        echo '<div id="reportText">';
        foreach ($captures as $cap) {
            $info4 = json_decode($cap['ipv4_info_json'] ?: 'null', true);
            $info6 = json_decode($cap['ipv6_info_json'] ?: 'null', true);
            $info = $info4 ?: $info6 ?: [];
            $conn = $info['connection'] ?? [];
            $ix = json_decode($cap['ix_json'] ?: '[]', true) ?: [];
            echo '<div class="capture"><h3>Capture - ' . h($cap['source']) . '</h3><table class="result-table">';
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
                    echo '<div class="value-with-copy"><span>' . h($value) . '</span><button class="btn secondary copy-ip-button" type="button" data-copy="' . h($value) . '">Copy</button></div>';
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
                    if (!empty($item['ipaddr4'])) echo '<div class="ix-ip value-with-copy"><span>IPv4: ' . h($item['ipaddr4']) . '</span><button class="btn secondary copy-ip-button" type="button" data-copy="' . h($item['ipaddr4']) . '">Copy</button></div>';
                    if (!empty($item['ipaddr6'])) echo '<div class="ix-ip value-with-copy"><span>IPv6: ' . h($item['ipaddr6']) . '</span><button class="btn secondary copy-ip-button" type="button" data-copy="' . h($item['ipaddr6']) . '">Copy</button></div>';
                    if (!empty($item['speed'])) echo '<div class="label port-speed">Port: ' . h(formatPortSpeed($item['speed'])) . '</div>';
                    echo '</div>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
    renderFooter(['result']);
