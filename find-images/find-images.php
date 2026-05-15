<?php
if ($argc < 3) {
    echo "Error: Invalid arguments.\n";
    echo "Usage: php find-images.php <url> <suffix> [--single] [--skip-slug=<slug1,slug2>] [--include-svgs] [--css-scope=page|linked]\n";
    exit(1);
}

$inputUrl = $argv[1] ?? '';
$suffix = ($argc >= 3 && preg_match('/^[\w\-]+$/', $argv[2]) && $argv[2] !== '--single') ? $argv[2] : '';
$singleMode = in_array('--single', $argv, true);
$includeSvgs = in_array('--include-svgs', $argv, true);
$cssScope = 'page';
$skipSlugs = [];

foreach ($argv as $arg) {
    if (preg_match('/^--skip-slug=(.+)$/', $arg, $m)) {
        $skipSlugs = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }
    if (preg_match('/^--css-scope=(.+)$/', $arg, $m)) {
        $candidate = strtolower(trim($m[1]));
        if (in_array($candidate, ['page', 'linked'], true)) {
            $cssScope = $candidate;
        }
    }
}

if ($suffix === '') {
    echo "Error: Suffix is required.\n";
    echo "Usage: php find-images.php <url> <suffix> [--single] [--skip-slug=<slug1,slug2>] [--include-svgs] [--css-scope=page|linked]\n";
    exit(1);
}

$outFile = __DIR__ . '/images_found-' . $suffix . '.json';
$progressFile = __DIR__ . '/progress-' . $suffix . '.json';
$checkedFile = __DIR__ . '/checked-image-urls-' . $suffix . '.tmp';

function cleanupFiles() {
    global $progressFile, $checkedFile;
    if (file_exists($progressFile)) {
        unlink($progressFile);
    }
    if (file_exists($checkedFile)) {
        unlink($checkedFile);
    }
}

file_put_contents($progressFile, json_encode([
    'processed' => 0,
    'total' => 0,
    'done' => false,
    'start_url' => $inputUrl,
    'pid' => null,
]));

function normalizeUrl($url) {
    $parts = parse_url(trim((string) $url));
    if (!isset($parts['host'])) {
        return '';
    }
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = isset($parts['path']) ? preg_replace('#/+#', '/', $parts['path']) : '';
    $path = $path !== '' ? rtrim($path, '/\\') : '';
    return $scheme . '://' . $host . $port . ($path ? '/' . ltrim($path, '/') : '');
}

function shouldSkipUrl($url, $skipSlugs) {
    if (empty($skipSlugs)) {
        return false;
    }
    $parts = parse_url($url);
    if (!isset($parts['path'])) {
        return false;
    }
    $segments = array_filter(explode('/', $parts['path']));
    foreach ($segments as $segment) {
        foreach ($skipSlugs as $slug) {
            if (strcasecmp($segment, $slug) === 0) {
                return true;
            }
        }
    }
    return false;
}

function isUrlChecked($url, $checkedFile) {
    $normalized = normalizeUrl($url);
    if ($normalized === '' || !file_exists($checkedFile)) {
        return false;
    }
    $fh = fopen($checkedFile, 'r');
    if (!$fh) {
        return false;
    }
    while (($line = fgets($fh)) !== false) {
        if (trim($line) === $normalized) {
            fclose($fh);
            return true;
        }
    }
    fclose($fh);
    return false;
}

function markUrlChecked($url, $checkedFile) {
    $normalized = normalizeUrl($url);
    if ($normalized !== '') {
        file_put_contents($checkedFile, $normalized . "\n", FILE_APPEND | LOCK_EX);
    }
}

function fetchContent($url, &$contentType = null) {
    $contentType = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'ImageFinderBot/1.0',
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    if ($body !== false) {
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
    }
    curl_close($ch);
    return $body;
}

function fetchPage($url) {
    $contentType = null;
    sleep(5);
    return fetchContent($url, $contentType);
}

