<?php
// functions.php - Helpers para o YouTube IPTV
require_once __DIR__ . '/config.php';

define('DATA_FILE', __DIR__ . '/data.json');

function load_channels(): array {
    if (!is_file(DATA_FILE)) return [];
    $json = @file_get_contents(DATA_FILE);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function save_channels(array $channels): bool {
    return file_put_contents(DATA_FILE, json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

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
    }
    if ($handle) {
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

function get_live_video_id_by_channel_id(string $channelId, string $api_key): ?string {
    $url = "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&eventType=live&channelId=" . urlencode($channelId) . "&order=viewCount&maxResults=1&key=" . urlencode($api_key);
    $data = yt_get_json($url);
    return $data['items'][0]['id']['videoId'] ?? null;
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

// Extrai o video_id de uma URL do YouTube
function extract_video_id(?string $url): ?string {
    if (!$url) return null;
    $url = trim($url);
    if (preg_match('~[?&]v=([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', $url)) return $url;
    return null;
}

// Resolve um link de canal para video_id OU handle
function normalize_channel_input($link): ?array {
    $link = trim($link);
    if (!$link) return null;

    // Video ID ou URL de vídeo
    $vid = extract_video_id($link);
    if ($vid) return ['type' => 'video_id', 'value' => $vid];

    // Canal: @handle, /channel/, /user/, /c/, URL do canal
    $cid = resolve_channel_id($link, YT_API_KEY);
    if ($cid) return ['type' => 'channel', 'value' => $cid];

    // Fallback: tenta como handle direto
    $handle = ltrim($link, '@');
    if (preg_match('~^[A-Za-z0-9_\.-]+$~', $handle) && strlen($handle) <= 30) {
        return ['type' => 'handle', 'value' => $handle];
    }
    return null;
}

// Instâncias Invidious públicas para tentar
function invidious_instances(): array {
    return [
        'https://inv.nadeko.net',
        'https://invidious.nerdvpn.de',
        'https://yewtu.be',
        'https://invidious.privacyredirect.com',
        'https://iv.melmac.space',
        'https://invidious.f5.si',
    ];
}

// Instâncias Piped (API) públicas para tentar
function piped_instances(): array {
    return [
        'https://pipedapi.kavin.rocks',
        'https://api.piped.yt',
        'https://pipedapi.pfcd.me',
        'https://pipedapi.leptons.xyz',
        'https://api.weirish.xyz',
        'https://pipedapi.lunar.icu',
    ];
}

// Resolve via Piped: as URLs já apontam pro proxy do Piped (bytes saem do IP da
// instância), então a assinatura do YouTube é válida e o VLC reproduz.
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

        // Live: usa o manifest HLS (proxy). Ideal pro IPTV.
        if (!empty($json['hls'])) return $json['hls'];

        // Vídeo normal: prefere formato "combinado" (vídeo + áudio juntos).
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

// Resolve o stream via Invidious (retorna URL direta ou null)
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