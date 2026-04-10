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

try {

// ── Parse route ─────────────────────────────────────────────────────────────
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path = '/' . ltrim($path, '/');

$DEFAULT_LOCALE = 'id';
$locale = $DEFAULT_LOCALE;
$routeSuffix = $path;

if (preg_match('#^/([a-z]{2})(?:/(.*))?$#', $path, $m) && isset($m[1]) && $m[1] !== $DEFAULT_LOCALE) {
    $locale = $m[1];
    $routeSuffix = '/' . (isset($m[2]) ? $m[2] : '');
} elseif (preg_match('#^/id(?:/(.*))?$#', $path, $m)) {
    $routeSuffix = '/' . (isset($m[1]) ? $m[1] : '');
}

$routeType = 'home';
$slug      = '';

if (preg_match('#^/blog/([^/?]+)#', $routeSuffix, $m)) {
    $routeType = 'blog';
    $slug = urldecode($m[1]);
} elseif (preg_match('#^/blog/?$#', $routeSuffix)) {
    $routeType = 'blog-list';
} elseif (preg_match('#^/page/([^/?]+)#', $routeSuffix, $m)) {
    $routeType = 'page';
    $slug = urldecode($m[1]);
} elseif (preg_match('#^/legal/([^/?]+)#', $routeSuffix, $m)) {
    $routeType = 'legal';
    $slug = urldecode($m[1]);
} elseif (preg_match('#^/contact#', $routeSuffix)) {
    $routeType = 'contact';
} elseif (preg_match('#^/compress#', $routeSuffix)) {
    $routeType = 'tool';
} elseif (preg_match('#^/tools#', $routeSuffix)) {
    $routeType = 'tools';
} elseif ($routeSuffix === '/' || $routeSuffix === '') {
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

// ── Debug mode ──────────────────────────────────────────────────────────────
if (!empty($_GET['_seo_debug'])) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array(
        'route_type' => $routeType,
        'locale'     => $locale,
        'slug'       => $slug,
        'api_path'   => $apiPath,
        'api_data'   => $meta,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Build & inject meta tags ────────────────────────────────────────────────
$tags = buildMetaTags($routeType, $meta, $locale, $SITE_ORIGIN, $path);
$html = injectMetaIntoHtml($html, $tags);

} catch (Exception $e) {
    // On any error, serve the raw SPA shell so the page never breaks
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
exit;


// ═══════════════════════════════════════════════════════════════════════════
// Functions
// ═══════════════════════════════════════════════════════════════════════════

function fetchCmsData($apiBase, $domain, $apiPath, $locale, $cacheDir, $ttl)
{
    $cacheKey = md5($domain . $apiPath . $locale);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && !isset($cached['_fetch_error'])) return $cached;
    }

    $urls = array(
        array(
            'url'    => rtrim($apiBase, '/') . '/' . $domain . '/api/public' . $apiPath . '?locale=' . urlencode($locale),
            'header' => "Accept: application/json\r\n",
        ),
        array(
            'url'    => rtrim($apiBase, '/') . '/api/public' . $apiPath . '?locale=' . urlencode($locale),
            'header' => "Accept: application/json\r\nX-Domain: {$domain}\r\n",
        ),
    );

    $data = null;
    foreach ($urls as $attempt) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method'        => 'GET',
                'header'        => $attempt['header'],
                'timeout'       => 5,
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));

        $http_response_header = array();
        $body = @file_get_contents($attempt['url'], false, $ctx);

        $status = httpStatusFromHeaders($http_response_header);

        if ($body === false || $status >= 400) {
            continue;
        }

        $parsed = json_decode($body, true);
        if (is_array($parsed) && !isset($parsed['message'])) {
            $data = $parsed;
            break;
        }
    }

    if (!$data) return null;

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($data), LOCK_EX);

    return $data;
}

function httpStatusFromHeaders($headers)
{
    if (!is_array($headers)) return 0;
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#i', $h, $m)) {
            return (int) $m[1];
        }
    }
    return 0;
}

function g($arr, $key)
{
    return (is_array($arr) && isset($arr[$key])) ? (string)$arr[$key] : '';
}

function esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function plainText($html)
{
    return trim(preg_replace('/\s+/', ' ', strip_tags((string)$html)));
}