function getUrlsFromSitemap($sitemapUrl) {
    $urls = [];
    $xmlContent = @file_get_contents($sitemapUrl);
    if ($xmlContent === false) {
        return [];
    }
    $reader = new XMLReader();
    if (!$reader->XML($xmlContent)) {
        return [];
    }
    $isIndex = false;
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        $localName = $reader->localName;
        if ($localName === 'sitemapindex') {
            $isIndex = true;
        }
        if ($isIndex && $localName === 'sitemap') {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'loc') {
                    $loc = $reader->readString();
                    $urls = array_merge($urls, getUrlsFromSitemap($loc));
                    break;
                }
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'sitemap') {
                    break;
                }
            }
        } elseif (!$isIndex && $localName === 'url') {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'loc') {
                    $urls[] = $reader->readString();
                    break;
                }
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'url') {
                    break;
                }
            }
        }
    }
    $reader->close();
    return $urls;
}

function getDomain($url) {
    $parts = parse_url($url);
    if (!isset($parts['scheme'], $parts['host'])) {
        return '';
    }
    $domain = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $domain .= ':' . $parts['port'];
    }
    return $domain;
}

function resolveUrl($baseUrl, $relativeUrl) {
    $relativeUrl = trim((string) $relativeUrl);
    if ($relativeUrl === '') {
        return '';
    }
    if (preg_match('#^(?:https?:)?//#i', $relativeUrl)) {
        if (strpos($relativeUrl, '//') === 0) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $relativeUrl;
        }
        return $relativeUrl;
    }
    if (strpos($relativeUrl, 'data:') === 0 || strpos($relativeUrl, 'javascript:') === 0 || strpos($relativeUrl, 'mailto:') === 0 || strpos($relativeUrl, 'tel:') === 0) {
        return $relativeUrl;
    }

    $base = parse_url($baseUrl);
    if (!$base || !isset($base['scheme'], $base['host'])) {
        return $relativeUrl;
    }

    $scheme = $base['scheme'];
    $host = $base['host'];
    $port = isset($base['port']) ? ':' . $base['port'] : '';

    if (strpos($relativeUrl, '/') === 0) {
        return $scheme . '://' . $host . $port . preg_replace('#/+#', '/', $relativeUrl);
    }

    $basePath = $base['path'] ?? '/';
    $baseDir = preg_replace('#/[^/]*$#', '/', $basePath);
    $combined = $baseDir . $relativeUrl;

    $segments = [];
    foreach (explode('/', $combined) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}

function isInternal($base, $url) {
    $baseParts = parse_url($base);
    $urlParts = parse_url($url);
    return isset($urlParts['host'], $baseParts['host'])
        && $urlParts['host'] === $baseParts['host']
        && (($urlParts['port'] ?? null) === ($baseParts['port'] ?? null));
}

function crawlSite($startUrl) {
    $visited = [];
    $queue = [$startUrl];
    while ($queue) {
        $url = array_shift($queue);
        $norm = normalizeUrl($url);
        if ($norm === '' || isset($visited[$norm])) {
            continue;
        }
        $visited[$norm] = true;
        yield $url;
        $html = fetchPage($url);
        if ($html === false || $html === '') {
            continue;
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || strpos($href, '#') === 0) {
                continue;
            }
            $abs = filter_var($href, FILTER_VALIDATE_URL) ? $href : resolveUrl($url, $href);
            if ($abs !== '' && isInternal($startUrl, $abs)) {
                $absNorm = normalizeUrl($abs);
                if ($absNorm !== '' && !isset($visited[$absNorm])) {
                    $queue[] = $abs;
                }
            }
        }
    }
}

function getExistingPid($progressFile) {
    if (!file_exists($progressFile)) {
        return null;
    }
    $data = json_decode(file_get_contents($progressFile), true);
    return $data['pid'] ?? null;
}

function sanitizeTextSnippet($text, $limit = 80) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

function getNearestSectionData($node) {
    $parent = $node;
    while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
        if (strtolower($parent->nodeName) === 'section') {
            $class = trim($parent->getAttribute('class'));
            $id = trim($parent->getAttribute('id'));
            $snippet = sanitizeTextSnippet($parent->textContent);
            $firstClass = '';
            if ($class !== '') {
                $classParts = preg_split('/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY);
                $firstClass = $classParts[0] ?? '';
            }
            $label = $firstClass !== '' ? $firstClass : ($id !== '' ? $id : $snippet);
            return [
                'tag' => 'section',
                'label' => $label,
                'class' => $class,
                'id' => $id,
                'text_snippet' => $snippet,
            ];
        }
        $parent = $parent->parentNode;
    }
    return [
        'tag' => '',
        'label' => '',
        'class' => '',
        'id' => '',
        'text_snippet' => '',
    ];
}

