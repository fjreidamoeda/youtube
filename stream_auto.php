<?php
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');

// Recebe channel_id OR handle OR URL
$input = isset($_GET['channel']) ? trim($_GET['channel']) : '';
if ($input === '') { http_response_code(400); exit('Faltou ?channel='); }

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$key = 'auto_' . md5($input);
$cacheFile = $cacheDir . "/{$key}.json";
$now = time();

if (is_file($cacheFile)) {
    $cache = json_decode(@file_get_contents($cacheFile), true);
    if (!empty($cache['video']) && ($now - ($cache['time'] ?? 0) < 120)) {
        header('Location: stream.php?id=' . urlencode($cache['video']), true, 302);
        exit;
    }
}

$channelId = (preg_match('~^UC[0-9A-Za-z_-]{20,}$~', $input)) ? $input : resolve_channel_id($input, YT_API_KEY);
if (!$channelId) { http_response_code(404); exit('Canal não encontrado.'); }

$videoId = get_live_video_id_by_channel_id($channelId, YT_API_KEY);
if (!$videoId) {
    // Fallback: se não há live, pega o último vídeo do canal
    $videoId = get_latest_video_id_by_channel_id($channelId, YT_API_KEY);
    if (!$videoId) { http_response_code(404); exit('Nenhuma live ou vídeo encontrado.'); }
}

@file_put_contents($cacheFile, json_encode(['video' => $videoId, 'time' => $now]));
header('Location: stream.php?id=' . urlencode($videoId), true, 302);
exit;