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

/**
 * Procura um binário já existente (colocado manualmente na pasta bin/, ou
 * instalado no sistema e disponível no PATH) antes de tentar baixar
 * qualquer coisa da internet.
 */
function find_existing_binary(array $names): ?string {
    $dir = __DIR__ . '/bin';
    foreach ($names as $n) {
        $p = $dir . '/' . $n;
        if (is_file($p) && is_executable($p)) return $p;
    }
    
    $isWindows = strtolower(PHP_OS_FAMILY) === 'windows';
    foreach ($names as $n) {
        $out = [];
        if ($isWindows) {
            @exec('where ' . escapeshellarg($n) . ' 2>NUL', $out);
        } else {
            @exec('command -v ' . escapeshellarg($n) . ' 2>/dev/null', $out);
        }
        if (!empty($out[0])) return trim($out[0]);
    }
    return null;
}

function find_ffmpeg(): ?string {
    return find_existing_binary(['ffmpeg', 'ffmpeg.exe']);
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
    if (!empty($prep['ffmpeg'])) {
        $args = array_merge(['--ffmpeg-location', $prep['ffmpeg']], $args);
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

    // 0) Usa um yt-dlp já existente (colocado manualmente na pasta bin/, ou
    // instalado no sistema) antes de tentar baixar qualquer coisa.
    $existing = find_existing_binary(['yt-dlp', 'yt-dlp-bin', 'yt-dlp.exe', 'yt-dlp.pyz']);
    if ($existing) {
        $isZip = substr($existing, -4) === '.pyz';
        $cand = $isZip
            ? ['type' => 'py', 'python' => ytdlp_python() ?: 'python3', 'zipapp' => $existing]
            : ['type' => 'bin', 'binary' => $existing];
        if (ytdlp_test_cmd($cand)) {
            log_stream('yt-dlp: usando binário existente em ' . $existing);
            $cand['ffmpeg'] = find_ffmpeg();
            return $cand;
        }
        log_stream('yt-dlp: binário encontrado em ' . $existing . ' mas falhou no teste --version');
    }

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
        if (is_file($zipapp) && ytdlp_test_cmd($cand)) {
            $cand['ffmpeg'] = find_ffmpeg();
            return $cand;
        }
    }

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
    if (is_file($bin) && is_executable($bin) && ytdlp_test_cmd($cand)) {
        $cand['ffmpeg'] = find_ffmpeg();
        return $cand;
    }
    if (is_file($bin) && is_executable($bin)) {
        $cand['ffmpeg'] = find_ffmpeg();
        return $cand;
    }
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
// PROXY
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
        CURLOPT_HEADER => true,
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

function serve_resolved(string $streamUrl, string $videoId): void {
    if (is_playlist_url($streamUrl)) {
        proxy_hls_vlc($streamUrl, $videoId);
    } else {
        proxy_stream($streamUrl);
    }
}

// PROXY HLS PARA IPTV (Xtream/XUI.One) - Manifesto mínimo
function proxy_hls_iptv(string $manifestUrl, string $videoId): void {
    $data = fetch_url($manifestUrl);
    if (empty($data['body']) || ($data['status'] >= 400)) {
        output_minimal_manifest($videoId, $manifestUrl);
        return;
    }
    
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    
    $baseNow = url_base_current();
    $lines = preg_split('/\r?\n/', $data['body']);
    $segmentCount = 0;
    $maxSegments = 5;
    $lastInf = '';
    $output = [];
    
    $output[] = '#EXTM3U';
    $output[] = '#EXT-X-VERSION:3';
    $output[] = '#EXT-X-TARGETDURATION:10';
    $output[] = '#EXT-X-MEDIA-SEQUENCE:0';
    $output[] = '#EXT-X-PLAYLIST-TYPE:VOD';
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') continue;
        
        if (strpos($line, '#EXTINF') === 0) {
            $lastInf = $line;
            continue;
        }
        
        if ($line[0] === '#') {
            continue;
        }
        
        if ($lastInf && $segmentCount < $maxSegments) {
            $abs = url_join($data['url'], trim($line));
            $output[] = $lastInf;
            $output[] = $baseNow . '/stream.php?u=' . rawurlencode($abs);
            $segmentCount++;
            $lastInf = '';
        }
        
        if ($segmentCount >= $maxSegments) {
            $output[] = '#EXT-X-ENDLIST';
            break;
        }
    }
    
    if ($segmentCount === 0) {
        $output[] = '#EXTINF:10.0,';
        $output[] = $baseNow . '/stream.php?u=' . rawurlencode($manifestUrl) . '&seg=1';
        $output[] = '#EXTINF:10.0,';
        $output[] = $baseNow . '/stream.php?u=' . rawurlencode($manifestUrl) . '&seg=2';
        $output[] = '#EXT-X-ENDLIST';
    }
    
    echo implode("\n", $output);
}

