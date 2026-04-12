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
 *
 * View-source vs React: client-only meta (Helmet after load) updates DevTools
 * Elements but not view-source. This script runs on the server and injects meta
 * into the HTML shell so view-source and crawlers see the same tags per URL.
 *
 * Server config: Apache must route SPA routes to this file (see public/.htaccess).
 * On Nginx, use try_files + fastcgi_pass to this script for non-file requests so
 * the HTML is always processed here (otherwise View Source stays the raw Vite shell).
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

$routeType = null;
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
    // Same React route as home (compressor); must use home-content SEO, not generic "tool" fallbacks.
    $routeType = 'home';
} elseif (preg_match('#^/tools#', $routeSuffix)) {
    $routeType = 'tools';
} elseif ($routeSuffix === '/' || $routeSuffix === '') {
    $routeType = 'home';
}

// /en/merge, /split, … — ComingSoon routes (not home SEO).
// /page, /legal without slug — not valid CMS URLs; avoid serving home meta on wrong URLs.
if ($routeType === null) {
    if ($routeSuffix === '/' || $routeSuffix === '') {
        $routeType = 'home';
    } elseif (preg_match('#^/([a-z0-9-]+)/?$#', $routeSuffix, $m)) {
        $seg = $m[1];
        $reserved = array('blog', 'page', 'legal', 'contact', 'compress', 'tools', 'id');
        if (!in_array($seg, $reserved, true)) {
            $routeType = 'tool-landing';
            $slug = $seg;
        } else {
            $routeType = 'spa-other';
        }
    } else {
        $routeType = 'home';
    }
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
    case 'blog-list':
        $apiPath = '/blogs';
        break;
    case 'page':
        $apiPath = '/pages/' . rawurlencode($slug);
        break;
    case 'legal':
        $apiPath = '/legal/' . rawurlencode($slug);
        break;
    case 'contact':
        $apiPath = '/contact';
        break;
    case 'tools':
        $apiPath = '/schema/tool';
        break;
    default:
        $apiPath = '';
}