function xpathLiteral($value) {
    if (strpos($value, "'") === false) {
        return "'" . $value . "'";
    }
    if (strpos($value, '"') === false) {
        return '"' . $value . '"';
    }
    $parts = explode("'", $value);
    $quoted = [];
    foreach ($parts as $index => $part) {
        if ($part !== '') {
            $quoted[] = "'" . $part . "'";
        }
        if ($index !== count($parts) - 1) {
            $quoted[] = '"\'"';
        }
    }
    return 'concat(' . implode(', ', $quoted) . ')';
}

function buildXPathSegment($simpleSelector) {
    $simpleSelector = trim($simpleSelector);
    if ($simpleSelector === '') {
        return '*';
    }

    $tag = '*';
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*|^\*/', $simpleSelector, $m)) {
        $tag = strtolower($m[0]);
        $simpleSelector = substr($simpleSelector, strlen($m[0]));
    }

    $predicates = [];
    if (preg_match_all('/(#[a-zA-Z0-9_-]+)|(\.[a-zA-Z0-9_-]+)|(\[[^]]+])/', $simpleSelector, $matches)) {
        foreach ($matches[0] as $token) {
            if ($token[0] === '#') {
                $predicates[] = '@id=' . xpathLiteral(substr($token, 1));
            } elseif ($token[0] === '.') {
                $class = substr($token, 1);
                $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), ' . xpathLiteral(' ' . $class . ' ') . ')';
            } elseif ($token[0] === '[') {
                $attr = trim($token, '[]');
                if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:.\-]*)(?:([~]?=)["\']?(.*?)["\']?)?$/', $attr, $m)) {
                    $name = $m[1];
                    $operator = $m[2] ?? null;
                    $value = $m[3] ?? null;
                    if (!$operator) {
                        $predicates[] = '@' . $name;
                    } elseif ($operator === '=') {
                        $predicates[] = '@' . $name . '=' . xpathLiteral($value);
                    } elseif ($operator === '~=') {
                        $predicates[] = 'contains(concat(" ", normalize-space(@' . $name . '), " "), ' . xpathLiteral(' ' . $value . ' ') . ')';
                    }
                }
            }
        }
    }

    return $predicates ? $tag . '[' . implode(' and ', $predicates) . ']' : $tag;
}

function selectorToXPath($selector) {
    $selector = trim((string) $selector);
    if ($selector === '' || strpos($selector, '@') === 0) {
        return null;
    }

    $selector = preg_replace('/::?[a-zA-Z0-9_-]+(?:\([^)]*\))?/', '', $selector);
    $selector = preg_replace('/\s*([>+~])\s*/', ' $1 ', $selector);
    $selector = trim(preg_replace('/\s+/', ' ', $selector));
    if ($selector === '') {
        return null;
    }

    $tokens = preg_split('/\s+/', $selector, -1, PREG_SPLIT_NO_EMPTY);
    $xpath = '.';
    $combinator = '//';

    foreach ($tokens as $token) {
        if ($token === '>') {
            $combinator = '/';
            continue;
        }
        if ($token === '+' || $token === '~') {
            $combinator = '//';
            continue;
        }

        $segment = buildXPathSegment($token);
        if ($segment === null || $segment === '') {
            return null;
        }
        $xpath .= $combinator . $segment;
        $combinator = '//';
    }

    return $xpath;
}

function stripCssComments($css) {
    return preg_replace('#/\*.*?\*/#s', '', (string) $css);
}

function extractCssRules($cssText) {
    $cssText = stripCssComments($cssText);
    $rules = [];
    if (!preg_match_all('/([^{}]+)\{([^{}]+)}/s', $cssText, $matches, PREG_SET_ORDER)) {
        return $rules;
    }
    foreach ($matches as $match) {
        $selector = trim($match[1]);
        $body = trim($match[2]);
        if ($selector === '' || $body === '' || strpos($selector, '@') === 0) {
            continue;
        }
        $rules[] = [$selector, $body];
    }
    return $rules;
}

function extractUrlsFromCssValue($value) {
    $urls = [];
    if (preg_match_all('/url\(([^)]+)\)/i', (string) $value, $matches)) {
        foreach ($matches[1] as $rawUrl) {
            $candidate = trim($rawUrl, " \t\n\r\0\x0B\"'");
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
        }
    }
    return array_values(array_unique($urls));
}

