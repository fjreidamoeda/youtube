<?php
// test_sel.php — diagnóstico do seletor de formato (vídeos THROTTLED).
// Uso: test_sel.php?id=ID
require_once __DIR__ . '/functions.php';
echo '<pre>';
@set_time_limit(0);

$v = extract_video_id($_GET['id'] ?? '');
if (!$v) { echo "Faltou ?id=ID\n</pre>"; exit; }

$prep = ytdlp_prepare();
if (!$prep) { echo "SEM YT-DLP FUNCIONAL\n</pre>"; exit; }

function run(array $prep, string $v, array $args): array {
    $cmd = ytdlp_build_cmd($prep, array_merge($args, yt_cookies_args(), ['https://www.youtube.com/watch?v=' . $v]));
    $o = null; $rc = null;
    exec($cmd, $o, $rc);
    return [$rc, $o, $cmd];
}

$sels = [
    '18',
    'best',
    'b',
    'best[format_id!*=sb]',
    'best[ext=mp4][protocol=https][format_id!*=sb]',
    'best[ext=mp4][protocol=https][format_id!*=sb]/best[acodec!=none][format_id!*=sb]/best[format_id!*=sb]',
];

foreach ($sels as $s) {
    [$rc, $o, $cmd] = run($prep, $v, ['-f', $s, '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates']);
    $lines = array_slice($o, 0, 8);
    echo "### -f '" . $s . "' => rc=$rc\n";
    echo implode("\n", $lines) . "\n\n";
    @flush();
}

// Com -v na cadeia principal, pra ver o motivo real da falha
[$rc, $o, $cmd] = run($prep, $v, ['-f', 'best[ext=mp4][protocol=https][format_id!*=sb]/best[acodec!=none][format_id!*=sb]/best[format_id!*=sb]', '--get-url', '-v', '--no-playlist', '--no-warnings', '--no-check-certificates']);
echo "### cadeia principal com -v => rc=$rc\n";
echo implode("\n", array_slice($o, -40)) . "\n";
echo '</pre>';
