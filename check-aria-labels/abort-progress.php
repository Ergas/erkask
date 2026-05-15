<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file'])) {
    $progressFile = $_POST['file'];
    if (preg_match('/progress-([\w\-]+)\.json$/', $progressFile, $m)) {
        $suffix = $m[1];
        $filesToDelete = [
            $progressFile,
            __DIR__ . "/aria_issues_temp-$suffix.json",
            __DIR__ . "/checked-aria-urls-$suffix.tmp",
        ];
        foreach ($filesToDelete as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }
    // Optionally: kill the process if PID is stored
    exit('OK');
}
?>

