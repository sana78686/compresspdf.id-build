<?php
/**
 * Live robots.txt from CMS (same as https://app…/{domain}/robots.txt).
 * .htaccess routes /robots.txt here so edits in the CMS show without rebuilding.
 */
declare(strict_types=1);

$CMS_API_BASE = getenv('CMS_PUBLIC_API_BASE') ?: 'https://app.apimstec.com';
$CMS_API_BASE = rtrim($CMS_API_BASE, '/');

$host = $_SERVER['HTTP_HOST'] ?? 'compresspdf.id';
$host = preg_replace('/:\d+$/', '', strtolower(trim((string) $host)));
$host = preg_replace('/^www\./', '', $host);

$cmsUrl = $CMS_API_BASE . '/' . $host . '/robots.txt';

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => "Accept: text/plain,*/*\r\nUser-Agent: compresspdf-seo-proxy/robots\r\n",
    ],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
]);

$body = @file_get_contents($cmsUrl, false, $ctx);
$status = 0;
if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
            break;
        }
    }
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? $host);

if ($body === false || $status >= 400 || strlen(trim((string) $body)) < 3) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=120');
    echo "User-agent: *\nAllow: /\n\nSitemap: {$origin}/sitemap.xml\n";
    exit;
}

$text = (string) $body;
if (stripos($text, '<!doctype') !== false || stripos($text, '<html') !== false) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=120');
    echo "User-agent: *\nAllow: /\n\nSitemap: {$origin}/sitemap.xml\n";
    exit;
}

$cmsHost = '';
$parsed = @parse_url($CMS_API_BASE);
if (is_array($parsed) && !empty($parsed['host'])) {
    $cmsHost = strtolower((string) $parsed['host']);
}
if ($cmsHost !== '') {
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $out = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*Sitemap:\s*(\S+)/i', $line, $m)) {
            $loc = $m[1];
            $pu = @parse_url($loc);
            if (is_array($pu) && !empty($pu['host']) && strcasecmp((string) $pu['host'], $cmsHost) === 0
                && !empty($pu['path']) && preg_match('/sitemap\.xml$/i', (string) $pu['path'])) {
                $out[] = 'Sitemap: ' . $origin . '/sitemap.xml';
                continue;
            }
        }
        $out[] = $line;
    }
    $text = implode("\n", $out);
}

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=300');
echo $text;
