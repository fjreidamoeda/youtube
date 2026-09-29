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

// ?why=ID: por que um vídeo falhou ("Falha ao resolver o stream do video X").
// Mostra o estado do download, o log do yt-dlp, as linhas do stream.log
// daquele ID e roda um teste real de extração (diz se o vídeo está
// indisponível/privado/precisa de login ou se a extração é que quebrou).
if (isset($_GET['why']) && $_GET['why'] !== '') {
    $id = preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['why']);
    echo "--- POR QUE {$id} ---\n";
    echo "agora: " . date('c') . "\n";

    // Libera o cache negativo pra o teste de resolução ser real
    @unlink(CACHE_DIR . '/yt_video_' . $id . '_fail.json');

    $f = loop_cache_file($id);
    $sz = is_file($f) ? (int)@filesize($f) : 0;
    echo "\n--- loop cache ---\n";
    echo "arquivo: " . ($f ?: '(nenhum)') . "\n";
    echo "tamanho: " . $sz . " bytes" . ($sz > 0 ? ' (' . round($sz / 1048576, 1) . ' MB)' : '') . "\n";
    echo "valido: " . ($sz > 1000000 ? 'SIM' : 'NAO') . "\n";
    foreach (['pid', 'fail', 'start'] as $sfx) {
        $p = CACHE_DIR . '/loop_' . $id . '.' . $sfx;
        echo "loop_{$id}.{$sfx}: " . (is_file($p) ? trim((string)@file_get_contents($p)) : '(nao existe)') . "\n";
    }
    $pidF = CACHE_DIR . '/loop_' . $id . '.pid';
    $pid = is_file($pidF) ? (int)trim((string)@file_get_contents($pidF)) : 0;
    echo "download rodando: " . ($pid > 0 ? (process_alive($pid) ? "SIM (pid $pid)" : "nao (pid $pid morto)") : 'nao') . "\n";

    echo "\n--- cache de resolucao ---\n";
    foreach (['yt_video_' . $id . '.json', 'yt_video_' . $id . '_fail.json'] as $cn) {
        echo $cn . ": " . (is_file(CACHE_DIR . '/' . $cn) ? trim((string)@file_get_contents(CACHE_DIR . '/' . $cn)) : '(nao existe)') . "\n";
    }

    $lg = CACHE_DIR . '/loop_' . $id . '.log';
    echo "\n--- cache/loop_{$id}.log (ultimas 40 linhas) ---\n";
    if (is_file($lg)) {
        $lines = @file($lg, FILE_IGNORE_NEW_LINES) ?: [];
        echo implode("\n", array_slice($lines, -40)) . "\n";
    } else {
        echo "(nao existe)\n";
    }

    $sl = CACHE_DIR . '/stream.log';
    echo "\n--- cache/stream.log (linhas com {$id}, ultimas 25) ---\n";
    if (is_file($sl)) {
        $all = @file($sl, FILE_IGNORE_NEW_LINES) ?: [];
        $hit = array_values(array_filter($all, function ($l) use ($id) { return strpos($l, $id) !== false; }));
        echo ($hit ? implode("\n", array_slice($hit, -25)) : '(nenhuma)') . "\n";
    } else {
        echo "(nao existe)\n";
    }

    echo "\n--- teste de extracao yt-dlp ---\n";
    $prep = ytdlp_prepare(false);
    echo "prep: " . ($prep ? (($prep['type'] ?? '?') . ' => ' . ($prep['binary'] ?? ($prep['zipapp'] ?? '?'))) : '(nenhum binario)') . "\n";
    if ($prep) {
        $ver = null; $vrc = null;
        @exec(ytdlp_build_cmd($prep, ['--version']), $ver, $vrc);
        echo "versao: " . trim($ver[0] ?? '(sem saida)') . " (rc={$vrc})\n";
        $args = ['--no-playlist', '--simulate', '--no-warnings', '--no-check-certificates',
                 '--print', '%(id)s | dur=%(duration)s | disp=%(availability)s | live=%(live_status)s | %(title).60s'];
        $args = array_merge($args, yt_cookies_args(), ['https://www.youtube.com/watch?v=' . $id]);
        $o = null; $rc = null;
        @exec(ytdlp_build_cmd($prep, $args), $o, $rc);
        echo "rc={$rc}\n";
        $out = trim(implode("\n", array_slice($o ?: [], -20)));
        echo ($out !== '' ? $out : '(sem saida)') . "\n";
    }

    // oEmbed: sinal independente de disponibilidade (401/404 = privado/removido)
    echo "\n--- oEmbed (disponibilidade) ---\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "http: {$code}\n";
    echo substr($body, 0, 400) . "\n";
    if ($code !== 200) {
        echo "=> Video provavelmente privado, removido, com restricao de login/idade ou da regiao.\n";
    }
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
