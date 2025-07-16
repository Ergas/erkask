<?php
error_reporting(E_ERROR | E_PARSE);

$counter = 0;
$batchSize = 50;

$newIssuesByUrl = [];
$skipSlugs = [];
$inputUrl = $argv[1];
$suffix = ($argc >= 3 && preg_match('/^[\w\-]+$/', $argv[2]) && $argv[2] !== '--single') ? $argv[2] : '';
$singleMode = in_array('--single', $argv, true);
$dashSuffix = $suffix !== '' ? '-' . $suffix : '';
$progressFile = __DIR__ . "/progress$dashSuffix.json";
$outFile = __DIR__ . DIRECTORY_SEPARATOR . "headings_issues$dashSuffix.json";
$tempFile = __DIR__ . DIRECTORY_SEPARATOR . "headings_issues_temp$dashSuffix.json";
$checkedFile = __DIR__ . '/checked-headings-urls' . $dashSuffix . '.tmp';

file_put_contents($tempFile, '');

$prevIssues = file_exists($outFile) ? json_decode(file_get_contents($outFile), true) ?: [] : [];
$prevIssuesByUrl = [];

foreach ($argv as $arg) {
    if (preg_match('/^--skip-slug=(.+)$/', $arg, $m)) {
        $skipSlugs = array_filter(array_map('trim', explode(',', $m[1])));
    }
}
foreach ($prevIssues as $issue) {
    $normUrl = normalizeUrl($issue['url']);
    $prevIssuesByUrl[$normUrl] = $issue;
}

// Helper to check if URL should be skipped
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

// Helper functions for checked URLs
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

function fetchPage($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HeadingCheckerBot/1.0',
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
        $err = curl_error($ch);
        echo "cURL error for $url: $err\n";
    }
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
                // In sitemap index: recurse into each <loc> under <sitemap>
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
                // In regular sitemap: collect <loc> under <url>
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

function checkHeadings($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $headings = [];
    foreach (['h1','h2','h3','h4','h5','h6'] as $tag) {
        foreach ($xpath->query("//$tag") as $node) {
            $region_id = '';
            $parent = $node->parentNode;
            $section = '';
            while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
                if (!$region_id && $parent->hasAttribute('role') && $parent->getAttribute('role') === 'region') {
                    $region_id = $parent->getAttribute('id');
                }
                if (!$section && in_array(strtolower($parent->nodeName), ['header', 'main', 'footer'])) {
                    $section = strtolower($parent->nodeName);
                }
                $parent = $parent->parentNode;
            }
            $headings[] = [
                'tag' => $node->nodeName,
                'id' => $node->getAttribute('id'),
                'class' => $node->getAttribute('class'),
                'region_id' => $region_id,
                'section' => $section,
                'text' => trim($node->textContent)
            ];
        }
    }
    $lastLevel = 0;
    $lastLevelId = '';
    $lastHeadingText = '';
    $h1Count = 0;
    $errors = [];
    foreach ($headings as $idx => $heading) {
        $tag = $heading['tag'];
        $level = intval(substr($tag, 1));
        if ($level === 1) $h1Count++;
        if ($lastLevel && ($level > $lastLevel + 1)) {
            $errors[] = [
                "type" => "Hierarchy error",
                "id" => $heading['id'],
                "className" => $heading['class'],
                "section" => $heading['section'],
                "regionId" => $heading['region_id'],
                "message" => "Heading <$tag> (\"{$heading['text']}\") is not in correct hierarchy after <h$lastLevel> (Id: $lastLevelId; Text: \"$lastHeadingText\").",
                "solved" => false,
                "comments" => [],
            ];
        }
        $lastLevel = $level;
        $lastLevelId = $heading['id'] ?: ($heading['region_id'] ?: "no-id");
        $lastHeadingText = $heading['text'];
    }
    if ($h1Count === 0) {
        $errors[] = [
            "type" => "No <H1> found",
            "id" => null,
            "className" => null,
            "section" => null,
            "regionId" => null,
            "message" => null,
            "solved" => false,
            "comments" => [],
        ];
    } elseif ($h1Count > 1) {
        $h1s = [];
        foreach ($headings as $heading) {
            if (strtolower($heading['tag']) === 'h1') {
                $attrs = [];
                if ($heading['id']) $attrs[] = 'id="' . $heading['id'] . '"';
                if ($heading['class']) $attrs[] = 'class="' . $heading['class'] . '"';
                if ($heading['region_id']) $attrs[] = 'region_id="' . $heading['region_id'] . '"';
                if ($heading['section']) $attrs[] = 'section="' . $heading['section'] . '"';
                $h1s[] = '<h1' . ($attrs ? ' ' . implode(' ', $attrs) : '') . '>';
            }
        }
        $errors[] = [
            "type" => "Multiple H1 error",
            "id" => null,
            "className" => null,
            "section" => null,
            "regionId" => null,
            "message" => $h1s,
            "solved" => false,
            "comments" => [],
        ];
    }
    libxml_clear_errors();
    return $errors;
}

