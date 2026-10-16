<?php
$configPath = __DIR__ . '/config.json';
$config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
$websites = is_array($config['websites'] ?? null) ? $config['websites'] : [];

function websiteSuffix($name) {
    return preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim((string) $name)));
}

$files = array_map('basename', glob(__DIR__ . '/elements_found-*.json'));
sort($files);
$selected = isset($_GET['file']) ? basename($_GET['file']) : (isset($files[0]) ? $files[0] : null);

function getResultsFilePath($filename) {
    if (!$filename) return null;
    $filePath = __DIR__ . '/' . basename($filename);
    return is_readable($filePath) ? $filePath : null;
}

function loadResults($filename) {
    $filePath = getResultsFilePath($filename);
    if (!$filePath) return [];
    $json = file_get_contents($filePath);
    return json_decode($json, true) ?: [];
}

function extractMatchedUrls(array $results): array {
    $matchedUrls = [];

    foreach (($results['results'] ?? []) as $result) {
        $url = trim((string) ($result['url'] ?? ''));
        if ($url !== '') {
            $matchedUrls[$url] = true;
        }
    }

    $matchedUrls = array_keys($matchedUrls);
    sort($matchedUrls, SORT_NATURAL | SORT_FLAG_CASE);

    return $matchedUrls;
}

