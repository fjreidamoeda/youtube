<?php
// stream.php - Proxy unificado para YouTube -> HLS/MP4 (serve VLC e Xtream/XUI)
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

// --- Log de requisições (diagnóstico: ver o que o app de IPTV realmente pede) ---
$reqLogFile = CACHE_DIR . '/reqlog.txt';
if (!is_file($reqLogFile) || @filesize($reqLogFile) < 1024 * 1024) {
    $reqLine = date('Y-m-d H:i:s') . ' '
             . $_SERVER['REQUEST_METHOD'] . ' '
             . ($_SERVER['PATH_INFO'] ?? '') 
             . ($_SERVER['QUERY_STRING'] ? '?' . substr($_SERVER['QUERY_STRING'], 0, 140) : '')
             . ' UA=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 140)
             . ' Range=' . substr($_SERVER['HTTP_RANGE'] ?? '', 0, 40)
             . ' Ref=' . substr($_SERVER['HTTP_REFERER'] ?? '', 0, 60)
             . "\n";
    @file_put_contents($reqLogFile, $reqLine, FILE_APPEND);
}

// Proxy de segmento/manifest HLS: stream.php?u=URL_ENCODED
if (isset($_GET['u'])) {
    $u = trim($_GET['u']);
    if (strpos($u, 'http') === 0) {
        // Para segmentos soltos, sempre faz proxy direto (sem reescrita)
        proxy_stream($u);
        exit;
    }
    http_response_code(400);
    exit('Parametro u invalido.');
}

$id = isset($_GET['id']) ? preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id']) : '';
$ext = '';

// Suporte a URLs estilo IPTV terminando em extensão: /stream.php/VIDEO_ID.ts
if ($id === '') {
    $pathInfo = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO']) : '';
    if (preg_match('~^/([A-Za-z0-9_-]+)\.(ts|m3u8|mp4)$~', $pathInfo, $m)) {
        $id = $m[1];
        $ext = strtolower($m[2]);
    }
}

if ($id === '') {
    http_response_code(400);
    exit('Faltou ?id=VIDEO_ID');
}

// Modo CANAL: id no formato c-CHANNELID => toca todos os vídeos do canal em
// sequência (quando um termina, começa o próximo) e repete.
$channelMode = false;
$channelId = '';
if (preg_match('~^c-([A-Za-z0-9_-]+)$~', $id, $m)) {
    $channelMode = true;
    $channelId = $m[1];
    $id = '';
}

$isHead  = ($_SERVER['REQUEST_METHOD'] === 'HEAD');
$isIptv  = is_iptv_request();
$cacheFile = CACHE_DIR . "/yt_video_{$id}.json";

// --- Pedidos .m3u8: entrega HLS ao vivo (apps como IBO pedem m3u8) ---
if ($ext === 'm3u8') {
    if ($isHead) {
        header('Content-Type: application/vnd.apple.mpegurl');
        http_response_code(200);
        exit;
    }
    serve_live_hls($id);
    exit;
}

// --- Resposta rápida para HEAD (probe do Xtream/XUI) ---
// Painéis Xtream fazem HEAD antes do GET para validar o canal, com timeout
// curto (10-15s). Responder rápido é essencial para não dar "sem sinal".
if ($isHead) {
    $cached = null;
    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if (!empty($cache['url']) && (time() - ($cache['time'] ?? 0) < 240)) {
            $cached = $cache['url'];
        }
    }
    // Sempre responde 200 com Content-Type de MPEG-TS para IPTV
    // O Xtream/XUI aceita video/mp2t e video/MP2T
    if ($isIptv || $channelMode) {
        header('Content-Type: video/mp2t');
        http_response_code(200);
    } else {
        $url = $cached ?? resolve_stream_url($id, 8);
        if ($url) {
            header('Content-Type: ' . (is_playlist_url($url) ? 'application/vnd.apple.mpegurl' : 'video/mp2t'));
            http_response_code(200);
        } else {
            http_response_code(503);
        }
    }
    exit;
}

// --- Modo CANAL: toca todos os vídeos do canal em sequência ---
if ($channelMode) {
    $ffmpeg = find_ffmpeg();
    if (!$ffmpeg) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Canal requer ffmpeg para concatenar os vídeos.";
        exit;
    }
    serve_channel_ts($ffmpeg, $channelId);
    exit;
}

// --- GET: entrega o stream ---
// O arquivo local (loop cache) é a fonte confiável: baixado via yt-dlp, não
// depende da URL googlevideo (instável/403 no IP de datacenter).
$localFile = find_loop_cache_file($id);