// PROXY HLS PARA VLC - Manifesto completo com URLs reescritas
function proxy_hls_vlc(string $manifestUrl, string $videoId): void {
    $data = fetch_url($manifestUrl);
    if (empty($data['body']) || ($data['status'] >= 400)) {
        http_response_code(502);
        echo "Falha ao buscar manifest HLS.";
        return;
    }
    
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    
    $baseNow = url_base_current();
    $lines = preg_split('/\r?\n/', $data['body']);
    $isMaster = strpos($data['body'], '#EXT-X-STREAM-INF') !== false;
    $isLive = !$isMaster && strpos($data['body'], '#EXT-X-ENDLIST') === false;
    
    if ($isLive) {
        // Manifesto ao vivo: entrega só os últimos segmentos (maioria dos apps
        // de IPTV falha com manifesto gigante de live do YouTube).
        $keep = 10;
        $headerLines = [];
        $segments = [];
        $pendingInf = '';
        $baseSeq = 0;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') continue;
            if (preg_match('/^#EXT-X-MEDIA-SEQUENCE:(\d+)/', $line, $m)) {
                $baseSeq = (int)$m[1];
                continue;
            }
            if ($line[0] === '#') {
                if (strpos($line, '#EXTINF') === 0) {
                    $pendingInf = $line;
                } elseif ($pendingInf === '' && empty($segments)) {
                    $headerLines[] = $line;
                }
                continue;
            }
            if ($pendingInf !== '') {
                $segments[] = ['inf' => $pendingInf, 'url' => trim($line)];
                $pendingInf = '';
            }
        }
        
        $total = count($segments);
        $startIdx = max(0, $total - $keep);
        
        foreach ($headerLines as $h) echo $h . "\n";
        echo '#EXT-X-MEDIA-SEQUENCE:' . ($baseSeq + $startIdx) . "\n";
        for ($i = $startIdx; $i < $total; $i++) {
            $abs = url_join($data['url'], $segments[$i]['url']);
            echo $segments[$i]['inf'] . "\n";
            echo $baseNow . '/stream.php?u=' . rawurlencode($abs) . "\n";
        }
        return;
    }
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') { 
            echo "\n"; 
            continue; 
        }
        if ($line[0] === '#') {
            // Reescreve URI="..." (EXT-X-MAP, EXT-X-MEDIA etc.) para passar pelo proxy
            if (preg_match('~URI="([^"]+)"~', $line, $m)) {
                $abs = url_join($data['url'], $m[1]);
                $line = str_replace('URI="' . $m[1] . '"', 'URI="' . $baseNow . '/stream.php?u=' . rawurlencode($abs) . '"', $line);
            }
            echo $line . "\n"; 
            continue; 
        }
        if (strpos($line, 'http') !== 0) {
            $abs = url_join($data['url'], trim($line));
            echo $baseNow . '/stream.php?u=' . rawurlencode($abs) . "\n";
        } else {
            echo $baseNow . '/stream.php?u=' . rawurlencode($line) . "\n";
        }
    }
}

function output_minimal_manifest(string $videoId, string $streamUrl): void {
    $baseNow = url_base_current();
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    
    echo "#EXTM3U\n";
    echo "#EXT-X-VERSION:3\n";
    echo "#EXT-X-TARGETDURATION:10\n";
    echo "#EXT-X-MEDIA-SEQUENCE:0\n";
    echo "#EXT-X-PLAYLIST-TYPE:VOD\n";
    
    for ($i = 0; $i < 3; $i++) {
        echo "#EXTINF:10.0,\n";
        echo $baseNow . '/stream.php?u=' . rawurlencode($streamUrl) . "&seg={$i}\n";
    }
    echo "#EXT-X-ENDLIST\n";
}

