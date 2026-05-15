<?php
$files = array_map('basename', glob(__DIR__ . '/images_found-*.json'));
sort($files);
$selected = isset($_GET['file']) ? basename($_GET['file']) : ($files[0] ?? null);

function getResultsFilePath($filename) {
	if (!$filename) {
		return null;
	}
	$filePath = __DIR__ . '/' . basename($filename);
	return is_readable($filePath) ? $filePath : null;
}

function loadResults($filename) {
	$filePath = getResultsFilePath($filename);
	if (!$filePath) {
		return [];
	}
	return json_decode(file_get_contents($filePath), true) ?: [];
}

function getResultsFileLastUpdated($filename) {
	$filePath = getResultsFilePath($filename);
	if (!$filePath) {
		return null;
	}
	clearstatcache(true, $filePath);
	$timestamp = filemtime($filePath);
	return $timestamp === false ? null : ['unix' => $timestamp];
}

function appendUniqueValues(array &$target, array $values) {
	foreach ($values as $value) {
		$value = trim((string) $value);
		if ($value !== '' && !in_array($value, $target, true)) {
			$target[] = $value;
		}
	}
}

function normalizeImageRows($results) {
	$rowsByKey = [];

	foreach (($results['image_usage_index'] ?? []) as $usageKey => $image) {
		if (!is_array($image)) {
			continue;
		}
		$key = is_string($usageKey) ? $usageKey : (string) ($image['usage_key'] ?? md5((string) ($image['image_url'] ?? '')));
		if ($key === '') {
			continue;
		}
		if (!isset($rowsByKey[$key])) {
			$rowsByKey[$key] = [
				'usage_key' => $key,
				'image_name' => (string) ($image['image_name'] ?? ''),
				'image_url' => (string) ($image['image_url'] ?? ''),
				'normalized_image' => (string) ($image['normalized_image'] ?? ''),
				'source_types' => [],
				'source_attributes' => [],
				'aria_labels' => [],
				'pages' => [],
			];
		}
		appendUniqueValues($rowsByKey[$key]['source_types'], $image['source_types'] ?? []);
		appendUniqueValues($rowsByKey[$key]['source_attributes'], $image['source_attributes'] ?? []);
		appendUniqueValues($rowsByKey[$key]['aria_labels'], $image['aria_labels'] ?? []);

		foreach (($image['pages'] ?? []) as $page) {
			$pageUrl = is_array($page) ? trim((string) ($page['url'] ?? '')) : trim((string) $page);
			if ($pageUrl !== '' && !in_array($pageUrl, $rowsByKey[$key]['pages'], true)) {
				$rowsByKey[$key]['pages'][] = $pageUrl;
			}
		}
	}

	if (empty($rowsByKey) && !empty($results['results'])) {
		foreach (($results['results'] ?? []) as $pageResult) {
			$pageUrl = trim((string) ($pageResult['url'] ?? ''));
			foreach (($pageResult['images'] ?? []) as $image) {
				if (!is_array($image)) {
					continue;
				}
				$key = (string) ($image['usage_key'] ?? md5((string) ($image['image_url'] ?? '')));
				if ($key === '') {
					continue;
				}
				if (!isset($rowsByKey[$key])) {
					$rowsByKey[$key] = [
						'usage_key' => $key,
						'image_name' => (string) ($image['image_name'] ?? ''),
						'image_url' => (string) ($image['image_url'] ?? ''),
						'normalized_image' => (string) ($image['normalized_image'] ?? ''),
						'source_types' => [],
						'source_attributes' => [],
						'aria_labels' => [],
						'pages' => [],
					];
				}
				appendUniqueValues($rowsByKey[$key]['source_types'], $image['source_types'] ?? []);
				appendUniqueValues($rowsByKey[$key]['source_attributes'], $image['source_attributes'] ?? []);
				appendUniqueValues($rowsByKey[$key]['aria_labels'], $image['aria_labels'] ?? []);
				if ($pageUrl !== '' && !in_array($pageUrl, $rowsByKey[$key]['pages'], true)) {
					$rowsByKey[$key]['pages'][] = $pageUrl;
				}
			}
		}
	}

	$rows = array_values($rowsByKey);
	foreach ($rows as &$row) {
		sort($row['source_types']);
		sort($row['source_attributes']);
		sort($row['aria_labels']);
		sort($row['pages'], SORT_NATURAL | SORT_FLAG_CASE);
	}
	unset($row);

	usort($rows, function ($a, $b) {
		$nameCompare = strcasecmp($a['image_name'], $b['image_name']);
		if ($nameCompare !== 0) {
			return $nameCompare;
		}
		return strcasecmp($a['image_url'], $b['image_url']);
	});

	return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'], $_POST['suffix'])) {
	$url = trim($_POST['url']);
	$suffix = trim($_POST['suffix']);
	$single = !empty($_POST['single']) ? '--single' : '';
	$skipSlug = !empty($_POST['skip_slug']) ? trim($_POST['skip_slug']) : '';
	$includeSvgs = !empty($_POST['include_svgs']) ? '--include-svgs' : '';
	$cssScope = !empty($_POST['css_scope']) && $_POST['css_scope'] === 'linked' ? 'linked' : 'page';

	$progressFile = __DIR__ . '/progress-' . $suffix . '.json';
	file_put_contents($progressFile, json_encode([
		'processed' => 0,
		'total' => 0,
		'done' => false,
		'start_url' => $url,
	]));

	$cmdParts = [
		escapeshellarg(PHP_BINARY),
		escapeshellarg(__DIR__ . '/find-images.php'),
		escapeshellarg($url),
		escapeshellarg($suffix),
	];
	if ($single) {
		$cmdParts[] = $single;
	}
	if ($skipSlug !== '') {
		$cmdParts[] = '--skip-slug=' . escapeshellarg($skipSlug);
	}
	if ($includeSvgs) {
		$cmdParts[] = $includeSvgs;
	}
	$cmdParts[] = '--css-scope=' . escapeshellarg($cssScope);

	$cmd = implode(' ', $cmdParts);
	$fullCmd = "nohup $cmd > /dev/null 2>&1 & echo $!";
	$pid = (int) shell_exec($fullCmd);

	file_put_contents($progressFile, json_encode([
		'processed' => 0,
		'total' => 0,
		'done' => false,
		'start_url' => $url,
		'pid' => $pid,
	]));

	header('Content-Type: application/json');
	echo json_encode(['suffix' => $suffix]);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_results_file'])) {
	$file = $_POST['file'] ?? null;
	if ($file && preg_match('/^images_found-([\w\-]+)\.json$/', $file)) {
		$filePath = __DIR__ . '/' . basename($file);
		if (file_exists($filePath)) {
			unlink($filePath);
		}
	}
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_images'], $_GET['file'])) {
	$filename = basename($_GET['file']);
	$exportResults = loadResults($filename);
	$imageRows = normalizeImageRows($exportResults);
	$imageUrls = [];
	foreach ($imageRows as $row) {
		$imageUrl = trim((string) ($row['image_url'] ?? ''));
		if ($imageUrl !== '') {
			$imageUrls[] = $imageUrl;
		}
	}
	$imageUrls = array_values(array_unique($imageUrls));
	sort($imageUrls, SORT_NATURAL | SORT_FLAG_CASE);
	$suffix = preg_replace('/^images_found-|\.json$/i', '', $filename);
	$exportFilename = "all-images-$suffix.txt";
	header('Content-Type: text/plain; charset=UTF-8');
	header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
	echo implode("\n", $imageUrls);
	if (!empty($imageUrls)) {
		echo "\n";
	}
	exit;
}

