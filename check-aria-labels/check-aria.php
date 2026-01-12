<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Logging function
$logFile = __DIR__ . '/debug-' . date('Y-m-d') . '.log';
function logDebug($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "$message\n";
}

logDebug("=== ARIA Checker Started ===");
logDebug("Arguments: " . json_encode($argv));

$counter = 0;
$batchSize = 50;

$newIssuesByUrl = [];
$skipSlugs = [];
$inputUrl = $argv[1] ?? '';
$suffix = ($argc >= 3 && preg_match('/^[\w\-]+$/', $argv[2]) && $argv[2] !== '--single') ? $argv[2] : '';
$singleMode = in_array('--single', $argv, true);
if ($suffix === '') {
    echo "Error: Suffix is required.\n";
    echo "Usage: php check-aria.php <url> <suffix> [--single] [--skip-slug=<SLUG>] [--attrs=aria-label,aria-labelledby] \n";
    exit(1);
}
$dashSuffix = $suffix !== '' ? '-' . $suffix : '';
$progressFile = __DIR__ . "/progress$dashSuffix.json";
$outFile = __DIR__ . DIRECTORY_SEPARATOR . "aria_issues$dashSuffix.json";
$tempFile = __DIR__ . DIRECTORY_SEPARATOR . "aria_issues_temp$dashSuffix.json";
$checkedFile = __DIR__ . '/checked-aria-urls' . $dashSuffix . '.tmp';

file_put_contents($tempFile, '');

$prevIssues = file_exists($outFile) ? json_decode(file_get_contents($outFile), true) ?: [] : [];
$prevIssuesByUrl = [];
foreach ($prevIssues as $issue) {
    $normUrl = normalizeUrl($issue['url']);
    $prevIssuesByUrl[$normUrl] = $issue;
}

foreach ($argv as $arg) {
    if (preg_match('/^--skip-slug=(.+)$/', $arg, $m)) {
        $skipSlugs = array_filter(array_map('trim', explode(',', $m[1])));
    }
}

// Parse attrs
$attrs = ['aria-label'];
foreach ($argv as $arg) {
    if (preg_match('/^--attrs=(.+)$/', $arg, $m)) {
        $list = trim($m[1]);
        if ($list !== '') {
            $parts = array_filter(array_map('trim', explode(',', $list)));
            if (!empty($parts)) $attrs = $parts;
        }
    }
}

// Helper: should skip
function shouldSkipUrl($url, $skipSlugs) {
    if (empty($skipSlugs)) return false;
    $parts = parse_url($url);
    if (!isset($parts['path'])) return false;
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
    if (!file_exists($checkedFile)) return false;
    $fh = fopen($checkedFile, 'r');
    while (($line = fgets($fh)) !== false) {
        if (trim($line) === $normalized) { fclose($fh); return true; }
    }
    fclose($fh);
    return false;
}

function markUrlChecked($url, $checkedFile) {
    $normalized = normalizeUrl($url);
    file_put_contents($checkedFile, $normalized . "\n", FILE_APPEND | LOCK_EX);
}

function fetchPage($url) {
    sleep(6);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AriaCheckerBot/1.0',
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
                while ($reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'loc') {
                        $loc = $reader->readString();
                        $urls = array_merge($urls, getUrlsFromSitemap($loc));
                        break;
                    }
                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'sitemap') break;
                }
            } elseif (!$isIndex && $localName === 'url') {
                while ($reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->localName == 'loc') {
                        $urls[] = $reader->readString();
                        break;
                    }
                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->localName == 'url') break;
                }
            }
        }
    }
    $reader->close();
    return $urls;
}

