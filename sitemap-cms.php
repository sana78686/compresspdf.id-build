<?php
/**
 * Live sitemap.xml from CMS (same as https://app…/{domain}/sitemap.xml).
 * .htaccess routes /sitemap.xml here so the sitemap updates when CMS content changes.
 */
declare(strict_types=1);

$CMS_API_BASE = getenv('CMS_PUBLIC_API_BASE') ?: 'https://app.apimstec.com';
$CMS_API_BASE = rtrim($CMS_API_BASE, '/');

$host = $_SERVER['HTTP_HOST'] ?? 'compresspdf.id';
$host = preg_replace('/:\d+$/', '', strtolower(trim((string) $host)));
$host = preg_replace('/^www\./', '', $host);

$cmsUrl = $CMS_API_BASE . '/' . $host . '/sitemap.xml';

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 12,
        'ignore_errors' => true,
        'header' => "Accept: application/xml,text/xml,*/*\r\nUser-Agent: compresspdf-seo-proxy/sitemap\r\n",
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

if ($body === false || $status >= 400) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=120');
    $today = gmdate('Y-m-d');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '  <url><loc>' . htmlspecialchars($origin . '/', ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><lastmod>' . $today . '</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>' . "\n";
    echo '</urlset>';
    exit;
}

$raw = (string) $body;
$head = strtolower(substr(trim($raw), 0, 80));
if (str_contains($head, '<!doctype') || str_contains($head, '<html')) {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=120');
    $today = gmdate('Y-m-d');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '  <url><loc>' . htmlspecialchars($origin . '/', ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><lastmod>' . $today . '</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>' . "\n";
    echo '</urlset>';
    exit;
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');
echo $raw;
