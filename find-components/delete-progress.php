<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['file'])) {
    http_response_code(400);
    exit('Bad request');
}
$file = basename($_POST['file']);
$progressFile = __DIR__ . '/' . $file;
if (file_exists($progressFile)) {
    unlink($progressFile);
}
echo 'OK';
