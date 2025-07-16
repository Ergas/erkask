<?php
if ($argc < 3) {
    echo "Usage: php find-elements.php <keyword> <url> [suffix]\n";
    exit(1);
}
$keyword = strtolower($argv[1]);
$inputUrl = $argv[2];
$suffix = ($argc >= 4 && preg_match('/^[\w\-]+$/', $argv[3])) ? $argv[3] : '';
$outFile = __DIR__ . '/elements_found' . ($suffix ? "-$suffix" : "") . '.json';

$progressFile = __DIR__ . '/progress' . ($suffix ? "-$suffix" : "") . '.json';

// Initialize progress file
file_put_contents($progressFile, json_encode([
    'processed' => 0,
    'total' => 0,
    'done' => false,
    'start_url' => $inputUrl,
    'pid' => null,
]));

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
    $visited = [];
    $queue = [$startUrl];
    while ($queue) {
        $url = array_shift($queue);
        if (isset($visited[$url])) continue;
        $visited[$url] = true;
        yield $url;
        $html = fetchPage($url);
        if ($html === false) continue;
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = $a->getAttribute('href');
            $abs = filter_var($href, FILTER_VALIDATE_URL) ? $href : rtrim(getDomain($url), '/') . '/' . ltrim($href, '/');
            if (isInternal($startUrl, $abs) && !isset($visited[$abs])) {
                $queue[] = $abs;
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

if ($singleMode) {
    $urls = [$inputUrl];
} elseif (preg_match('/sitemap\.xml$/i', $inputUrl)) {
    $urls = getUrlsFromSitemap($inputUrl);
} else {
    $urls = [];
    foreach (crawlSite($inputUrl) as $url) {
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

// Now loop over $urls and find elements as before
foreach ($urls as $url) {
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
echo "Done. See $outFile\n";