function checkAriaAttributes($html, $attrs) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $errors = [];
    foreach ($dom->getElementsByTagName('*') as $el) {
        foreach ($attrs as $attr) {
            if ($el->hasAttribute($attr)) {
                $val = trim($el->getAttribute($attr));
                if ($val === '') {
                    $tag = strtolower($el->tagName);
                    $textContent = trim($el->textContent);
                    // Limit text content to first 100 characters
                    if (strlen($textContent) > 100) {
                        $textContent = substr($textContent, 0, 100) . '...';
                    }
                    $errors[] = [
                        'attribute' => $attr,
                        'tag' => $tag,
                        'id' => $el->getAttribute('id'),
                        'class' => $el->getAttribute('class'),
                        'text' => $textContent,
                    ];
                }
            }
        }
    }
    libxml_clear_errors();
    return $errors;
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
    return isset($urlParts['host'], $baseParts['host']) && $urlParts['host'] === $baseParts['host'] && (isset($urlParts['port']) ? $urlParts['port'] : null) === (isset($baseParts['port']) ? $baseParts['port'] : null);
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

if ($argc < 2) {
    echo "Error: Invalid number of arguments.\n";
    echo "Usage: php check-aria.php <url> <suffix> [--single] [--skip-slug=<SLUG>] [--attrs=aria-label,aria-labelledby]\n";
    exit(1);
}

if ($singleMode) {
    $urls = [$inputUrl];
    logDebug("Single mode: checking 1 URL");
} elseif (preg_match('/sitemap\.xml$/i', $inputUrl)) {
    logDebug("Sitemap mode: fetching URLs from $inputUrl");
    $allUrls = getUrlsFromSitemap($inputUrl);
    logDebug("Sitemap returned " . count($allUrls) . " URLs");
    $urls = [];
    foreach ($allUrls as $url) {
        if (shouldSkipUrl($url, $skipSlugs)) continue;
        $urls[] = $url;
    }
    logDebug("After filtering: " . count($urls) . " URLs to check");
} else {
    logDebug("Crawl mode: starting from $inputUrl");
    $urls = [];
    foreach (crawlSite($inputUrl) as $url) {
        if (shouldSkipUrl($url, $skipSlugs)) continue;
        $urls[] = $url;
    }
    logDebug("Crawl found " . count($urls) . " URLs");
}

if (empty($urls)) {
    logDebug("ERROR: No URLs found to check!");
    echo "No URLs found to check.\n";
    exit(1);
}

logDebug("Total URLs to process: " . count($urls));

$existingProgress = [];
if (file_exists($progressFile)) {
    $existingProgress = json_decode(file_get_contents($progressFile), true) ?: [];
    $existingPid = $existingProgress['pid'] ?? null;
} else {
    $existingPid = null;
}

