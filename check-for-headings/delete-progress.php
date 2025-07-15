<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'])) {
    $file = basename($_POST['file']);
    $path = __DIR__ . '/' . $file;
    if (file_exists($path) && preg_match('/^progress[\w\-]*\.json$/', $file)) {
        unlink($path);
    }
}
?>