function extractUrlsFromSrcset($srcset) {
    $urls = [];
    foreach (explode(',', (string) $srcset) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $segments = preg_split('/\s+/', $part);
        if (!empty($segments[0])) {
            $urls[] = $segments[0];
        }
    }
    return array_values(array_unique($urls));
}

function extractImageName($url) {
    $path = parse_url((string) $url, PHP_URL_PATH);
    if (!$path) {
        return '';
    }
    return basename($path);
}

function normalizeImageUrlForComparison($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, 'data:') === 0) {
        return $url;
    }
    $parts = parse_url($url);
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
    $host = isset($parts['host']) ? strtolower($parts['host']) : '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '';
    $path = preg_replace('#/+#', '/', $path);
    $dirname = rtrim(str_replace('\\', '/', dirname($path)), '/.');
    $basename = basename($path);
    $basename = preg_replace('/([-_])\d+x\d+(?=\.[a-z0-9]+$)/i', '', $basename);
    $normalizedPath = ($dirname !== '' ? $dirname . '/' : '/') . $basename;
    return $scheme . '://' . $host . $port . $normalizedPath;
}

function buildUsageKey($url) {
    return md5(normalizeImageUrlForComparison($url));
}

function isSvgUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }
    if (stripos($url, 'data:image/svg+xml') === 0) {
        return true;
    }
    $path = parse_url($url, PHP_URL_PATH);
    return is_string($path) && preg_match('/\.svg$/i', $path) === 1;
}

function getResizeAreaFromName($imageName) {
    if (preg_match('/[-_](\d+)x(\d+)(?=\.[a-z0-9]+$)/i', $imageName, $m)) {
        return ((int) $m[1]) * ((int) $m[2]);
    }
    return null;
}

function choosePreferredImageUrl($currentUrl, $candidateUrl) {
    $currentName = extractImageName($currentUrl);
    $candidateName = extractImageName($candidateUrl);

    $currentHasResize = preg_match('/[-_]\d+x\d+(?=\.[a-z0-9]+$)/i', $currentName) === 1;
    $candidateHasResize = preg_match('/[-_]\d+x\d+(?=\.[a-z0-9]+$)/i', $candidateName) === 1;

    if ($currentHasResize !== $candidateHasResize) {
        return $candidateHasResize ? $currentUrl : $candidateUrl;
    }

    $currentArea = getResizeAreaFromName($currentName);
    $candidateArea = getResizeAreaFromName($candidateName);
    if ($currentArea !== null && $candidateArea !== null && $currentArea !== $candidateArea) {
        return $candidateArea > $currentArea ? $candidateUrl : $currentUrl;
    }

    return strlen($candidateUrl) > strlen($currentUrl) ? $candidateUrl : $currentUrl;
}

function addOccurrence(&$occurrences, $resolvedUrl, $sourceType, $sourceAttribute, $ariaLabel, $section) {
    if ($resolvedUrl === '' || strpos($resolvedUrl, 'data:') === 0) {
        return;
    }
    $usageKey = buildUsageKey($resolvedUrl);
    $occurrences[] = [
        'usage_key' => $usageKey,
        'image_name' => extractImageName($resolvedUrl),
        'image_url' => $resolvedUrl,
        'normalized_image' => normalizeImageUrlForComparison($resolvedUrl),
        'source_type' => $sourceType,
        'source_attribute' => $sourceAttribute,
        'aria_label' => trim((string) $ariaLabel),
        'section' => $section,
    ];
}

