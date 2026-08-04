<?php
require_once __DIR__ . '/functions.php';

// download=1 -> força download no navegador em vez de abrir para reproduzir
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
    $logo  = $c['logo'] ?? '';
    $group = $c['group'] ?? 'YouTube';
    $tvgId = $c['tvg_id'] ?? ('yt_' . substr(md5($name), 0, 8));

    if (!empty($c['video_id'])) {
        $playUrl = $base . '/stream.php?id=' . rawurlencode($c['video_id']);
    } elseif (!empty($c['channel_id'])) {
        $playUrl = $base . '/stream_auto.php?channel=' . rawurlencode($c['channel_id']);
    } else {
        continue;
    }

    echo '#EXTINF:-1 tvg-id="' . $tvgId . '" tvg-name="' . $name . '" tvg-logo="' . $logo . '" group-title="' . $group . '",' . $name . "\n";
    echo $playUrl . "\n";
}
exit;