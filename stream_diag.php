<?php
// stream_diag.php — replica a decisão do stream.php para um ID e mostra o log
// do ffmpeg (por que o VLC não abre). Uso: stream_diag.php?id=ID
require_once __DIR__ . '/functions.php';
header('Content-Type: text/plain; charset=utf-8');
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

// Testa o remux real com o primeiro segundo de saída
$ffmpeg = find_ffmpeg();
if ($ffmpeg && $lf) {
    echo "\n--- teste ffmpeg remux (1s, 512KB max) ---\n";
    $cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error'
        . ' -t 1 -i ' . escapeshellarg($lf)
        . ' -c copy -f mpegts -bsf:v h264_mp4toannexb - 2>&1 | head -c 524288 | base64 | head -c 120';
    $o = null; $rc = null;
    @exec($cmd . ' 2>&1', $o, $rc);
    echo 'rc=' . $rc . "\n";
    echo implode("\n", array_slice($o, 0, 10)) . "\n";
}
