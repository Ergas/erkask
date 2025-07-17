<?php
if ($argc < 3) {
    echo "Error: Invalid arguments.\n";
    echo "Usage: php find-elements.php <keyword> <url> <suffix> [--single] [--skip-slug=<SLUG>]\n";
    exit(1);
}
$keyword = strtolower($argv[1]);
$inputUrl = $argv[2];
$suffix = ($argc >= 4 && preg_match('/^[\w\-]+$/', $argv[3])) ? $argv[3] : '';
$outFile = __DIR__ . '/elements_found' . ($suffix ? "-$suffix" : "") . '.json';
$progressFile = __DIR__ . '/progress' . ($suffix ? "-$suffix" : "") . '.json';
$checkedFile = __DIR__ . '/checked-urls' . ($suffix ? "-$suffix" : "") . '.tmp';

if ($suffix === '') {
    echo "Error: Suffix is required.\n";
    echo "Usage: php find-elements.php <keyword> <url> <suffix> [--single] [--skip-slug=<SLUG>]\n";
    exit(1);
}

// Initialize progress file
file_put_contents($progressFile, json_encode([
    'processed' => 0,
    'total' => 0,
    'done' => false,
    'start_url' => $inputUrl,
    'pid' => null,
]));

$skipSlugs = [];
foreach ($argv as $arg) {
    if (preg_match('/^--skip-slug=(.+)$/', $arg, $m)) {
        $skipSlugs = array_filter(array_map('trim', explode(',', $m[1])));
    }
}

function normalizeUrl($url) {
    $parts = parse_url(trim($url));
    if (!isset($parts['host'])) return '';
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
    $host = strtolower($parts['host']);
    $path = isset($parts['path']) ? rtrim($parts['path'], '/\\') : '';
    // Remove default index, fragments, and trailing slashes
    $normalized = $scheme . '://' . $host . ($path ? '/' . ltrim($path, '/') : '');
    return $normalized;
}

function isUrlChecked($url, $checkedFile) {
    $normalized = normalizeUrl($url);
    if (!file_exists($checkedFile)) return false;
    $fh = fopen($checkedFile, 'r');
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
    file_put_contents($checkedFile, $normalized . "\n", FILE_APPEND | LOCK_EX);
}

function shouldSkipUrl($url, $skipSlugs) {
    if (empty($skipSlugs)) return false;
    $parts = parse_url($url);
    if (!isset($parts['path'])) return false;
    foreach ($skipSlugs as $slug) {
        if (preg_match('#^/' . preg_quote($slug, '#') . '(/|$)#i', $parts['path'])) {
            return true;
        }
    }
    return false;
}

function fetchPage($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'ElementFinderBot/1.0',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

function getUrlsFromSitemap($sitemapUrl) {
    $urls = [];
    $xmlContent = @file_get_contents($sitemapUrl);
    if ($xmlContent === false) return [];
    $reader = new XMLReader();
    if (!$reader->XML($xmlContent)) return [];
    $isIndex = false;
    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT) {
            $localName = $reader->localName;
            if ($localName === 'sitemapindex') {
                $isIndex = true;
            }
            if ($isIndex && $localName === 'sitemap') {
                while ($reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'loc') {
                        $loc = $reader->readString();
                        $urls = array_merge($urls, getUrlsFromSitemap($loc));
                        break;
                    }
                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'sitemap') {
                        break;
                    }
                }
            } elseif (!$isIndex && $localName === 'url') {
                while ($reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'loc') {
                        $urls[] = $reader->readString();
                        break;
                    }
                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'url') {
                        break;
                    }
                }
            }
        }
    }
    $reader->close();
    return $urls;
}

function getDomain($url) {
    $parts = parse_url($url);
    if (isset($parts['scheme'], $parts['host'])) {
        $domain = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) $domain .= ':' . $parts['port'];
        return $domain;
    }
    return '';
}

function isInternal($base, $url) {
    $baseParts = parse_url($base);
    $urlParts = parse_url($url);
    return isset($urlParts['host'], $baseParts['host']) &&
        $urlParts['host'] === $baseParts['host'] &&
        (isset($urlParts['port']) ? $urlParts['port'] : null) === (isset($baseParts['port']) ? $baseParts['port'] : null);
}

