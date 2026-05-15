<?php
$files = glob('aria_issues-*.json');
sort($files);
$selected = isset($_GET['file']) ? $_GET['file'] : (isset($files[0]) ? $files[0] : null);

function loadResults($filename) {
    if (!is_readable($filename)) return [];
    $json = file_get_contents($filename);
    return json_decode($json, true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_url'])) {
    $url = trim($_POST['new_url']);
    if (empty($_POST['new_suffix'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Suffix is required']);
        exit;
    }
    $suffix = trim($_POST['new_suffix']);
    $single = !empty($_POST['new_single']) ? '--single' : '';
    $skipSlug = !empty($_POST['new_skip_slug']) ? trim($_POST['new_skip_slug']) : '';
    $attrs = [];
    if (!empty($_POST['new_attrs']) && is_array($_POST['new_attrs'])) {
        $attrs = array_map('trim', $_POST['new_attrs']);
    }
    $attrString = $attrs ? implode(',', $attrs) : '';

    $progressFile = __DIR__ . "/progress" . ($suffix !== '' ? "-$suffix" : "") . ".json";
    file_put_contents($progressFile, json_encode([
        'processed' => 0,
        'total' => 0,
        'done' => false,
        'start_url' => $url
    ]));

    $cmd = "php check-aria.php " . escapeshellarg($url) . " " . escapeshellarg($suffix);
    if ($single) $cmd .= " $single";
    if ($skipSlug) $cmd .= " --skip-slug=" . escapeshellarg($skipSlug);
    if ($attrString) $cmd .= " --attrs=" . escapeshellarg($attrString);
    $fullCmd = "nohup $cmd > /dev/null 2>&1 & echo $!";
    $pid = (int) shell_exec($fullCmd);
    file_put_contents($progressFile, json_encode([
        'processed' => 0,
        'total' => 0,
        'done' => false,
        'start_url' => $url,
        'pid' => $pid
    ]));
    header('Content-Type: application/json');
    echo json_encode(['suffix' => $suffix]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_results_file'])) {
    $file = isset($_POST['file']) ? $_POST['file'] : null;
    if ($file && preg_match('/^aria_issues-([\w\-]+)\.json$/', $file, $m)) {
        if (file_exists($file)) unlink($file);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle export URLs to text file
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_urls'], $_GET['file'])) {
    $filename = $_GET['file'];
    $results = loadResults($filename);
    $selectedAttr = isset($_GET['attr']) ? $_GET['attr'] : '';

    $urls = [];
    foreach ($results as $result) {
        // Support both new 'issues' and old 'error' fields
        $issuesList = !empty($result['issues']) ? $result['issues'] : (!empty($result['error']) ? $result['error'] : []);
        if (empty($issuesList)) continue;

        $hasMatchingIssue = false;
        foreach ($issuesList as $issue) {
            if ($selectedAttr && $issue['attribute'] !== $selectedAttr) continue;
            $hasMatchingIssue = true;
            break;
        }

        if ($hasMatchingIssue) {
            $urls[] = $result['url'];
        }
    }

    $suffix = preg_replace('/^aria_issues-|\.json$/i', '', $filename);
    $exportFilename = "problematic-urls-$suffix.txt";

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    echo implode("\n", $urls);
    exit;
}

$results = $selected ? loadResults($selected) : [];

// Collect all attribute types (support both 'issues' and 'error' fields)
$attrTypes = [];
foreach ($results as $result) {
    $issuesList = !empty($result['issues']) ? $result['issues'] : (!empty($result['error']) ? $result['error'] : []);
    foreach ($issuesList as $issue) {
        if (!empty($issue['attribute'])) {
            $attrTypes[$issue['attribute']] = true;
        }
    }
}
$attrTypes = array_keys($attrTypes);
$selectedAttr = isset($_GET['attr']) ? $_GET['attr'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ARIA Attributes Checker</title>
    <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .url-card { margin-bottom: 1.5rem; }
        .element-list li { font-family: monospace; }
        .element-attr { font-weight: bold; color: #dc3545; }
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
    <h1 class="mb-4">ARIA Attributes Checker</h1>
    <div id="all-progress-panel" style="position:fixed;top:16px;right:16px;z-index:9999;min-width:220px;max-width:320px;display:none;background:#fff;border:1px solid #ccc;padding:12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
        <strong>Ongoing Progress</strong>
        <ul id="all-progress-list" style="list-style:none;padding-left:0;margin-bottom:0"></ul>
    </div>
    <form class="mb-4" id="check-aria-form" method="post">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="new_url" class="form-label">URL or Sitemap:</label>
                <input type="url" class="form-control" id="new_url" name="new_url" required>
            </div>
            <div class="col-md-2">
                <label for="new_suffix" class="form-label">Suffix:</label>
                <input type="text" class="form-control" id="new_suffix" name="new_suffix" pattern="^[\w\-]+$" required>
            </div>
            <div class="col-md-2">
                <label for="new_skip_slug" class="form-label">Skip Slug (comma-separated):</label>
                <input type="text" class="form-control" id="new_skip_slug" name="new_skip_slug" pattern="^[\w\-, ]*$">
            </div>
            <div class="col-md-4">
                <label class="form-label">ARIA attributes to check:</label>
                <div class="d-flex flex-wrap gap-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="attr_aria-label" name="new_attrs[]" value="aria-label" checked>
                        <label class="form-check-label" for="attr_aria-label">aria-label</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="attr_aria-labelledby" name="new_attrs[]" value="aria-labelledby">
                        <label class="form-check-label" for="attr_aria-labelledby">aria-labelledby</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="attr_aria-describedby" name="new_attrs[]" value="aria-describedby">
                        <label class="form-check-label" for="attr_aria-describedby">aria-describedby</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="attr_aria-hidden" name="new_attrs[]" value="aria-hidden">
                        <label class="form-check-label" for="attr_aria-hidden">aria-hidden</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="attr_aria-controls" name="new_attrs[]" value="aria-controls">
                        <label class="form-check-label" for="attr_aria-controls">aria-controls</label>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="new_single" name="new_single" value="1">
                    <label class="form-check-label" for="new_single">Single page</label>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Check ARIA</button>
            </div>
        </div>
    </form>
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
                    <?= htmlspecialchars(strtoupper(preg_replace('/^aria_issues-|\..*$/', '', $file))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if ($selected): ?>
        <form class="mb-3" method="get">
            <input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
            <label for="attr" class="form-label">Filter by attribute:</label>
            <select id="attr" name="attr" class="form-select" onchange="this.form.submit()">
                <option value="">All attributes</option>
                <?php foreach ($attrTypes as $attr): ?>
                    <option value="<?= htmlspecialchars($attr) ?>"<?= $attr === $selectedAttr ? ' selected' : '' ?>>
                        <?= htmlspecialchars($attr) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="mb-3">
            <a href="?export_urls=1&file=<?= urlencode($selected) ?><?= $selectedAttr ? '&attr=' . urlencode($selectedAttr) : '' ?>"
               class="btn btn-success" download>
                📄 Export Problematic URLs
            </a>
            <a href="<?= htmlspecialchars($selected) ?>" download class="btn btn-info ms-2">
                💾 Download JSON file
            </a>
        </div>
        <form method="post" class="mb-3" onsubmit="return confirm('Are you sure you want to delete this results file?');">
            <input type="hidden" name="delete_results_file" value="1">
            <input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">🗑️ Delete This Results File</button>
        </form>
    <?php endif; ?>

    <?php if ($selected && $results): ?>
        <?php foreach ($results as $res): ?>
            <?php
            // Filter by selected attribute
            // Support both new 'issues' field and old 'error' field for backward compatibility
            $issuesList = !empty($res['issues']) ? $res['issues'] : (!empty($res['error']) ? $res['error'] : []);
            $filteredIssues = [];
            foreach ($issuesList as $issue) {
                if (!$selectedAttr || $issue['attribute'] === $selectedAttr) {
                    $filteredIssues[] = $issue;
                }
            }
            if (empty($filteredIssues)) continue;
            ?>
            <div class="card url-card">
                <div class="card-header bg-primary text-white">
                    <a href="<?= htmlspecialchars($res['url']) ?>" target="_blank" class="text-white text-decoration-underline">
                        <?= htmlspecialchars($res['url']) ?>
                    </a>
                </div>
                <ul class="list-group list-group-flush element-list">
                    <?php foreach ($filteredIssues as $issue): ?>
                        <li class="list-group-item">
                            <span class="element-attr"><?= htmlspecialchars($issue['attribute']) ?>=""</span>
                            <span class="element-tag">&lt;<?= htmlspecialchars($issue['tag']) ?>&gt;</span>
                            <?php if ($issue['id']): ?>
                                <span class="element-id">id="<?= htmlspecialchars($issue['id']) ?>"</span>
                            <?php endif; ?>
                            <?php if ($issue['class']): ?>
                                <span class="element-class">class="<?= htmlspecialchars($issue['class']) ?>"</span>
                            <?php endif; ?>
                            <?php if ($issue['text']): ?>
                                <span class="element-text">text="<?= htmlspecialchars($issue['text']) ?>"</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
    const basePath = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
    function apiUrl(file) {
        return basePath + file;
    }
    let progressInterval = null;

    document.getElementById('check-aria-form').addEventListener('submit', function(e) {
        e.preventDefault();
        document.getElementById('progress-container').style.display = 'flex';
        const suffix = document.getElementById('new_suffix').value;
        fetch('', {
            method: 'POST',
            body: new FormData(this)
        })
            .then(res => res.json())
            .then(data => {
                if (progressInterval) clearInterval(progressInterval);
                window.currentProgressFile = `progress${data.suffix ? '-' + data.suffix : ''}.json`;
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

                    if (window.currentProgressFile && item.file === window.currentProgressFile) {
                        updateMainProgressBar(item);
                        anyActive = true;
                    }
                });
                panel.style.display = list.length ? 'block' : 'none';
                if (!anyActive) {
                    document.getElementById('progress-container').style.display = 'none';
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
            if (progress.done) {
                container.style.display = 'none';
                clearInterval(progressInterval);
                deleteProgressFile(window.currentProgressFile);
                location.reload();
            }
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
            updateAllProgressPanel();
        });
    }

    let previousProgressFiles = [];

    function updateAllProgressPanel() {
        fetch('list-progress.php')
            .then(res => res.json())
            .then(list => {
                const panel = document.getElementById('all-progress-panel');
                const ul = document.getElementById('all-progress-list');
                ul.innerHTML = '';
                const currentFiles = list.map(item => item.file);

                const disappeared = previousProgressFiles.filter(f => !currentFiles.includes(f));
                previousProgressFiles = currentFiles;

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
                if (disappeared.length > 0) {
                    deleteProgressFile(disappeared[0]);
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
    updateAllProgressPanel();
</script>
</body>
</html>

