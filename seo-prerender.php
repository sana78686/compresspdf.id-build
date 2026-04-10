<?php
/**
 * SEO Prerender — injects correct meta tags into the SPA shell before serving.
 *
 * Since React SPAs serve one index.html for all routes, view-source always shows
 * generic meta. This script fetches the correct CMS data for the current URL and
 * injects title, description, robots, canonical, OG, and twitter tags so that:
 *   - Social media crawlers see correct previews
 *   - SEO audit tools see per-page meta
 *   - view-source shows the right tags
 *
 * Place in the same directory as index.html. Apache .htaccess routes non-file
 * requests here instead of directly to index.html.
 */

// ── Config ──────────────────────────────────────────────────────────────────
$CMS_API_BASE  = 'https://app.apimstec.com';
$SITE_DOMAIN   = $_SERVER['HTTP_HOST'] ?? 'compresspdf.id';
$SITE_DOMAIN   = preg_replace('/:\d+$/', '', strtolower(trim($SITE_DOMAIN)));
$SITE_ORIGIN   = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$CACHE_DIR     = __DIR__ . '/_seo_cache';
$CACHE_TTL     = 300; // 5 minutes

// ── Read the SPA shell ──────────────────────────────────────────────────────
$indexPath = __DIR__ . '/index.html';
if (!file_exists($indexPath)) {
    http_response_code(500);
    echo 'index.html not found';
    exit;
}
$html = file_get_contents($indexPath);

// ── Parse route ─────────────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . ltrim($path, '/');

$locale = 'en';
if (preg_match('#^/([a-z]{2})(?:/|$)#', $path, $m)) {
    $locale = $m[1];
}

$routeType = 'home';
$slug      = '';

if (preg_match('#^/[a-z]{2}/blog/([^/?]+)#', $path, $m)) {
    $routeType = 'blog';
    $slug = urldecode($m[1]);
} elseif (preg_match('#^/[a-z]{2}/blog/?$#', $path)) {
    $routeType = 'blog-list';
} elseif (preg_match('#^/[a-z]{2}/page/([^/?]+)#', $path, $m)) {
    $routeType = 'page';
    $slug = urldecode($m[1]);
} elseif (preg_match('#^/[a-z]{2}/legal/([^/?]+)#', $path, $m)) {
    $routeType = 'legal';
    $slug = urldecode($m[1]);
} elseif (preg_match('#^/[a-z]{2}/contact#', $path)) {
    $routeType = 'contact';
} elseif (preg_match('#^/[a-z]{2}/compress#', $path)) {
    $routeType = 'tool';
} elseif (preg_match('#^/[a-z]{2}/tools#', $path)) {
    $routeType = 'tools';
} elseif (preg_match('#^/[a-z]{2}/?$#', $path) || $path === '/') {
    $routeType = 'home';
}

// ── Build API URL ───────────────────────────────────────────────────────────
$apiPath = '';
switch ($routeType) {
    case 'home':
        $apiPath = '/home-content';
        break;
    case 'blog':
        $apiPath = '/blogs/' . rawurlencode($slug);
        break;
    case 'page':
        $apiPath = '/pages/' . rawurlencode($slug);
        break;
    case 'legal':
        $apiPath = '/legal/' . rawurlencode($slug);
        break;
    default:
        $apiPath = '';
}

// ── Fetch CMS data ─────────────────────────────────────────────────────────
$meta = null;
if ($apiPath !== '') {
    $meta = fetchCmsData($CMS_API_BASE, $SITE_DOMAIN, $apiPath, $locale, $CACHE_DIR, $CACHE_TTL);
}

// ── Build meta tags ─────────────────────────────────────────────────────────
$tags = buildMetaTags($routeType, $meta, $locale, $SITE_ORIGIN, $path);

// ── Inject into HTML ────────────────────────────────────────────────────────
$html = injectMetaIntoHtml($html, $tags);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
exit;


// ═══════════════════════════════════════════════════════════════════════════
// Functions
// ═══════════════════════════════════════════════════════════════════════════

