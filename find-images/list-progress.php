<?php
header('Content-Type: application/json');
$progressFiles = glob(__DIR__ . '/progress-*.json');
$result = [];
foreach ($progressFiles as $file) {
    $json = @file_get_contents($file);
    $data = @json_decode($json, true);
    if ($data && !$data['done']) {
        $domain = '';
        if (!empty($data['start_url'])) {
            $parts = parse_url($data['start_url']);
            if (isset($parts['scheme'], $parts['host'])) {
                $domain = $parts['scheme'] . '://' . $parts['host'];
                if (isset($parts['port'])) {
                    $domain .= ':' . $parts['port'];
                }
            }
        }
        $result[] = [
            'file' => basename($file),
            'processed' => $data['processed'],
            'total' => $data['total'],
            'pid' => $data['pid'] ?? null,
            'done' => $data['done'],
            'start_url' => $data['start_url'] ?? null,
            'domain' => $domain,
        ];
    }
}
echo json_encode($result);

