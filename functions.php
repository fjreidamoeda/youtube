<?php
// functions.php - Helpers para o YouTube IPTV
require_once __DIR__ . '/config.php';

define('DATA_FILE', __DIR__ . '/data.json');
define('CACHE_DIR', __DIR__ . '/cache');

if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0775, true);
}

function load_channels(): array {
    if (!is_file(DATA_FILE)) return [];
    $json = @file_get_contents(DATA_FILE);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function save_channels(array $channels): bool {
    return file_put_contents(DATA_FILE, json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

// ------------------------------------------------------------------
// INTEGRAÇÃO YT-DLP (Prioridade máxima)
// O yt-dlp é um programa em Python. Ele é baixado sozinho para a
// pasta bin/ do app, sem precisar de SSH:
//  1) Se o servidor tem python3  -> baixa o yt-dlp em Python (3,5 MB) e roda via python3
//  2) Senão                       -> baixa o binário standalone (não precisa de Python)
// ------------------------------------------------------------------

function ytdlp_python(): ?string {
    $out = [];
    foreach (['python3', 'python'] as $c) {
        @exec($c . ' -c "import sys" 2>&1', $out, $rc);
        $out = [];
        if ($rc === 0) return $c;
    }
    return null;
}

function ytdlp_download(string $url): ?string {
    @set_time_limit(180);
    $ctx = stream_context_create([
        'http' => ['timeout' => 120, 'header' => "User-Agent: Mozilla/5.0\r\n", 'follow_location' => 1],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    return @file_get_contents($url, false, $ctx);
}

function ytdlp_build_cmd(array $prep, array $args): string {
    $parts = [];
    if ($prep['type'] === 'py') {
        $parts[] = $prep['python'];
        $parts[] = $prep['zipapp'];
    } else {
        $parts[] = $prep['binary'];
    }
    $parts = array_merge($parts, $args);

    if (strtolower(PHP_OS_FAMILY) === 'windows') {
        return implode(' ', array_map(function ($p) { return '"' . str_replace('"', '\\"', $p) . '"'; }, $parts)) . ' 2>&1';
    }
    return implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
}

function ytdlp_test_cmd(array $prep): bool {
    $out = $last = null;
    @exec(ytdlp_build_cmd($prep, ['--version']), $out, $last);
    $out = [];
    return $last === 0;
}

function ytdlp_prepare(): ?array {
    $dir = __DIR__ . '/bin';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    // 1) Modo Python: baixa o zipapp puro e confirma que ele REALMENTE roda
    $py = ytdlp_python();
    if ($py) {
        $zipapp = $dir . '/yt-dlp.pyz';
        if (!is_file($zipapp) || time() - filemtime($zipapp) > 3 * 86400) {
            $data = ytdlp_download('https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp');
            if ($data && strlen($data) > 500000) {
                @file_put_contents($zipapp, $data);
                @chmod($zipapp, 0755);
            }
        }
        $cand = ['type' => 'py', 'python' => $py, 'zipapp' => $zipapp];
        if (is_file($zipapp) && ytdlp_test_cmd($cand)) return $cand;
    }

    // 2) Fallback: binário standalone (embute o próprio Python, não depende do python3 do servidor)
    if (strtolower(PHP_OS_FAMILY) === 'windows') {
        $bin = $dir . '/yt-dlp.exe';
        $url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe';
    } else {
        $bin = $dir . '/yt-dlp-bin';
        $url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux';
    }
    if (!is_file($bin) || time() - filemtime($bin) > 3 * 86400) {
        $data = ytdlp_download($url);
        if ($data && strlen($data) > 1000000) {
            @file_put_contents($bin . '.tmp', $data);
            @rename($bin . '.tmp', $bin);
            @chmod($bin, 0755);
        } else {
            @unlink($bin . '.tmp');
        }
    }
    $cand = ['type' => 'bin', 'binary' => $bin];
    if (is_file($bin) && is_executable($bin) && ytdlp_test_cmd($cand)) return $cand;
    if (is_file($bin) && is_executable($bin)) return $cand;
    return null;
}

function resolve_via_ytdlp(string $videoId): ?string {
    $prep = ytdlp_prepare();
    if (!$prep) return null;

    $url = 'https://www.youtube.com/watch?v=' . $videoId;
    $formats = [
        'best[ext=mp4][protocol=https]/best[protocol=https]/best',
        'best',
    ];
    foreach ($formats as $fmt) {
        $cmd = ytdlp_build_cmd($prep, ['-f', $fmt, '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates', $url]);
        exec($cmd, $output, $return_var);
        $line = trim($output[0] ?? '');
        $output = [];
        if ($return_var === 0 && strpos($line, 'http') === 0) {
            return $line;
        }
    }
    return null;
}

// ------------------------------------------------------------------
// PROXY: transmite os bytes do stream pelo próprio servidor.
// Assim o player (ex.: StreamFlow em outro IP) recebe o conteúdo
// direto daqui, sem levar 403 de assinatura vinculada ao IP.
// ------------------------------------------------------------------

function proxy_stream(string $url): void {
    set_time_limit(0);
    @ini_set('output_buffering', '0');
    header('X-Accel-Buffering: no');

    $headers = [];
    if (!empty($_SERVER['HTTP_RANGE'])) $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
    $headers[] = 'Referer: https://www.youtube.com/';
    $headers[] = 'Origin: https://www.youtube.com';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) {
            echo $data;
            if (function_exists('ob_flush')) @ob_flush();
            flush();
            return strlen($data);
        },
        CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) {
            $trim = trim($headerLine);
            if (strpos($trim, 'HTTP/') === 0) { header($trim); }
            else {
                $parts = explode(':', $trim, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    if (in_array($name, ['content-type', 'content-range', 'accept-ranges', 'content-length', 'content-disposition', 'etag'])) {
                        header($trim);
                    }
                }
            }
            return strlen($headerLine);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function url_join(string $base, string $rel): string {
    if ($rel === '' || $rel === null) return $base;
    if (strpos($rel, '://') !== false) return $rel;
    $p = parse_url($base);
    $scheme = $p['scheme'] ?? 'http';
    $host   = $p['host'] ?? '';
    $port   = isset($p['port']) ? ':' . $p['port'] : '';
    if ($rel[0] === '/') return $scheme . '://' . $host . $port . $rel;
    $path = $p['path'] ?? '/';
    $dir  = substr($path, 0, strrpos($path, '/') + 1);
    return $scheme . '://' . $host . $port . $dir . $rel;
}

function fetch_url(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Referer: https://www.youtube.com/',
        ],
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $hs = $info['header_size'] ?? 0;
    return [
        'status' => $info['http_code'] ?? 0,
        'url'    => $info['url'] ?? $url,
        'body'   => $hs > 0 ? substr($resp, $hs) : $resp,
    ];
}

function output_stream(string $url, string $videoId): void {
    $isHls = (stripos($url, '.m3u8') !== false) || (stripos($url, 'manifest') !== false);
    if ($isHls) {
        proxy_hls($url, $videoId);
    } else {
        proxy_stream($url);
    }
}

function is_playlist_url(string $url): bool {
    return stripos($url, '.m3u8') !== false
        || stripos($url, 'manifest') !== false
        || stripos($url, 'playlist') !== false
        || stripos($url, 'index.m3u8') !== false;
}

function proxy_hls(string $manifestUrl, string $videoId): void {
    $data = fetch_url($manifestUrl);
    if (empty($data['body']) || ($data['status'] >= 400)) {
        http_response_code(502);
        echo "Falha ao buscar manifest HLS (status {$data['status']}).";
        return;
    }
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');

    $baseNow = url_base_current();
    foreach (preg_split('/\r?\n/', $data['body']) as $line) {
        $line = rtrim($line);
        if ($line === '') { echo "\n"; continue; }
        if ($line[0] === '#') { echo $line . "\n"; continue; }
        $abs = url_join($data['url'], trim($line));
        echo $baseNow . '/stream.php?u=' . rawurlencode($abs) . "\n";
    }
}

function url_base_current(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
}

// ------------------------------------------------------------------
// YOUTUBE API EXTRAS (Grade e M3U)
// ------------------------------------------------------------------
function yt_get_channel_uploads_playlist(string $channelId): string {
    // A playlist de uploads de um canal substitui o "UC" inicial por "UU"
    if (strpos($channelId, 'UC') === 0) {
        return 'UU' . substr($channelId, 2);
    }
    return $channelId;
}

function get_channel_videos_paginated(string $channelId, string $api_key, int $maxResults = 15, string $pageToken = ''): array {
    $playlistId = yt_get_channel_uploads_playlist($channelId);
    $url = "https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId=" . urlencode($playlistId) . "&maxResults=" . (int)$maxResults . "&key=" . urlencode($api_key);
    
    if (!empty($pageToken)) {
        $url .= "&pageToken=" . urlencode($pageToken);
    }
    return yt_get_json($url);
}

function get_cached_channel_videos(string $channelId, string $api_key, int $maxResults = 50): array {
    $cacheFile = CACHE_DIR . "/videos_{$channelId}.json";
    $now = time();
    
    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if ($cache && ($now - $cache['time'] < 3600)) { // Cache de 1 hora
            return $cache['data'];
        }
    }
    
    $data = get_channel_videos_paginated($channelId, $api_key, $maxResults);
    @file_put_contents($cacheFile, json_encode(['time' => $now, 'data' => $data]));
    return $data;
}

function get_live_video_id_by_channel_id(string $channelId, string $api_key): ?string {
    $cacheFile = CACHE_DIR . "/live_{$channelId}.json";
    $now = time();
    
    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if ($cache && ($now - $cache['time'] < 300)) { // Cache de 5 minutos para lives
            return $cache['video_id'];
        }
    }

    $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&eventType=live&channelId=" . urlencode($channelId) . "&order=viewCount&maxResults=1&key=" . urlencode($api_key);
    $data = yt_get_json($url);
    $videoId = $data['items'][0]['id']['videoId'] ?? null;
    
    @file_put_contents($cacheFile, json_encode(['time' => $now, 'video_id' => $videoId]));
    return $videoId;
}

// ------------------------------------------------------------------
// FUNÇÕES ORIGINAIS OTIMIZADAS
// ------------------------------------------------------------------
function resolve_channel_id(string $input, string $api_key): ?string {
    $input = trim($input);
    if (preg_match('~^UC[0-9A-Za-z_-]{20,}$~', $input)) return $input;
    $handle = null;
    if ($input[0] === '@') $handle = substr($input, 1);
    elseif (preg_match('~youtube\.com/@([^/?#]+)~i', $input, $m)) $handle = $m[1];
    elseif (preg_match('~youtube\.com/channel/(UC[0-9A-Za-z_-]{20,})~i', $input, $m)) return $m[1];
    elseif (preg_match('~youtube\.com/user/([^/?#]+)~i', $input, $m)) return yt_get_channel_id("forUsername=" . urlencode($m[1]), $api_key);
    elseif (preg_match('~youtube\.com/c/([^/?#]+)~i', $input, $m)) $handle = $m[1];
    
    if ($handle) {
        $cid = yt_get_channel_id("forHandle=" . urlencode($handle), $api_key);
        if ($cid) return $cid;
        $cid = yt_search_channel_id($handle, $api_key);
        if ($cid) return $cid;
    }
    return yt_search_channel_id($input, $api_key);
}

function yt_get_channel_id(string $params, string $api_key): ?string {
    $url = "https://www.googleapis.com/youtube/v3/channels?part=id&$params&key=" . urlencode($api_key);
    $data = yt_get_json($url);
    return $data['items'][0]['id'] ?? null;
}

function yt_search_channel_id(string $q, string $api_key): ?string {
    $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=channel&maxResults=1&q=" . urlencode($q) . "&key=" . urlencode($api_key);
    $data = yt_get_json($url);
    return $data['items'][0]['snippet']['channelId'] ?? null;
}

function get_latest_video_id_by_channel_id(string $channelId, string $api_key): ?string {
    $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&order=date&channelId=" . urlencode($channelId) . "&maxResults=5&key=" . urlencode($api_key);
    $data = yt_get_json($url);
    foreach (($data['items'] ?? []) as $it) {
        $vid = $it['id']['videoId'] ?? null;
        if ($vid) return $vid;
    }
    return null;
}

function yt_get_json(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    return is_array($json) ? $json : [];
}

function extract_video_id(?string $url): ?string {
    if (!$url) return null;
    $url = trim($url);
    if (preg_match('~[?&]v=([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) return $url;
    return null;
}

function normalize_channel_input($link): ?array {
    $link = trim($link);
    if (!$link) return null;

    $vid = extract_video_id($link);
    if ($vid) return ['type' => 'video_id', 'value' => $vid];

    $cid = resolve_channel_id($link, YT_API_KEY);
    if ($cid) return ['type' => 'channel', 'value' => $cid];

    $handle = ltrim($link, '@');
    if (preg_match('~^[A-Za-z0-9_\.-]+$~', $handle) && strlen($handle) <= 30) {
        return ['type' => 'handle', 'value' => $handle];
    }
    return null;
}

function invidious_instances(): array {
    return [
        'https://inv.nadeko.net',
        'https://yewtu.be',
        'https://invidious.nerdvpn.de',
        'https://invidious.privacyredirect.com'
    ];
}

function piped_instances(): array {
    return [
        'https://pipedapi.kavin.rocks',
        'https://api.piped.yt',
        'https://pipedapi.pfcd.me'
    ];
}

function resolve_via_piped(string $videoId): ?string {
    foreach (piped_instances() as $api) {
        $url = "$api/streams/{$videoId}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        if (!is_array($json) || !empty($json['error'])) continue;
        if (!empty($json['hls'])) return $json['hls'];

        $best = null;
        foreach (($json['videoStreams'] ?? []) as $f) {
            if (empty($f['url'])) continue;
            if (empty($f['videoOnly'])) return $f['url'];
            if (!$best || ($f['height'] ?? 0) > ($best['height'] ?? 0)) $best = $f;
        }
        if ($best) return $best['url'];
    }
    return null;
}

function resolve_via_invidious(string $videoId): ?string {
    foreach (invidious_instances() as $inst) {
        $attempts = [
            "$inst/api/v1/videos/{$videoId}?fields=hlsUrl,formatStreams,adaptiveFormats",
            "$inst/latest_version?id={$videoId}&itag=18",
        ];
        foreach ($attempts as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0'],
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $json = json_decode((string)$resp, true);
            if (is_array($json)) {
                if (!empty($json['hlsUrl'])) return $json['hlsUrl'];
                if (!empty($json['url'])) return $json['url'];
                foreach (($json['formatStreams'] ?? []) as $f) {
                    if (!empty($f['url'])) return $f['url'];
                }
                foreach (($json['adaptiveFormats'] ?? []) as $f) {
                    if (!empty($f['url']) && strpos($f['type'] ?? '', 'video') === 0) return $f['url'];
                }
            }
        }
    }
    return null;
}