function fetchCmsData(string $apiBase, string $domain, string $apiPath, string $locale, string $cacheDir, int $ttl): ?array
{
    $cacheKey = md5($domain . $apiPath . $locale);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $url = rtrim($apiBase, '/') . '/' . $domain . '/api/public' . $apiPath . '?locale=' . urlencode($locale);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Accept: application/json\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $fallbackUrl = rtrim($apiBase, '/') . '/api/public' . $apiPath . '?locale=' . urlencode($locale);
        $ctxFallback = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Accept: application/json\r\nX-Domain: {$domain}\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($fallbackUrl, false, $ctxFallback);
    }

    if ($body === false) return null;

    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($data), LOCK_EX);

    return $data;
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function plainText(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

function buildMetaTags(string $routeType, ?array $data, string $locale, string $origin, string $path): array
{
    $tags = [
        'title'       => '',
        'description' => '',
        'robots'      => 'index, follow',
        'canonical'   => $origin . $path,
        'og_title'    => '',
        'og_desc'     => '',
        'og_image'    => '',
        'og_type'     => 'website',
        'keywords'    => '',
    ];

    if (!$data) {
        switch ($routeType) {
            case 'blog-list':
                $tags['title'] = 'Blog';
                $tags['description'] = 'Latest articles and guides.';
                break;
            case 'contact':
                $tags['title'] = 'Contact Us';
                break;
            case 'tool':
                $tags['title'] = 'Compress PDF';
                $tags['description'] = 'Compress PDF files online for free.';
                break;
            case 'tools':
                $tags['title'] = 'All Tools';
                break;
        }
        $tags['og_title'] = $tags['title'];
        $tags['og_desc']  = $tags['description'];
        return $tags;
    }

    switch ($routeType) {
        case 'home':
            $tags['title']       = trim($data['meta_title'] ?? '');
            $tags['description'] = trim($data['meta_description'] ?? '');
            $tags['keywords']    = trim($data['meta_keywords'] ?? '');
            $tags['robots']      = trim($data['meta_robots'] ?? '') ?: 'index, follow';
            $tags['og_title']    = trim($data['og_title'] ?? '') ?: $tags['title'];
            $tags['og_desc']     = trim($data['og_description'] ?? '') ?: $tags['description'];
            $tags['og_image']    = trim($data['og_image'] ?? '');
            if (!empty($data['canonical_url'])) {
                $tags['canonical'] = trim($data['canonical_url']);
            }
            break;

        case 'blog':
            $tags['og_type']     = 'article';
            $tags['title']       = trim($data['meta_title'] ?? $data['title'] ?? '');
            $desc = trim($data['meta_description'] ?? '');
            if (!$desc && !empty($data['excerpt'])) $desc = trim($data['excerpt']);
            if (!$desc && !empty($data['content']))  $desc = mb_substr(plainText($data['content']), 0, 160);
            $tags['description'] = $desc;
            $tags['keywords']    = trim($data['meta_keywords'] ?? '');
            $tags['robots']      = trim($data['meta_robots'] ?? '') ?: 'index, follow';
            $tags['og_title']    = trim($data['og_title'] ?? '') ?: $tags['title'];
            $tags['og_desc']     = trim($data['og_description'] ?? '') ?: $tags['description'];
            $tags['og_image']    = trim($data['og_image'] ?? $data['image'] ?? '');
            if (!empty($data['canonical_url'])) {
                $tags['canonical'] = trim($data['canonical_url']);
            }
            break;

        case 'page':
            $tags['title']       = trim($data['meta_title'] ?? $data['title'] ?? '');
            $desc = trim($data['meta_description'] ?? '');
            if (!$desc && !empty($data['content'])) $desc = mb_substr(plainText($data['content']), 0, 160);
            $tags['description'] = $desc;
            $tags['keywords']    = trim($data['meta_keywords'] ?? '');
            $tags['robots']      = trim($data['meta_robots'] ?? '') ?: 'index, follow';
            $tags['og_title']    = trim($data['og_title'] ?? '') ?: $tags['title'];
            $tags['og_desc']     = trim($data['og_description'] ?? '') ?: $tags['description'];
            $tags['og_image']    = trim($data['og_image'] ?? '');
            if (!empty($data['canonical_url'])) {
                $tags['canonical'] = trim($data['canonical_url']);
            }
            break;

        case 'legal':
            $tags['title']       = trim($data['title'] ?? '');
            if (!empty($data['content'])) {
                $tags['description'] = mb_substr(plainText($data['content']), 0, 160);
            }
            $tags['og_title'] = $tags['title'];
            $tags['og_desc']  = $tags['description'];
            break;
    }

    return $tags;
}

function injectMetaIntoHtml(string $html, array $tags): string
{
    $title = esc($tags['title']);
    $html = preg_replace('/<title>[^<]*<\/title>/', '<title>' . $title . '</title>', $html);

    $robotsEsc = esc($tags['robots']);
    $html = preg_replace(
        '/<meta\s+name="robots"\s+content="[^"]*"\s*\/?>/',
        '<meta name="robots" content="' . $robotsEsc . '" />',
        $html
    );

    $ogTypeEsc = esc($tags['og_type']);
    $html = preg_replace(
        '/<meta\s+property="og:type"\s+content="[^"]*"\s*\/?>/',
        '<meta property="og:type" content="' . $ogTypeEsc . '" />',
        $html
    );

    $inject = [];
    if ($tags['title'])       $inject[] = '<meta name="title" content="' . esc($tags['title']) . '" />';
    if ($tags['description']) $inject[] = '<meta name="description" content="' . esc($tags['description']) . '" />';
    if ($tags['keywords'])    $inject[] = '<meta name="keywords" content="' . esc($tags['keywords']) . '" />';
    if ($tags['canonical'])   $inject[] = '<link rel="canonical" href="' . esc($tags['canonical']) . '" />';
    if ($tags['og_title'])    $inject[] = '<meta property="og:title" content="' . esc($tags['og_title']) . '" />';
    if ($tags['og_desc'])     $inject[] = '<meta property="og:description" content="' . esc($tags['og_desc']) . '" />';
    if ($tags['og_image'])    $inject[] = '<meta property="og:image" content="' . esc($tags['og_image']) . '" />';
    $inject[] = '<meta property="og:url" content="' . esc($tags['canonical']) . '" />';

    if ($tags['og_title'])    $inject[] = '<meta name="twitter:title" content="' . esc($tags['og_title']) . '" />';
    if ($tags['og_desc'])     $inject[] = '<meta name="twitter:description" content="' . esc($tags['og_desc']) . '" />';
    if ($tags['og_image'])    $inject[] = '<meta name="twitter:image" content="' . esc($tags['og_image']) . '" />';

    if (!empty($inject)) {
        $block = '    ' . implode("\n    ", $inject);
        $html = str_replace('</head>', $block . "\n  </head>", $html);
    }

    return $html;
}
