<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['file'])) exit;
$file = basename($_POST['file']);
$progressFile = __DIR__ . '/' . $file;
if (!file_exists($progressFile)) exit;
$data = json_decode(file_get_contents($progressFile), true);
if (!empty($data['pid'])) {
    $pid = (int)$data['pid'];
    if ($pid > 0) {
        // Try to kill the process
        exec("kill $pid");
    }
}
unlink($progressFile);
echo 'OK';
