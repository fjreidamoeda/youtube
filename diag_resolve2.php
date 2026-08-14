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

$prep = ytdlp_prepare();
$bin = $prep ? ($prep['binary'] ?? ($prep['zipapp'] ?? '')) : '';

echo "\n== teste bruto: yt-dlp --get-url Y6gZVwSJxeU ==\n";
if ($bin) {
    $cmd = ytdlp_build_cmd($prep, [
        '-f', 'best[ext=mp4][protocol=https][format_id!*=sb]/best[acodec!=none][format_id!*=sb]/best[format_id!*=sb]',
        '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates',
        'https://www.youtube.com/watch?v=Y6gZVwSJxeU',
    ]);
    echo "CMD: $cmd\n";
    $o = null; $rc = null;
    exec($cmd, $o, $rc);
    echo "rc=$rc\n" . implode("\n", array_slice($o, 0, 25)) . "\n";

    echo "\n== teste de extracao (--get-title) ==\n";
    $cmd2 = ytdlp_build_cmd($prep, [
        '--get-title', '--no-playlist', '--no-warnings', '--no-check-certificates',
        'https://www.youtube.com/watch?v=Y6gZVwSJxeU',
    ]);
    echo "CMD: $cmd2\n";
    $o2 = null; $rc2 = null;
    exec($cmd2, $o2, $rc2);
    echo "rc=$rc2\n" . implode("\n", array_slice($o2, 0, 10)) . "\n";
} else {
    echo "(sem yt-dlp via ytdlp_prepare)\n";
}

echo "\n== pipeline check: resolve_stream_url('kfy_SZ_wCe8', 25) ==\n";
$u2 = resolve_stream_url('kfy_SZ_wCe8', 25);
echo $u2 ? $u2 : '(NULL - FALHOU)' . "\n";

echo "\n== bloqueado? clients alternativos para Y6gZVwSJxeU ==\n";
if ($bin) {
    foreach (['tv', 'web_embedded', 'android'] as $cl) {
        $cmd3 = ytdlp_build_cmd($prep, [
            '--extractor-args', 'youtube:player_client=' . $cl,
            '-f', 'best[format_id!*=sb]',
            '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates',
            'https://www.youtube.com/watch?v=Y6gZVwSJxeU',
        ]);
        echo "\n--- player_client=$cl ---\nCMD: $cmd3\n";
        $o3 = null; $rc3 = null;
        exec($cmd3, $o3, $rc3);
        echo "rc=$rc3\n" . implode("\n", array_slice($o3, 0, 6)) . "\n";
    }
}

echo "\n== ultimas 25 linhas de cache/stream.log ==\n";
$lf = CACHE_DIR . '/stream.log';
if (is_file($lf)) {
    $lines = @file($lf);
    echo $lines ? implode('', array_slice($lines, -25)) : "(vazio)";
} else {
    echo "(stream.log nao existe)";
}
echo '</pre>';
