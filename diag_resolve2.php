<?php
// diag_resolve2.php — Diagnóstico focado no resolve (usar após o fix do yt-dlp).
// Abra: http://45.143.7.108:27021/diag_resolve2.php
echo '<pre>';
require_once __DIR__ . '/functions.php';

echo "== deploy check: functions.php tem o fix do storyboard? ==\n";
$src = (string)@file_get_contents(__DIR__ . '/functions.php');
echo (strpos($src, 'format_id!*=sb') !== false ? 'SIM (fix presente)' : 'NAO (functions.php ANTIGO!)') . "\n\n";

echo "== cache/ytdlp_prep.json ==\n";
$f = CACHE_DIR . '/ytdlp_prep.json';
echo is_file($f) ? @file_get_contents($f) : "(nao existe)" . "\n\n";

echo "== cache/ytdlp_bad.txt ==\n";
$f = CACHE_DIR . '/ytdlp_bad.txt';
echo is_file($f) ? @file_get_contents($f) : "(vazio/inexistente)" . "\n\n";

echo "== arquivos em bin/ ==\n";
foreach (glob(__DIR__ . '/bin/*') ?: [] as $b) {
    echo basename($b) . '  ' . filesize($b) . ' bytes  ' . date('Y-m-d H:i:s', filemtime($b)) . "\n";
}

echo "\n== ytdlp_prepare() ==\n";
var_export(ytdlp_prepare());
echo "\n\n";

echo "== resolve_stream_url('Y6gZVwSJxeU', 25) ==\n";
$u = resolve_stream_url('Y6gZVwSJxeU', 25);
echo $u ? $u : '(NULL - FALHOU)' . "\n";

echo "\n== ultimas 25 linhas de cache/stream.log ==\n";
$lf = CACHE_DIR . '/stream.log';
if (is_file($lf)) {
    $lines = @file($lf);
    echo $lines ? implode('', array_slice($lines, -25)) : "(vazio)";
} else {
    echo "(stream.log nao existe)";
}
echo '</pre>';