function extractImageOccurrencesFromDom($dom, $xpath, $pageUrl) {
    $occurrences = [];
    $imgUrlAttrs = [
        'src',
        'data-src',
        'data-lazy-src',
        'data-original',
        'data-bg',
        'data-background',
        'data-background-image',
    ];
    $imgSrcsetAttrs = [
        'srcset',
        'data-srcset',
        'data-lazy-srcset',
    ];

    foreach ($xpath->query('//img') as $img) {
        $ariaLabel = $img->getAttribute('aria-label');
        $section = getNearestSectionData($img->parentNode instanceof DOMNode ? $img->parentNode : $img);
        foreach ($imgUrlAttrs as $attr) {
            $value = trim($img->getAttribute($attr));
            if ($value === '') {
                continue;
            }
            addOccurrence($occurrences, resolveUrl($pageUrl, $value), 'img', $attr, $ariaLabel, $section);
        }
        foreach ($imgSrcsetAttrs as $attr) {
            $value = trim($img->getAttribute($attr));
            if ($value === '') {
                continue;
            }
            foreach (extractUrlsFromSrcset($value) as $candidateUrl) {
                addOccurrence($occurrences, resolveUrl($pageUrl, $candidateUrl), 'img', $attr, $ariaLabel, $section);
            }
        }
    }

    foreach ($xpath->query('//*[@style]') as $el) {
        $style = $el->getAttribute('style');
        if ($style === '') {
            continue;
        }
        $section = getNearestSectionData($el);
        $ariaLabel = $el->getAttribute('aria-label');
        foreach (extractUrlsFromCssValue($style) as $url) {
            addOccurrence($occurrences, resolveUrl($pageUrl, $url), 'css-background', 'style', $ariaLabel, $section);
        }
        foreach (['data-bg', 'data-background', 'data-background-image'] as $attr) {
            $value = trim($el->getAttribute($attr));
            if ($value === '') {
                continue;
            }
            foreach (extractUrlsFromCssValue($value) as $url) {
                addOccurrence($occurrences, resolveUrl($pageUrl, $url), 'css-background', $attr, $ariaLabel, $section);
            }
        }
    }

    return $occurrences;
}

function extractOccurrencesFromCssText($cssText, $cssBaseUrl, $xpath, $sourceAttribute) {
    $occurrences = [];
    foreach (extractCssRules($cssText) as [$selectorList, $body]) {
        $urls = extractUrlsFromCssValue($body);
        if (empty($urls)) {
            continue;
        }
        $selectors = array_filter(array_map('trim', explode(',', $selectorList)));
        foreach ($selectors as $selector) {
            $xpathExpr = selectorToXPath($selector);
            if (!$xpathExpr) {
                continue;
            }
            $nodes = @$xpath->query($xpathExpr);
            if (!$nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                $section = getNearestSectionData($node);
                $ariaLabel = $node->getAttribute('aria-label');
                foreach ($urls as $url) {
                    addOccurrence($occurrences, resolveUrl($cssBaseUrl, $url), 'css-background', $sourceAttribute, $ariaLabel, $section);
                }
            }
        }
    }
    return $occurrences;
}

function makeSectionKey($section) {
    return md5(json_encode([
        $section['label'] ?? '',
        $section['class'] ?? '',
        $section['id'] ?? '',
        $section['text_snippet'] ?? '',
    ]));
}

function makePageSectionKey($pageUrl, $section) {
    return md5(json_encode([
        $pageUrl,
        $section['label'] ?? '',
        $section['class'] ?? '',
        $section['id'] ?? '',
        $section['text_snippet'] ?? '',
    ]));
}

function mergeOccurrenceIntoPage(&$pageImages, $occurrence) {
    $key = $occurrence['usage_key'];
    if (!isset($pageImages[$key])) {
        $pageImages[$key] = [
            'usage_key' => $occurrence['usage_key'],
            'image_name' => $occurrence['image_name'],
            'image_url' => $occurrence['image_url'],
            'normalized_image' => $occurrence['normalized_image'],
            'page_url' => '',
            'source_types' => [$occurrence['source_type']],
            'source_attributes' => [$occurrence['source_attribute']],
            'aria_label' => $occurrence['aria_label'],
            'aria_labels' => $occurrence['aria_label'] !== '' ? [$occurrence['aria_label']] : [],
            'sections' => [],
        ];
    } else {
        $preferredUrl = choosePreferredImageUrl($pageImages[$key]['image_url'], $occurrence['image_url']);
        if ($preferredUrl !== $pageImages[$key]['image_url']) {
            $pageImages[$key]['image_url'] = $preferredUrl;
            $pageImages[$key]['image_name'] = extractImageName($preferredUrl);
            $pageImages[$key]['normalized_image'] = normalizeImageUrlForComparison($preferredUrl);
        }
    }

    if (!in_array($occurrence['source_type'], $pageImages[$key]['source_types'], true)) {
        $pageImages[$key]['source_types'][] = $occurrence['source_type'];
    }
    if (!in_array($occurrence['source_attribute'], $pageImages[$key]['source_attributes'], true)) {
        $pageImages[$key]['source_attributes'][] = $occurrence['source_attribute'];
    }
    if ($occurrence['aria_label'] !== '' && !in_array($occurrence['aria_label'], $pageImages[$key]['aria_labels'], true)) {
        $pageImages[$key]['aria_labels'][] = $occurrence['aria_label'];
        if ($pageImages[$key]['aria_label'] === '') {
            $pageImages[$key]['aria_label'] = $occurrence['aria_label'];
        }
    }

    $sectionKey = makeSectionKey($occurrence['section']);
    $pageImages[$key]['sections'][$sectionKey] = $occurrence['section'];
}