// ── Fetch CMS data (per URL + locale; cached briefly) ─────────────────────
$meta = null;
if ($apiPath !== '') {
    $publicPathForApi = null;
    if ($apiPath === '/home-content' || $apiPath === '/schema/tool') {
        $publicPathForApi = $path;
    }
    $meta = fetchCmsData($CMS_API_BASE, $SITE_DOMAIN, $apiPath, $locale, $CACHE_DIR, $CACHE_TTL, $publicPathForApi);
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

function fetchCmsData($apiBase, $domain, $apiPath, $locale, $cacheDir, $ttl, $publicPath = null)
{
    $pathKey = ($publicPath !== null && $publicPath !== '') ? (string) $publicPath : '';
    $cacheKey = md5($domain . $apiPath . $locale . $pathKey);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && !isset($cached['_fetch_error'])) return $cached;
    }

    $query = 'locale=' . urlencode($locale);
    if ($publicPath !== null && $publicPath !== '') {
        $query .= '&public_path=' . urlencode($publicPath);
    }

    $urls = array(
        array(
            'url'    => rtrim($apiBase, '/') . '/' . $domain . '/api/public' . $apiPath . '?' . $query,
            'header' => "Accept: application/json\r\n",
        ),
        array(
            'url'    => rtrim($apiBase, '/') . '/api/public' . $apiPath . '?' . $query,
            'header' => "Accept: application/json\r\nX-Domain: {$domain}\r\n",
        ),
    );

    $data = null;
    foreach ($urls as $attempt) {
        $body = fetchUrlBody($attempt['url'], $attempt['header']);
        if ($body === null || $body === '') {
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

/**
 * GET JSON from CMS API — file_get_contents first, then cURL (many hosts block allow_url_fopen or break $http_response_header).
 *
 * @return string|null Raw body or null on failure
 */
function fetchUrlBody($url, $headerBlock)
{
    $ctx = stream_context_create(array(
        'http' => array(
            'method'        => 'GET',
            'header'        => $headerBlock,
            'timeout'       => 8,
            'ignore_errors' => true,
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));

    $body = @file_get_contents($url, false, $ctx);
    if ($body !== false && $body !== '') {
        return (string) $body;
    }

    if (!function_exists('curl_init')) {
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($headerBlock));
    $curlHeaders = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $curlHeaders[] = $line;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $curlBody = curl_exec($ch);
    $curlCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlBody === false || $curlBody === '') {
        return null;
    }
    if ($curlCode >= 400) {
        return null;
    }

    return (string) $curlBody;
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
        'twitter_card'       => 'summary',
        'json_ld'            => null,
        'head_snippet'       => '',
    );

    $hasPayload = is_array($data) && (
        (isset($data['json_ld']) && is_array($data['json_ld']))
        || isset($data['title']) || isset($data['meta_title']) || isset($data['content'])
        || ($routeType === 'blog-list' && isset($data['blogs']))
        || ($routeType === 'contact' && array_key_exists('contact_email', $data))
        || $routeType === 'tools'
    );

    if (!$data || !$hasPayload) {
        switch ($routeType) {
            case 'home':
                $tags['title'] = 'Compress PDF';
                $tags['description'] = '';
                $tags['og_title'] = $tags['title'];
                $tags['og_desc'] = $tags['description'];
                $tags['twitter_card'] = !empty($tags['og_image']) ? 'summary_large_image' : 'summary';
                return $tags;
            case 'blog':
            case 'page':
                $tags['title'] = 'Compress PDF';
                $tags['description'] = '';
                $tags['robots'] = 'noindex, follow';
                $tags['og_title'] = $tags['title'];
                $tags['og_desc'] = $tags['description'];
                $tags['twitter_card'] = !empty($tags['og_image']) ? 'summary_large_image' : 'summary';
                return $tags;
            case 'legal':
                $tags['title'] = 'Legal';
                $tags['description'] = '';
                $tags['og_title'] = $tags['title'];
                $tags['og_desc'] = $tags['description'];
                $tags['twitter_card'] = !empty($tags['og_image']) ? 'summary_large_image' : 'summary';
                return $tags;
            case 'blog-list':
                $tags['title'] = 'Blog';
                $tags['description'] = 'Latest articles and guides.';
                break;
            case 'contact':
                $tags['title'] = 'Contact Us';
                $tags['description'] = 'Get in touch.';
                break;
            case 'tools':
                $tags['title'] = 'All Tools';
                $tags['description'] = 'PDF tools and utilities.';
                break;
            case 'tool-landing':
                $tags['title'] = 'Compress PDF';
                $tags['description'] = 'PDF tools online.';
                break;
            case 'spa-other':
                $tags['title'] = 'Compress PDF';
                $tags['description'] = '';
                $tags['robots'] = 'noindex, follow';
                break;
        }
        $tags['og_title'] = $tags['title'];
        $tags['og_desc']  = $tags['description'];
        $tags['twitter_card'] = !empty($tags['og_image']) ? 'summary_large_image' : 'summary';
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
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
            }
            if (!empty($data['head_snippet'])) {
                $tags['head_snippet'] = (string) $data['head_snippet'];
            }
            break;

        case 'blog-list':
            $tags['title'] = 'Blog';
            $tags['description'] = 'Latest articles and guides.';
            if (!empty($data['blogs']) && is_array($data['blogs']) && isset($data['blogs'][0]) && is_array($data['blogs'][0])) {
                $b0 = $data['blogs'][0];
                $tags['og_title'] = trim(g($b0, 'og_title') ?: g($b0, 'title')) ?: $tags['title'];
                $tags['og_desc'] = trim(g($b0, 'og_description') ?: g($b0, 'excerpt')) ?: $tags['description'];
                $tags['og_image'] = trim(g($b0, 'og_image') ?: g($b0, 'image'));
                $tags['description'] = $tags['og_desc'] ?: $tags['description'];
            } else {
                $tags['og_title'] = $tags['title'];
                $tags['og_desc'] = $tags['description'];
            }
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
            }
            break;

        case 'contact':
            $tags['title'] = 'Contact Us';
            $bits = array();
            $e = trim(g($data, 'contact_email'));
            $p = trim(g($data, 'contact_phone'));
            $a = trim(g($data, 'contact_address'));
            if ($e !== '') {
                $bits[] = $e;
            }
            if ($p !== '') {
                $bits[] = $p;
            }
            if ($a !== '') {
                $bits[] = $a;
            }
            $tags['description'] = $bits ? implode(' · ', $bits) : 'Get in touch.';
            $tags['og_title'] = $tags['title'];
            $tags['og_desc'] = $tags['description'];
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
            }
            break;

        case 'tools':
            $tags['title'] = 'All Tools';
            $tags['description'] = 'Browse PDF tools: compress, merge, split, and more.';
            $tags['og_title'] = $tags['title'];
            $tags['og_desc'] = $tags['description'];
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
            }
            break;

        case 'tool-landing':
            $pretty = trim(str_replace(array('-', '_'), ' ', $slug));
            if ($pretty !== '') {
                $pretty = function_exists('mb_convert_case')
                    ? mb_convert_case($pretty, MB_CASE_TITLE, 'UTF-8')
                    : ucwords(strtolower($pretty));
            } else {
                $pretty = 'Tools';
            }
            $tags['title'] = $pretty . ' · ' . $tags['og_site_name'];
            $tags['description'] = $pretty . ' — PDF tool. ' . $tags['og_site_name'] . '.';
            $tags['og_title'] = $tags['title'];
            $tags['og_desc'] = $tags['description'];
            break;

        case 'spa-other':
            $tags['title'] = $tags['og_site_name'];
            $tags['description'] = '';
            $tags['robots'] = 'noindex, follow';
            $tags['og_title'] = $tags['title'];
            $tags['og_desc'] = $tags['description'];
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
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
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
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
            }
            break;

        case 'legal':
            $tags['title']       = trim(g($data,'title'));
            if (!empty($data['content'])) {
                $tags['description'] = mb_substr(plainText($data['content']), 0, 160);
            }
            $tags['og_title'] = $tags['title'];
            $tags['og_desc']  = $tags['description'];
            if (!empty($data['json_ld'])) {
                $tags['json_ld'] = $data['json_ld'];
            }
            break;
    }

    // Cross-fill: ensure description and og_desc never disagree
    if (!$tags['description'] && $tags['og_desc']) $tags['description'] = $tags['og_desc'];
    if (!$tags['og_desc'] && $tags['description']) $tags['og_desc'] = $tags['description'];

    $tags['twitter_card'] = !empty($tags['og_image']) ? 'summary_large_image' : 'summary';

    return $tags;
}

