<?php
/**
 * frontend/img_proxy.php
 *
 * Server-side image proxy that fetches external vehicle images and re-serves
 * them to the browser from the same origin, bypassing hotlink protection on
 * Pinterest, Google gstatic thumbnails, Hearst, etc.
 *
 * Usage:  <img src="/Spin_Go/frontend/img_proxy.php?url=https://...">
 *
 * Security:
 *  - Only http/https URLs are allowed.
 *  - Only image/* MIME responses are forwarded; everything else is a 400.
 *  - An allowlist of trusted domains blocks SSRF attacks against localhost/intranet.
 *  - Max response size: 10 MB.
 *  - Served with a 24-hour browser cache header to minimize round-trips.
 */

// ── Allowed external image domains (SSRF allowlist) ──────────────────────────
const ALLOWED_HOSTS = [
    'images.unsplash.com',
    'upload.wikimedia.org',
    'i.pinimg.com',
    'encrypted-tbn0.gstatic.com',
    'encrypted-tbn1.gstatic.com',
    'encrypted-tbn2.gstatic.com',
    'encrypted-tbn3.gstatic.com',
    'hips.hearstapps.com',
    'i.imgur.com',
    'live.staticflickr.com',
    'cdn.pixabay.com',
    'images.pexels.com',
    'media.istockphoto.com',
    'img.freepik.com',
];

const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

// ── Validate the requested URL ────────────────────────────────────────────────
$rawUrl = $_GET['url'] ?? '';

if (empty($rawUrl)) {
    http_response_code(400);
    exit('Missing url parameter');
}

// Decode any double-encoding
$url = urldecode($rawUrl);

// Only allow http/https
$scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('Invalid URL scheme');
}

// Check host against allowlist
$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
$allowed = false;
foreach (ALLOWED_HOSTS as $allowedHost) {
    if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Domain not in allowlist: ' . htmlspecialchars($host));
}

// ── Fetch the image via cURL ──────────────────────────────────────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_REFERER        => $scheme . '://' . $host . '/',  // spoof same-site referer
    CURLOPT_HTTPHEADER     => [
        'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
    ],
    CURLOPT_BUFFERSIZE     => 131072,   // 128 KB read chunks
]);

$imageData = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$mime      = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlErr   = curl_error($ch);
curl_close($ch);

// ── Validate response ─────────────────────────────────────────────────────────
if ($curlErr || $imageData === false) {
    http_response_code(502);
    exit('Fetch error: ' . htmlspecialchars($curlErr));
}

if ($httpCode < 200 || $httpCode >= 400) {
    http_response_code(502);
    exit('Upstream returned HTTP ' . $httpCode);
}

// Strip parameters from MIME type  (e.g. "image/jpeg; charset=utf-8" → "image/jpeg")
$mimeClean = strtolower(trim(explode(';', $mime)[0]));

// Only allow actual image types
$allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'image/avif'];
if (!in_array($mimeClean, $allowedMimes, true)) {
    http_response_code(400);
    exit('Upstream returned non-image MIME: ' . htmlspecialchars($mimeClean));
}

// Enforce size cap
if (strlen($imageData) > MAX_BYTES) {
    http_response_code(413);
    exit('Image too large');
}

// ── Serve the image ───────────────────────────────────────────────────────────
header('Content-Type: '  . $mimeClean);
header('Content-Length: ' . strlen($imageData));
header('Cache-Control: public, max-age=86400, stale-while-revalidate=3600'); // 24h cache
header('X-Proxy-Status: OK');
header('X-Content-Type-Options: nosniff');

echo $imageData;
