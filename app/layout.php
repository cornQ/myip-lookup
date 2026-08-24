<?php

function renderHeader(string $title = "What's My IP?", string $description = 'Check your public IPv4 and IPv6 address, ISP, ASN, location and Internet Exchange information.', bool $noindex = false): void {
    $fullTitle = h($title . ' | MyIP by CORNQ');
    $desc = h($description);
    $canonical = h(rtrim(BASE_URL, '/') . '/');
    $robots = $noindex ? 'noindex, nofollow, noarchive' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $stylesheetUrl = h(assetUrl('assets/css/app.css'));
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
<meta name="application-name" content="MyIP by CORNQ">
<meta name="robots" content="{$robots}">
<meta name="googlebot" content="{$robots}">
<meta name="referrer" content="no-referrer">
<link rel="canonical" href="{$canonical}">
<meta property="og:type" content="website">
<meta property="og:title" content="{$fullTitle}">
<meta property="og:description" content="{$desc}">
<meta property="og:url" content="{$canonical}">
<meta property="og:site_name" content="MyIP by CORNQ">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{$fullTitle}">
<meta name="twitter:description" content="{$desc}">
<meta name="theme-color" content="#182c45">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebApplication",
  "name": "MyIP by CORNQ",
  "url": "{$canonical}",
  "description": "A free IP and network information lookup tool that displays public IPv4, IPv6, ISP, ASN, organization, location, timezone and Internet Exchange peering information.",
  "applicationCategory": "UtilityApplication",
  "operatingSystem": "Any",
  "isAccessibleForFree": true,
  "provider": {"@type":"Organization","name":"CORNQ","url":"https://cornq.com/"},
  "featureList": ["Public IPv4 detection","Public IPv6 detection","ISP lookup","ASN lookup","Network organization lookup","Country lookup","City and region lookup","Timezone lookup","Internet Exchange lookup","IXP peering IPv4 lookup","IXP peering IPv6 lookup","Consent-based sharable diagnostic links"]
}
</script>
<link rel="stylesheet" href="{$stylesheetUrl}">
</head>
<body>
<header class="site-header">
  <div class="site-header-inner">
    <a class="brand" href="/" aria-label="MyIP home">
      <span class="product-name">MyIP</span>
      <span class="brand-attribution">by CORNQ</span>
    </a>
    <span class="header-tagline">Public IP &amp; network lookup</span>
  </div>
</header>
HTML;
}

function renderFooter(array $pageScripts = []): void {
    $year = date('Y');
    echo '<footer class="footer"><span>&copy; ' . h($year) . ' IP &amp; Network Lookup Tool powered by </span><a href="https://cornq.com/" target="_blank" rel="noopener noreferrer"><strong>CORNQ</strong></a></footer>';

    foreach (array_merge(['app'], $pageScripts) as $script) {
        if (!preg_match('/^[a-z0-9-]+$/', $script)) {
            continue;
        }
        echo '<script src="' . h(assetUrl('assets/js/' . $script . '.js')) . '"></script>';
    }

    echo '</body></html>';
}