function crawlSite($startUrl) {
    global $checkedFile;
    $visited = [];
    $queue = [$startUrl];
    while ($queue) {
        $url = array_shift($queue);
        $norm = normalizeUrl($url);
        if (isset($visited[$norm]) || isUrlChecked($url, $checkedFile)) continue;
        $visited[$norm] = true;
        markUrlChecked($url, $checkedFile);
        yield $url;
        $html = fetchPage($url);
        if ($html === false) continue;
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = $a->getAttribute('href');
            $abs = filter_var($href, FILTER_VALIDATE_URL) ? $href : rtrim(getDomain($url), '/') . '/' . ltrim($href, '/');
            if (isInternal($startUrl, $abs)) {
                $absNorm = normalizeUrl($abs);
                if (!isset($visited[$absNorm]) && !isUrlChecked($abs, $checkedFile)) {
                    $queue[] = $abs;
                }
            }
        }
        unset($dom, $xpath, $html);
    }
}

function getExistingPid($progressFile) {
    if (file_exists($progressFile)) {
        $data = json_decode(file_get_contents($progressFile), true);
        return $data['pid'] ?? null;
    }
    return null;
}


$result = [
    'last_updated' => date('Y-m-d H:i:s'),
    'results' => []
];

$singleMode = in_array('--single', $argv, true);

$urls = [];

if ($singleMode) {
    $urls = [$inputUrl];
} elseif (preg_match('/sitemap\.xml$/i', $inputUrl)) {
    if (preg_match('/sitemap\.xml$/i', $inputUrl)) {
        $allUrls = getUrlsFromSitemap($inputUrl);
        $urls = [];
        foreach ($allUrls as $url) {
            if (shouldSkipUrl($url, $skipSlugs)) continue;
            $urls[] = $url;
        }
    }
} else {
    $urls = [];
    foreach (crawlSite($inputUrl) as $url) {
        if (shouldSkipUrl($url, $skipSlugs)) continue;
        $urls[] = $url;
    }
}

if (empty($urls)) {
    echo "No URLs found to check.\n";
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
    'pid' => $existingPid ?? getmypid()
]));

$alreadyProcessed = [];

// Now loop over $urls and find elements as before
foreach ($urls as $url) {
    $norm = normalizeUrl($url);
    if (isset($alreadyProcessed[$norm]) || isUrlChecked($url, $checkedFile)) {
        continue; // Skip duplicate or already checked URL
    }
    $alreadyProcessed[$norm] = true;
    markUrlChecked($url, $checkedFile);

    $html = fetchPage($url);
    echo "Fetching: $url\n";
    if (!$html) continue;
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $matches = [];
    foreach ($dom->getElementsByTagName('*') as $el) {
        $tag = strtolower($el->tagName);
        $id = strtolower($el->getAttribute('id'));
        $class = strtolower($el->getAttribute('class'));
        if (
            strpos($tag, $keyword) !== false ||
            strpos($id, $keyword) !== false ||
            strpos($class, $keyword) !== false
        ) {
            $matches[] = [
                'tag' => $tag,
                'id' => $id,
                'class' => $class,
                'text' => trim($el->textContent)
            ];
        }
    }
    if ($matches) {
        $result['results'][] = [
            'url' => $url,
            'matches' => $matches
        ];
    }

    $processed++;
    file_put_contents($progressFile, json_encode([
        'processed' => $processed,
        'total' => $total,
        'done' => false,
        'start_url' => $inputUrl,
        'pid' => $existingPid ?? getmypid()
    ]));
}

file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($progressFile, json_encode([
    'processed' => $processed,
    'total' => $total,
    'done' => true,
    'start_url' => $inputUrl,
    'pid' => $existingPid ?? getmypid()
]));

sleep(5);
if (file_exists($progressFile)) {
    unlink($progressFile);
}
if (file_exists($checkedFile)) {
    unlink($checkedFile);
}
echo "Done. See $outFile\n";
