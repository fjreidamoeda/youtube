<?php
// test_chan_videos.php — testa a resolução de vídeos de um canal (e IDs avulsos)
// e mostra o motivo exato das falhas (bloqueio do YouTube, live recording, etc).
//
// Uso:
//   test_chan_videos.php?c=@FLAZOEIRO          (canal por handle, URL ou ID)
//   test_chan_videos.php?c=@FLAZOEIRO&n=20     (quantos vídeos testar, padrão 10)
//   test_chan_videos.php?ids=ID1,ID2,ID3       (IDs diretos)
//   test_chan_videos.php?fmt=ID1,ID2           (mostra --list-formats dos vídeos)
//   test_chan_videos.php?sel=ID1,ID2           (testa vários seletores de formato)
require_once __DIR__ . '/functions.php';
echo '<pre>';
@set_time_limit(0);

if (isset($_GET['sel'])) {
    $prep = ytdlp_prepare();
    if (!$prep) { echo "SEM YT-DLP FUNCIONAL\n</pre>"; exit; }
    $sels = [
        '18',
        'best',
        'b',
        'best[ext=mp4][protocol=https][format_id!*=sb]/best[acodec!=none][format_id!*=sb]/best[format_id!*=sb]',
        'best[format_id!*=sb]',
        'bestvideo+bestaudio/best',
    ];
    foreach (explode(',', (string)$_GET['sel']) as $i) {
        $v = extract_video_id(trim($i));
        if (!$v) continue;
        echo "===== {$v} =====\n";
        foreach ($sels as $s) {
            $cmd = ytdlp_build_cmd($prep, array_merge(
                ['-f', $s, '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates'],
                yt_cookies_args(),
                ['https://www.youtube.com/watch?v=' . $v]
            ));
            $o = null; $rc = null;
            exec($cmd, $o, $rc);
            $line = '';
            foreach ($o as $l) { $l = trim($l); if (strpos($l, 'http') === 0) { $line = substr($l, 0, 50) . '...'; break; } }
            if ($line === '') foreach ($o as $l) { $l = trim($l); if (stripos($l, 'ERROR') !== false) { $line = substr($l, 0, 85); break; } }
            if ($line === '') $line = '(sem saida util)';
            echo "  -f '" . $s . "' => rc=$rc " . $line . "\n";
            @flush();
        }
        echo "\n";
    }
    echo '</pre>';
    exit;
}

if (isset($_GET['fmt'])) {
    $prep = ytdlp_prepare();
    if (!$prep) { echo "SEM YT-DLP FUNCIONAL\n</pre>"; exit; }
    foreach (explode(',', (string)$_GET['fmt']) as $i) {
        $v = extract_video_id(trim($i));
        if (!$v) continue;
        $cmd = ytdlp_build_cmd($prep, array_merge(
            ['--list-formats', '--no-playlist', '--no-warnings', '--no-check-certificates'],
            yt_cookies_args(),
            ['https://www.youtube.com/watch?v=' . $v]
        ));
        echo "===== formatos de {$v} =====\nCMD: $cmd\n";
        $o = null; $rc = null;
        exec($cmd, $o, $rc);
        echo "rc=$rc\n" . implode("\n", array_slice($o, 0, 60)) . "\n\n";
        @flush();
    }
    echo '</pre>';
    exit;
}

function test_one(string $vid): string {
    $prep = ytdlp_prepare();
    if (!$prep) return "SEM YT-DLP FUNCIONAL";
    $t = microtime(true);
    $cmd = ytdlp_build_cmd($prep, array_merge(
        [
            '-f', 'best[ext=mp4][protocol=https][format_id!*=sb]/best[acodec!=none][format_id!*=sb]/best[format_id!*=sb]',
            '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates',
        ],
        yt_cookies_args(),
        ['https://www.youtube.com/watch?v=' . $vid]
    ));
    $o = null; $rc = null;
    exec($cmd, $o, $rc);
    $ms = (int)((microtime(true) - $t) * 1000);
    foreach ($o as $line) {
        $line = trim($line);
        if (strpos($line, 'http') === 0 && strpos($line, 'googlevideo.com') !== false) {
            return "OK ({$ms}ms) " . substr($line, 0, 55) . "...";
        }
    }
    foreach ($o as $line) {
        $line = trim($line);
        if (stripos($line, 'ERROR:') !== false) {
            return "FALHOU ({$ms}ms) " . substr($line, 0, 95);
        }
    }
    return "FALHOU ({$ms}ms) rc={$rc} (sem saida util)";
}

$ids = [];
$c = trim($_GET['c'] ?? '');
if (isset($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $i) {
        $v = extract_video_id(trim($i));
        if ($v) $ids[] = $v;
    }
    echo count($ids) . " IDs recebidos\n\n";
} elseif ($c !== '') {
    $cid = resolve_channel_id($c, YT_API_KEY);
    echo "Canal: " . htmlspecialchars($c) . " -> channelId: " . ($cid ?: '(nao resolvido)') . "\n\n";
    if (!$cid) { echo '</pre>'; exit; }
    $n = max(1, min(50, (int)($_GET['n'] ?? 10)));
    $data = get_cached_channel_videos($cid, YT_API_KEY, $n);
    foreach (($data['items'] ?? []) as $it) {
        $v = $it['snippet']['resourceId']['videoId'] ?? ($it['id']['videoId'] ?? null);
        if ($v) $ids[] = $v;
    }
    echo count($ids) . " videos (mais recentes) do canal:\n\n";
}

if (!$ids) {
    echo "Nenhum video para testar. Uso: ?c=@CANAL ou ?ids=ID1,ID2,ID3\n";
    echo '</pre>';
    exit;
}

$start = microtime(true);
$ok = 0;
foreach ($ids as $vid) {
    $r = test_one($vid);
    if (strpos($r, 'OK') === 0) $ok++;
    echo "[{$vid}] {$r}\n";
    @flush();
}
echo "\nResumo: {$ok} de " . count($ids) . " ok — " . round(microtime(true) - $start, 1) . "s\n";
echo '</pre>';
