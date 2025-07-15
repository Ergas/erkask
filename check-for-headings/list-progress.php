<?php
$progressFiles = glob(__DIR__ . '/progress-*.json');
$result = [];
foreach ($progressFiles as $file) {
    $json = @file_get_contents($file);
    $data = @json_decode($json, true);
    if ($data && !empty($data['total']) && !$data['done']) {
        $suffix = preg_replace('/^progress-?|\.json$/i', '', basename($file));
        $issuesFile = __DIR__ . "/headings_issues" . ($suffix ? "-$suffix" : "") . ".json";
        $domain = '';
        if (file_exists($issuesFile)) {
            $issues = @json_decode(@file_get_contents($issuesFile), true);
            if ($issues && isset($issues[0]['url'])) {
                $url = $issues[0]['url'];
                $parts = parse_url($url);
                if (isset($parts['scheme'], $parts['host'])) {
                    $domain = $parts['scheme'] . '://' . $parts['host'];
                    if (isset($parts['port'])) {
                        $domain .= ':' . $parts['port'];
                    }
                }
            }
        }
        // Fallback to start_url if domain is still empty
        if (!$domain && !empty($data['start_url'])) {
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
            'domain' => $domain,
            'pid' => $data['pid'] ?? null
        ];
    }
}
header('Content-Type: application/json');
echo json_encode($result);
