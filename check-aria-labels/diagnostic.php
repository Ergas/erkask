#!/usr/bin/env php
<?php
/**
 * Diagnostic script for ARIA checker
 * Run this to see what's happening with your checks
 */

echo "=== ARIA Checker Diagnostic ===\n\n";

$dir = __DIR__;
echo "Directory: $dir\n\n";

// 1. Check for results files
echo "1. Results files found:\n";
$files = glob("$dir/aria_issues-*.json");
if (empty($files)) {
    echo "   ❌ No results files found!\n";
} else {
    foreach ($files as $file) {
        $size = filesize($file);
        $basename = basename($file);
        $data = json_decode(file_get_contents($file), true);
        $urlCount = is_array($data) ? count($data) : 0;
        $issueCount = 0;
        if (is_array($data)) {
            foreach ($data as $result) {
                if (!empty($result['issues'])) {
                    $issueCount += count($result['issues']);
                }
            }
        }
        echo "   ✓ $basename ($size bytes, $urlCount URLs, $issueCount issues)\n";
    }
}
echo "\n";

// 2. Check for progress files (active jobs)
echo "2. Active jobs (progress files):\n";
$progress = glob("$dir/progress-*.json");
if (empty($progress)) {
    echo "   ✓ No active jobs\n";
} else {
    foreach ($progress as $file) {
        $data = json_decode(file_get_contents($file), true);
        $basename = basename($file);
        echo "   ⚠ $basename: {$data['processed']}/{$data['total']} URLs processed\n";
        if (!empty($data['pid'])) {
            $running = posix_kill($data['pid'], 0);
            echo "      PID {$data['pid']}: " . ($running ? "RUNNING" : "NOT RUNNING") . "\n";
        }
    }
}
echo "\n";

// 3. Check for temp files
echo "3. Temporary files:\n";
$temps = array_merge(
    glob("$dir/aria_issues_temp-*.json"),
    glob("$dir/checked-aria-urls-*.tmp")
);
if (empty($temps)) {
    echo "   ✓ No temp files (clean state)\n";
} else {
    foreach ($temps as $file) {
        $basename = basename($file);
        $size = filesize($file);
        echo "   ⚠ $basename ($size bytes) - leftover from interrupted job?\n";
    }
}
echo "\n";

// 4. Test the checker script
echo "4. Testing check-aria.php:\n";
if (!file_exists("$dir/check-aria.php")) {
    echo "   ❌ check-aria.php not found!\n";
} else {
    echo "   ✓ check-aria.php exists\n";
    // Test syntax
    exec("php -l $dir/check-aria.php 2>&1", $output, $return);
    if ($return === 0) {
        echo "   ✓ PHP syntax valid\n";
    } else {
        echo "   ❌ PHP syntax error:\n";
        echo "      " . implode("\n      ", $output) . "\n";
    }
}
echo "\n";

// 5. Recommendations
echo "5. Recommendations:\n";
if (empty($files)) {
    echo "   → Run a check to create results files\n";
    echo "   → Use --single mode for testing: php check-aria.php <url> <suffix> --single --attrs=aria-label\n";
}
if (!empty($progress)) {
    echo "   → You have active jobs. Wait for them to complete or abort them.\n";
}
if (!empty($temps)) {
    echo "   → Clean up temp files if jobs are stuck\n";
}

echo "\n=== End Diagnostic ===\n";

