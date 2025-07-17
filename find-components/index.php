<?php
$files = glob('elements_found-*.json');
sort($files);
$selected = isset($_GET['file']) ? $_GET['file'] : (isset($files[0]) ? $files[0] : null);

function loadResults($filename) {
    if (!is_readable($filename)) return [];
    $json = file_get_contents($filename);
    return json_decode($json, true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['keyword'], $_POST['url'], $_POST['suffix'])) {
    $keyword = trim($_POST['keyword']);
    $url = trim($_POST['url']);
    $suffix = trim($_POST['suffix']);
    $single = !empty($_POST['single']) ? '--single' : '';
    $progressFile = __DIR__ . "/progress" . ($suffix !== '' ? "-$suffix" : "") . ".json";
    file_put_contents($progressFile, json_encode([
        'processed' => 0,
        'total' => 0,
        'done' => false,
        'start_url' => $url
    ]));
    $skipSlug = !empty($_POST['skip_slug']) ? trim($_POST['skip_slug']) : '';
    $cmd = "php find-elements.php " . escapeshellarg($keyword) . " " . escapeshellarg($url) . " " . escapeshellarg($suffix);
    if ($single) $cmd .= " $single";
    if ($skipSlug) $cmd .= " --skip-slug=" . escapeshellarg($skipSlug);    if ($single) $cmd .= " $single";
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
    if ($file && preg_match('/^elements_found-([\w\-]+)\.json$/', $file, $m)) {
        if (file_exists($file)) unlink($file);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$results = $selected ? loadResults($selected) : [];
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
            <div class="col-md-3">
                <label for="url" class="form-label">URL or Sitemap:</label>
                <input type="url" class="form-control" id="url" name="url" required>
            </div>
            <div class="col-md-2">
                <label for="suffix" class="form-label">Suffix:</label>
                <input type="text" class="form-control" id="suffix" name="suffix" pattern="^[\w\-]+$" required>
            </div>
            <div class="col-md-2">
                <label for="skip_slug" class="form-label">Skip Slug (comma-separated):</label>
                <input type="text" class="form-control" id="skip_slug" name="skip_slug" pattern="^[\w\-, ]*$">
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="single" name="single" value="1">
                    <label class="form-check-label" for="single">Single page</label>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Find Elements</button>
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
                    <?= htmlspecialchars(strtoupper(preg_replace('/^elements_found-|\..*$/', '', $file))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($selected && file_exists($selected)): ?>
        <a href="<?= htmlspecialchars($selected) ?>" download class="btn btn-success mb-3">
            Download JSON file with results
        </a>
    <?php endif; ?>
    <?php if ($selected && $results): ?>
        <p><strong>Last updated:</strong> <?= htmlspecialchars($results['last_updated'] ?? '') ?></p>
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
    const basePath = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/');
    function apiUrl(file) {
        return basePath + file;
    }
    let progressInterval = null;

    document.getElementById('find-elements-form').addEventListener('submit', function(e) {
        e.preventDefault();
        document.getElementById('progress-container').style.display = 'flex';
        const suffix = document.getElementById('suffix').value;
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

                    // If this is the current job, update the main progress bar
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

                        // If any progress is done, mark for deletion and refresh
                        if (item.done && !foundDone) {
                            foundDone = true;
                            doneFile = item.file;
                        }
                    });
                    panel.style.display = 'block';
                }
                if (disappeared.length > 0) {
                    deleteProgressFile(doneFile);
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
