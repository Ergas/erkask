<?php
// List of scripts/programs with display names and optional descriptions
$links = [
    [
        'path' => 'find-components/index.php',
        'title' => 'Element Finder',
        'desc' => 'Find elements by name from pages.'
    ],
    [
        'path' => 'check-for-headings/index.php',
        'title' => 'Check for Headings',
        'desc' => 'Check and review heading issues across sites.'
    ],
    // Add more entries as needed
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ErKask scripts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .card-link { text-decoration: none; color: inherit; }
        .card-link:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
    </style>
</head>
<body>
<div class="container py-5">
    <h1 class="mb-4 text-center">Project Dashboard</h1>
    <div class="row justify-content-center">
        <?php foreach ($links as $link): ?>
            <div class="col-md-5 col-lg-4 mb-4">
                <a href="<?= htmlspecialchars($link['path']) ?>" class="card card-link shadow-sm h-100">
                    <div class="card-body">
                        <h4 class="card-title"><?= htmlspecialchars($link['title']) ?></h4>
                        <p class="card-text"><?= htmlspecialchars($link['desc']) ?></p>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <footer class="text-center mt-5 text-muted">
        &copy; <?= date('Y') ?> ErKask
    </footer>
</div>
</body>
</html>