function url_base_current(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
}

// ------------------------------------------------------------------
// YOUTUBE API EXTRAS
// ------------------------------------------------------------------

function yt_get_channel_uploads_playlist(string $channelId): string {
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
    return yt_get_json($url, 'playlistItems_' . $channelId);
}

function get_cached_channel_videos(string $channelId, string $api_key, int $maxResults = 50): array {
    $cacheFile = CACHE_DIR . "/videos_{$channelId}.json";
    $now = time();
    
    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if ($cache) {
            $cachedCount = count($cache['data']['items'] ?? []);
            $age = $now - ($cache['time'] ?? 0);
            // Cache "cheio": vale por até 1h.
            if ($cachedCount > 0 && $age < 3600 && ($cachedCount >= $maxResults || $cachedCount >= 50)) {
                return $cache['data'];
            }
            // Cache "vazio" (API falhou/sem vídeos): vale só 5 min, para não
            // esconder por 1h inteira um problema de chave/quota já corrigido.
            if ($cachedCount === 0 && $age < 300) {
                return $cache['data'];
            }
        }
    }
    
    // Busca com paginação para atingir até $maxResults vídeos
    $allItems = [];
    $pageToken = '';
    $remaining = $maxResults;
    $pageInfo = [];
    
    while ($remaining > 0) {
        $perPage = min(50, $remaining); // API aceita no máximo 50 por página
        $data = get_channel_videos_paginated($channelId, $api_key, $perPage, $pageToken);
        
        if (empty($data['items'])) break;
        
        $allItems = array_merge($allItems, $data['items']);
        $remaining -= count($data['items']);
        $pageInfo = $data['pageInfo'] ?? [];
        
        // Se não tem próxima página, para
        if (empty($data['nextPageToken'])) break;
        $pageToken = $data['nextPageToken'];
    }
    
    $result = [
        'items' => array_slice($allItems, 0, $maxResults),
        'pageInfo' => $pageInfo,
    ];
    
    @file_put_contents($cacheFile, json_encode(['time' => $now, 'data' => $result]));
    return $result;
}

function get_live_video_id_by_channel_id(string $channelId, string $api_key): ?string {
    $cacheFile = CACHE_DIR . "/live_{$channelId}.json";
    $now = time();

    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if ($cache && ($now - $cache['time'] < 120)) {
            return $cache['video_id'];
        }
    }

    // 1) Checagem gratuita (sem gastar quota): a página /live do canal só
    //    existe/renderiza um player quando o canal está ao vivo.
    $videoId = detect_live_via_scrape($channelId);

    // 2) Fallback: YouTube Data API search.list (custa 100 unidades de quota
    //    por chamada). Só usa se o scrape não confirmou nada, para não
    //    estourar a quota diária (10.000 unidades no plano gratuito).
    if (!$videoId) {
        $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&eventType=live&channelId=" . urlencode($channelId) . "&order=viewCount&maxResults=1&key=" . urlencode($api_key);
        $data = yt_get_json($url, 'live_search_' . $channelId);
        $videoId = $data['items'][0]['id']['videoId'] ?? null;
    }

    @file_put_contents($cacheFile, json_encode(['time' => $now, 'video_id' => $videoId]));
    return $videoId;
}

/**
 * Verifica se um canal está ao vivo agora sem gastar quota da Data API,
 * lendo a página pública /live do canal (redireciona para o watch?v= do
 * vídeo ao vivo quando existe transmissão em andamento).
 */
function detect_live_via_scrape(string $channelId): ?string {
    $url = "https://www.youtube.com/channel/" . rawurlencode($channelId) . "/live";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return null;

    // O player só marca isLive/isLiveBroadcast=true quando a transmissão
    // está realmente ao ar agora.
    if (strpos($html, '"isLive":true') === false && strpos($html, '"isLiveBroadcast":true') === false) {
        return null;
    }
    if (preg_match('~"videoId":"([A-Za-z0-9_-]{11})"~', $html, $m)) {
        return $m[1];
    }
    return null;
}

