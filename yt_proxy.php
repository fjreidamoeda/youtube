<?php
// yt_proxy.php - Proxy de streams do YouTube para StreamFlow
// Copie este arquivo para a pasta do StreamFlow (ex: c:/xampp/htdocs/streamflow/)
// Uso: yt_proxy.php?id=YOUTUBE_VIDEO_ID

$videoId = $_GET['id'] ?? '';
if (!$videoId) {
    header("HTTP/1.1 400 Bad Request");
    exit('id nao fornecido');
}

$ytUrl = 'https://www.youtube.com/watch?v=' . urlencode($videoId);

// Tenta yt-dlp via Python (se instalado)
$ytdlp = exec('where yt-dlp 2>nul', $out, $code);
if ($code === 0) {
    $cmd = 'yt-dlp -f "best[protocol=https]/best" --get-url ' . escapeshellarg($ytUrl) . ' 2>nul';
    $streamUrl = trim(shell_exec($cmd));
    if ($streamUrl && strpos($streamUrl, 'http') === 0) {
        header('Location: ' . $streamUrl, true, 302);
        exit;
    }
}

// Tenta via Python + yt-dlp
$pyCmd = 'python -c "import yt_dlp; yd = yt_dlp.YoutubeDL({\"quiet\": True, \"format\": \"best[protocol=https]/best\"}); info = yd.extract_info(\'' . addslashes($ytUrl) . '\', download=False); print(info[\'url\'])" 2>nul';
$streamUrl = trim(shell_exec($pyCmd));
if ($streamUrl && strpos($streamUrl, 'http') === 0) {
    header('Location: ' . $streamUrl, true, 302);
    exit;
}

// Fallback: extrai HLS da pagina do YouTube
$html = @file_get_contents($ytUrl, false, stream_context_create([
    'http' => [
        'timeout' => 15,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
]));

if ($html) {
    // Tenta ytInitialPlayerResponse
    if (preg_match('/ytInitialPlayerResponse\s*=\s*({.+?});/s', $html, $m)) {
        $data = json_decode($m[1], true);
        if ($data && !empty($data['streamingData']['hlsManifestUrl'])) {
            header('Location: ' . $data['streamingData']['hlsManifestUrl'], true, 302);
            exit;
        }
    }
}

// Ultimo recurso: redireciona pro YouTube
header('Location: ' . $ytUrl, true, 302);
