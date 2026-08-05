<?php
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');

$input = isset($_GET['channel']) ? trim($_GET['channel']) : '';
if ($input === '') { http_response_code(400); exit('Faltou ?channel='); }

$cacheFile = CACHE_DIR . "/auto_" . md5($input) . ".json";
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
    // Se não há live, pega o vídeo mais recente cacheado
    $videos = get_cached_channel_videos($channelId, YT_API_KEY, 1);
    if (!empty($videos['items'])) {
        $videoId = $videos['items'][0]['snippet']['resourceId']['videoId'] ?? null;
    }
    
    if (!$videoId) { http_response_code(404); exit('Nenhuma live ou vídeo encontrado para este canal.'); }
}

@file_put_contents($cacheFile, json_encode(['video' => $videoId, 'time' => $now]));
header('Location: stream.php?id=' . urlencode($videoId), true, 302);
exit;