// ------------------------------------------------------------------
// FUNÇÕES DE RESOLUÇÃO
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

function yt_get_json(string $url, string $label = ''): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $json = json_decode((string)$resp, true);

    // Loga qualquer erro da API do YouTube (chave inválida, quota estourada,
    // API não habilitada, restrição de referrer/IP etc.) em cache/stream.log,
    // em vez de simplesmente devolver um array vazio e esconder o motivo real.
    if (!is_array($json) || isset($json['error'])) {
        $reason  = $json['error']['errors'][0]['reason'] ?? null;
        $message = $json['error']['message'] ?? ($curlErr ?: 'resposta inválida/vazia da API');
        $httpCode = $info['http_code'] ?? 0;
        log_stream("YT API ERRO" . ($label ? " [{$label}]" : '') . ": HTTP {$httpCode} - {$message}" . ($reason ? " (reason={$reason})" : ''));
    }

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

function resolve_via_piped(string $videoId, int $perInstanceTimeout = 4): ?string {
    foreach (piped_instances() as $api) {
        $url = "$api/streams/{$videoId}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min(4, $perInstanceTimeout),
            CURLOPT_CONNECTTIMEOUT => 2,
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

function resolve_via_invidious(string $videoId, int $perInstanceTimeout = 4): ?string {
    foreach (invidious_instances() as $inst) {
        // Só a URL de vídeo formatado direto (mais rápido, um pedido por instância)
        $attempts = [
            "$inst/api/v1/videos/{$videoId}?fields=hlsUrl,formatStreams,adaptiveFormats",
        ];
        foreach ($attempts as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => min(4, $perInstanceTimeout),
                CURLOPT_CONNECTTIMEOUT => 2,
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

// ------------------------------------------------------------------
// RESOLUÇÃO COM ORÇAMENTO DE TEMPO + CACHE NEGATIVO
// (evita estourar o timeout de probe do Xtream/XUI e evita repetir
// a cadeia inteira de fallbacks toda vez que ela falhou há pouco)
// ------------------------------------------------------------------

function log_stream(string $msg): void {
    @file_put_contents(CACHE_DIR . '/stream.log', date('Y-m-d H:i:s') . ' - ' . $msg . "\n", FILE_APPEND);
}

/**
 * Faz um HEAD real na URL resolvida para descobrir o Content-Type/tamanho
 * verdadeiros, em vez de adivinhar. Necessário porque vídeos arquivados
 * (não-live) costumam resolver para MP4/WebM progressivo, não para
 * segmento MPEG-TS — mandar o Content-Type errado no probe do Xtream faz
 * o painel rejeitar o canal antes mesmo de tentar reproduzir.
 */
function probe_remote_headers(string $url, int $timeout = 5): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Referer: https://www.youtube.com/',
        ],
    ]);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $headers = [];
    if ($resp) {
        foreach (explode("\r\n", $resp) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
    }
    return ['status' => $info['http_code'] ?? 0, 'headers' => $headers];
}

/**
 * Resolve a URL de stream para um videoId, respeitando um orçamento de
 * tempo total ($budgetSeconds). Usa cache positivo (4h, URLs do YouTube
 * valem ~6h) e cache negativo (60s) para não martelar provedores externos
 * a cada request.
 */
function resolve_stream_url(string $id, int $budgetSeconds = 18): ?string {
    $cacheFile = CACHE_DIR . "/yt_video_{$id}.json";
    $negCacheFile = CACHE_DIR . "/yt_video_{$id}_fail.json";
    $now = time();

    if (is_file($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if (!empty($cache['url']) && ($now - ($cache['time'] ?? 0) < 14400)) {
            return $cache['url'];
        }
    }

    // Cache negativo: se falhou nos últimos 60s, não tenta de novo agora
    if (is_file($negCacheFile)) {
        $neg = json_decode(@file_get_contents($negCacheFile), true);
        if ($neg && ($now - ($neg['time'] ?? 0) < 60)) {
            log_stream("id={$id} resolução em cache negativo, pulando");
            return null;
        }
    }

    $deadline = microtime(true) + $budgetSeconds;
    $streamUrl = null;

    // 1) yt-dlp (mais confiável quando funciona)
    if (microtime(true) < $deadline) {
        $streamUrl = resolve_via_ytdlp($id);
        if ($streamUrl) log_stream("id={$id} resolvido via yt-dlp");
    }

    // 2) Piped (timeout curto, aborta cedo se estourar o orçamento)
    if (!$streamUrl && microtime(true) < $deadline) {
        $streamUrl = resolve_via_piped($id, max(2, (int)($deadline - microtime(true))));
        if ($streamUrl) log_stream("id={$id} resolvido via piped");
    }

    // 3) Invidious
    if (!$streamUrl && microtime(true) < $deadline) {
        $streamUrl = resolve_via_invidious($id, max(2, (int)($deadline - microtime(true))));
        if ($streamUrl) log_stream("id={$id} resolvido via invidious");
    }

    // 4) Scrape direto do HTML do YouTube (último recurso, raramente funciona hoje em dia)
    if (!$streamUrl && microtime(true) < $deadline) {
        $streamUrl = resolve_via_html_scrape($id);
        if ($streamUrl) log_stream("id={$id} resolvido via scrape HTML");
    }

    if ($streamUrl) {
        $streamUrl = str_replace(['\\u0026', '\\/'], ['&', '/'], $streamUrl);
        @file_put_contents($cacheFile, json_encode(['url' => $streamUrl, 'time' => $now]));
        @unlink($negCacheFile);
        return $streamUrl;
    }

    log_stream("id={$id} FALHOU em todos os métodos de resolução");
    @file_put_contents($negCacheFile, json_encode(['time' => $now]));
    return null;
}

function resolve_via_html_scrape(string $id): ?string {
    $watchUrl = "https://www.youtube.com/watch?v={$id}&hl=en";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $watchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!preg_match('~ytInitialPlayerResponse\s*=\s*({.+?});~s', (string)$html, $m2)) return null;
    $data = json_decode($m2[1], true);
    if (!$data) return null;

    if (isset($data['streamingData']['hlsManifestUrl'])) return $data['streamingData']['hlsManifestUrl'];

    $best = null;
    foreach (($data['streamingData']['adaptiveFormats'] ?? []) as $f) {
        if (strpos($f['mimeType'] ?? '', 'video') !== 0 || empty($f['url'])) continue;
        $h = $f['height'] ?? 0;
        if (!$best || $h > $best['height']) $best = ['height' => $h, 'url' => $f['url']];
    }
    if ($best) return $best['url'];

    foreach (($data['streamingData']['formats'] ?? []) as $f) {
        if (!empty($f['url']) && strpos($f['mimeType'] ?? '', 'video') === 0) return $f['url'];
    }
    return null;
}

// DETECTA SE É REQUISIÇÃO IPTV (Xtream/XUI.One)
function is_iptv_request(): bool {
    // HEAD request = Xtream/XUI probe
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        return true;
    }
    
    // Parâmetro IPTV forçado (adicionado automaticamente pela lista.php)
    if (isset($_GET['iptv']) && $_GET['iptv'] === '1') {
        return true;
    }
    
    // Parâmetro XUI forçado (compatibilidade)
    if (isset($_GET['xui']) && $_GET['xui'] === '1') {
        return true;
    }
    
    // User-Agent específico de painéis IPTV
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
        if (strpos($ua, 'xtream') !== false || 
            strpos($ua, 'xui') !== false ||
            strpos($ua, 'stalker') !== false ||
            strpos($ua, 'ministra') !== false ||
            strpos($ua, 'mag') !== false ||
            strpos($ua, 'iptvpro') !== false ||
            strpos($ua, 'tivimate') !== false ||
            strpos($ua, 'ott') !== false) {
            return true;
        }
    }
    
    // URL terminando em .ts (path-info) = pedido de fluxo MPEG-TS
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    if (preg_match('~\.ts$~i', $pathInfo)) {
        return true;
    }
    
    return false;
}
?>