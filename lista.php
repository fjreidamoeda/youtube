<?php
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);

$download = isset($_GET['download']) && $_GET['download'] === '1';
if ($download) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="playlist.m3u8"');
} else {
    header('Content-Type: application/x-mpegURL; charset=utf-8');
}

$channels = load_channels();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

echo "#EXTM3U url-tvg=\"{$base}/epg.php\"\n";

foreach ($channels as $c) {
    $name  = $c['name'] ?? 'YouTube';
    $tvgId = $c['tvg_id'] ?? ('yt_' . substr(md5($name), 0, 8));
    $logo  = $c['logo'] ?? '';
    
    // Configura o cabeçalho exatamente como pedido
    $group = "YouTuber " . $name;

    if (!empty($c['video_id'])) {
        // Se for apenas um vídeo solto
        $playUrl = $base . '/stream.php?id=' . rawurlencode($c['video_id']);
        echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-name="' . $name . '" tvg-logo="' . $logo . '" group-title="' . $group . '",' . $name . "\n";
        echo $playUrl . "\n";
    } elseif (!empty($c['channel_id'])) {
        $channelId = $c['channel_id'];
        
        // 1. Verifica se está ao vivo primeiro e corta a playlist para colocar a live no topo
        $liveId = get_live_video_id_by_channel_id($channelId, YT_API_KEY);
        if ($liveId) {
            $liveUrl = $base . '/stream.php?id=' . rawurlencode($liveId);
            echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-name="[AO VIVO] ' . $name . '" tvg-logo="' . $logo . '" group-title="' . $group . '",[AO VIVO] ' . $name . "\n";
            echo $liveUrl . "\n";
        }
        
        // 2. Busca e lista todos os vídeos recentes do canal (limitado a 50 na M3U por questões de performance do player)
        $videos = get_cached_channel_videos($channelId, YT_API_KEY, 50);
        
        if (!empty($videos['items'])) {
            foreach ($videos['items'] as $item) {
                $vidId = $item['snippet']['resourceId']['videoId'] ?? null;
                if (!$vidId || $vidId === $liveId) continue; // Pula se já for a live ativa
                
                $vTitle = $item['snippet']['title'];
                $vThumb = $item['snippet']['thumbnails']['high']['url'] ?? $logo;
                $playUrl = $base . '/stream.php?id=' . rawurlencode($vidId);
                
                // Remove vírgulas do título para não quebrar a sintaxe do M3U
                $vTitleClean = str_replace(',', '', $vTitle);
                
                echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-logo="' . $vThumb . '" group-title="' . $group . '",' . $vTitleClean . "\n";
                echo $playUrl . "\n";
            }
        }
    }
}
exit;