function getResultsFileLastUpdated($filename) {
    $filePath = getResultsFilePath($filename);
    if (!$filePath) return null;

    clearstatcache(true, $filePath);
    $timestamp = filemtime($filePath);
    if ($timestamp === false) return null;

    return [
        'unix' => $timestamp,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['keyword'], $_POST['suffix'])) {
    $keyword = trim($_POST['keyword']);
    $url = trim($_POST['url']);
    $single = !empty($_POST['single']) ? '--single' : '';
    $suffix = trim($_POST['suffix']);
    $globalSkipSlugs = !empty($_POST['skip_slug']) ? trim($_POST['skip_slug']) : '';
    $multiSite = !empty($_POST['all_websites']);
    $jobs = [];

    if ($multiSite) {
        foreach ($websites as $website) {
            $name = websiteSuffix($website['name'] ?? '');
            $siteUrl = trim($website['url'] ?? '');
            if ($name === '' || $siteUrl === '') continue;
            $configuredSlugs = is_array($website['skipSlugs'] ?? null)
                ? implode(',', array_map('trim', $website['skipSlugs']))
                : '';
            $skipSlug = implode(',', array_filter([$configuredSlugs, $globalSkipSlugs]));
            $jobs[] = ['url' => $siteUrl, 'suffix' => "$name-$suffix", 'skipSlug' => $skipSlug];
        }
    } else {
        if ($url === '') {
            http_response_code(400);
            exit('URL is required');
        }
        $jobs[] = ['url' => $url, 'suffix' => $suffix, 'skipSlug' => $globalSkipSlugs];
    }

    foreach ($jobs as $job) {
        $progressFile = __DIR__ . "/progress-{$job['suffix']}.json";
        file_put_contents($progressFile, json_encode([
            'processed' => 0, 'total' => 0, 'done' => false, 'start_url' => $job['url']
        ]));
        $cmdParts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/find-elements.php'),
            escapeshellarg($keyword),
            escapeshellarg($job['url']),
            escapeshellarg($job['suffix']),
        ];
        if ($single && !$multiSite) $cmdParts[] = $single;
        if ($job['skipSlug'] !== '') $cmdParts[] = '--skip-slug=' . escapeshellarg($job['skipSlug']);
        if (!empty($_POST['search_id'])) $cmdParts[] = '--search-id';
        if (!empty($_POST['search_class'])) $cmdParts[] = '--search-class';
        $pid = (int) shell_exec("nohup " . implode(' ', $cmdParts) . " > /dev/null 2>&1 & echo $!");
        file_put_contents($progressFile, json_encode([
            'processed' => 0, 'total' => 0, 'done' => false,
            'start_url' => $job['url'], 'pid' => $pid
        ]));
    }

    header('Content-Type: application/json');
    echo json_encode([
        'suffixes' => array_column($jobs, 'suffix'),
        'suffix' => count($jobs) === 1 ? $jobs[0]['suffix'] : null
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_results_file'])) {
    $file = isset($_POST['file']) ? $_POST['file'] : null;
    if ($file && preg_match('/^elements_found-([\w\-]+)\.json$/', $file, $m)) {
        $filePath = __DIR__ . '/' . basename($file);
        if (file_exists($filePath)) unlink($filePath);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_matched_urls'], $_GET['file'])) {
    $filename = basename($_GET['file']);
    $exportResults = loadResults($filename);
    $matchedUrls = extractMatchedUrls($exportResults);
    $suffix = preg_replace('/^elements_found-|\.json$/i', '', $filename);
    $exportFilename = "matched-urls-$suffix.txt";

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    echo implode("\n", $matchedUrls);
    if (!empty($matchedUrls)) {
        echo "\n";
    }
    exit;
}

$selectedFilePath = getResultsFilePath($selected);
$results = $selected ? loadResults($selected) : [];
$lastUpdated = $selected ? getResultsFileLastUpdated($selected) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Element Finder JSON Viewer</title>
    <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .url-card { margin-bottom: 1.5rem; }
        .element-list li { font-family: monospace; }
        .element-tag { font-weight: bold; }
        .element-id { color: #007bff; }
        .element-class { color: #28a745; }
        .element-text { color: #6c757d; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="container mt-3 mb-4">
        <a href="../index.php" class="btn btn-outline-primary">&larr; Back to Home</a>
    </div>
    <h1 class="mb-4">Element Finder JSON Viewer</h1>
    <div id="all-progress-panel" style="position:fixed;top:16px;right:16px;z-index:9999;min-width:220px;max-width:320px;display:none;background:#fff;border:1px solid #ccc;padding:12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
        <strong>Ongoing Progress</strong>
        <ul id="all-progress-list" style="list-style:none;padding-left:0;margin-bottom:0"></ul>
    </div>
    <form class="mb-4" id="find-elements-form" method="post">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="keyword" class="form-label">Keywords (comma-separated):</label>
                <input type="text" class="form-control" id="keyword" name="keyword" required>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="search_id" name="search_id" value="1">
                <label class="form-check-label" for="search_id">Search by id</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="search_class" name="search_class" value="1" checked>
                <label class="form-check-label" for="search_class">Search by class</label>
            </div>
            <div class="col-md-3">
                <label for="url" class="form-label">URL or Sitemap:</label>
                <input type="url" class="form-control" id="url" name="url" required>
            </div>
            <div class="col-md-2">
                <label for="suffix" class="form-label">Suffix:</label>
                <input type="text" class="form-control" id="suffix" name="suffix" pattern="^[\w\-]+$" required>
            </div>
            <div class="col-md-2">
                <label for="skip_slug" class="form-label">Additional Skip Slugs:</label>
                <input type="text" class="form-control" id="skip_slug" name="skip_slug" pattern="^[\w\-, ]*$">
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="single" name="single" value="1">
                    <label class="form-check-label" for="single">Single page</label>
                </div>
                <?php if ($websites): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="all_websites" name="all_websites" value="1">
                        <label class="form-check-label" for="all_websites">All configured websites (<?= count($websites) ?>)</label>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary mt-2">Find Elements</button>
            </div>
        </div>
    </form>
    <?php if ($websites): ?>
        <p class="text-muted small">Websites and their saved skip slugs are loaded from <code>config.json</code>.
            Multi-site runs use the suffix <code>{website}-your-suffix</code>.</p>
    <?php endif; ?>
    <div id="progress-container" style="display:none; margin-bottom:1rem;">
        <div class="d-flex align-items-center">
            <div class="spinner-border text-primary me-3" role="status"></div>
            <div>
                <span id="progress-text">Processing...</span>
                <div class="progress mt-1" style="height: 8px; width: 200px;">
                    <div id="progress-bar" class="progress-bar" role="progressbar" style="width:0%"></div>
                </div>
            </div>
        </div>
    </div>
    <form class="mb-4" method="get">
        <label for="file" class="form-label">Choose results file:</label>
        <select id="file" name="file" class="form-select" onchange="this.form.submit()">
            <?php foreach ($files as $file): ?>
                <option value="<?= htmlspecialchars($file) ?>"<?= $file === $selected ? ' selected' : '' ?>>
                    <?= htmlspecialchars(strtoupper(preg_replace('/^elements_found-|\..*$/', '', $file))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($selectedFilePath): ?>
        <div class="mb-3 d-flex gap-2 flex-wrap">
            <a href="<?= htmlspecialchars($selected) ?>" download class="btn btn-success">
                Download JSON file with results
            </a>
            <a href="?export_matched_urls=1&amp;file=<?= urlencode($selected) ?>" class="btn btn-outline-success">
                Export matched URLs (.txt)
            </a>
        </div>
    <?php endif; ?>
    <?php if ($selected && $results): ?>
        <?php if ($lastUpdated): ?>
            <p>
                <strong>Last updated:</strong>
                <span
                    id="results-last-updated"
                    data-last-updated-timestamp="<?= (int) $lastUpdated['unix'] ?>"
                ></span>
            </p>
        <?php endif; ?>
        <?php if (!empty($results['summary'])): ?>
            <p>
                <strong>Checked URLs:</strong> <?= (int) ($results['summary']['processed_urls'] ?? 0) ?>
                <?php if (isset($results['summary']['matched_urls'])): ?>
                    | <strong>Matched URLs:</strong> <?= (int) $results['summary']['matched_urls'] ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (empty($results['results'])): ?>
            <div class="alert alert-warning">No matching elements were found, but the checked URLs were saved to the JSON file.</div>
        <?php endif; ?>
        <?php foreach ($results['results'] as $res): ?>
            <div class="card url-card">
                <div class="card-header bg-primary text-white">
                    <a href="<?= htmlspecialchars($res['url']) ?>" target="_blank" class="text-white text-decoration-underline">
                        <?= htmlspecialchars($res['url']) ?>
                    </a>
                </div>
                <ul class="list-group list-group-flush element-list">
                    <?php foreach ($res['matches'] as $el): ?>
                        <li class="list-group-item">
                            <span class="element-tag">&lt;<?= htmlspecialchars($el['tag']) ?>&gt;</span>
                            <?php if ($el['id']): ?>
                                <span class="element-id">id="<?= htmlspecialchars($el['id']) ?>"</span>
                            <?php endif; ?>
                            <?php if ($el['class']): ?>
                                <span class="element-class">class="<?= htmlspecialchars($el['class']) ?>"</span>
                            <?php endif; ?>
                            <?php if ($el['text']): ?>
                                <span class="element-text">text="<?= htmlspecialchars($el['text']) ?>"</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <form method="post" onsubmit="return confirm('Are you sure you want to delete all results?');">
        <input type="hidden" name="delete_results_file" value="1">
        <input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
        <button type="submit" class="btn btn-danger">Delete All Results</button>
    </form>
</div>
<script>
    function updateLastUpdatedDisplay() {
        const element = document.getElementById('results-last-updated');
        if (!element) return;

        const timestamp = Number(element.dataset.lastUpdatedTimestamp);
        if (!Number.isFinite(timestamp)) return;

        const date = new Date(timestamp * 1000);
        if (Number.isNaN(date.getTime())) return;

        const pad = n => String(n).padStart(2, '0');
        const day = pad(date.getDate());
        const month = pad(date.getMonth() + 1);
        const year = date.getFullYear();
        const hours = pad(date.getHours());
        const minutes = pad(date.getMinutes());
        const seconds = pad(date.getSeconds());

        // Format: DD.MM.YYYY HH:MM:SS (24h)
        element.textContent = `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`;
    }

    function getProgressFileName(filePath) {
        return String(filePath || '').split('/').pop();
    }

    let progressInterval = null;
    const allWebsitesCheckbox = document.getElementById('all_websites');
    if (allWebsitesCheckbox) {
        allWebsitesCheckbox.addEventListener('change', function() {
            document.getElementById('url').required = !this.checked;
        });
    }

    document.getElementById('find-elements-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const allWebsites = document.getElementById('all_websites')?.checked;
        document.getElementById('url').required = !allWebsites;
        document.getElementById('progress-container').style.display = 'flex';
        fetch('', {
            method: 'POST',
            body: new FormData(this)
        })
            .then(res => res.json())
            .then(data => {
                if (progressInterval) clearInterval(progressInterval);
                window.currentProgressFiles = (data.suffixes || []).map(suffix => `progress-${suffix}.json`);
                progressInterval = setInterval(pollAllProgress, 2000);
                pollAllProgress();
            });
    });

    function pollAllProgress() {
        fetch('list-progress.php')
            .then(res => res.json())
            .then(list => {
                const panel = document.getElementById('all-progress-panel');
                const ul = document.getElementById('all-progress-list');
                ul.innerHTML = '';
                let anyActive = false;
                let currentJobVisible = false;
                list.forEach(item => {
                    const percent = item.total ? Math.round((item.processed / item.total) * 100) : 0;
                    let domain = '';
                    if (item.domain) {
                        domain = item.domain;
                    } else if (item.start_url) {
                        try {
                            domain = new URL(item.start_url).hostname;
                        } catch (e) {
                            domain = item.start_url;
                        }
                    }
                    const domainHtml = domain ? `<br><small style="color:#888">${domain}</small>` : '';
                    const abortBtn = item.pid
                        ? `<button class="btn btn-sm btn-danger ms-2" onclick="abortProgress('${item.file}')">Abort</button>`
                        : '';
                    const suffix = item.file.replace(/^.*\/progress-/, '').replace(/\.json$/, '');
                    const li = document.createElement('li');
                    li.innerHTML = `<strong>${suffix}</strong>: ${item.processed} / ${item.total} (${percent}%)${domainHtml} ${abortBtn}`;
                    ul.appendChild(li);

                    // If this is the current job, update the main progress bar
                    if (window.currentProgressFiles && window.currentProgressFiles.includes(getProgressFileName(item.file))) {
                        updateMainProgressBar(item);
                        anyActive = true;
                        currentJobVisible = true;
                    }
                });
                panel.style.display = list.length ? 'block' : 'none';
                if (!anyActive) {
                    document.getElementById('progress-container').style.display = 'none';
                }
                if (window.currentProgressFiles && !currentJobVisible) {
                    clearInterval(progressInterval);
                    window.currentProgressFiles = null;
                    location.reload();
                }
            });
    }

    function updateMainProgressBar(progress) {
        const container = document.getElementById('progress-container');
        const text = document.getElementById('progress-text');
        const bar = document.getElementById('progress-bar');
        if (progress && typeof progress.processed === 'number' && typeof progress.total === 'number') {
            const percent = progress.total ? Math.round((progress.processed / progress.total) * 100) : 0;
            text.textContent = `Processed: ${progress.processed} / ${progress.total} (${percent}%)`;
            bar.style.width = percent + '%';
            container.style.display = 'flex';
            if (progress.done) container.style.display = 'none';
        } else {
            container.style.display = 'none';
        }
    }

    function abortProgress(progressFile) {
        if (!confirm('Abort this job?')) return;
        fetch('abort-progress.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(progressFile)}`
        }).then(() => {
            pollAllProgress();
        });
    }

    function updateAllProgressPanel() {
        fetch('list-progress.php')
            .then(res => res.json())
            .then(list => {
                const panel = document.getElementById('all-progress-panel');
                const ul = document.getElementById('all-progress-list');
                ul.innerHTML = '';

                if (list.length === 0) {
                    panel.style.display = 'none';
                } else {
                    list.forEach(item => {
                        const percent = item.total ? Math.round((item.processed / item.total) * 100) : 0;
                        let domain = '';
                        if (item.domain) {
                            domain = item.domain;
                        } else if (item.start_url) {
                            try {
                                domain = new URL(item.start_url).hostname;
                            } catch (e) {
                                domain = item.start_url;
                            }
                        }
                        const domainHtml = domain ? `<br><small style="color:#888">${domain}</small>` : '';
                        const abortBtn = item.pid
                            ? `<button class="btn btn-sm btn-danger ms-2" onclick="abortProgress('${item.file}')">Abort</button>`
                            : '';
                        const suffix = item.file.replace(/^.*\/progress-/, '').replace(/\.json$/, '');
                        const li = document.createElement('li');
                        li.innerHTML = `<strong>${suffix}</strong>: ${item.processed} / ${item.total} (${percent}%)${domainHtml} ${abortBtn}`;
                        ul.appendChild(li);
                    });
                    panel.style.display = 'block';
                }
            });
    }
    function deleteProgressFile(progressFile) {
        fetch('delete-progress.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(progressFile)}`
        }).then(() => {
            location.reload();
        });
    }
    setInterval(updateAllProgressPanel, 1500);
    updateLastUpdatedDisplay();
    updateAllProgressPanel();
</script>
</body>
</html>