function stripShellSeoDuplicates($html)
{
    $html = preg_replace('/<script\s+type=["\']application\/ld\+json["\'][^>]*>[\s\S]*?<\/script>/i', '', $html);
    $patterns = array(
        '/<meta\s+name=["\']robots["\'][^>]*>/i',
        '/<meta\s+name=["\']title["\'][^>]*>/i',
        '/<meta\s+name=["\']description["\'][^>]*>/i',
        '/<meta\s+name=["\']keywords["\'][^>]*>/i',
        '/<meta\s+property=["\']og:[^"\']+["\'][^>]*>/i',
        '/<meta\s+name=["\']twitter:[^"\']+["\'][^>]*>/i',
        '/<meta\s+property=["\']article:[^"\']+["\'][^>]*>/i',
        '/<link\s+rel=["\']canonical["\'][^>]*>/i',
        '/<link\s+rel=["\']alternate["\'][^>]*hreflang[^>]*>/i',
    );
    foreach ($patterns as $p) {
        $html = preg_replace($p, '', $html);
    }
    return $html;
}

function injectMetaIntoHtml($html, $tags)
{
    $html = stripShellSeoDuplicates($html);

    if (trim((string) (isset($tags['title']) ? $tags['title'] : '')) === '') {
        $tags['title'] = 'Compress PDF';
    }

    $title = esc($tags['title']);
    if (preg_match('/<title\s*>[\s\S]*?<\/title>/i', $html)) {
        $html = preg_replace('/<title\s*>[\s\S]*?<\/title>/i', '<title>' . $title . '</title>', $html, 1);
    } else {
        $html = preg_replace('/<head[^>]*>/i', '$0' . "\n    " . '<title>' . $title . '</title>', $html, 1);
    }

    $inject = array();
    if (!empty($tags['head_snippet'])) {
        $inject[] = $tags['head_snippet'];
    }

    $inject[] = '<meta name="robots" content="' . esc($tags['robots']) . '" />';
    if ($tags['title']) {
        $inject[] = '<meta name="title" content="' . esc($tags['title']) . '" />';
    }
    if ($tags['description']) {
        $inject[] = '<meta name="description" content="' . esc($tags['description']) . '" />';
    }
    if ($tags['keywords']) {
        $inject[] = '<meta name="keywords" content="' . esc($tags['keywords']) . '" />';
    }
    if (!empty($tags['canonical'])) {
        $inject[] = '<link rel="canonical" href="' . esc($tags['canonical']) . '" />';
    }

    $inject[] = '<meta property="og:type" content="' . esc($tags['og_type']) . '" />';
    if ($tags['og_title']) {
        $inject[] = '<meta property="og:title" content="' . esc($tags['og_title']) . '" />';
    }
    if ($tags['og_desc']) {
        $inject[] = '<meta property="og:description" content="' . esc($tags['og_desc']) . '" />';
    }
    if ($tags['og_image']) {
        $inject[] = '<meta property="og:image" content="' . esc($tags['og_image']) . '" />';
    }
    $inject[] = '<meta property="og:url" content="' . esc($tags['canonical']) . '" />';
    if (!empty($tags['og_site_name'])) {
        $inject[] = '<meta property="og:site_name" content="' . esc($tags['og_site_name']) . '" />';
    }
    if (!empty($tags['og_locale'])) {
        $inject[] = '<meta property="og:locale" content="' . esc($tags['og_locale']) . '" />';
    }

    if (!empty($tags['article_published'])) {
        $inject[] = '<meta property="article:published_time" content="' . esc($tags['article_published']) . '" />';
    }
    if (!empty($tags['article_modified'])) {
        $inject[] = '<meta property="article:modified_time" content="' . esc($tags['article_modified']) . '" />';
    }
    if (!empty($tags['article_author'])) {
        $inject[] = '<meta property="article:author" content="' . esc($tags['article_author']) . '" />';
    }

    $twCard = !empty($tags['twitter_card']) ? $tags['twitter_card'] : 'summary';
    $inject[] = '<meta name="twitter:card" content="' . esc($twCard) . '" />';
    if ($tags['og_title']) {
        $inject[] = '<meta name="twitter:title" content="' . esc($tags['og_title']) . '" />';
    }
    if ($tags['og_desc']) {
        $inject[] = '<meta name="twitter:description" content="' . esc($tags['og_desc']) . '" />';
    }
    if ($tags['og_image']) {
        $inject[] = '<meta name="twitter:image" content="' . esc($tags['og_image']) . '" />';
    }

    if (!empty($tags['json_ld'])) {
        $ld = $tags['json_ld'];
        if (is_array($ld)) {
            $ldJson = json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $ldJson = (string) $ld;
        }
        if ($ldJson !== '') {
            $inject[] = '<script type="application/ld+json" data-cms-seo-prerender="1">' . $ldJson . '</script>';
        }
    }

    if (!empty($inject)) {
        $block = '    ' . implode("\n    ", $inject);
        if (stripos($html, '</head>') !== false) {
            $html = str_replace('</head>', $block . "\n  </head>", $html);
        } else {
            $html = preg_replace('/<head[^>]*>/i', '$0' . "\n" . $block . "\n", $html, 1);
        }
    }

    return $html;
}
