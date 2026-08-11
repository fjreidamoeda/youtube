<?php
// stream.php - Proxy unificado para YouTube -> HLS/MP4 (serve VLC e Xtream/XUI)
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

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
if ($id === '') {
    http_response_code(400);
    exit('Faltou ?id=VIDEO_ID');
}

$isHead  = ($_SERVER['REQUEST_METHOD'] === 'HEAD');
$isIptv  = is_iptv_request();
$cacheFile = CACHE_DIR . "/yt_video_{$id}.json";

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
    if ($isIptv) {
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

// --- GET: resolve com orçamento normal e entrega o stream ---
$streamUrl = resolve_stream_url($id, 20);

if (!$streamUrl) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falha ao resolver o stream do video {$id}. Verifique o selftest.php e o log em cache/stream.log na pasta do app no servidor.";
    exit;
}

// --- IPTV/Xtream: converte para MPEG-TS via ffmpeg para compatibilidade ---
// Servidores IPTV (Xtream/XUI.One) esperam um fluxo MPEG-TS contínuo.
// Tentar servir HLS reescrito causa "sem sinal" porque o painel não consegue
// reprocessar os manifests reescritos a tempo. A solução é usar ffmpeg para
// remuxar (sem re-encode quando possível) para MPEG-TS, que é o formato
// nativo que esses painéis entendem.
if ($isIptv) {
    $ffmpeg = find_ffmpeg();
    if ($ffmpeg) {
        serve_via_ffmpeg($ffmpeg, $streamUrl, $id);
        exit;
    }
    // Sem ffmpeg: tenta proxy direto do stream (funciona com MP4 progressivo)
    log_stream("id={$id} IPTV sem ffmpeg, tentando proxy direto");
    header('Content-Type: video/mp2t');
    proxy_stream($streamUrl);
    exit;
}

// --- VLC e players normais: serve HLS reescrito ou proxy direto ---
serve_resolved($streamUrl, $id);
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
    
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: close');
    
    // Monta o comando ffmpeg:
    // -reconnect 1 -reconnect_streamed 1: reconecta automaticamente se o stream cair
    // -analyzeduration/probesize baixos: inicia a reprodução mais rápido
    // -c copy: sem re-encode (velocidade máxima)
    // -f mpegts pipe:1: saída MPEG-TS no stdout
    $cmd = escapeshellarg($ffmpegPath)
         . ' -y -hide_banner -loglevel error'
         . ' -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5'
         . ' -analyzeduration 2000000 -probesize 2000000'
         . ' -headers "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: https://www.youtube.com/\r\nOrigin: https://www.youtube.com"'
         . ' -stream_loop -1 -re' . ' -i ' . escapeshellarg($streamUrl)
         . ' -c copy -f mpegts -bsf:v h264_mp4toannexb'
         . ' pipe:1 2>' . escapeshellarg(CACHE_DIR . '/ffmpeg_' . $videoId . '.log');
    
    log_stream("id={$videoId} IPTV: iniciando ffmpeg remux para MPEG-TS");
    
    // Tenta com copy primeiro
    $proc = popen($cmd, 'rb');
    if (!$proc) {
        // Fallback: tenta sem bsf (para streams que já são annexb ou não são h264)
        $cmd = escapeshellarg($ffmpegPath)
             . ' -y -hide_banner -loglevel error'
             . ' -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5'
             . ' -analyzeduration 2000000 -probesize 2000000'
             . ' -headers "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: https://www.youtube.com/\r\nOrigin: https://www.youtube.com"'
             . ' -stream_loop -1 -re' . ' -i ' . escapeshellarg($streamUrl)
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
    
    // Lê e envia dados do ffmpeg para o cliente
    while (!feof($proc)) {
        $chunk = fread($proc, 65536);
        if ($chunk === false || $chunk === '') {
            usleep(10000); // 10ms
            continue;
        }
        echo $chunk;
        if (function_exists('ob_flush')) @ob_flush();
        flush();
        
        // Verifica se o cliente desconectou
        if (connection_aborted()) {
            log_stream("id={$videoId} IPTV: cliente desconectou");
            break;
        }
    }
    pclose($proc);
}

