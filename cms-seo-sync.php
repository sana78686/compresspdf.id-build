<?php
/**
 * Receives robots.txt / sitemap.xml / cms-home-seo.json from the Global CMS (POST) and writes them next to this file
 * (public web root). Set the same secret as FRONTEND_SEO_SYNC_SECRET in the CMS .env:
 *   SetEnv CMS_SEO_SYNC_SECRET "your-long-random-string"
 * or in .htaccess / hosting panel.
 *
 * After a successful write, Apache serves the real files first (see public/.htaccess), so
 * https://yoursite.com/robots.txt matches the CMS canonical copy.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$expected = getenv('CMS_SEO_SYNC_SECRET') ?: '';
if ($expected === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'CMS_SEO_SYNC_SECRET is not set on this host']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$secret = isset($_POST['secret']) ? (string) $_POST['secret'] : '';
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$content = isset($_POST['content']) ? (string) $_POST['content'] : '';

if (! hash_equals($expected, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
}

if ($action !== 'robots' && $action !== 'sitemap' && $action !== 'home_seo') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid action']);
    exit;
}

$maxBytes = 6 * 1024 * 1024;
if (strlen($content) > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Content too large']);
    exit;
}

if ($action === 'home_seo') {
    if ($content === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Empty JSON for home_seo']);
        exit;
    }
    json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid JSON for home_seo']);
        exit;
    }
    $file = 'cms-home-seo.json';
} else {
    $file = $action === 'robots' ? 'robots.txt' : 'sitemap.xml';
}

$path = __DIR__ . DIRECTORY_SEPARATOR . $file;

if (@file_put_contents($path, $content) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not write file (check permissions on public/)']);
    exit;
}

echo json_encode(['ok' => true, 'file' => $file]);
