<?php
$files = glob('headings_issues-*.json');
sort($files);
$selected = isset($_GET['file']) ? $_GET['file'] : (isset($files[0]) ? $files[0] : null);

function loadIssues($filename) {
    if (!is_readable($filename)) return [];
    $json = file_get_contents($filename);
    return json_decode($json, true) ?: [];
}

function saveIssues($filename, $data) {
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_url'])) {
    $url = trim($_POST['new_url']);
    if (empty($_POST['new_suffix'])) {
        exit;
    }
    $suffix = trim($_POST['new_suffix']);
    $single = !empty($_POST['new_single']) ? '--single' : '';

    // Use a unique progress file per suffix
    $progressFile = __DIR__ . "/progress" . ($suffix !== '' ? "-$suffix" : "") . ".json";
    file_put_contents($progressFile, json_encode([
        'processed' => 0,
        'total' => 0,
        'done' => false,
        'start_url' => $url
    ]));

    $cmd = "php check-headings.php " . escapeshellarg($url) . " " . escapeshellarg($suffix);
    if ($single) $cmd .= " $single";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_issues_file'])) {
    $file = isset($_POST['file']) ? $_POST['file'] : null;
    if ($file && preg_match('/^headings_issues-([\w\-]+)\.json$/', $file, $m)) {
        $suffix = $m[1];
        $filesToDelete = [
            $file,
            "headings_issues_temp-$suffix.json",
            "progress-$suffix.json",
        ];
        foreach ($filesToDelete as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle POST for error or issue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'], $_POST['issue_id'], $_POST['error_idx'])) {
    $filename = $_POST['file'];
    $issue_id = $_POST['issue_id'];
    $error_idx = (int)$_POST['error_idx'];
    $issues = loadIssues($filename);

    foreach ($issues as &$issue) {
        if ($issue['id'] === $issue_id && isset($issue['error'][$error_idx])) {
            // Comments
            if (!isset($issue['error'][$error_idx]['comments'])) {
                $issue['error'][$error_idx]['comments'] = [];
            }
            if (isset($_POST['add_comment'])) {
                $commentText = trim($_POST['add_comment']);
                if ($commentText !== '') {
                    $dt = new DateTime('now', new DateTimeZone('Europe/Tallinn'));
                    $timestamp = $dt->format('Y-m-d H:i:s');
                    $issue['error'][$error_idx]['comments'][] = [
                        'text' => $commentText,
                        'timestamp' => $timestamp
                    ];
                    saveIssues($filename, $issues);
                    echo 'OK';
                    exit;
                }
            }
            if (isset($_POST['delete_comment_idx'])) {
                $delIdx = (int)$_POST['delete_comment_idx'];
                if (isset($issue['error'][$error_idx]['comments'][$delIdx])) {
                    array_splice($issue['error'][$error_idx]['comments'], $delIdx, 1);
                    saveIssues($filename, $issues);
                    echo 'OK';
                    exit;
                }
            }
            // Error-level toggle
            if (isset($_POST['action'])) {
                $action = $_POST['action'];
                if ($action === 'solve') {
                    $issue['error'][$error_idx]['solved'] = true;
                } elseif ($action === 'unsolve') {
                    $issue['error'][$error_idx]['solved'] = false;
                }
                // Update group solved status
                $issue['solved'] = !array_filter($issue['error'], fn($err) => empty($err['solved']));
                saveIssues($filename, $issues);
                echo 'OK';
                exit;
            }
        }
    }
}

// Group-level toggle (no error_idx)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'], $_POST['issue_id'], $_POST['action']) && !isset($_POST['error_idx'])) {
    $filename = $_POST['file'];
    $issue_id = $_POST['issue_id'];
    $action = $_POST['action'];
    $issues = loadIssues($filename);

    foreach ($issues as &$issue) {
        if ($issue['id'] === $issue_id) {
            if ($action === 'solve') {
                $issue['solved'] = true;
                foreach ($issue['error'] as &$err) {
                    $err['solved'] = true;
                }
            } elseif ($action === 'unsolve') {
                $issue['solved'] = false;
                foreach ($issue['error'] as &$err) {
                    $err['solved'] = false;
                }
            }
            saveIssues($filename, $issues);
            echo 'OK';
            exit;
        }
    }
}

$issues = $selected ? loadIssues($selected) : [];

// Collect all error types
$errorTypes = [];
foreach ($issues as $issue) {
    if (!empty($issue['error'])) {
        foreach ($issue['error'] as $err) {
            if (!empty($err['type'])) {
                $errorTypes[$err['type']] = true;
            }
        }
    }
}
$errorTypes = array_keys($errorTypes);
$selectedType = isset($_GET['type']) ? $_GET['type'] : '';

// Extract ending from filename, e.g. headings_issues-investor.json -> INVESTOR
$ending = $selected
    ? strtoupper(preg_replace('/^headings_issues-|\.json$/i', '', $selected))
    : '';

// Count unsolved errors (respecting filter)
$unsolvedCount = 0;
foreach ($issues as $issue) {
    if (!empty($issue['error'])) {
        foreach ($issue['error'] as $err) {
            if ((empty($err['solved'])) && (!$selectedType || $err['type'] === $selectedType)) {
                $unsolvedCount++;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Heading Issues JSON Viewer<?= $ending ? " - $ending" : "" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .url-card { margin-bottom: 1.5rem; }
        .issue-list { margin-bottom: 0; }
        .issue-list li { font-family: monospace; }
        .hierarchy-error { background: #fff3cd; }
        .no-h1-error { background: #f8d7da; }
        .multiple-h1-error { background: #d1ecf1; }
        .unknown-error { background: #546d70; }
        .solved { opacity: 0.5; pointer-events: none; }
        .url-card.solved .card-header { pointer-events: auto; }
        .url-card.solved .unsolve-btn { pointer-events: auto; opacity: 1; }
        .error-solved { opacity: 0.5; pointer-events: none; }
        .error-solved .unsolve-btn { pointer-events: auto; opacity: 1; }
        .btn-xs {
            padding: 0.15rem 0.4rem;
            font-size: 0.75rem;
            line-height: 1.2;
            border-radius: 0.2rem;
        }
        .list-group-item.comment-item {
            background: transparent !important;
            border: none;
            padding-left: 0;
            padding-right: 0;
        }
        .comments-section {
            margin-top: 1.25rem !important; /* Increase as needed */
        }
        .info-text-line {
            font-size: 0.9rem;
            color: #6c757d;
            background-color: #e0f7fa;;
        }}
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="mb-4">Heading Issues JSON Viewer<?= $ending ? " - $ending" : "" ?></h1>
    <form class="mb-4" id="add-page-form" method="post">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="new_url" class="form-label">URL or Sitemap:</label>
                <input type="url" class="form-control" id="new_url" name="new_url" required>
            </div>
            <div class="col-md-3">
                <label for="new_suffix" class="form-label">Suffix (ee, eu, investor etc):</label>
                <input type="text" class="form-control" id="new_suffix" name="new_suffix" pattern="^[\w\-]+$" required>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="new_single" name="new_single" value="1">
                    <label class="form-check-label" for="new_single">Single page</label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">Add page</button>
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
    <p class="mb-3">
        <strong>Unsolved errors on this page:</strong> <?= $unsolvedCount ?>
    </p>
    <form class="mb-4" method="get">
        <label for="file" class="form-label">Choose issues file:</label>
        <select id="file" name="file" class="form-select" onchange="this.form.submit()">
            <?php foreach ($files as $file): ?>
                <option value="<?= htmlspecialchars($file) ?>"<?= $file === $selected ? ' selected' : '' ?>>
                    <?= htmlspecialchars(strtoupper(preg_replace('/^headings_issues-|\..*$/', '', $file))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <form class="mb-4" method="get">
        <input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
        <label for="type" class="form-label">Filter by error type:</label>
        <select id="type" name="type" class="form-select" onchange="this.form.submit()">
            <option value="">All types</option>
            <?php foreach ($errorTypes as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>"<?= $type === $selectedType ? ' selected' : '' ?>>
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <button id="collapse-solved-btn" class="btn btn-secondary mb-3">Collapse All Solved Issues</button>
    <div id="issues-list">
        <?php if ($selected): ?>

            <?php foreach ($issues as $issue): ?>
                <?php
                // Only show issues with errors of the selected type (or all if not filtered)
                $hasType = false;
                if (!empty($issue['error'])) {
                    foreach ($issue['error'] as $err) {
                        if (!$selectedType || $err['type'] === $selectedType) {
                            $hasType = true;
                            break;
                        }
                    }
                }
                if (empty($issue['error']) || !$hasType) continue;
                ?>
                <div class="card url-card<?= $issue['solved'] ? ' solved' : '' ?>" id="issue-<?= htmlspecialchars($issue['id']) ?>">
                    <div class="card-header bg-primary text-white">
                        <a href="<?= htmlspecialchars($issue['url']) ?>" target="_blank" class="text-white text-decoration-underline">
                            <?= htmlspecialchars($issue['url']) ?>
                        </a>
                        <div class="form-check form-switch float-end ms-2 issue-toggle-switch">
                            <input class="form-check-input" type="checkbox"
                                   id="issue-switch-<?= htmlspecialchars($issue['id']) ?>"
                                <?= $issue['solved'] ? 'checked' : '' ?>
                                   onchange="this.checked ? markIssueSolved('<?= htmlspecialchars($selected) ?>','<?= htmlspecialchars($issue['id']) ?>', this) : markIssueUnsolved('<?= htmlspecialchars($selected) ?>','<?= htmlspecialchars($issue['id']) ?>', this)">
                            <label class="form-check-label visually-hidden" for="issue-switch-<?= htmlspecialchars($issue['id']) ?>">
                                Mark all as solved
                            </label>
                        </div>
                        <?php if ($issue['solved']): ?>
                            <span class="badge bg-secondary float-end me-2">All Solved</span>
                        <?php endif; ?>
                    </div>
                    <ul class="list-group list-group-flush issue-list">
                        <?php foreach ($issue['error'] as $idx => $err): ?>
                            <?php
                            if ($selectedType && $err['type'] !== $selectedType) continue;
                            $type = isset($err['type']) ? $err['type'] : '';
                            $class = '';
                            if ($type === 'Hierarchy error') $class = 'hierarchy-error';
                            elseif ($type === 'No <H1> found') $class = 'no-h1-error';
                            elseif ($type === 'Multiple H1 error') $class = 'multiple-h1-error';
                            else $class = 'unknown-error';
                            $solved = !empty($err['solved']);
                            $comments = isset($err['comments']) ? $err['comments'] : [];
                            ?>
                            <li class="list-group-item <?= $class ?><?= $solved ? ' error-solved' : '' ?>" id="error-<?= htmlspecialchars($issue['id']) ?>-<?= $idx ?>">
                                <strong><?= htmlspecialchars($type) ?></strong>
                                <?php if ($type === 'Hierarchy error'): ?>
                                    <br>
                                    <?php
                                    // Extract tag name from message, e.g. "Heading <h4> is not in correct hierarchy after <h1>."
                                    $tagName = null;
                                    if (preg_match('/<([a-zA-Z0-9]+)>/', $err['message'], $matches)) {
                                        $tagName = $matches[1];
                                    }
                                    ?>
                                    <span class="info-text-line">
                                        Tag: <code><?= htmlspecialchars($tagName) ?></code>
                                        <?php if (!empty($err['regionId'])): ?>
                                            &nbsp;|&nbsp; Region ID:
                                            <a href="<?= htmlspecialchars($issue['url'] . '#' . $err['regionId']) ?>" target="_blank">
                                                <code><?= htmlspecialchars($err['regionId']) ?></code>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($err['className'])): ?>
                                            &nbsp;|&nbsp; Class: <code><?= htmlspecialchars($err['className']) ?></code>
                                        <?php endif; ?>
                                        <?php if (!empty($err['section'])): ?>
                                            &nbsp;|&nbsp; Section: <code><?= htmlspecialchars($err['section']) ?></code>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($err['message'])): ?>
                                    <br>
                                    <?php if (is_array($err['message'])): ?>
                                        <?php foreach ($err['message'] as $msg): ?>
                                            <?= htmlspecialchars($msg) ?><br>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($err['message']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$solved): ?>
                                    <button class="btn btn-sm btn-success float-end" onclick="markErrorSolved('<?= htmlspecialchars($selected) ?>','<?= htmlspecialchars($issue['id']) ?>',<?= $idx ?>, this)">Mark as Solved</button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-warning float-end unsolve-btn" onclick="markErrorUnsolved('<?= htmlspecialchars($selected) ?>','<?= htmlspecialchars($issue['id']) ?>',<?= $idx ?>, this)">Mark as Not Solved</button>
                                    <span class="badge bg-secondary float-end me-2">Solved</span>
                                <?php endif; ?>
                                <div class="mt-2 comments-section">
                                    <strong>Comments:</strong>
                                    <ul class="list-unstyled mb-1" id="comments-<?= htmlspecialchars($issue['id']) ?>-<?= $idx ?>">
                                        <?php foreach ($comments as $cIdx => $comment): ?>
                                            <li class="list-group-item comment-item d-flex justify-content-between align-items-center">
                                                <span>
                                                    <?= htmlspecialchars($comment['text']) ?>
                                                    <?php if (!empty($comment['timestamp'])): ?>
                                                        <small class="text-muted ms-2">(<?= htmlspecialchars($comment['timestamp']) ?>)</small>
                                                    <?php endif; ?>
                                                </span>
                                                <button class="btn btn-xs btn-danger ms-2" onclick="deleteErrorComment('<?= htmlspecialchars($selected) ?>','<?= htmlspecialchars($issue['id']) ?>',<?= $idx ?>,<?= $cIdx ?>, this)">Delete</button>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <div class="input-group input-group-sm">
                                        <textarea class="form-control" rows="1" placeholder="Add comment..." id="add-comment-<?= htmlspecialchars($issue['id']) ?>-<?= $idx ?>"></textarea>
                                        <button class="btn btn-outline-primary"
                                                onclick="addErrorComment('<?= htmlspecialchars($selected) ?>','<?= htmlspecialchars($issue['id']) ?>',<?= $idx ?>, this)">Add</button>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <form method="post" onsubmit="return confirm('Are you sure you want to delete all issues?');">
        <input type="hidden" name="delete_issues_file" value="1">
        <input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
        <button type="submit" class="btn btn-danger">Delete All Issues</button>
    </form>
</div>
<div id="all-progress-panel" style="position:fixed;top:16px;right:16px;z-index:9999;min-width:220px;max-width:320px;display:none;background:#fff;border:1px solid #ccc;padding:12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
    <strong>Ongoing Progress</strong>
    <ul id="all-progress-list" style="list-style:none;padding-left:0;margin-bottom:0"></ul>
</div>
<script>
    window.addEventListener('DOMContentLoaded', reorderIssueCards);
    function markErrorSolved(file, issueId, errorIdx, btn) {
        const scrollYBefore = window.scrollY;
        const card = document.getElementById(`issue-${issueId}`);
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(file)}&issue_id=${encodeURIComponent(issueId)}&error_idx=${errorIdx}&action=solve`
        }).then(() => {
            const li = document.getElementById(`error-${issueId}-${errorIdx}`);
            if (li) {
                li.classList.add('error-solved');
                // Check if all errors in this issue are solved
                const ul = li.parentNode;
                const allSolved = Array.from(ul.children).every(
                    item => item.classList.contains('error-solved')
                );
                if (allSolved && card) {
                    card.classList.add('solved');
                    reorderIssueCards();
                }
                // Always restore previous scroll position
                window.scrollTo({ top: scrollYBefore });
            }
        });
    }
    function markErrorUnsolved(file, issueId, errorIdx, btn) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(file)}&issue_id=${encodeURIComponent(issueId)}&error_idx=${errorIdx}&action=unsolve`
        }).then(() => location.reload());
    }

    // Move all solved .url-card elements to the end
    function markIssueSolved(file, issueId, btn) {
        const scrollYBefore = window.scrollY;
        const card = document.getElementById(`issue-${issueId}`);
        if (!card) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(file)}&issue_id=${encodeURIComponent(issueId)}&action=solve`
        }).then(() => {
            card.classList.add('solved');
            reorderIssueCards();
            // Always restore previous scroll position
            window.scrollTo({ top: scrollYBefore });
        });
    }
    function markIssueUnsolved(file, issueId, btn) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(file)}&issue_id=${encodeURIComponent(issueId)}&action=unsolve`
        }).then(() => {
            const card = document.getElementById(`issue-${issueId}`);
            if (card) {
                card.classList.remove('solved');
                reorderIssueCards();
            }
        });
    }
    function addErrorComment(file, issueId, errorIdx, btn) {
        const textarea = document.getElementById(`add-comment-${issueId}-${errorIdx}`);
        const comment = textarea.value.trim();
        if (!comment) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(file)}&issue_id=${encodeURIComponent(issueId)}&error_idx=${errorIdx}&add_comment=${encodeURIComponent(comment)}`
        }).then(() => {
            // Get current timestamp in the same format as PHP
            const now = new Date();
            const pad = n => n.toString().padStart(2, '0');
            const timestamp = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
            // Append comment to the list without reload
            const commentsList = document.getElementById(`comments-${issueId}-${errorIdx}`);
            const li = document.createElement('li');
            li.className = 'list-group-item comment-item d-flex justify-content-between align-items-center';
            li.innerHTML = `<span>${comment} <small class="text-muted ms-2">(${timestamp})</small></span>
            <button class="btn btn-xs btn-danger ms-2" onclick="deleteErrorComment('${file}','${issueId}',${errorIdx},${commentsList.children.length}, this)">Delete</button>`;
            commentsList.appendChild(li);
            textarea.value = '';
        });
    }
    function deleteErrorComment(file, issueId, errorIdx, commentIdx, btn) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(file)}&issue_id=${encodeURIComponent(issueId)}&error_idx=${errorIdx}&delete_comment_idx=${commentIdx}`
        }).then(() => location.reload());
    }

    function reorderIssueCards() {
        const issuesList = document.getElementById('issues-list');
        if (!issuesList) return;
        const cards = Array.from(issuesList.querySelectorAll('.url-card'));
        cards.sort((a, b) => {
            const aSolved = a.classList.contains('solved') ? 1 : 0;
            const bSolved = b.classList.contains('solved') ? 1 : 0;
            return aSolved - bSolved;
        });
        cards.forEach(card => issuesList.appendChild(card));
    }

    document.querySelectorAll('.mark-solved-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const errorDiv = this.closest('.error');
            errorDiv.dataset.solved = 'true';
            errorDiv.classList.add('solved');
            // Move to end of parent container
            errorDiv.parentNode.appendChild(errorDiv);
            // Optionally, update server via AJAX here
        });
    });

    function reorderErrors() {
        const container = document.getElementById('errors-container');
        const errors = Array.from(container.children);
        errors.sort((a, b) => {
            return (a.dataset.solved === 'true') - (b.dataset.solved === 'true');
        });
        errors.forEach(err => container.appendChild(err));
    }

    let progressInterval = null;

    document.getElementById('add-page-form').addEventListener('submit', function(e) {
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
                // Update Ongoing Progress panel
                const panel = document.getElementById('all-progress-panel');
                const ul = document.getElementById('all-progress-list');
                ul.innerHTML = '';
                let anyActive = false;
                list.forEach(item => {
                    const percent = item.total ? Math.round((item.processed / item.total) * 100) : 0;
                    const domain = item.domain ? `<br><small style="color:#888">${item.domain}</small>` : '';
                    const abortBtn = item.pid
                        ? `<button class="btn btn-sm btn-danger ms-2" onclick="abortProgress('${item.file}')">Abort</button>`
                        : '';
                    const li = document.createElement('li');
                    li.innerHTML = `<strong>${item.file.replace(/^progress-?|\.json$/g, '')}</strong>: ${item.processed} / ${item.total} (${percent}%)${domain} ${abortBtn}`;
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

    function deleteProgressFile(progressFile) {
        fetch('delete-progress.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(progressFile)}`
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
                    return;
                }
                list.forEach(item => {
                    const percent = Math.round((item.processed / item.total) * 100);
                    const domain = item.domain ? `<br><small style="color:#888">${item.domain}</small>` : '';
                    const abortBtn = item.pid
                        ? `<button class="btn btn-sm btn-danger ms-2" onclick="abortProgress('${item.file}')">Abort</button>`
                        : '';
                    const li = document.createElement('li');
                    li.innerHTML = `<strong>${item.file.replace(/^progress-?|\.json$/g, '')}</strong>: ${item.processed} / ${item.total} (${percent}%)${domain} ${abortBtn}`;
                    ul.appendChild(li);
                });
                panel.style.display = 'block';
            });
    }
    setInterval(updateAllProgressPanel, 1500);
    updateAllProgressPanel();

    function abortProgress(progressFile) {
        if (!confirm('Abort this job?')) return;
        fetch('abort-progress.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file=${encodeURIComponent(progressFile)}`
        }).then(() => {
            if (progressInterval) clearInterval(progressInterval);
            document.getElementById('progress-container').style.display = 'none';
            pollAllProgress();
        });
    }
    document.getElementById('collapse-solved-btn').addEventListener('click', function() {
        const solvedCards = document.querySelectorAll('.url-card.solved');
        solvedCards.forEach(card => {
            card.style.display = (card.style.display === 'none') ? '' : 'none';
        });
        this.textContent = this.textContent.includes('Collapse') ? 'Expand All Solved Issues' : 'Collapse All Solved Issues';
    });
</script>
</body>
</html>