function buildMetaTags($routeType, $data, $locale, $origin, $path)
{
    $OG_LOCALE_MAP = array('id' => 'id_ID', 'en' => 'en_US');

    $tags = array(
        'title'              => '',
        'description'        => '',
        'robots'             => 'index, follow',
        'canonical'          => $origin . $path,
        'og_title'           => '',
        'og_desc'            => '',
        'og_image'           => '',
        'og_type'            => 'website',
        'og_locale'          => isset($OG_LOCALE_MAP[$locale]) ? $OG_LOCALE_MAP[$locale] : $locale,
        'og_site_name'       => 'Compress PDF',
        'keywords'           => '',
        'article_published'  => '',
        'article_modified'   => '',
        'article_author'     => '',
    );

    if (!$data || (!isset($data['title']) && !isset($data['meta_title']) && !isset($data['content']))) {
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
            $tags['title']       = trim(g($data,'meta_title'));
            $tags['description'] = trim(g($data,'meta_description'));
            $tags['keywords']    = trim(g($data,'meta_keywords'));
            $tags['robots']      = trim(g($data,'meta_robots')) ?: 'index, follow';
            $tags['og_title']    = trim(g($data,'og_title')) ?: $tags['title'];
            $tags['og_desc']     = trim(g($data,'og_description')) ?: $tags['description'];
            $tags['og_image']    = trim(g($data,'og_image'));
            if (!empty($data['canonical_url'])) {
                $tags['canonical'] = trim($data['canonical_url']);
            }
            break;

        case 'blog':
            $tags['og_type']     = 'article';
            $tags['title']       = trim(g($data,'meta_title') ?: g($data,'title'));
            $desc = trim(g($data,'meta_description'));
            if (!$desc) $desc = trim(g($data,'og_description'));
            if (!$desc) $desc = trim(g($data,'excerpt'));
            if (!$desc && !empty($data['content'])) $desc = mb_substr(plainText($data['content']), 0, 160);
            $tags['description'] = $desc;
            $tags['keywords']    = trim(g($data,'meta_keywords'));
            $tags['robots']      = trim(g($data,'meta_robots')) ?: 'index, follow';
            $tags['og_title']    = trim(g($data,'og_title')) ?: $tags['title'];
            $tags['og_desc']     = trim(g($data,'og_description')) ?: $tags['description'];
            $tags['og_image']    = trim(g($data,'og_image') ?: g($data,'image') ?: g($data,'featured_image'));
            $tags['article_published'] = trim(g($data,'published_at') ?: g($data,'created_at'));
            $tags['article_modified']  = trim(g($data,'updated_at'));
            $authorRaw = isset($data['author']) ? $data['author'] : '';
            $tags['article_author'] = is_array($authorRaw) ? trim(g($authorRaw,'name')) : trim((string)$authorRaw);
            if (!empty($data['canonical_url'])) {
                $tags['canonical'] = trim($data['canonical_url']);
            }
            break;

        case 'page':
            $tags['title']       = trim(g($data,'meta_title') ?: g($data,'title'));
            $desc = trim(g($data,'meta_description'));
            if (!$desc) $desc = trim(g($data,'og_description'));
            if (!$desc && !empty($data['content'])) $desc = mb_substr(plainText($data['content']), 0, 160);
            $tags['description'] = $desc;
            $tags['keywords']    = trim(g($data,'meta_keywords'));
            $tags['robots']      = trim(g($data,'meta_robots')) ?: 'index, follow';
            $tags['og_title']    = trim(g($data,'og_title')) ?: $tags['title'];
            $tags['og_desc']     = trim(g($data,'og_description')) ?: $tags['description'];
            $tags['og_image']    = trim(g($data,'og_image'));
            if (!empty($data['canonical_url'])) {
                $tags['canonical'] = trim($data['canonical_url']);
            }
            break;

        case 'legal':
            $tags['title']       = trim(g($data,'title'));
            if (!empty($data['content'])) {
                $tags['description'] = mb_substr(plainText($data['content']), 0, 160);
            }
            $tags['og_title'] = $tags['title'];
            $tags['og_desc']  = $tags['description'];
            break;
    }

    // Cross-fill: ensure description and og_desc never disagree
    if (!$tags['description'] && $tags['og_desc']) $tags['description'] = $tags['og_desc'];
    if (!$tags['og_desc'] && $tags['description']) $tags['og_desc'] = $tags['description'];

    return $tags;
}

function injectMetaIntoHtml($html, $tags)
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

    $inject = array();
    if ($tags['title'])       $inject[] = '<meta name="title" content="' . esc($tags['title']) . '" />';
    if ($tags['description']) $inject[] = '<meta name="description" content="' . esc($tags['description']) . '" />';
    if ($tags['keywords'])    $inject[] = '<meta name="keywords" content="' . esc($tags['keywords']) . '" />';
    if ($tags['canonical'])   $inject[] = '<link rel="canonical" href="' . esc($tags['canonical']) . '" />';

    // OG tags
    if ($tags['og_title'])    $inject[] = '<meta property="og:title" content="' . esc($tags['og_title']) . '" />';
    if ($tags['og_desc'])     $inject[] = '<meta property="og:description" content="' . esc($tags['og_desc']) . '" />';
    if ($tags['og_image'])    $inject[] = '<meta property="og:image" content="' . esc($tags['og_image']) . '" />';
    $inject[] = '<meta property="og:url" content="' . esc($tags['canonical']) . '" />';
    if (!empty($tags['og_site_name'])) $inject[] = '<meta property="og:site_name" content="' . esc($tags['og_site_name']) . '" />';
    if (!empty($tags['og_locale']))    $inject[] = '<meta property="og:locale" content="' . esc($tags['og_locale']) . '" />';

    // Article-specific
    if (!empty($tags['article_published'])) $inject[] = '<meta property="article:published_time" content="' . esc($tags['article_published']) . '" />';
    if (!empty($tags['article_modified']))  $inject[] = '<meta property="article:modified_time" content="' . esc($tags['article_modified']) . '" />';
    if (!empty($tags['article_author']))    $inject[] = '<meta property="article:author" content="' . esc($tags['article_author']) . '" />';

    // Twitter tags
    if ($tags['og_title'])    $inject[] = '<meta name="twitter:title" content="' . esc($tags['og_title']) . '" />';
    if ($tags['og_desc'])     $inject[] = '<meta name="twitter:description" content="' . esc($tags['og_desc']) . '" />';
    if ($tags['og_image'])    $inject[] = '<meta name="twitter:image" content="' . esc($tags['og_image']) . '" />';

    if (!empty($inject)) {
        $block = '    ' . implode("\n    ", $inject);
        $html = str_replace('</head>', $block . "\n  </head>", $html);
    }

    return $html;
}
