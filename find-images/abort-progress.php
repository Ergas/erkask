<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['file'])) {
    http_response_code(400);
    exit('Bad request');
}

$file = basename($_POST['file']);
$progressFile = __DIR__ . '/' . $file;
if (!file_exists($progressFile)) {
    http_response_code(404);
    exit('File not found');
}

$data = json_decode(file_get_contents($progressFile), true);
if (!empty($data['pid'])) {
    $pid = (int) $data['pid'];
    if ($pid > 0) {
        exec("kill $pid");
        exec("ps -p $pid", $output);
        if (count($output) > 1) {
            exec("kill -9 $pid");
        }
    }
}

unlink($progressFile);
if (preg_match('/progress-([\w\-]+)\.json$/', $file, $m)) {
    $suffix = $m[1];
    $resultsFile = __DIR__ . "/images_found-$suffix.json";
    if (file_exists($resultsFile)) {
        unlink($resultsFile);
    }
    $checkedFile = __DIR__ . "/checked-image-urls-$suffix.tmp";
    if (file_exists($checkedFile)) {
        unlink($checkedFile);
    }
}

echo 'OK';

