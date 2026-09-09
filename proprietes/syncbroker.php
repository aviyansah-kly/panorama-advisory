<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=900');

function sb_respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$config = [];
$configFile = __DIR__ . '/syncbroker-config.php';
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) $config = $loaded;
}

$apiKey = trim((string)($config['api_key'] ?? getenv('SYNCBROKER_API_KEY') ?: ''));
$endpoint = trim((string)($config['endpoint'] ?? 'https://app.sync.quebec/api/properties/data'));

if ($apiKey === '') {
    sb_respond(503, ['success' => false, 'message' => 'Syncbroker is not configured on this server.']);
}

$cacheFile = __DIR__ . '/syncbroker-cache.json';
$cacheTtl = max(300, (int)($config['cache_ttl'] ?? 1800));

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false && json_decode($cached, true) !== null) {
        header('X-Syncbroker-Cache: HIT');
        echo $cached;
        exit;
    }
}

$url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'api-key=' . rawurlencode($apiKey);
$response = false;
$status = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'PanoramaAdvisory/1.0',
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 18,
            'header' => "Accept: application/json\r\nUser-Agent: PanoramaAdvisory/1.0\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
}

if ($response === false || $status < 200 || $status >= 300) {
    if (is_file($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale !== false && json_decode($stale, true) !== null) {
            header('X-Syncbroker-Cache: STALE');
            echo $stale;
            exit;
        }
    }
    sb_respond(502, ['success' => false, 'message' => 'Property feed is temporarily unavailable.']);
}

$decoded = json_decode($response, true);
if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    sb_respond(502, ['success' => false, 'message' => 'Invalid property feed response.']);
}

$payload = json_encode([
    'success' => true,
    'synced_at' => gmdate('c'),
    'data' => $decoded,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

@file_put_contents($cacheFile, $payload, LOCK_EX);
header('X-Syncbroker-Cache: MISS');
echo $payload;
