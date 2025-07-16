<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'])) {
    $file = basename($_POST['file']); // Prevent directory traversal
    $path = __DIR__ . 'delete-progress.php/' . $file;
    if (file_exists($path) && preg_match('/^progress.*\.json$/', $file)) {
        unlink($path);
        echo 'OK';
    } else {
        http_response_code(404);
        echo 'File not found';
    }
} else {
    http_response_code(400);
    echo 'Bad request';
}
?>
