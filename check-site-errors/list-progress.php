<?php
header('Content-Type: application/json');

$progressFiles = glob(__DIR__ . '/progress/progress-*.json') ?: [];
$result = [];

foreach ($progressFiles as $file) {
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data) || !empty($data['done'])) {
        continue;
    }

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
        'processed' => (int) ($data['processed'] ?? 0),
        'total' => (int) ($data['total'] ?? 0),
        'pid' => isset($data['pid']) ? (int) $data['pid'] : null,
        'done' => !empty($data['done']),
        'status' => $data['status'] ?? 'running',
        'currentUrl' => $data['currentUrl'] ?? null,
        'start_url' => $data['start_url'] ?? null,
        'domain' => $domain,
    ];
}

echo json_encode($result);