function mergeUsageIndexEntry(&$usageIndex, $pageUrl, $pageImage) {
    $key = $pageImage['usage_key'];
    if (!isset($usageIndex[$key])) {
        $usageIndex[$key] = [
            'usage_key' => $key,
            'image_name' => $pageImage['image_name'],
            'image_url' => $pageImage['image_url'],
            'normalized_image' => $pageImage['normalized_image'],
            'source_types' => $pageImage['source_types'] ?? [],
            'source_attributes' => $pageImage['source_attributes'] ?? [],
            'aria_labels' => $pageImage['aria_labels'] ?? [],
            'pages' => [],
        ];
    } else {
        $preferredUrl = choosePreferredImageUrl($usageIndex[$key]['image_url'], $pageImage['image_url']);
        if ($preferredUrl !== $usageIndex[$key]['image_url']) {
            $usageIndex[$key]['image_url'] = $preferredUrl;
            $usageIndex[$key]['image_name'] = extractImageName($preferredUrl);
            $usageIndex[$key]['normalized_image'] = normalizeImageUrlForComparison($preferredUrl);
        }
    }

    foreach (($pageImage['source_types'] ?? []) as $sourceType) {
        if (!in_array($sourceType, $usageIndex[$key]['source_types'], true)) {
            $usageIndex[$key]['source_types'][] = $sourceType;
        }
    }

    foreach (($pageImage['source_attributes'] ?? []) as $sourceAttribute) {
        if (!in_array($sourceAttribute, $usageIndex[$key]['source_attributes'], true)) {
            $usageIndex[$key]['source_attributes'][] = $sourceAttribute;
        }
    }

    foreach (($pageImage['aria_labels'] ?? []) as $ariaLabel) {
        if ($ariaLabel !== '' && !in_array($ariaLabel, $usageIndex[$key]['aria_labels'], true)) {
            $usageIndex[$key]['aria_labels'][] = $ariaLabel;
        }
    }

    if (!in_array($pageUrl, $usageIndex[$key]['pages'], true)) {
        $usageIndex[$key]['pages'][] = $pageUrl;
    }
}

function finalizePageImages($pageImages, $pageUrl) {
    foreach ($pageImages as &$image) {
        $image['page_url'] = $pageUrl;
        $image['sections'] = array_values($image['sections']);
        usort($image['sections'], function ($a, $b) {
            return strcasecmp(($a['label'] ?? ''), ($b['label'] ?? ''));
        });
        sort($image['source_types']);
        sort($image['source_attributes']);
        sort($image['aria_labels']);
    }
    unset($image);

    usort($pageImages, function ($a, $b) {
        return strcasecmp($a['image_name'], $b['image_name']);
    });

    return array_values($pageImages);
}

function finalizeUsageIndex($usageIndex) {
    foreach ($usageIndex as &$usage) {
        $usage['pages'] = array_values(array_unique($usage['pages']));
        sort($usage['pages'], SORT_NATURAL | SORT_FLAG_CASE);
        sort($usage['source_types']);
        sort($usage['source_attributes']);
        sort($usage['aria_labels']);
    }
    unset($usage);
    uasort($usageIndex, function ($a, $b) {
        $nameCompare = strcasecmp($a['image_name'], $b['image_name']);
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        return strcasecmp($a['image_url'], $b['image_url']);
    });
    return array_values($usageIndex);
}

