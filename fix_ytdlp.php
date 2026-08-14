<?php
// fix_ytdlp.php — Limpeza única + validação do yt-dlp no VPS.
// 1) remove a lista negra (ytdlp_bad.txt), caches negativos e o lixo
//    bin/yt-dlp.exe (binário Windows sem uso no Linux);
// 2) chama ytdlp_prepare(), que com o functions.php novo recria sozinho
//    o wrapper bin/yt-dlp a partir do pacote yt_dlp/ truncado;
// 3) valida o resolve de um vídeo.
// Abra: http://45.143.7.108:27021/fix_ytdlp.php
require_once __DIR__ . '/functions.php';
echo '<pre>';
$bad = CACHE_DIR . '/ytdlp_bad.txt';
if (is_file($bad)) { @unlink($bad); echo "ytdlp_bad.txt removido\n"; }
foreach (glob(CACHE_DIR . '/yt_video_*_fail.json') ?: [] as $f) { @unlink($f); echo "removido: " . basename($f) . "\n"; }
@unlink(CACHE_DIR . '/ytdlp_prep.json');
$exe = __DIR__ . '/bin/yt-dlp.exe';
if (is_file($exe)) { @unlink($exe); echo "yt-dlp.exe (lixo Windows) removido\n"; }
echo "\n== ytdlp_prepare() (deve recriar bin/yt-dlp) ==\n";
var_export(ytdlp_prepare());
echo "\n\n== bin/ ==\n";
foreach (glob(__DIR__ . '/bin/*') ?: [] as $b) {
    echo basename($b) . '  ' . filesize($b) . ' bytes  ' . (is_executable($b) ? 'executavel' : 'sem exec bit') . "\n";
}
echo "\n== resolve_stream_url('Y6gZVwSJxeU', 25) ==\n";
$u = resolve_stream_url('Y6gZVwSJxeU', 25);
echo $u ? $u : '(NULL - FALHOU)';
echo "\n</pre>";