// --- IPTV/Xtream: MPEG-TS via ffmpeg (painéis esperam fluxo TS contínuo) ---
// Tentar servir HLS reescrito causa "sem sinal" porque o painel não consegue
// reprocessar os manifests reescritos a tempo. O ffmpeg remuxa (sem re-encode
// quando possível) para MPEG-TS, o formato nativo que esses painéis entendem.
if ($isIptv) {
    $ffmpeg = find_ffmpeg();
    if ($localFile) {
        if ($ffmpeg) {
            log_stream("id={$id} IPTV: usando loop local em {$localFile}");
            serve_via_ffmpeg($ffmpeg, $localFile, $id);
        } else {
            // Sem ffmpeg: entrega o arquivo local direto (melhor que a URL
            // direta do YouTube, que costuma dar 403 no IP do datacenter).
            log_stream("id={$id} IPTV sem ffmpeg: entregando arquivo local direto");
            serve_local_file($localFile);
        }
        exit;
    }
    // Sem arquivo local: baixa em linha (rápido no VPS, datacenter a
    // datacenter) e serve o arquivo — bem mais estável que a URL direta do
    // YouTube (403/throttle). Só se o download não completar a tempo, tenta
    // tocar pela URL direta (melhor esforço) em vez de devolver 503.
    if (wait_loop_download($id, 20)) {
        $localFile = loop_cache_file($id);
        if ($ffmpeg) {
            log_stream("id={$id} IPTV: baixado em linha, servindo {$localFile}");
            serve_via_ffmpeg($ffmpeg, $localFile, $id);
        } else {
            log_stream("id={$id} IPTV sem ffmpeg: baixado em linha, entregando direto");
            serve_local_file($localFile);
        }
        exit;
    }
    $streamUrl = resolve_stream_url($id, 20);
    if (!$streamUrl) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Falha ao resolver o stream do video {$id}. Verifique o selftest.php e o log em cache/stream.log na pasta do app no servidor.";
        exit;
    }
    if (!$ffmpeg) {
        log_stream("id={$id} IPTV sem ffmpeg, tentando proxy direto");
        header('Content-Type: video/mp2t');
        proxy_stream($streamUrl);
        exit;
    }
    log_stream("id={$id} IPTV sem loop local: streaming direto da URL (melhor esforço)");
    serve_via_ffmpeg($ffmpeg, $streamUrl, $id);
    exit;
}

// --- VLC/players normais: arquivo local direto (mp4, com seek) ---
if ($localFile) {
    log_stream("id={$id} VLC: servindo loop local em {$localFile}");
    serve_local_file($localFile);
    exit;
}

$streamUrl = resolve_stream_url($id, 20);
if (!$streamUrl) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falha ao resolver o stream do video {$id}. Verifique o selftest.php e o log em cache/stream.log na pasta do app no servidor.";
    exit;
}

// Sem arquivo local: baixa em linha (rápido) e serve o arquivo direto.
// Evita o "Baixando... tente novamente" e também o streaming instável da URL.
if (wait_loop_download($id, 20)) {
    $localFile = loop_cache_file($id);
    log_stream("id={$id} VLC: baixado em linha, servindo {$localFile}");
    serve_local_file($localFile);
    exit;
}

// Download não completou: tenta pela URL direta (melhor esforço) via ffmpeg.
$ffmpeg = find_ffmpeg();
if ($ffmpeg) {
    $streamUrl = resolve_stream_url($id, 20);
    if ($streamUrl) {
        log_stream("id={$id} VLC sem loop local: streaming direto da URL (melhor esforço)");
        serve_via_ffmpeg($ffmpeg, $streamUrl, $id);
        exit;
    }
}
http_response_code(503);
header('Retry-After: 3');
header('Content-Type: text/plain; charset=utf-8');
echo "Baixando o conteudo para gerar o canal. Tente novamente em alguns segundos.";
exit;

/**
 * Serve o stream via ffmpeg, remuxando para MPEG-TS.
 * Usa copy codec (sem re-encode) para velocidade máxima.
 * Se a URL for HLS (.m3u8), o ffmpeg lê nativamente.
 * Se for MP4 progressivo, o ffmpeg também lida sem problemas.
 */
