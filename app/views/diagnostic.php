<?php

renderHeader('MyIP Network Diagnostic', 'Consent-based network diagnostic for CORNQ support troubleshooting.', true);
    $token = h($diag['public_token']);
    $ref = h($diag['reference_code']);
    $expires = h(formatUtcDateTime($diag['expires_at'], 'd M Y, h:i A'));
    echo <<<HTML
<div class="narrow">
  <div class="panel">
    <h2>MyIP Network Diagnostic</h2>
    <p class="muted">Reference: <strong>{$ref}</strong> &nbsp;•&nbsp; Expires: {$expires}</p>
    <div class="notice">
      To help CORNQ troubleshoot an IP reputation, delisting, connectivity, or server issue, this tool can collect your public IPv4/IPv6 address and related network information such as ISP, ASN, approximate IP-based location, reverse DNS, IX/IXP information, browser user-agent, and capture time.
      <br><br>Nothing is submitted until you click <strong>Share Network Information</strong> below.
    </div>
    <div id="diagnosticConfig" data-endpoint="/api-capture.php?token={$token}" hidden></div>
    <div class="actions"><button id="shareBtn" class="btn primary" type="button">Share Network Information</button></div>
    <div id="status" class="diagnostic-status"></div>

    <div class="result-block">
      <h3>Checking a server?</h3>
      <p class="muted">Run these from the affected server. Run both if the server has dual-stack connectivity.</p>
      <div class="codebox">curl -4 'https://myip.cornq.net/capture.php?token={$token}'</div>
      <div class="codebox">curl -6 'https://myip.cornq.net/capture.php?token={$token}'</div>
    </div>
  </div>
</div>
HTML;
    renderFooter(['diagnostic']);
