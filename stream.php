<?php
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

// Proxy de segmento/manifest HLS: stream.php?u=URL_ENCODED
// Se a URL apontar para outro manifest (.m3u8), ele é reescrito de novo;
// se for segmento, os bytes são retransmitidos direto.
if (isset($_GET['u'])) {
    $u = trim($_GET['u']);
    if (strpos($u, 'http') === 0) {
        if (is_playlist_url($u)) {
            proxy_hls($u, 'hls');
        } else {
            proxy_stream($u);
        }
        exit;
    }
    http_response_code(400);
    exit('Parametro u invalido.');
}

$id = isset($_GET['id']) ? preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id']) : '';
if ($id === '') { http_response_code(400); exit('Faltou ?id=VIDEO_ID'); }

$cacheFile = CACHE_DIR . "/yt_video_{$id}.json";
$now = time();

// Cache rápido de 4 minutos (guarda só a URL resolvida)
if (is_file($cacheFile)) {
    $cache = json_decode(@file_get_contents($cacheFile), true);
    if (!empty($cache['url']) && ($now - ($cache['time'] ?? 0) < 240)) {
        output_stream($cache['url'], $id);
        exit;
    }
}

// 1) Prioridade máxima: yt-dlp (binário gerenciado na pasta bin/)
$streamUrl = resolve_via_ytdlp($id);

// 2) Fallback: Piped e Invidious
if (!$streamUrl) $streamUrl = resolve_via_piped($id);
if (!$streamUrl) $streamUrl = resolve_via_invidious($id);

// 3) Fallback: extração direta do HTML do YouTube
if (!$streamUrl) {
    $watchUrl = "https://www.youtube.com/watch?v={$id}&hl=en";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $watchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    $data = null;
    if (preg_match('~ytInitialPlayerResponse\s*=\s*({.+?});~s', (string)$html, $m2)) {
        $data = json_decode($m2[1], true);
    }
    if ($data) {
        if (isset($data['streamingData']['hlsManifestUrl'])) {
            $streamUrl = $data['streamingData']['hlsManifestUrl'];
        }
        if (!$streamUrl && isset($data['streamingData']['adaptiveFormats'])) {
            $best = null;
            foreach ($data['streamingData']['adaptiveFormats'] as $f) {
                if (strpos($f['mimeType'] ?? '', 'video') !== 0 || empty($f['url'])) continue;
                $h = $f['height'] ?? 0;
                if (!$best || $h > $best['height']) $best = ['height' => $h, 'url' => $f['url']];
            }
            if ($best) $streamUrl = $best['url'];
        }
        if (!$streamUrl && isset($data['streamingData']['formats'])) {
            foreach ($data['streamingData']['formats'] as $f) {
                if (!empty($f['url']) && strpos($f['mimeType'] ?? '', 'video') === 0) { $streamUrl = $f['url']; break; }
            }
        }
    }
}

// 4) Transmite os bytes pelo proxy do próprio servidor (sem redirecionar)
if ($streamUrl) {
    $streamUrl = str_replace(['\\u0026', '\\/'], ['&', '/'], $streamUrl);
    @file_put_contents($cacheFile, json_encode(['url' => $streamUrl, 'time' => $now]));
    output_stream($streamUrl, $id);
    exit;
}

// 5) Falhou tudo
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Falha ao resolver o stream do video {$id}. Verifique o selftest.php na pasta do app no servidor.";
exit;