function serve_via_ffmpeg(string $ffmpegPath, string $streamUrl, string $videoId): void {
    set_time_limit(0);
    @ini_set('output_buffering', '0');

    // Opções de entrada: -reconnect/-headers só valem para URL remota.
    // Para arquivo local (loop cache) o ffmpeg 4.1 aborta com "Option
    // reconnect not found".
    $srcIsUrl = preg_match('~^https?://~i', $streamUrl) === 1;
    $inputOpts = $srcIsUrl
        ? ' -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5'
          . ' -headers "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: https://www.youtube.com/\r\nOrigin: https://www.youtube.com"'
        : '';

    // Validação rápida só para arquivo local: se o remux TS falhar por qualquer
    // motivo (opção inválida, probe pequeno, codec), entrega o arquivo direto
    // (mp4) em vez de resposta vazia. Para URL remota pula a validação
    // (custaria baixar 1s de stream) e parte direto pro remux com reconnect.
    if (!$srcIsUrl) {
        $testCmd = escapeshellarg($ffmpegPath)
             . ' -y -hide_banner -loglevel error -t 1 -i ' . escapeshellarg($streamUrl)
             . ' -c copy -f mpegts -bsf:v h264_mp4toannexb - 2>/dev/null | wc -c';
        $to = $trc = null;
        @exec($testCmd, $to, $trc);
        $nbytes = (int)trim($to[0] ?? '');
        if ($trc !== 0 || $nbytes < 376) {
            log_stream("id={$videoId} IPTV: remux TS falhou na validacao (rc={$trc}, bytes={$nbytes}); entregando o mp4 direto");
            serve_local_file($streamUrl);
            exit;
        }
    }

    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: close');

    // Monta o comando ffmpeg:
    // -stream_loop -1: coloca o vídeo em loop (comportamento de canal).
    //   O backpressure do pipe já paceia a saída para o ritmo do cliente —
    //   sem -re, evitando edge-cases de temporização com loop+copy.
    // -analyzeduration/probesize: probe grande o bastante para o ffmpeg achar
    //   o moov do mp4 (probe pequeno demais faz "moov atom not found").
    // -c copy: sem re-encode (velocidade máxima)
    // -bsf:v h264_mp4toannexb: converte o h264 de MP4 (AVCC) para MPEG-TS
    //   (annexb). NÃO usar h264_metadata=sample_aspect_ratio=1:1: o ':' separa
    //   opções no bsf e o ffmpeg 4.1 aborta com "No option name near '1'" (0
    //   bytes de saída). O loop local já é SAR 1:1/DAR 16:9 (640x360) — sem
    //   anamorfismo para corrigir.
    // -f mpegts pipe:1: saída MPEG-TS no stdout
    $cmd = escapeshellarg($ffmpegPath)
         . ' -y -hide_banner -loglevel error'
         . $inputOpts
         . ' -analyzeduration 2000000 -probesize 2000000'
         . ' -stream_loop -1 -i ' . escapeshellarg($streamUrl)
         . ' -c copy -f mpegts -bsf:v h264_mp4toannexb'
         . ' pipe:1 2>' . escapeshellarg(CACHE_DIR . '/ffmpeg_' . $videoId . '.log');

    log_stream("id={$videoId} IPTV: iniciando ffmpeg remux para MPEG-TS");

    // Tenta com copy primeiro
    $proc = popen($cmd, 'rb');
    if (!$proc) {
        // Fallback: tenta sem bsf (para streams que já são annexb ou não são h264)
        $cmd = escapeshellarg($ffmpegPath)
             . ' -y -hide_banner -loglevel error'
             . $inputOpts
             . ' -analyzeduration 2000000 -probesize 2000000'
             . ' -stream_loop -1 -i ' . escapeshellarg($streamUrl)
             . ' -c copy -f mpegts'
             . ' pipe:1 2>' . escapeshellarg(CACHE_DIR . '/ffmpeg_' . $videoId . '.log');
        $proc = popen($cmd, 'rb');
    }
    
    if (!$proc) {
        log_stream("id={$videoId} IPTV: falha ao iniciar ffmpeg");
        http_response_code(503);
        echo "Falha ao iniciar ffmpeg para remuxar.";
        return;
    }

    stream_pipe_to_client($proc, $videoId);
}

/**
 * Lê a saída de um processo ffmpeg (pipe) e envia para o cliente,
 * parando quando o cliente desconecta.
 */
function stream_pipe_to_client($proc, string $label): void {
    while (!feof($proc)) {
        $chunk = fread($proc, 65536);
        if ($chunk === false || $chunk === '') {
            usleep(10000); // 10ms
            continue;
        }
        echo $chunk;
        if (function_exists('ob_flush')) @ob_flush();
        flush();

        if (connection_aborted()) {
            log_stream("stream {$label}: cliente desconectou");
            break;
        }
    }
    pclose($proc);
}

