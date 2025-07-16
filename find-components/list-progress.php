<?php
header('Content-Type: application/json');
$progressFiles = glob(__DIR__ . '/progress-*.json');
$result = [];
foreach ($progressFiles as $file) {
    $json = @file_get_contents($file);
    $data = @json_decode($json, true);
    if ($data && !$data['done']) {
        $result[] = [
            'file' => $file,
            'processed' => $data['processed'],
            'total' => $data['total'],
            'pid' => $data['pid'] ?? null,
            'done' => $data['done'],
            'start_url' => $data['start_url'] ?? null
        ];
    }
}
echo json_encode($result);
