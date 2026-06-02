<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['file'])) {
    http_response_code(400);
    exit('Bad request');
}

$file = basename((string) $_POST['file']);
$progressFile = __DIR__ . '/progress/' . $file;
if (!is_file($progressFile)) {
    http_response_code(404);
    exit('File not found');
}

$data = json_decode((string) file_get_contents($progressFile), true) ?: [];
$data['abortRequested'] = true;
$data['status'] = 'aborting';
file_put_contents($progressFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if (!empty($data['pid'])) {
    $pid = (int) $data['pid'];
    if ($pid > 0) {
        exec('kill ' . $pid . ' 2>/dev/null');
    }
}

echo 'OK';

