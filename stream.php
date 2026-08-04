<?php
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');

$id = isset($_GET['id']) ? preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id']) : '';
if ($id === '') { http_response_code(400); exit('Faltou ?id=VIDEO_ID'); }

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . "/yt_video_{$id}.json";
$now = time();

// Cache 4 min
if (is_file($cacheFile)) {
    $cache = json_decode(@file_get_contents($cacheFile), true);
    if (!empty($cache['url']) && ($now - ($cache['time'] ?? 0) < 240)) {
        header('Location: ' . $cache['url'], true, 302);
        exit;
    }
}

// 1) Piped (proxy retransmite bytes → assinatura válida) e depois Invidious
$streamUrl = resolve_via_piped($id);
if (!$streamUrl) $streamUrl = resolve_via_invidious($id);

// 2) Fallback: YouTube direto (hlsManifestUrl / adaptiveFormats)
if (!$streamUrl) {
    $watchUrl = "https://www.youtube.com/watch?v={$id}&hl=en";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $watchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    $data = null;
    if (preg_match('~ytInitialPlayerResponse\s*=\s*({.+?});~s', (string)$html, $m2)) {
        $data = json_decode($m2[1], true);
    }
    if ($data) {
        if (isset($data['streamingData']['hlsManifestUrl'])) {
            $streamUrl = $data['streamingData']['hlsManifestUrl'];
        }
        if (!$streamUrl && isset($data['streamingData']['adaptiveFormats'])) {
            $best = null;
            foreach ($data['streamingData']['adaptiveFormats'] as $f) {
                if (strpos($f['mimeType'] ?? '', 'video') !== 0 || empty($f['url'])) continue;
                $h = $f['height'] ?? 0;
                if (!$best || $h > $best['height']) $best = ['height' => $h, 'url' => $f['url']];
            }
            if ($best) $streamUrl = $best['url'];
        }
        if (!$streamUrl && isset($data['streamingData']['formats'])) {
            foreach ($data['streamingData']['formats'] as $f) {
                if (!empty($f['url']) && strpos($f['mimeType'] ?? '', 'video') === 0) { $streamUrl = $f['url']; break; }
            }
        }
    }
}

// 3) Último recurso: manda pro YouTube (funciona no VLC)
if ($streamUrl) {
    $streamUrl = str_replace(['\\u0026', '\\/'], ['&', '/'], $streamUrl);
    @file_put_contents($cacheFile, json_encode(['url' => $streamUrl, 'time' => $now]));
    header('Location: ' . $streamUrl, true, 302);
    exit;
}

header('Location: https://www.youtube.com/watch?v=' . urlencode($id), true, 302);
exit;