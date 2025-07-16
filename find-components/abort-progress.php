<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['file'])) {
    http_response_code(400);
    exit('Bad request');
}

$file = basename($_POST['file']); // Prevent directory traversal
$progressFile = __DIR__ . '/' . $file;

if (!file_exists($progressFile)) {
    http_response_code(404);
    exit('File not found');
}

// Kill the process if running
$data = json_decode(file_get_contents($progressFile), true);
if (!empty($data['pid'])) {
    $pid = (int)$data['pid'];
    if ($pid > 0) {
        exec("kill $pid");
    }
}

// Delete the progress file
unlink($progressFile);

// Also delete elements_found-<suffix>.json in the same directory
if (preg_match('/progress-([\w\-]+)\.json$/', $file, $m)) {
    $suffix = $m[1];
    $elementsFile = __DIR__ . "/elements_found-$suffix.json";
    if (file_exists($elementsFile)) {
        unlink($elementsFile);
    }
}

echo 'OK';