$alreadyProcessed = [];
foreach ($urls as $url) {
    if (shouldSkipUrl($url, $skipSlugs)) continue;

    $normUrl = normalizeUrl($url);
    if (isset($alreadyProcessed[$normUrl]) || isUrlChecked($url, $checkedFile)) {
        continue; // Skip duplicate or already checked URL
    }
    $alreadyProcessed[$normUrl] = true;
    markUrlChecked($url, $checkedFile);
    logDebug("Checking URL " . ($counter + 1) . "/" . count($urls) . ": $url");

    $html = fetchPage($url);
    if (empty($html)) {
        logDebug("  Failed to fetch or empty HTML for $url");
        continue;
    }
    $errorObjs = checkAriaAttributes($html, $attrs);
    logDebug("  Found " . count($errorObjs) . " issues");
    $issueId = md5($normUrl);
    $newIssuesByUrl[$normUrl] = [
        'id' => $issueId,
        'url' => $url,
        'issues' => $errorObjs,
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
        // Load existing temp data and merge with new batch
        $existingTemp = [];
        if (file_exists($tempFile)) {
            $existingTemp = json_decode(file_get_contents($tempFile), true) ?: [];
        }
        $merged = array_merge($existingTemp, $newIssuesByUrl);
        logDebug("Batch write at $counter URLs: writing " . count($newIssuesByUrl) . " new + " . count($existingTemp) . " existing = " . count($merged) . " total");
        file_put_contents($tempFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $newIssuesByUrl = [];
    }
}

// Write remaining URLs to temp (merge with existing)
if (!empty($newIssuesByUrl)) {
    $existingTemp = [];
    if (file_exists($tempFile)) {
        $existingTemp = json_decode(file_get_contents($tempFile), true) ?: [];
    }
    $merged = array_merge($existingTemp, $newIssuesByUrl);
    logDebug("Final write: writing " . count($newIssuesByUrl) . " new + " . count($existingTemp) . " existing = " . count($merged) . " total");
    file_put_contents($tempFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} else {
    logDebug("No remaining URLs to write (newIssuesByUrl is empty)");
}

// Merge previous and new issues
$mergedIssuesList = [];
$newTemp = [];
if (file_exists($tempFile)) {
    $tempContent = file_get_contents($tempFile);
    logDebug("Temp file exists, size: " . strlen($tempContent) . " bytes");
    $newTemp = json_decode($tempContent, true) ?: [];
    if (json_last_error() !== JSON_ERROR_NONE) {
        logDebug("JSON decode error: " . json_last_error_msg());
    }
    logDebug("Parsed temp file: " . count($newTemp) . " entries, type: " . gettype($newTemp));
} else {
    logDebug("Temp file does not exist: $tempFile");
}

logDebug("Merging results: " . count($newTemp) . " URLs in temp file");

// Convert associative array to indexed array and filter out URLs with no issues
foreach ($newTemp as $url => $newIssue) {
    // Skip if no issues found
    if (empty($newIssue['issues'])) {
        logDebug("  Skipping $url - no issues");
        continue;
    }

    $prevIssue = $prevIssuesByUrl[$url] ?? null;
    $mergedIssue = $newIssue;
    $prevIssues = $prevIssue['issues'] ?? [];
    $newIssues = $newIssue['issues'] ?? [];
    $mergedIssues = [];
    $prevIssueMap = [];
    foreach ($prevIssues as $iss) {
        $key = md5(json_encode([$iss['attribute'] ?? '', $iss['tag'] ?? '', $iss['id'] ?? '', $iss['class'] ?? '']));
        $prevIssueMap[$key] = $iss;
    }
    foreach ($newIssues as $iss) {
        $key = md5(json_encode([$iss['attribute'] ?? '', $iss['tag'] ?? '', $iss['id'] ?? '', $iss['class'] ?? '']));
        if (isset($prevIssueMap[$key])) {
            unset($prevIssueMap[$key]);
        }
        $mergedIssues[] = $iss;
    }
    foreach ($prevIssueMap as $iss) {
        $mergedIssues[] = $iss;
    }
    $mergedIssue['issues'] = $mergedIssues;
    $mergedIssuesList[] = $mergedIssue;
    logDebug("  Added " . $mergedIssue['url'] . " with " . count($mergedIssues) . " issues");
}

// Add prev issues that weren't included in new run (and have issues)
foreach ($prevIssuesByUrl as $url => $prevIssue) {
    if (!isset($newTemp[$url]) && !empty($prevIssue['issues'])) {
        $mergedIssuesList[] = $prevIssue;
        logDebug("  Kept previous " . $prevIssue['url'] . " with " . count($prevIssue['issues']) . " issues");
    }
}

$result = file_put_contents($outFile, json_encode($mergedIssuesList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($result === false) {
    logDebug("ERROR: Failed to write results file! Check permissions on $outFile");
    echo "ERROR: Failed to write results file!\n";
} else {
    logDebug("Saved results to $outFile (" . count($mergedIssuesList) . " URLs with issues) - $result bytes written");
    // Verify file exists
    if (file_exists($outFile)) {
        logDebug("File verified: exists with size " . filesize($outFile) . " bytes");
    } else {
        logDebug("WARNING: File was written but doesn't exist!");
    }
}
if (file_exists($tempFile)) unlink($tempFile);
file_put_contents($progressFile, json_encode([
    'processed' => $counter,
    'total' => count($urls),
    'done' => true,
    'start_url' => $inputUrl,
    'pid' => $existingPid
]));
sleep(2);
if (file_exists($progressFile)) unlink($progressFile);
if (file_exists($checkedFile)) unlink($checkedFile);

logDebug("=== Check complete ===");
echo "Check complete. See $outFile for results.\n";

?>