/**
 * Modo CANAL: toca todos os vídeos do canal em sequência (concat), repetindo.
 * Baixa em background os que ainda não têm arquivo local e concatena os prontos.
 */
function serve_channel_ts(string $ffmpeg, string $channelId): void {
    $videos = get_cached_channel_videos($channelId, YT_API_KEY, 50);
    $items = $videos['items'] ?? [];
    if (empty($items)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Canal sem vídeos.";
        return;
    }
    $ids = [];
    foreach ($items as $it) {
        $v = $it['snippet']['resourceId']['videoId'] ?? null;
        if ($v) $ids[] = $v;
    }
    if (empty($ids)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Canal sem vídeos.";
        return;
    }

    // Garante downloads (background) e monta a lista concat só com prontos.
    $ready = [];
    foreach ($ids as $v) {
        $f = loop_cache_file($v);
        if (is_file($f) && @filesize($f) > 1000000) {
            $ready[] = $f;
        } else {
            ensure_loop_download($v);
        }
    }

    if (empty($ready)) {
        // Nada baixado ainda: toca o primeiro pela URL direta (melhor esforço).
        $u = resolve_stream_url($ids[0], 20);
        if (!$u) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Sem conteudo pronto ainda.";
            return;
        }
        log_stream("ch={$channelId}: nada baixado, tocando direto {$ids[0]}");
        serve_via_ffmpeg($ffmpeg, $u, $ids[0]);
        return;
    }

    $listFile = CACHE_DIR . '/concat_' . preg_replace('~[^A-Za-z0-9_-]~', '', $channelId) . '.txt';
    $lines = '';
    foreach ($ready as $f) {
        $lines .= "file '" . str_replace("'", "'\\''", $f) . "'\n";
    }
    @file_put_contents($listFile, $lines);
    log_stream("ch={$channelId}: servindo concat de " . count($ready) . " vídeos");

    serve_via_concat($ffmpeg, $listFile, $channelId);
}

/**
 * Streama uma lista de arquivos locais em sequência (concat demuxer), em loop,
 * remuxando para MPEG-TS.
 */
function serve_via_concat(string $ffmpeg, string $listFile, string $channelId): void {
    set_time_limit(0);
    @ini_set('output_buffering', '0');

    // Validação rápida: o concat demuxer abre a lista? Se falhar, tenta tocar
    // o primeiro arquivo da lista isolado (remux simples).
    $testCmd = escapeshellarg($ffmpeg)
         . ' -y -hide_banner -loglevel error -t 1 -f concat -safe 0 -i ' . escapeshellarg($listFile)
         . ' -c copy -f mpegts -bsf:v h264_mp4toannexb - 2>/dev/null | wc -c';
    $o = $rc = null;
    @exec($testCmd, $o, $rc);
    $nbytes = (int)trim($o[0] ?? '');
    if ($rc !== 0 || $nbytes < 376) {
        log_stream("ch={$channelId}: concat falhou na validacao (rc={$rc}, bytes={$nbytes}); tocando 1o arquivo isolado");
        $first = null;
        foreach (file($listFile) as $l) {
            if (preg_match('/^file \'(.*)\'$/', trim($l), $m)) { $first = stripcslashes($m[1]); break; }
        }
        if ($first && is_file($first)) {
            serve_via_ffmpeg($ffmpeg, $first, $channelId);
            return;
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Concat falhou.";
        return;
    }

    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: close');

    $cmd = escapeshellarg($ffmpeg)
         . ' -y -hide_banner -loglevel error -fflags +genpts'
         . ' -analyzeduration 500000 -probesize 500000'
         . ' -stream_loop -1 -f concat -safe 0 -i ' . escapeshellarg($listFile)
         . ' -c copy -f mpegts -bsf:v h264_mp4toannexb'
         . ' pipe:1 2>' . escapeshellarg(CACHE_DIR . '/ffmpeg_' . $channelId . '.log');

    log_stream("ch={$channelId}: iniciando ffmpeg concat (loop) para MPEG-TS");

    $proc = popen($cmd, 'rb');
    if (!$proc) {
        http_response_code(503);
        echo "Falha ao iniciar ffmpeg para o canal.";
        return;
    }
    stream_pipe_to_client($proc, 'ch=' . $channelId);
}

