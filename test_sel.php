<?php
// test_sel.php — diagnóstico do seletor de formato (yt-dlp moderno x antigo).
// Uso: test_sel.php?id=ID
require_once __DIR__ . '/functions.php';
echo '<pre>';
@set_time_limit(0);

$v = extract_video_id($_GET['id'] ?? '');
if (!$v) { echo "Faltou ?id=ID\n</pre>"; exit; }

$prep = ytdlp_prepare();
if (!$prep) { echo "SEM YT-DLP FUNCIONAL\n</pre>"; exit; }

echo "== prep em uso ==\n";
var_export($prep);
echo "\n\n== versao ==\n";
$o = null; $rc = null;
@exec(ytdlp_build_cmd($prep, ['--version']), $o, $rc);
echo 'rc=' . $rc . ' ' . implode("\n", array_slice($o, 0, 3)) . "\n\n";

function run(array $prep, string $v, array $args): array {
    $cmd = ytdlp_build_cmd($prep, array_merge($args, yt_cookies_args(), ['https://www.youtube.com/watch?v=' . $v]));
    $o = null; $rc = null;
    exec($cmd, $o, $rc);
    return [$rc, $o, $cmd];
}

echo "== --list-formats ==\n";
[$rc, $o, $cmd] = run($prep, $v, ['--list-formats', '--no-playlist', '--no-warnings', '--no-check-certificates']);
echo "rc=$rc\n" . implode("\n", array_slice($o, -20)) . "\n\n";

$sels = [
    ['18', []],
    ['best', []],
    ['b', []],
    ['best[format_id!*=sb]', []],
    ['best[ext=mp4][protocol=https][format_id!*=sb]/best[acodec!=none][format_id!*=sb]/best[format_id!*=sb]', []],
    ['18', ['--extractor-args', 'youtube:player_client=android']],
    ['18', ['--extractor-args', 'youtube:player_client=web_embedded']],
];

foreach ($sels as [$s, $extra]) {
    [$rc, $o, $cmd] = run($prep, $v, array_merge($extra, ['-f', $s, '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates']));
    $line = '';
    foreach ($o as $l) { $l = trim($l); if (strpos($l, 'http') === 0) { $line = substr($l, 0, 60) . '...'; break; } }
    if ($line === '') foreach ($o as $l) { $l = trim($l); if (stripos($l, 'ERROR') !== false) { $line = substr($l, 0, 90); break; } }
    echo "### -f '" . $s . "'" . ($extra ? ' ' . implode(' ', $extra) : '') . " => rc=$rc " . ($line ?: '(sem saida)') . "\n";
    @flush();
}
echo '</pre>';
