<?php
// stream_diag.php — replica a decisão do stream.php para um ID e mostra o log
// do ffmpeg (por que o VLC não abre). Uso: stream_diag.php?id=ID
require_once __DIR__ . '/functions.php';
header('Content-Type: text/plain; charset=utf-8');

// ?c=CHANNELID: varre o canal mostrando cada vídeo, status do loop e dimensões.
if (isset($_GET['c']) && $_GET['c'] !== '') {
    $channelId = preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['c']);
    echo "--- CANAL {$channelId} ---\n";
    $ffprobe = find_ffprobe();
    $videos = get_cached_channel_videos($channelId, YT_API_KEY, 50);
    $n = 0;
    foreach (($videos['items'] ?? []) as $it) {
        $v = $it['snippet']['resourceId']['videoId'] ?? null;
        $title = $it['snippet']['title'] ?? '';
        if (!$v) continue;
        $n++;
        $f = loop_cache_file($v);
        $status = 'sem arquivo';
        $dims = '';
        if (is_file($f)) {
            $sz = @filesize($f);
            $status = $sz > 1000000 ? round($sz / 1048576, 1) . ' MB' : 'incompleto';
            if ($ffprobe) {
                $cmd = escapeshellarg($ffprobe) . ' -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 ' . escapeshellarg($f) . ' 2>/dev/null';
                $o = $rc = null;
                @exec($cmd, $o, $rc);
                if ($rc === 0 && !empty($o)) $dims = trim($o[0] ?? '');
            }
        }
        echo str_pad($v, 12) . ' ' . str_pad($status, 14) . ' ' . str_pad($dims, 10) . ' ' . substr($title, 0, 45) . "\n";
    }
    echo "videos: {$n}\n";
    echo "ffprobe: " . ($ffprobe ?: '(nao encontrado)') . "\n";
    exit;
}

$id = isset($_GET['id']) ? preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id']) : '';
if (!$id) { echo "Faltou ?id=ID\n"; exit; }

echo "method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "UA: " . ($_SERVER['HTTP_USER_AGENT'] ?? '(vazio)') . "\n";
echo "PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? '(vazio)') . "\n";
echo "QUERY: " . ($_SERVER['QUERY_STRING'] ?? '') . "\n";
echo "Range: " . ($_SERVER['HTTP_RANGE'] ?? '(vazio)') . "\n";
echo "is_iptv_request: " . (is_iptv_request() ? 'SIM' : 'nao') . "\n";
echo "ffmpeg: " . ((find_ffmpeg()) ?: '(nao encontrado)') . "\n";
$lf = find_loop_cache_file($id);
echo "loop local: " . ($lf ? $lf . ' (' . @filesize($lf) . ' bytes)' : '(nao tem)') . "\n";

$flog = CACHE_DIR . '/ffmpeg_' . $id . '.log';
if (is_file($flog)) {
    echo "\n--- cache/ffmpeg_{$id}.log ---\n";
    echo @file_get_contents($flog) ?: '(vazio)';
} else {
    echo "\n--- cache/ffmpeg_{$id}.log: (nao existe) ---\n";
}

// ffprobe do arquivo local: resolução/SAR/DAR (imagem larga = aspect errado)
if ($lf) {
    echo "\n--- ffprobe do loop local ---\n";
    $fp = find_ffprobe();
    if ($fp) {
        $o = $rc = null;
        @exec(escapeshellarg($fp) . ' -v error -select_streams v:0 -show_entries stream=width,height,sample_aspect_ratio,display_aspect_ratio,avg_frame_rate -of default=noprint_wrappers=1 ' . escapeshellarg($lf), $o, $rc);
        echo 'rc=' . $rc . "\n" . implode("\n", $o) . "\n";
    } else {
        echo "(ffprobe nao encontrado)\n";
    }
}

// Testa o remux real com o comando EXATO de produção (2s de saída, 512KB max)
$ffmpeg = find_ffmpeg();
if ($ffmpeg && $lf) {
    echo "\n--- teste remux PRODUCAO (com -stream_loop -1) ---\n";
    $cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error'
        . ' -analyzeduration 2000000 -probesize 2000000'
        . ' -t 2 -stream_loop -1 -i ' . escapeshellarg($lf)
        . ' -c copy -f mpegts -bsf:v h264_mp4toannexb'
        . ' - 2>/tmp/remux_prod.log | wc -c';
    $o = null; $rc = null;
    @exec($cmd . ' 2>&1', $o, $rc);
    echo 'rc=' . $rc . ' bytes=' . trim($o[0] ?? '(sem saida)') . "\n";
    echo "stderr (/tmp/remux_prod.log):\n" . (@file_get_contents('/tmp/remux_prod.log') ?: '(vazio)') . "\n";

    // Comparação: com probe pequeno (500KB) que pode quebrar o moov
    echo "\n--- teste com probe 500KB (hipotese moov) ---\n";
    $cmd2 = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error'
        . ' -analyzeduration 500000 -probesize 500000'
        . ' -t 2 -stream_loop -1 -i ' . escapeshellarg($lf)
        . ' -c copy -f mpegts -bsf:v h264_mp4toannexb'
        . ' - 2>/tmp/remux_probe500.log | wc -c';
    $o2 = null; $rc2 = null;
    @exec($cmd2 . ' 2>&1', $o2, $rc2);
    echo 'rc=' . $rc2 . ' bytes=' . trim($o2[0] ?? '(sem saida)') . "\n";
    echo "stderr (/tmp/remux_probe500.log):\n" . (@file_get_contents('/tmp/remux_probe500.log') ?: '(vazio)') . "\n";
}