$results = $selected ? loadResults($selected) : [];
$selectedFilePath = getResultsFilePath($selected);
$lastUpdated = $selected ? getResultsFileLastUpdated($selected) : null;
$imageRows = normalizeImageRows($results);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>Image Finder</title>
	<base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<style>
		body { background: #f8f9fa; }
		.url-card { margin-bottom: 1.5rem; }
		.image-meta { font-family: monospace; font-size: 0.95rem; }
		.image-url { word-break: break-all; }
		.usage-panel { display: none; border-top: 1px solid #e9ecef; padding-top: 1rem; margin-top: 1rem; }
		.usage-panel.is-open { display: block; }
		.usage-entry + .usage-entry { margin-top: 0.75rem; }
		.aria-badge { background: #fff3cd; color: #664d03; }
		.summary-code { font-family: monospace; }
	</style>
</head>
<body>
<div class="container py-4">
	<div class="container mt-3 mb-4">
		<a href="../index.php" class="btn btn-outline-primary">&larr; Back to Home</a>
	</div>
	<h1 class="mb-4">Image Finder</h1>

	<div id="all-progress-panel" style="position:fixed;top:16px;right:16px;z-index:9999;min-width:220px;max-width:320px;display:none;background:#fff;border:1px solid #ccc;padding:12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
		<strong>Ongoing Progress</strong>
		<ul id="all-progress-list" style="list-style:none;padding-left:0;margin-bottom:0"></ul>
	</div>

	<form class="mb-4" id="find-images-form" method="post">
		<div class="row g-3 align-items-end">
			<div class="col-md-4">
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
				<label for="css_scope" class="form-label">CSS coverage:</label>
				<select class="form-select" id="css_scope" name="css_scope">
					<option value="page" selected>Page styles only</option>
					<option value="linked">Page styles + linked CSS</option>
				</select>
			</div>
			<div class="col-md-2">
				<div class="form-check">
					<input class="form-check-input" type="checkbox" id="single" name="single" value="1">
					<label class="form-check-label" for="single">Single page</label>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" id="include_svgs" name="include_svgs" value="1">
					<label class="form-check-label" for="include_svgs">Include SVGs</label>
				</div>
				<button type="submit" class="btn btn-primary mt-2">Find Images</button>
			</div>
		</div>
	</form>

	<div id="progress-container" style="display:none; margin-bottom:1rem;">
		<div class="d-flex align-items-center">
			<div class="spinner-border text-primary me-3" role="status"></div>
			<div>
				<span id="progress-text">Processing...</span>
				<div class="progress mt-1" style="height: 8px; width: 220px;">
					<div id="progress-bar" class="progress-bar" role="progressbar" style="width:0"></div>
				</div>
			</div>
		</div>
	</div>

	<form class="mb-4" method="get">
		<label for="file" class="form-label">Choose results file:</label>
		<select id="file" name="file" class="form-select" onchange="this.form.submit()">
			<?php foreach ($files as $file): ?>
				<option value="<?= htmlspecialchars($file) ?>"<?= $file === $selected ? ' selected' : '' ?>>
					<?= htmlspecialchars(strtoupper(preg_replace('/^images_found-|\..*$/', '', $file))) ?>
				</option>
			<?php endforeach; ?>
		</select>
	</form>

	<?php if ($selectedFilePath): ?>
		<div class="mb-3 d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars($selected) ?>" download class="btn btn-success">Download JSON file with results</a>
			<a href="?export_images=1&amp;file=<?= urlencode($selected) ?>" class="btn btn-outline-success">Export all image URLs (.txt)</a>
		</div>
	<?php endif; ?>

	<?php if ($selected): ?>
		<form method="post" class="mb-3" onsubmit="return confirm('Are you sure you want to delete this results file?');">
			<input type="hidden" name="delete_results_file" value="1">
			<input type="hidden" name="file" value="<?= htmlspecialchars($selected) ?>">
			<button type="submit" class="btn btn-outline-danger btn-sm">Delete This Results File</button>
		</form>
	<?php endif; ?>

	<?php if ($selected && $results): ?>
		<?php if ($lastUpdated): ?>
			<p>
				<strong>Last updated:</strong>
				<span id="results-last-updated" data-last-updated-timestamp="<?= (int) $lastUpdated['unix'] ?>"></span>
			</p>
		<?php endif; ?>

		<?php if (!empty($results['summary'])): ?>
			<p class="summary-code">
				<strong>Checked URLs:</strong> <?= (int) ($results['summary']['processed_urls'] ?? 0) ?> |
				<strong>Unique images:</strong> <?= count($imageRows) ?> |
				<strong>Include SVGs:</strong> <?= !empty($results['summary']['include_svgs']) ? 'Yes' : 'No' ?> |
				<strong>CSS scope:</strong> <?= htmlspecialchars($results['summary']['css_scope'] ?? 'page') ?>
			</p>
		<?php endif; ?>

		<?php if (empty($imageRows)): ?>
			<div class="alert alert-warning">No images were found, but the checked URLs were saved to the JSON file.</div>
		<?php endif; ?>

		<?php foreach ($imageRows as $imgIndex => $image): ?>
			<?php
			$collapseId = 'usage-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($image['usage_key'] ?? $imgIndex));
			$pages = $image['pages'] ?? [];
			?>
			<div class="card url-card">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
						<div>
							<h5 class="mb-1"><?= htmlspecialchars($image['image_name'] ?: '[unknown image]') ?></h5>
							<div class="image-meta">
								<div><strong>Image URL:</strong> <a class="image-url" href="<?= htmlspecialchars($image['image_url']) ?>" target="_blank"><?= htmlspecialchars($image['image_url']) ?></a></div>
								<?php if (!empty($image['source_types'])): ?>
									<div><strong>Source type(s):</strong> <?= htmlspecialchars(implode(', ', $image['source_types'])) ?></div>
								<?php endif; ?>
								<?php if (!empty($image['source_attributes'])): ?>
									<div><strong>Source attribute(s):</strong> <?= htmlspecialchars(implode(', ', $image['source_attributes'])) ?></div>
								<?php endif; ?>
								<?php if (!empty($image['aria_labels'])): ?>
									<div>
										<strong>ARIA label(s):</strong>
										<?php foreach ($image['aria_labels'] as $ariaLabel): ?>
											<span class="badge aria-badge me-1"><?= htmlspecialchars($ariaLabel) ?></span>
										<?php endforeach; ?>
									</div>
								<?php else: ?>
									<div><strong>ARIA label:</strong> <span class="text-muted">—</span></div>
								<?php endif; ?>
								<div><strong>Used on:</strong> <?= count($pages) ?> page(s)</div>
							</div>
						</div>
						<?php if (!empty($pages)): ?>
							<button type="button" class="btn btn-outline-secondary btn-sm toggle-usage-btn" data-target="<?= htmlspecialchars($collapseId) ?>" data-page-count="<?= (int) count($pages) ?>">
								Show usage on <?= count($pages) ?> page(s)
							</button>
						<?php endif; ?>
					</div>

					<?php if (!empty($pages)): ?>
						<div class="usage-panel" id="<?= htmlspecialchars($collapseId) ?>">
							<strong>Used on page(s):</strong>
							<?php foreach ($pages as $pageUrl): ?>
								<div class="usage-entry">
									<a href="<?= htmlspecialchars($pageUrl) ?>" target="_blank"><?= htmlspecialchars($pageUrl) ?></a>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
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
		element.textContent = `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
	}

	function getProgressFileName(filePath) {
		return String(filePath || '').split('/').pop();
	}

	let progressInterval = null;

	document.getElementById('find-images-form').addEventListener('submit', function (e) {
		e.preventDefault();
		document.getElementById('progress-container').style.display = 'flex';
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
					const abortBtn = item.pid ? `<button class="btn btn-sm btn-danger ms-2" onclick="abortProgress('${item.file}')">Abort</button>` : '';
					const suffix = item.file.replace(/^.*\/progress-/, '').replace(/\.json$/, '');
					const li = document.createElement('li');
					li.innerHTML = `<strong>${suffix}</strong>: ${item.processed} / ${item.total} (${percent}%)${domainHtml} ${abortBtn}`;
					ul.appendChild(li);

					if (window.currentProgressFile && getProgressFileName(item.file) === window.currentProgressFile) {
						updateMainProgressBar(item);
						anyActive = true;
						currentJobVisible = true;
					}
				});

				panel.style.display = list.length ? 'block' : 'none';
				if (!anyActive) {
					document.getElementById('progress-container').style.display = 'none';
				}
				if (window.currentProgressFile && !currentJobVisible) {
					clearInterval(progressInterval);
					window.currentProgressFile = null;
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
		}).then(() => pollAllProgress());
	}

	function deleteProgressFile(progressFile) {
		fetch('delete-progress.php', {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded'},
			body: `file=${encodeURIComponent(progressFile)}`
		}).then(() => location.reload());
	}

	document.querySelectorAll('.toggle-usage-btn').forEach(button => {
		button.addEventListener('click', function () {
			const panel = document.getElementById(this.dataset.target);
			if (!panel) return;
			const isOpen = panel.classList.toggle('is-open');
			const pageCount = this.dataset.pageCount || panel.querySelectorAll('.usage-entry').length;
			this.textContent = isOpen ? 'Hide usage list' : `Show usage on ${pageCount} page(s)`;
		});
	});

	setInterval(pollAllProgress, 1500);
	updateLastUpdatedDisplay();
	pollAllProgress();
</script>
</body>
</html>