function extractPageImages($html, $pageUrl, $cssScope, $includeSvgs) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $occurrences = extractImageOccurrencesFromDom($dom, $xpath, $pageUrl);

    foreach ($xpath->query('//style') as $styleEl) {
        $occurrences = array_merge($occurrences, extractOccurrencesFromCssText($styleEl->textContent, $pageUrl, $xpath, 'style-block'));
    }

    if ($cssScope === 'linked') {
        foreach ($xpath->query('//link[@href]') as $linkEl) {
            $rel = strtolower(trim($linkEl->getAttribute('rel')));
            if ($rel !== '' && strpos($rel, 'stylesheet') === false) {
                continue;
            }
            $href = trim($linkEl->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $stylesheetUrl = resolveUrl($pageUrl, $href);
            if ($stylesheetUrl === '' || strpos($stylesheetUrl, 'data:') === 0) {
                continue;
            }
            $contentType = null;
            $cssText = fetchContent($stylesheetUrl, $contentType);
            if ($cssText === false || $cssText === '') {
                continue;
            }
            $occurrences = array_merge($occurrences, extractOccurrencesFromCssText($cssText, $stylesheetUrl, $xpath, 'linked-stylesheet'));
        }
    }

    $pageImages = [];
    foreach ($occurrences as $occurrence) {
        if (!$includeSvgs && isSvgUrl($occurrence['image_url'])) {
            continue;
        }
        if ($occurrence['image_name'] === '' && strpos($occurrence['image_url'], 'data:') !== 0) {
            continue;
        }
        mergeOccurrenceIntoPage($pageImages, $occurrence);
    }

    return finalizePageImages($pageImages, $pageUrl);
}

$result = [
    'last_updated' => date('Y-m-d H:i:s'),
    'checked_urls' => [],
    'image_usage_index' => [],
    'summary' => [
        'processed_urls' => 0,
        'matched_images' => 0,
        'include_svgs' => $includeSvgs,
        'css_scope' => $cssScope,
        'skip_slugs' => array_values($skipSlugs),
    ],
];

$urls = [];
if ($singleMode) {
    $urls = [$inputUrl];
} elseif (preg_match('/sitemap\.xml$/i', $inputUrl)) {
    $allUrls = getUrlsFromSitemap($inputUrl);
    foreach ($allUrls as $url) {
        if (!shouldSkipUrl($url, $skipSlugs)) {
            $urls[] = $url;
        }
    }
} else {
    foreach (crawlSite($inputUrl) as $url) {
        if (!shouldSkipUrl($url, $skipSlugs)) {
            $urls[] = $url;
        }
    }
}

if (empty($urls)) {
    echo "No URLs found to check.\n";
    cleanupFiles();
    if (file_exists($outFile)) {
        unlink($outFile);
    }
    exit(1);
}

$total = count($urls);
$processed = 0;
$existingPid = getExistingPid($progressFile);
file_put_contents($progressFile, json_encode([
    'processed' => 0,
    'total' => $total,
    'done' => false,
    'start_url' => $inputUrl,
    'pid' => $existingPid ?? getmypid(),
]));

$alreadyProcessed = [];
$usageIndex = [];

foreach ($urls as $url) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    $norm = normalizeUrl($url);
    if ($norm === '' || isset($alreadyProcessed[$norm]) || isUrlChecked($url, $checkedFile)) {
        continue;
    }

    $alreadyProcessed[$norm] = true;
    markUrlChecked($url, $checkedFile);
    $result['checked_urls'][] = $url;

    echo "Fetching: $url\n";
    $html = fetchPage($url);
    if ($html !== false && $html !== '') {
        $pageImages = extractPageImages($html, $url, $cssScope, $includeSvgs);
        foreach ($pageImages as $pageImage) {
            mergeUsageIndexEntry($usageIndex, $url, $pageImage);
        }
    }

    $processed++;
    $result['summary']['processed_urls'] = $processed;
    $result['last_updated'] = date('Y-m-d H:i:s');

    file_put_contents($progressFile, json_encode([
        'processed' => $processed,
        'total' => $total,
        'done' => false,
        'start_url' => $inputUrl,
        'pid' => $existingPid ?? getmypid(),
    ]));
}

$result['image_usage_index'] = finalizeUsageIndex($usageIndex);
$result['summary']['total_urls'] = $total;
$result['summary']['matched_images'] = count($result['image_usage_index']);
file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($progressFile, json_encode([
    'processed' => $processed,
    'total' => $total,
    'done' => true,
    'start_url' => $inputUrl,
    'pid' => $existingPid ?? getmypid(),
]));

sleep(2);
if (file_exists($progressFile)) {
    unlink($progressFile);
}
if (file_exists($checkedFile)) {
    unlink($checkedFile);
}

echo $result['image_usage_index'] === []
    ? "No images found. Checked URLs were saved to $outFile\n"
    : "Done. See $outFile\n";

