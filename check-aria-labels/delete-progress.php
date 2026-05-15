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
        // DO NOT delete aria_issues-$suffix.json - that's the final results file!
        foreach ($filesToDelete as $f) {
            if (file_exists($f)) unlink($f);
        }
    }
    echo 'OK';
}
?>

