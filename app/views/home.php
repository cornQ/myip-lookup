<?php

renderHeader("What's My IP? - IPv4, IPv6, ISP, ASN & IX Lookup", 'Check your public IPv4 and IPv6 address, ISP, ASN, organization, location, timezone and Internet Exchange peering information instantly.');
echo '<div id="shareConfig" data-csrf="' . h($_SESSION['csrf']) . '" data-info-endpoint="/?api=info&amp;ip=" data-ix-endpoint="/?api=ix&amp;asn=" data-create-endpoint="/?api=create_share" hidden></div>';
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
      <div class="share-link-box codebox">
        <span id="shareUrlBox"></span>
        <button id="copyShareBox" class="share-link-copy" type="button" data-icon-button="true" aria-label="Copy shareable link" title="Copy shareable link">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <rect x="9" y="9" width="11" height="11" rx="2"></rect>
            <path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path>
          </svg>
          <span class="copy-feedback" aria-hidden="true">Copied</span>
        </button>
      </div>
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
HTML;
renderFooter(['home']);
