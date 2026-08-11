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

// Modo de saída: iptv (padrão) ou vlc
// - iptv: URL termina em .ts (formato padrão de listas IPTV: stream.php/VIDEO_ID.ts)
// - vlc:  URLs normais com HLS reescrito (funciona no VLC)
// Uso: lista.php?mode=vlc  ou  lista.php?mode=iptv  ou  lista.php (= iptv)
$mode = $_GET['mode'] ?? 'iptv';

$channels = load_channels();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$streamUrl = function (string $id) use ($base, $mode): string {
    if ($mode === 'vlc') {
        return $base . '/stream.php?id=' . rawurlencode($id);
    }
    return $base . '/stream.php/' . rawurlencode($id) . '.ts';
};

echo "#EXTM3U url-tvg=\"{$base}/epg.php\"\n";

foreach ($channels as $c) {
    $name  = $c['name'] ?? 'YouTube';
    $tvgId = $c['tvg_id'] ?? ('yt_' . substr(md5($name), 0, 8));
    $logo  = $c['logo'] ?? '';
    
    // Configura o cabeçalho exatamente como pedido
    $group = "YOUTUBE | " . (function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name));

    if (!empty($c['video_id'])) {
        // Se for apenas um vídeo solto
        $playUrl = $streamUrl($c['video_id']);
        echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-name="' . $name . '" tvg-logo="' . $logo . '" group-title="' . $group . '",' . $name . "\n";
        echo '#EXTGRP:' . $group . "\n";
        echo $playUrl . "\n";
    } elseif (!empty($c['channel_id'])) {
        $channelId = $c['channel_id'];
        
        // 1. Verifica se está ao vivo primeiro e corta a playlist para colocar a live no topo
        $liveId = get_live_video_id_by_channel_id($channelId, YT_API_KEY);
        if ($liveId) {
            $liveUrl = $streamUrl($liveId);
            echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-name="[AO VIVO] ' . $name . '" tvg-logo="' . $logo . '" group-title="' . $group . '",[AO VIVO] ' . $name . "\n";
            echo '#EXTGRP:' . $group . "\n";
            echo $liveUrl . "\n";
        }
        
        // 2. Busca e lista todos os vídeos recentes do canal (limitado a 50 na M3U por questões de performance do player)
        $videos = get_cached_channel_videos($channelId, YT_API_KEY, 50);

        if (empty($liveId) && empty($videos['items'])) {
            // Nada foi encontrado para este canal: provavelmente a YT_API_KEY
            // está inválida, sem quota, ou não habilitada para a YouTube Data
            // API v3. Deixa um rastro visível na própria playlist (comentário
            // M3U, ignorado pelos players) e no cache/stream.log do servidor.
            echo "# [AVISO] Nenhum conteudo encontrado para '{$name}' ({$channelId}). Verifique YT_API_KEY e cache/stream.log no servidor.\n";
        }

        if (!empty($videos['items'])) {
            foreach ($videos['items'] as $item) {
                $vidId = $item['snippet']['resourceId']['videoId'] ?? null;
                if (!$vidId || $vidId === $liveId) continue; // Pula se já for a live ativa
                
                $vTitle = $item['snippet']['title'];
                $vThumb = $item['snippet']['thumbnails']['high']['url'] ?? $logo;
                $playUrl = $streamUrl($vidId);
                
                // Remove vírgulas do título para não quebrar a sintaxe do M3U
                $vTitleClean = str_replace(',', '', $vTitle);
                
                echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-logo="' . $vThumb . '" group-title="' . $group . '",' . $vTitleClean . "\n";
                echo '#EXTGRP:' . $group . "\n";
                echo $playUrl . "\n";
            }
        }
    }
}
exit;