<?php
$resultsDir = __DIR__ . '/results';
$progressDir = __DIR__ . '/progress';
$configDir = __DIR__ . '/configs';
$logsDir = __DIR__ . '/logs';

foreach ([$resultsDir, $progressDir, $configDir, $logsDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function sanitizeSuffix(string $suffix): string
{
    return preg_replace('/[^\w\-]+/', '-', trim($suffix));
}

function parseFilterLines(string $input): array
{
    $filters = [];
    $lines = preg_split('/\R+/', $input) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (stripos($line, 'regex:') === 0) {
            $filters[] = [
                'type' => 'regex',
                'value' => trim(substr($line, 6)),
            ];
            continue;
        }
        if (stripos($line, 'exact:') === 0) {
            $filters[] = [
                'type' => 'exact',
                'value' => trim(substr($line, 6)),
            ];
            continue;
        }
        $filters[] = [
            'type' => 'substring',
            'value' => $line,
        ];
    }
    return $filters;
}

function parseSkipSlugs(string $input): array
{
    $parts = preg_split('/[\r\n,]+/', $input) ?: [];
    $normalized = [];
    foreach ($parts as $part) {
        $slug = trim(trim($part), "/ ");
        if ($slug === '') {
            continue;
        }
        $normalized[] = function_exists('mb_strtolower') ? mb_strtolower($slug, 'UTF-8') : strtolower($slug);
    }

    return array_values(array_unique($normalized));
}

function getResultsFilePath(string $filename): ?string
{
    if ($filename === '') {
        return null;
    }
    $path = __DIR__ . '/results/' . basename($filename);
    return is_readable($path) ? $path : null;
}

function loadResults(string $filename): array
{
    $path = getResultsFilePath($filename);
    if (!$path) {
        return [];
    }
    return json_decode((string) file_get_contents($path), true) ?: [];
}

function getResultsLastUpdated(string $filename): ?int
{
    $path = getResultsFilePath($filename);
    if (!$path) {
        return null;
    }
    clearstatcache(true, $path);
    $mtime = filemtime($path);
    return $mtime === false ? null : $mtime;
}

function getSeverityClass(string $category): string
{
    switch ($category) {
        case 'page-http-error':
        case 'page-navigation-error':
        case 'request-http-error':
        case 'request-failed':
        case 'console-error':
        case 'pageerror':
            return 'danger';
        case 'console-warning':
            return 'warning';
        default:
            return 'secondary';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_scan'])) {
    header('Content-Type: application/json');

    $sourceUrl = trim((string) ($_POST['source_url'] ?? ''));
    $suffix = sanitizeSuffix((string) ($_POST['suffix'] ?? ''));
    $sourceType = (string) ($_POST['source_type'] ?? 'auto');
    $maxPages = max(1, (int) ($_POST['max_pages'] ?? 100));
    $sleepBetweenRequestsSeconds = max(0, (float) ($_POST['sleep_between_requests_seconds'] ?? 8));
    $pageTimeoutMs = max(1000, (int) ($_POST['page_timeout_ms'] ?? 30000));
    $waitAfterLoadMs = max(0, (int) ($_POST['wait_after_load_ms'] ?? 1500));
    $includeWarnings = !empty($_POST['include_warnings']);
    $sameDomainOnly = !empty($_POST['same_domain_only']);
    $consoleIgnore = parseFilterLines((string) ($_POST['console_ignore'] ?? ''));
    $requestIgnore = parseFilterLines((string) ($_POST['request_ignore'] ?? ''));
    $skipSlugs = parseSkipSlugs((string) ($_POST['skip_slug'] ?? ''));

    if ($sourceUrl === '' || !preg_match('#^https?://#i', $sourceUrl)) {
        http_response_code(422);
        echo json_encode(['error' => 'Please provide a valid http(s) source URL.']);
        exit;
    }

    if ($suffix === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Please provide a suffix using only letters, numbers, underscores, or dashes.']);
        exit;
    }

    $nodeBinary = trim((string) shell_exec('command -v node'));
    if ($nodeBinary === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Node.js was not found in PATH for the PHP process.']);
        exit;
    }

    $config = [
        'source' => [
            'type' => in_array($sourceType, ['auto', 'sitemap', 'single'], true) ? $sourceType : 'auto',
            'url' => $sourceUrl,
        ],
        'crawl' => [
            'maxPages' => $maxPages,
            'sameDomainOnly' => $sameDomainOnly,
            'concurrency' => 2,
            'skipSlugs' => $skipSlugs,
            'sleepBetweenRequestsSeconds' => $sleepBetweenRequestsSeconds,
            'pageTimeoutMs' => $pageTimeoutMs,
            'waitAfterLoadMs' => $waitAfterLoadMs,
            'sitemapTimeoutMs' => 20000,
        ],
        'browser' => [
            'headless' => true,
            'ignoreHTTPSErrors' => true,
        ],
        'console' => [
            'includeErrors' => true,
            'includeWarnings' => $includeWarnings,
            'ignore' => $consoleIgnore,
        ],
        'requests' => [
            'trackFailedRequests' => true,
            'trackHttpErrors' => true,
            'ignoreResourceTypes' => [],
            'ignoreUrlPatterns' => $requestIgnore,
        ],
    ];

    $configPath = $configDir . '/config-' . $suffix . '.json';
    $progressPath = $progressDir . '/progress-' . $suffix . '.json';
    $outputPath = $resultsDir . '/site-errors-' . $suffix . '.json';
    $logPath = $logsDir . '/scan-' . $suffix . '.log';

    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($progressPath, json_encode([
        'runId' => $suffix,
        'status' => 'starting',
        'done' => false,
        'processed' => 0,
        'total' => 0,
        'currentUrl' => null,
        'start_url' => $sourceUrl,
        'abortRequested' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    $cmdParts = [
        escapeshellarg($nodeBinary),
        escapeshellarg(__DIR__ . '/run-check.mjs'),
        '--config', escapeshellarg($configPath),
        '--output', escapeshellarg($outputPath),
        '--progress', escapeshellarg($progressPath),
        '--run-id', escapeshellarg($suffix),
    ];
    $command = implode(' ', $cmdParts);
    $fullCommand = 'nohup ' . $command . ' > ' . escapeshellarg($logPath) . ' 2>&1 & echo $!';
    $pid = (int) shell_exec($fullCommand);

    $progressData = json_decode((string) file_get_contents($progressPath), true) ?: [];
    $progressData['pid'] = $pid;
    file_put_contents($progressPath, json_encode($progressData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    echo json_encode([
        'suffix' => $suffix,
        'file' => basename($progressPath),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_results_file'])) {
    $file = basename((string) ($_POST['file'] ?? ''));
    if (preg_match('/^site-errors-([\w\-]+)\.json$/', $file, $matches)) {
        $suffix = $matches[1];
        foreach ([
            $resultsDir . '/site-errors-' . $suffix . '.json',
            $progressDir . '/progress-' . $suffix . '.json',
            $configDir . '/config-' . $suffix . '.json',
            $logsDir . '/scan-' . $suffix . '.log',
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$files = array_map('basename', glob($resultsDir . '/site-errors-*.json') ?: []);
usort($files, static function (string $a, string $b) use ($resultsDir): int {
    return filemtime($resultsDir . '/' . $b) <=> filemtime($resultsDir . '/' . $a);
});

$selected = isset($_GET['file']) ? basename((string) $_GET['file']) : ($files[0] ?? null);
$results = $selected ? loadResults($selected) : [];
$lastUpdated = $selected ? getResultsLastUpdated($selected) : null;
$pages = $results['pages'] ?? [];
$pagesWithIssues = array_values(array_filter($pages, static fn(array $page): bool => !empty($page['issues'])));
$issueCategories = [];
foreach ($pagesWithIssues as $page) {
    foreach (($page['issues'] ?? []) as $issue) {
        $category = trim((string) ($issue['category'] ?? ''));
        if ($category !== '' && !in_array($category, $issueCategories, true)) {
            $issueCategories[] = $category;
        }
    }
}
sort($issueCategories, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Site Error Scanner</title>
    <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .issue-text { white-space: pre-wrap; word-break: break-word; }
        .issue-location, .summary-code { font-family: monospace; font-size: 0.95rem; }
        .filter-checkbox-list { max-height: 190px; overflow-y: auto; }
        .results-filter-modal {
            position: fixed;
            inset: 0;
            z-index: 1080;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(33, 37, 41, 0.55);
        }
        .results-filter-modal.is-open { display: flex; }
        .results-filter-modal-dialog {
            width: min(960px, 100%);
            max-height: calc(100vh - 2rem);
            overflow: auto;
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.25);
        }
        .sticky-progress {
            position: fixed;
            bottom: 16px;
            right: 16px;
            z-index: 9999;
            min-width: 280px;
            max-width: 360px;
            display: none;
            background: #fff;
            border: 1px solid #d0d7de;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="mb-3">
        <a href="../index.php" class="btn btn-outline-primary">&larr; Back to Home</a>
    </div>

    <h1 class="mb-4">Site Error Scanner</h1>
    <p class="text-muted">Scan a sitemap or single page with Playwright, collect page/request failures, record console errors, and optionally include warnings.</p>

    <div id="all-progress-panel" class="sticky-progress">
        <strong>Ongoing scans</strong>
        <ul id="all-progress-list" class="list-unstyled mb-0 mt-2"></ul>
    </div>

    <form id="site-error-form" class="card shadow-sm mb-4" method="post">
        <div class="card-body">
            <input type="hidden" name="start_scan" value="1">
            <div class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label for="source_url" class="form-label">Source URL</label>
                    <input type="url" class="form-control" id="source_url" name="source_url" placeholder="https://example.com/sitemap.xml" required>
                </div>
                <div class="col-lg-2">
                    <label for="suffix" class="form-label">Suffix</label>
                    <input type="text" class="form-control" id="suffix" name="suffix" pattern="^[\w\-]+$" required>
                </div>
                <div class="col-lg-2">
                    <label for="source_type" class="form-label">Source type</label>
                    <select class="form-select" id="source_type" name="source_type">
                        <option value="auto" selected>Auto</option>
                        <option value="sitemap">Sitemap</option>
                        <option value="single">Single page</option>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label for="max_pages" class="form-label">Max pages</label>
                    <input type="number" class="form-control" id="max_pages" name="max_pages" min="1" value="100">
                </div>
                <div class="col-lg-2 d-grid">
                    <button type="submit" class="btn btn-primary">Start scan</button>
                </div>
                <div class="col-lg-3">
                    <label for="page_timeout_ms" class="form-label">Page timeout (ms)</label>
                    <input type="number" class="form-control" id="page_timeout_ms" name="page_timeout_ms" min="1000" step="500" value="30000">
                </div>
                <div class="col-lg-3">
                    <label for="sleep_between_requests_seconds" class="form-label">Sleep between requests (seconds)</label>
                    <input type="number" class="form-control" id="sleep_between_requests_seconds" name="sleep_between_requests_seconds" min="0" step="0.1" value="8">
                </div>
                <div class="col-lg-3">
                    <label for="wait_after_load_ms" class="form-label">Extra wait after load (ms)</label>
                    <input type="number" class="form-control" id="wait_after_load_ms" name="wait_after_load_ms" min="0" step="100" value="1500">
                </div>
                <div class="col-lg-3 d-flex flex-column gap-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="include_warnings" name="include_warnings" value="1">
                        <label class="form-check-label" for="include_warnings">Include console warnings</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="same_domain_only" name="same_domain_only" value="1" checked>
                        <label class="form-check-label" for="same_domain_only">Same domain only</label>
                    </div>
                </div>
                <div class="col-lg-6">
                    <label for="console_ignore" class="form-label">Ignore console messages</label>
                    <textarea class="form-control" id="console_ignore" name="console_ignore" rows="5" placeholder="One filter per line. Plain text = substring. Prefix with regex: or exact:."></textarea>
                </div>
                <div class="col-lg-6">
                    <label for="request_ignore" class="form-label">Ignore request URLs</label>
                    <textarea class="form-control" id="request_ignore" name="request_ignore" rows="5" placeholder="One filter per line. Example: regex:.*googletagmanager.*"></textarea>
                </div>
                <div class="col-lg-3">
                    <label for="skip_slug" class="form-label">Skip Slugs (comma-separated):</label>
                    <input type="text" class="form-control" id="skip_slug" name="skip_slug" pattern="^[\w\-, ]*$">
                </div>
            </div>
        </div>
    </form>

    <div id="progress-container" class="alert alert-info d-none">
        <div class="d-flex align-items-center gap-3">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="flex-grow-1">
                <div id="progress-text">Processing…</div>
                <div class="progress mt-2" style="height: 8px;">
                    <div id="progress-bar" class="progress-bar" role="progressbar" style="width: 0"></div>
                </div>
            </div>
        </div>
    </div>

    <form class="mb-4" method="get">
        <label for="file" class="form-label">Choose results file</label>
        <select id="file" name="file" class="form-select" onchange="this.form.submit()">
            <?php if (!$files): ?>
                <option value="">No scans yet</option>
            <?php endif; ?>
            <?php foreach ($files as $file): ?>
                <option value="<?= htmlspecialchars($file) ?>"<?= $file === $selected ? ' selected' : '' ?>>
                    <?= htmlspecialchars(strtoupper(preg_replace('/^site-errors-|\.json$/', '', $file))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($selected && getResultsFilePath($selected)): ?>
        <div class="mb-3 d-flex gap-2 flex-wrap">
            <a href="results/<?= htmlspecialchars($selected) ?>" download class="btn btn-success">Download JSON results</a>
            <form method="post" onsubmit="return confirm('Delete this scan and its related files?');">
                <input type="hidden" name="delete_results_file" value="1">
                <input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
                <button type="submit" class="btn btn-outline-danger">Delete this scan</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($results): ?>
        <?php if ($lastUpdated): ?>
            <p><strong>Last updated:</strong> <span id="results-last-updated" data-timestamp="<?= (int) $lastUpdated ?>"></span></p>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h4">Summary</h2>
                <p class="summary-code mb-0">
                    <strong>Status:</strong> <?= htmlspecialchars((string) ($results['status'] ?? 'unknown')) ?> |
                    <strong>Pages discovered:</strong> <?= (int) ($results['summary']['pagesDiscovered'] ?? 0) ?> |
                    <strong>Pages scanned:</strong> <?= (int) ($results['summary']['pagesScanned'] ?? 0) ?> |
                    <strong>Pages with issues:</strong> <?= (int) ($results['summary']['pagesWithIssues'] ?? 0) ?> |
                    <strong>Console errors:</strong> <?= (int) ($results['summary']['consoleErrors'] ?? 0) ?> |
                    <strong>Console warnings:</strong> <?= (int) ($results['summary']['consoleWarnings'] ?? 0) ?> |
                    <strong>Page HTTP errors:</strong> <?= (int) ($results['summary']['pageHttpErrors'] ?? 0) ?> |
                    <strong>Navigation errors:</strong> <?= (int) ($results['summary']['pageNavigationErrors'] ?? 0) ?> |
                    <strong>Request HTTP errors:</strong> <?= (int) ($results['summary']['requestHttpErrors'] ?? 0) ?> |
                    <strong>Request failures:</strong> <?= (int) ($results['summary']['requestFailedErrors'] ?? 0) ?> |
                    <strong>Uncaught page errors:</strong> <?= (int) ($results['summary']['pageErrors'] ?? 0) ?> |
                    <strong>Ignored console messages:</strong> <?= (int) ($results['summary']['ignoredConsoleMessages'] ?? 0) ?> |
                    <strong>Sleep between requests:</strong> <?= htmlspecialchars(number_format((float) ($results['config']['crawl']['sleepBetweenRequestsSeconds'] ?? 0), 1)) ?>s
                </p>
            </div>
        </div>

        <?php if (!$pagesWithIssues): ?>
            <div class="alert alert-success">No issues matched the current scan settings.</div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
                <button type="button" class="btn btn-outline-secondary" id="open-results-filters">Search &amp; hide issues</button>
                <div class="text-muted small" id="results-filter-summary"></div>
            </div>

            <div class="results-filter-modal" id="results-filter-modal" aria-hidden="true">
                <div class="results-filter-modal-dialog">
                    <div class="p-4 border-bottom d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h2 class="h4 mb-1">Search &amp; hide issues</h2>
                            <p class="text-muted mb-0">Search across page metadata and issue details. Hide issue rows by selecting one or more issue types and message substrings to suppress.</p>
                        </div>
                        <button type="button" class="btn-close" id="close-results-filters" aria-label="Close"></button>
                    </div>
                    <div class="p-4">
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-4">
                                <label for="results-search" class="form-label">Search visible results</label>
                                <input type="search" class="form-control" id="results-search" placeholder="Search title, URL, message, failure text...">
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label d-block">Issue types to hide</label>
                                <div class="border rounded p-3 bg-light-subtle filter-checkbox-list">
                                    <?php foreach ($issueCategories as $issueCategory): ?>
                                        <?php $filterId = 'hide-type-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $issueCategory); ?>
                                        <div class="form-check">
                                            <input class="form-check-input js-hide-issue-type" type="checkbox" value="<?= htmlspecialchars($issueCategory) ?>" id="<?= htmlspecialchars($filterId) ?>">
                                            <label class="form-check-label" for="<?= htmlspecialchars($filterId) ?>"><?= htmlspecialchars($issueCategory) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">If you pick issue types but leave message filters empty, all matching issue rows in those types will be hidden.</div>
                            </div>
                            <div class="col-lg-4">
                                <label for="hide-messages" class="form-label">Messages to hide</label>
                                <textarea class="form-control" id="hide-messages" rows="6" placeholder="One substring per line. Example:&#10;net::ERR_NAME_NOT_RESOLVED"></textarea>
                                <div class="form-text">These are substring matches. Example: choose <code>request-failed</code> and add <code>net::ERR_NAME_NOT_RESOLVED</code>.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="clear-results-filters">Clear filters</button>
                            <button type="button" class="btn btn-primary" id="done-results-filters">Done</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-warning d-none" id="filtered-empty-state">No issues match the current search / hide filters.</div>
        <?php endif; ?>

        <?php foreach ($pagesWithIssues as $index => $page): ?>
            <div class="card shadow-sm mb-3 page-result-card" data-page-index="<?= (int) $index ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap js-page-meta-text">
                        <div>
                            <h3 class="h5 mb-1"><?= htmlspecialchars((string) ($page['title'] ?? 'Untitled page')) ?></h3>
                            <div><a href="<?= htmlspecialchars((string) ($page['url'] ?? '')) ?>" target="_blank"><?= htmlspecialchars((string) ($page['url'] ?? '')) ?></a></div>
                            <?php if (!empty($page['finalUrl']) && $page['finalUrl'] !== $page['url']): ?>
                                <div class="text-muted">Final URL: <?= htmlspecialchars((string) $page['finalUrl']) ?></div>
                            <?php endif; ?>
                            <div class="text-muted">Status: <?= htmlspecialchars((string) ($page['status'] ?? 'n/a')) ?> · Load state: <?= htmlspecialchars((string) ($page['loadState'] ?? 'n/a')) ?></div>
                        </div>
                        <div class="text-end">
                            <div><strong class="js-visible-issue-count"><?= count($page['issues'] ?? []) ?></strong> <span class="js-visible-issue-label">visible issue(s)</span></div>
                            <div class="small text-muted">Total issues: <?= count($page['issues'] ?? []) ?></div>
                            <?php if (!empty($page['ignoredConsoleMessages'])): ?>
                                <div class="small text-muted">Ignored console messages: <?= (int) $page['ignoredConsoleMessages'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <hr>
                    <?php foreach (($page['issues'] ?? []) as $issue): ?>
                        <div class="mb-3 issue-item" data-issue-category="<?= htmlspecialchars((string) ($issue['category'] ?? '')) ?>">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="badge text-bg-<?= htmlspecialchars(getSeverityClass((string) ($issue['category'] ?? ''))) ?>"><?= htmlspecialchars((string) ($issue['category'] ?? 'issue')) ?></span>
                                <?php if (isset($issue['status'])): ?>
                                    <span class="badge text-bg-light border">HTTP <?= (int) $issue['status'] ?></span>
                                <?php endif; ?>
                                <?php if (!empty($issue['resourceType'])): ?>
                                    <span class="badge text-bg-light border"><?= htmlspecialchars((string) $issue['resourceType']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($issue['occurrences']) && $issue['occurrences'] > 1): ?>
                                    <span class="badge text-bg-secondary">x<?= (int) $issue['occurrences'] ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($issue['text'])): ?>
                                <div class="issue-text"><?= htmlspecialchars((string) $issue['text']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($issue['url'])): ?>
                                <div class="issue-location">URL: <?= htmlspecialchars((string) $issue['url']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($issue['failureText'])): ?>
                                <div class="issue-location">Failure: <?= htmlspecialchars((string) $issue['failureText']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($issue['location']['url'])): ?>
                                <div class="issue-location">
                                    Source: <?= htmlspecialchars((string) $issue['location']['url']) ?>
                                    <?php if (isset($issue['location']['lineNumber'])): ?>
                                        :<?= (int) $issue['location']['lineNumber'] ?>
                                    <?php endif; ?>
                                    <?php if (isset($issue['location']['columnNumber'])): ?>
                                        :<?= (int) $issue['location']['columnNumber'] ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
const progressContainer = document.getElementById('progress-container');
const progressText = document.getElementById('progress-text');
const progressBar = document.getElementById('progress-bar');
const resultsFilterModal = document.getElementById('results-filter-modal');
const openResultsFiltersButton = document.getElementById('open-results-filters');
const closeResultsFiltersButton = document.getElementById('close-results-filters');
const doneResultsFiltersButton = document.getElementById('done-results-filters');
let progressInterval = null;
let currentProgressFile = null;

function formatTimestamp(elementId) {
    const element = document.getElementById(elementId);
    if (!element) {
        return;
    }
    const timestamp = Number(element.dataset.timestamp || 0);
    if (!Number.isFinite(timestamp) || timestamp <= 0) {
        return;
    }
    const date = new Date(timestamp * 1000);
    element.textContent = date.toLocaleString();
}

function updateMainProgress(progress) {
    if (!progress) {
        progressContainer.classList.add('d-none');
        return;
    }
    const total = Number(progress.total || 0);
    const processed = Number(progress.processed || 0);
    const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
    const currentUrlText = progress.currentUrl ? ` – ${progress.currentUrl}` : '';
    progressText.textContent = `${progress.status || 'running'}: ${processed} / ${total} (${percent}%)${currentUrlText}`;
    progressBar.style.width = `${percent}%`;
    progressContainer.classList.remove('d-none');

    if (progress.done) {
        progressContainer.classList.add('d-none');
        if (progressInterval) {
            clearInterval(progressInterval);
        }
        currentProgressFile = null;
        window.location.reload();
    }
}

function abortProgress(progressFile) {
    if (!confirm('Abort this scan?')) {
        return;
    }
    fetch('abort-progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `file=${encodeURIComponent(progressFile)}`
    }).then(() => pollAllProgress());
}

function pollAllProgress() {
    fetch('list-progress.php')
        .then((response) => response.json())
        .then((items) => {
            const panel = document.getElementById('all-progress-panel');
            const list = document.getElementById('all-progress-list');
            list.innerHTML = '';
            let foundCurrent = false;

            items.forEach((item) => {
                const total = Number(item.total || 0);
                const processed = Number(item.processed || 0);
                const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
                const li = document.createElement('li');
                const domain = item.domain ? `<div class="small text-muted">${item.domain}</div>` : '';
                const currentUrl = item.currentUrl ? `<div class="small text-muted text-break">${item.currentUrl}</div>` : '';
                li.className = 'mb-2';
                li.innerHTML = `
                    <div><strong>${item.file.replace(/^progress-/, '').replace(/\.json$/, '')}</strong> — ${processed}/${total} (${percent}%)</div>
                    ${domain}
                    ${currentUrl}
                    <button type="button" class="btn btn-sm btn-outline-danger mt-1">Abort</button>
                `;
                li.querySelector('button').addEventListener('click', () => abortProgress(item.file));
                list.appendChild(li);

                if (currentProgressFile && item.file === currentProgressFile) {
                    foundCurrent = true;
                    updateMainProgress(item);
                }
            });

            panel.style.display = items.length ? 'block' : 'none';
            if (currentProgressFile && !foundCurrent) {
                window.location.reload();
            }
        });
}

document.getElementById('site-error-form').addEventListener('submit', function (event) {
    event.preventDefault();
    fetch('', {
        method: 'POST',
        body: new FormData(this)
    })
        .then((response) => response.json())
        .then((data) => {
            if (data.error) {
                alert(data.error);
                return;
            }
            currentProgressFile = data.file;
            updateMainProgress({ processed: 0, total: 0, status: 'starting', done: false });
            if (progressInterval) {
                clearInterval(progressInterval);
            }
            progressInterval = setInterval(pollAllProgress, 1500);
            pollAllProgress();
        })
        .catch((error) => {
            alert(error.message || 'Failed to start scan.');
        });
});

function normalizeFilterLines(value) {
    return String(value || '')
        .split(/\r?\n/)
        .map((line) => line.trim().toLowerCase())
        .filter(Boolean);
}

function openResultsFiltersModal() {
    if (!resultsFilterModal) {
        return;
    }
    resultsFilterModal.classList.add('is-open');
    resultsFilterModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');
    const searchInput = document.getElementById('results-search');
    if (searchInput) {
        searchInput.focus();
    }
}

function closeResultsFiltersModal() {
    if (!resultsFilterModal) {
        return;
    }
    resultsFilterModal.classList.remove('is-open');
    resultsFilterModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');
}

function applyResultsFilters() {
    const searchInput = document.getElementById('results-search');
    const hideMessagesInput = document.getElementById('hide-messages');
    if (!searchInput || !hideMessagesInput) {
        return;
    }

    const searchQuery = searchInput.value.trim().toLowerCase();
    const hideMessages = normalizeFilterLines(hideMessagesInput.value);
    const selectedCategories = Array.from(document.querySelectorAll('.js-hide-issue-type:checked')).map((checkbox) => checkbox.value);
    const selectedCategorySet = new Set(selectedCategories);
    const cards = Array.from(document.querySelectorAll('.page-result-card'));
    let visiblePageCount = 0;
    let visibleIssueCount = 0;
    let totalIssueCount = 0;

    cards.forEach((card) => {
        const metaElement = card.querySelector('.js-page-meta-text');
        const pageMetaText = (metaElement ? metaElement.textContent : card.textContent || '').toLowerCase();
        const pageMatchesSearch = searchQuery === '' || pageMetaText.includes(searchQuery);
        const issues = Array.from(card.querySelectorAll('.issue-item'));
        let visibleIssuesOnPage = 0;

        issues.forEach((issue) => {
            totalIssueCount += 1;
            const category = issue.dataset.issueCategory || '';
            const issueText = (issue.textContent || '').toLowerCase();
            const hideByType = selectedCategorySet.has(category);
            const hideByMessage = hideMessages.length === 0 || hideMessages.some((message) => issueText.includes(message));
            const hiddenByFilter = hideByType && hideByMessage;
            const issueMatchesSearch = searchQuery === '' || issueText.includes(searchQuery) || pageMatchesSearch;
            const shouldShowIssue = !hiddenByFilter && issueMatchesSearch;
            issue.classList.toggle('d-none', !shouldShowIssue);
            if (shouldShowIssue) {
                visibleIssuesOnPage += 1;
                visibleIssueCount += 1;
            }
        });

        const pageVisible = visibleIssuesOnPage > 0;
        card.classList.toggle('d-none', !pageVisible);
        if (pageVisible) {
            visiblePageCount += 1;
        }

        const visibleCountElement = card.querySelector('.js-visible-issue-count');
        const visibleLabelElement = card.querySelector('.js-visible-issue-label');
        if (visibleCountElement) {
            visibleCountElement.textContent = String(visibleIssuesOnPage);
        }
        if (visibleLabelElement) {
            visibleLabelElement.textContent = visibleIssuesOnPage === 1 ? 'visible issue' : 'visible issues';
        }
    });

    const summaryElement = document.getElementById('results-filter-summary');
    if (summaryElement) {
        summaryElement.textContent = `${visiblePageCount} / ${cards.length} page(s) visible • ${visibleIssueCount} / ${totalIssueCount} issue(s) visible`;
    }

    const emptyState = document.getElementById('filtered-empty-state');
    if (emptyState) {
        emptyState.classList.toggle('d-none', visibleIssueCount > 0);
    }
}

const resultsSearchInput = document.getElementById('results-search');
const hideMessagesInput = document.getElementById('hide-messages');
const clearResultsFiltersButton = document.getElementById('clear-results-filters');
if (openResultsFiltersButton) {
    openResultsFiltersButton.addEventListener('click', openResultsFiltersModal);
}
if (closeResultsFiltersButton) {
    closeResultsFiltersButton.addEventListener('click', closeResultsFiltersModal);
}
if (doneResultsFiltersButton) {
    doneResultsFiltersButton.addEventListener('click', closeResultsFiltersModal);
}
if (resultsFilterModal) {
    resultsFilterModal.addEventListener('click', (event) => {
        if (event.target === resultsFilterModal) {
            closeResultsFiltersModal();
        }
    });
}
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && resultsFilterModal && resultsFilterModal.classList.contains('is-open')) {
        closeResultsFiltersModal();
    }
});
if (resultsSearchInput) {
    resultsSearchInput.addEventListener('input', applyResultsFilters);
}
if (hideMessagesInput) {
    hideMessagesInput.addEventListener('input', applyResultsFilters);
}
document.querySelectorAll('.js-hide-issue-type').forEach((checkbox) => {
    checkbox.addEventListener('change', applyResultsFilters);
});
if (clearResultsFiltersButton) {
    clearResultsFiltersButton.addEventListener('click', () => {
        if (resultsSearchInput) {
            resultsSearchInput.value = '';
        }
        if (hideMessagesInput) {
            hideMessagesInput.value = '';
        }
        document.querySelectorAll('.js-hide-issue-type').forEach((checkbox) => {
            checkbox.checked = false;
        });
        applyResultsFilters();
    });
}

formatTimestamp('results-last-updated');
pollAllProgress();
setInterval(pollAllProgress, 5000);
applyResultsFilters();
</script>
</body>
</html>