function getDomain($url) {
    $parts = parse_url($url);
    if (isset($parts['scheme']) && isset($parts['host'])) {
        $domain = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $domain .= ':' . $parts['port'];
        }
        return $domain;
    }
    if (isset($parts['host'])) {
        $domain = 'http://' . $parts['host'];
        if (isset($parts['port'])) {
            $domain .= ':' . $parts['port'];
        }
        return $domain;
    }
    if (isset($parts['path']) && $parts['path'] === 'localhost') {
        return 'http://localhost';
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

function normalizeUrl($url) {
    $parts = parse_url($url);
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
    $host = isset($parts['host']) ? strtolower($parts['host']) : '';
    $host = preg_replace('/^www\./', '', $host);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
    return "$scheme://$host$port" . ($path ? $path : '');
}

if ($argc < 2 || $argc > 4) {
    echo "Usage: php check-headings.php <url> [suffix] [--single]\n";
    exit(1);
}

if ($singleMode) {
    $urls = [$inputUrl];
} elseif (preg_match('/sitemap\.xml$/i', $inputUrl)) {
    $allUrls = getUrlsFromSitemap($inputUrl);
    $urls = [];
    foreach ($allUrls as $url) {
        if (shouldSkipUrl($url, $skipSlug)) continue;
        $urls[] = $url;
    }
} else {
    $urls = [];
    foreach (crawlSite($inputUrl) as $url) {
        if (shouldSkipUrl($url, $skipSlug)) continue;
        $urls[] = $url;
    }
}

if (empty($urls)) {
    echo "No URLs found to check.\n";
    exit(1);
}

$existingProgress = [];
if (file_exists($progressFile)) {
    $existingProgress = json_decode(file_get_contents($progressFile), true) ?: [];
    $existingPid = $existingProgress['pid'] ?? null;
} else {
    $existingPid = null;
}

$alreadyProcessed = [];

foreach ($urls as $url) {
    if (shouldSkipUrl($url, $skipSlug)) continue;

    $normUrl = normalizeUrl($url);
    if (isset($alreadyProcessed[$normUrl]) || isUrlChecked($url, $checkedFile)) {
        continue; // Skip duplicate or already checked URL
    }
    $alreadyProcessed[$normUrl] = true;
    markUrlChecked($url, $checkedFile);
    echo "Checking: $url from main loop\n";

    $html = fetchPage($url);
    if (empty($html)) {
        echo "Failed to fetch or empty HTML for $url\n";
        continue;
    }
    $errorObjs = checkHeadings($html);
    $issueId = md5($normUrl);
    $newIssuesByUrl[$normUrl] = [
        'id' => $issueId,
        'url' => $url,
        'error' => $errorObjs,
        'solved' => false,
    ];
    $counter++;
    file_put_contents($progressFile, json_encode([
        'processed' => $counter,
        'total' => count($urls),
        'done' => false,
        'start_url' => $inputUrl,
        'pid' => $existingPid
    ]));
    if ($counter % $batchSize === 0) {
        file_put_contents($tempFile, json_encode($newIssuesByUrl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $newIssuesByUrl = [];
    }
}

if (!empty($newIssuesByUrl)) {
    file_put_contents($tempFile, json_encode($newIssuesByUrl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Merge previous and new issues
$mergedIssues = [];
foreach ($newIssuesByUrl as $url => $newIssue) {
    $prevIssue = $prevIssuesByUrl[$url] ?? null;
    $mergedIssue = $newIssue;
    $mergedIssue['solved'] = $prevIssue['solved'] ?? false;
    $mergedIssue['comments'] = $prevIssue['comments'] ?? [];
    $prevErrors = $prevIssue['error'] ?? [];
    $newErrors = $newIssue['error'] ?? [];
    $mergedErrors = [];
    $prevErrorMap = [];
    foreach ($prevErrors as $err) {
        $key = md5(json_encode([$err['type'], $err['id'], $err['message']]));
        $prevErrorMap[$key] = $err;
    }
    foreach ($newErrors as $err) {
        $key = md5(json_encode([$err['type'], $err['id'], $err['message']]));
        if (isset($prevErrorMap[$key])) {
            $err['solved'] = $prevErrorMap[$key]['solved'] ?? false;
            $err['comments'] = $prevErrorMap[$key]['comments'] ?? [];
            unset($prevErrorMap[$key]);
        } else {
            $err['solved'] = false;
            $err['comments'] = [];
        }
        $mergedErrors[] = $err;
    }
    foreach ($prevErrorMap as $err) {
        $mergedErrors[] = $err;
    }
    $mergedIssue['error'] = $mergedErrors;
    $mergedIssues[] = $mergedIssue;
}

foreach ($prevIssuesByUrl as $url => $prevIssue) {
    if (!isset($newIssuesByUrl[$url])) {
        $mergedIssues[] = $prevIssue;
    }
}

file_put_contents($outFile, json_encode($mergedIssues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($tempFile, '');
file_put_contents($progressFile, json_encode([
    'processed' => $counter,
    'total' => count($urls),
    'done' => true,
    'start_url' => $inputUrl,
    'pid' => $existingPid
]));
sleep(5);
if (file_exists($tempFile)) {
    unlink($tempFile);
}
if (file_exists($progressFile)) {
    unlink($progressFile);
}
if (file_exists($checkedFile)) {
    unlink($checkedFile);
}
echo "Check complete. See $outFile for results.\n";
?>
