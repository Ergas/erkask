<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['file'])) {
    http_response_code(400);
    exit('Bad request');
}

$file = basename((string) $_POST['file']);
$progressFile = __DIR__ . '/progress/' . $file;
if (is_file($progressFile)) {
    unlink($progressFile);
}

echo 'OK';

