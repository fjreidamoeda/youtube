<?php
// functions.php - Helpers para o YouTube IPTV
require_once __DIR__ . '/config.php';

define('DATA_FILE', __DIR__ . '/data.json');
define('CACHE_DIR', __DIR__ . '/cache');

// Formato dos segmentos HLS para apps com ExoPlayer (IBO etc.).
// 'mpegts'=> segmentos TS clássicos .ts (padrão; compatível com qualquer ffmpeg >= 3.x e players)
// 'fmp4'  => segmentos CMAF .m4s (corte limpo de áudio, zero CPU, ideal p/ ExoPlayer; exige ffmpeg >= 4.0 e player moderno)
define('HLS_SEGMENT_TYPE', 'mpegts');

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

function ytdlp_prepare(bool $allowDownload = false): ?array {
    // Cache em arquivo para não re-testar/re-baixar o yt-dlp a cada request
    // do player (o IBO polia o manifest a cada ~6s — cada poll não pode ficar lento).
    // IMPORTANTE: no caminho do player (allowDownload=false) NUNCA baixa nada —
    // só usa um binário que já exista e esteja válido; o download/conserto do
    // yt-dlp fica para o script de background (bg_download.php).
    $cache = CACHE_DIR . '/ytdlp_prep.json';
    // No caminho de background (allowDownload=true) NÃO usa o cache: re-prepara
    // sempre, priorizando um binário novo baixado do GitHub.
    if (!$allowDownload && is_file($cache)) {
        $pc = json_decode(@file_get_contents($cache), true);
        if (!empty($pc['prep']) && (time() - ($pc['time'] ?? 0)) < 1800) {
            $cand = $pc['prep'];
            $bin = $cand['binary'] ?? ($cand['zipapp'] ?? '');
            $sig = ($bin && is_file($bin)) ? ((string)@filesize($bin) . ':' . (string)@filemtime($bin)) : '';
            if ($sig !== '' && $sig === ($cand['sig'] ?? '')) {
                $cand['ffmpeg'] = find_ffmpeg();
                return $cand;
            }
        }
    }
    $cand = _ytdlp_prepare_uncached($allowDownload);
    if ($cand) {
        $bin = $cand['binary'] ?? ($cand['zipapp'] ?? '');
        $cand['sig'] = ($bin && is_file($bin)) ? ((string)@filesize($bin) . ':' . (string)@filemtime($bin)) : '';
        @file_put_contents($cache, json_encode(['prep' => $cand, 'time' => time()]));
    }
    return $cand;
}

function ytdlp_bad_list(): array {
    $f = CACHE_DIR . '/ytdlp_bad.txt';
    if (!is_file($f)) return [];
    $out = [];
    foreach (array_filter(array_map('trim', file($f))) as $line) {
        if (preg_match('~^([^|]+)\|(\d+)$~', $line, $m) && time() - (int)$m[2] < 4 * 3600) {
            $out[$m[1]] = (int)$m[2];
        }
    }
    return $out;
}

function ytdlp_mark_bad(string $binary): void {
    $bad = ytdlp_bad_list();
    $bad[$binary] = time();
    $lines = [];
    foreach ($bad as $b => $t) $lines[] = $b . '|' . $t;
    @file_put_contents(CACHE_DIR . '/ytdlp_bad.txt', implode("\n", $lines) . "\n");
    @unlink(CACHE_DIR . '/ytdlp_prep.json'); // força re-preparar
}

/**
 * Código-fonte Python do yt-dlp (extraído de um tarball do GitHub).
 * É a única rota que FUNCIONA no VPS (Python 3.7): os binários PyInstaller
 * (latest e 2025.06.09) não extraem nesse kernel/glibc antigo, mas o yt-dlp
 * 2023.11.16 roda com `python3 <dir>/yt-dlp`. As pastas podem estar em
 * cache/ytdlp_src (criada por ytdlp_download_python_source) ou em ytdlp_pkg/
 * (criada manualmente / pelo install_ytdlp.php).
 */
function ytdlp_src_roots(): array {
    return [CACHE_DIR . '/ytdlp_src', __DIR__ . '/ytdlp_pkg'];
}

/**
 * Python 3.12 portátil instalado por install_py312.php (python-build-standalone).
 * Necessário para rodar o yt-dlp atual, que decifra o nsig do YouTube —
 * o 2023.11.16 (Python 3.7) falha em vídeos novos ("Requested format is not
 * available" por causa de formatos THROTTLED).
 */
function ytdlp_modern_python(): ?string {
    $candidates = [
        CACHE_DIR . '/pybuild/python/bin/python3.12',
        __DIR__ . '/cache/pybuild/python/bin/python3.12',
        __DIR__ . '/pybuild/python/bin/python3.12',
        '/home/container/cache/pybuild/python/bin/python3.12',
    ];
    foreach ($candidates as $c) {
        if (is_file($c) && is_executable($c)) return $c;
    }
    return null;
}

function ytdlp_modern_prep(): ?array {
    $py = ytdlp_modern_python();
    if (!$py) return null;
    $wrapper = __DIR__ . '/bin/yt-dlp-modern';
    if (!is_file($wrapper) || !is_executable($wrapper)) {
        $content = "#!/bin/sh\nexec " . escapeshellarg($py) . " -m yt_dlp \"\$@\"\n";
        if (@file_put_contents($wrapper, $content) === false) return null;
        @chmod($wrapper, 0775);
    }
    $cand = ['type' => 'bin', 'binary' => $wrapper];
    if (ytdlp_test_cmd($cand)) {
        log_stream('yt-dlp: usando yt-dlp MODERNO (Python 3.12) em ' . $wrapper);
        $cand['ffmpeg'] = find_ffmpeg();
        return $cand;
    }
    return null;
}

function ytdlp_find_python_source(): ?array {
    foreach (ytdlp_src_roots() as $root) {
        if (!is_dir($root)) continue;
        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir)) continue;
            // 1) Script raiz yt-dlp (tarball completo do PyPI/GitHub completo).
            $script = $dir . '/yt-dlp';
            if (is_file($script) && is_readable($script)) {
                $cand = ['type' => 'py', 'python' => 'python3', 'zipapp' => $script];
                if (ytdlp_test_cmd($cand)) {
                    log_stream('yt-dlp: usando código-fonte Python em ' . $script);
                    $cand['ffmpeg'] = find_ffmpeg();
                    return $cand;
                }
                log_stream('yt-dlp: fonte ' . $script . ' falhou no teste --version');
                continue;
            }
            // 2) Pacote yt_dlp/ sem o script raiz (os tarballs do GitHub vêm
            //    truncados SEM o yt-dlp raiz). Gera o wrapper bin/yt-dlp
            //    (python3 -m yt_dlp) e usa como binário — é a única rota que
            //    funciona no Python 3.7 do VPS.
            if (is_dir($dir . '/yt_dlp') && is_file($dir . '/yt_dlp/__init__.py')) {
                $wrapper = __DIR__ . '/bin/yt-dlp';
                if (ytdlp_ensure_wrapper($wrapper, $dir)) {
                    $cand = ['type' => 'bin', 'binary' => $wrapper];
                    if (ytdlp_test_cmd($cand)) {
                        log_stream('yt-dlp: usando pacote Python + wrapper em ' . $wrapper);
                        $cand['ffmpeg'] = find_ffmpeg();
                        return $cand;
                    }
                    log_stream('yt-dlp: wrapper ' . $wrapper . ' falhou no teste --version');
                }
            }
        }
    }
    return null;
}

/**
 * Garante o wrapper bin/yt-dlp que roda `python3 -m yt_dlp` com PYTHONPATH
 * apontando para a fonte. Se um wrapper válido já existir, reaproveita.
 */
function ytdlp_ensure_wrapper(string $wrapper, string $srcDir): bool {
    if (is_file($wrapper) && is_readable($wrapper) && is_executable($wrapper)) {
        return true;
    }
    $content = "#!/bin/sh\n"
             . 'PYTHONPATH="' . addcslashes($srcDir, '"\\') . "\"\n"
             . "export PYTHONPATH\n"
             . "exec python3 -m yt_dlp \"\$@\"\n";
    if (@file_put_contents($wrapper, $content) === false) return false;
    @chmod($wrapper, 0775);
    return is_file($wrapper) && is_executable($wrapper);
}

/**
 * Args extras para o yt-dlp quando existe cache/cookies.txt (formato Netscape,
 * exportado do navegador logado). Ajuda a destravar vídeos que o YouTube
 * bloqueia para IP de datacenter sem conta ("live stream recording is not
 * available", age/geo-restrictions etc.). Se o arquivo não existir, não
 * adiciona nada — o comportamento atual é mantido.
 */
function yt_cookies_args(): array {
    $f = CACHE_DIR . '/cookies.txt';
    if (is_file($f) && @filesize($f) > 50) {
        log_stream('yt-dlp: usando cookies de ' . $f);
        return ['--cookies', $f];
    }
    return [];
}

function ytdlp_download_python_source(): ?array {
    $root = CACHE_DIR . '/ytdlp_src';
    if (!is_dir($root)) @mkdir($root, 0775, true);
    // Última versão compatível com Python 3.7 (suporte a 3.7 caiu no 2024.04.09).
    $versions = ['2023.11.16', '2023.12.30', '2024.03.10'];
    foreach ($versions as $v) {
        $url = 'https://github.com/yt-dlp/yt-dlp/archive/refs/tags/' . $v . '.tar.gz';
        $data = ytdlp_download($url);
        if (!$data || strlen($data) < 100000) {
            log_stream("yt-dlp: tarball {$v} falhou no download");
            continue;
        }
        $tgz = $root . "/yt-dlp-{$v}.tar.gz";
        @file_put_contents($tgz, $data);
        $o = null;
        $rc = null;
        @exec('tar xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($root) . ' 2>&1', $o, $rc);
        @unlink($tgz);
        $w = $root . "/yt-dlp-{$v}/yt-dlp";
        if ($rc !== 0 || !is_file($w)) {
            log_stream("yt-dlp: extração do tarball {$v} falhou");
            continue;
        }
        $cand = ['type' => 'py', 'python' => 'python3', 'zipapp' => $w];
        $cand['ffmpeg'] = find_ffmpeg();
        if (ytdlp_test_cmd($cand)) {
            log_stream("yt-dlp: fonte {$v} baixada e testada OK");
            return $cand;
        }
        log_stream("yt-dlp: fonte {$v} baixada mas falhou no teste --version");
    }
    return null;
}

function _ytdlp_prepare_uncached(bool $allowDownload): ?array {
    $dir = __DIR__ . '/bin';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $bad = ytdlp_bad_list();

    // Prioridade 1: yt-dlp MODERNO (Python 3.12 + yt-dlp atual) — resolve o
    // nsig/THROTTLED dos vídeos novos, que o 2023.11.16 não decifra.
    $mod = ytdlp_modern_prep();
    if ($mod) return $mod;

    // Prioridade 2: código-fonte Python já extraído (única rota que funcionava
    // no VPS com Python 3.7; os binários PyInstaller não extraem nesse sistema).
    $src = ytdlp_find_python_source();
    if ($src) return $src;

    // Prioridade 3 (quando há download permitido): baixa o código-fonte Python.
    if ($allowDownload) {
        $src = ytdlp_download_python_source();
        if ($src) return $src;
    }

    // Prioridade 4 (quando há download permitido): binário baixado do GitHub.
    // Moderno e íntegro — evita o binário antigo do sistema que passa no
    // --version mas não extrai vídeo do YouTube atual.
    if ($allowDownload) {
        $cand = ytdlp_download_binary($dir);
        if ($cand) return $cand;
    }

    // Prioridade 5: yt-dlp já existente (bin/ ou PATH), fora da lista negra.
    $existing = find_existing_binary(['yt-dlp', 'yt-dlp-bin', 'yt-dlp.exe', 'yt-dlp.pyz']);
    if ($existing && !isset($bad[$existing])) {
        $isZip = substr($existing, -4) === '.pyz';
        $cand = $isZip
            ? ['type' => 'py', 'python' => ytdlp_python() ?: 'python3', 'zipapp' => $existing]
            : ['type' => 'bin', 'binary' => $existing];
        if (ytdlp_test_cmd($cand)) {
            log_stream('yt-dlp: usando binário existente em ' . $existing);
            $cand['ffmpeg'] = find_ffmpeg();
            return $cand;
        }
        // Não apaga: um wrapper/source pode ser recriado por ytdlp_find_python_source.
        log_stream('yt-dlp: binário ' . $existing . ' quebrado/corrompido no teste --version');
    } elseif ($existing && isset($bad[$existing])) {
        // Não apaga nem ignora para sempre: apenas tenta as prioridades acima;
        // o wrapper/source nunca deve ser destruído pela lista negra.
        log_stream('yt-dlp: binário ' . $existing . ' na lista negra; tentando outras rotas');
    }

    // No caminho do player (GET do m3u8) NUNCA baixa yt-dlp da internet —
    // isso deixaria a resposta lentíssima. O conserto acontece em background.
    return null;
}

function ytdlp_download_binary(string $dir): ?array {
    if (strtolower(PHP_OS_FAMILY) === 'windows') {
        $urls = [
            'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe',
        ];
        $bin = $dir . '/yt-dlp.exe';
    } else {
        $urls = [
            'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux',
            'https://github.com/yt-dlp/yt-dlp/releases/download/2025.06.09/yt-dlp_linux', // bootloader mais antigo (p/ kernel/glibc velhos)
        ];
        $bin = $dir . '/yt-dlp-bin';
    }
    $cand = ['type' => 'bin', 'binary' => $bin];

    // Binário recente já no disco? testa e reusa se funcionar.
    if (is_file($bin) && time() - filemtime($bin) <= 3 * 86400) {
        $cand['ffmpeg'] = find_ffmpeg();
        if (is_executable($bin) && ytdlp_test_cmd($cand)) return $cand;
        @unlink($bin);
    }

    // Baixa e TESTA cada mirror dentro do loop — só aceita um que rode.
    // (Se o "latest" baixar mas não extrair no kernel antigo, o próximo
    // mirror com bootloader mais velho é tentado.)
    foreach ($urls as $url) {
        $data = ytdlp_download($url);
        if (!$data || strlen($data) <= 1000000) {
            log_stream('yt-dlp: download falhou (curto) de ' . $url);
            continue;
        }
        @file_put_contents($bin . '.tmp', $data);
        @rename($bin . '.tmp', $bin);
        @chmod($bin, 0755);
        $cand['ffmpeg'] = find_ffmpeg();
        if (ytdlp_test_cmd($cand)) {
            log_stream('yt-dlp: baixado e testado OK de ' . $url);
            return $cand;
        }
        log_stream('yt-dlp: binário de ' . $url . ' falhou no teste (--version)');
        @unlink($bin);
    }
    return null;
}

function resolve_via_ytdlp(string $videoId): ?string {
    $prep = ytdlp_prepare();
    if (!$prep) return null;

    $url = 'https://www.youtube.com/watch?v=' . $videoId;
    // Exclui formatos "storyboard" (ids tipo sb0/sb1 — grid de thumbnails que o
    // yt-dlp coloca no topo do "best" e quebra a reprodução). Prefere mp4 com
    // áudio, depois qualquer formato com áudio, depois qualquer um (sem sb).
    $formats = [
        'best[ext=mp4][protocol=https][vcodec!=none][acodec!=none][format_id!*=sb]/best[vcodec!=none][acodec!=none][format_id!*=sb]/b',
    ];
    foreach ($formats as $fmt) {
        $cmd = ytdlp_build_cmd($prep, array_merge(
            ['-f', $fmt, '--get-url', '--no-playlist', '--no-warnings', '--no-check-certificates'],
            yt_cookies_args(),
            [$url]
        ));
        exec($cmd, $output, $return_var);
        if ($return_var === 0) {
            foreach ($output as $line) {
                $line = trim($line);
                // A primeira linha de saída pode ser aviso de deprecação do
                // Python 3.7; a URL real vem depois. Exige URL de stream de
                // verdade (googlevideo.com), nunca storyboard/erro.
                if (strpos($line, 'http') === 0 && strpos($line, 'googlevideo.com') !== false) {
                    return $line;
                }
            }
        }
        $output = [];
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

// ------------------------------------------------------------------
// HLS AO VIVO (para apps que pedem .m3u8, ex.: IBO em modo HLS)
// Gera segmentos TS com ffmpeg em segundo plano (rolling) e entrega
// o manifest com URLs absolutas. Os segmentos são servidos como
// arquivos estáticos em /hls/<id>/ dentro do docroot.
// ------------------------------------------------------------------

function serve_live_hls(string $id): void {
    $ffmpeg = find_ffmpeg();
    if (!$ffmpeg) {
        http_response_code(503);
        echo 'Sem ffmpeg para gerar HLS.';
        return;
    }

    // 1) Já tem o vídeo baixado em cache? usa direto (rápido, sem depender do YouTube)
    $localFile = find_loop_cache_file($id);
    if ($localFile) {
        if (!ensure_hls_ffmpeg($ffmpeg, $id, $localFile)) {
            http_response_code(503);
            echo "Falha ao iniciar gerador HLS para {$id}.";
            return;
        }
        serve_hls_manifest($id);
        return;
    }

    // 2) Sem arquivo local: tenta detectar canal ao vivo de forma barata (3s).
    //    O download do loop usa o yt-dlp direto (por id), então a resolução aqui
    //    NÃO pode travar o pedido do player (IBO polia a cada ~6s).
    $source = resolve_stream_url($id, 3);
    if ($source && is_playlist_url($source)) {
        // YouTube ao vivo -> proxy do manifest real (não é baixável)
        proxy_hls_vlc($source, $id);
        return;
    }

    // 3) Vídeo arquivado: baixa com yt-dlp em background e responde rápido.
    //    O player (IBO) polia o manifest a cada poucos segundos e volta aqui.
    ensure_loop_download($id);
    http_response_code(503);
    header('Retry-After: 3');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Baixando o conteudo para gerar o canal. Tente novamente em alguns segundos.";
}

function serve_hls_manifest(string $id): void {
    $manifest = read_current_manifest($id);
    if ($manifest === null) {
        http_response_code(503);
        echo "Manifest HLS indisponível para {$id}.";
        return;
    }
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
    echo $manifest;
}

function ensure_hls_ffmpeg(string $ffmpegPath, string $id, string $source): bool {
    $dir = __DIR__ . '/hls/' . $id;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $pidFile      = $dir . '/ffmpeg.pid';
    $manifestFile = $dir . '/index.m3u8';
    $cmdFile      = $dir . '/cmd.txt';

    $segType = defined('HLS_SEGMENT_TYPE') && in_array(HLS_SEGMENT_TYPE, ['fmp4', 'mpegts'], true)
        ? HLS_SEGMENT_TYPE : 'mpegts';

    $host = $_SERVER['HTTP_HOST'] ?? '45.143.7.108:27021';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $host . '/hls/' . $id . '/';

    // Opções por tipo de segmento:
    // - mpegts: vídeo em copy + re-encode de áudio AAC (emenda de áudio limpa p/ ExoPlayer);
    //   compatível com qualquer ffmpeg >= 3.x e com todos os players.
    // - fmp4:   cópia total em CMAF .m4s (corte limpo nativo; exige ffmpeg >= 4.0 e player moderno)
    $codecOpts = ($segType === 'fmp4')
        ? ' -c copy'
        : ' -c:v copy -c:a aac -b:a 96k';
    $hlsOpts = ($segType === 'fmp4')
        ? ' -hls_segment_type fmp4 -hls_flags delete_segments+temp_file+independent_segments'
        : ' -hls_flags delete_segments';

    // Opções de entrada: arquivo local (loop já baixado) ou URL remota
    $srcIsUrl = preg_match('~^https?://~i', $source) === 1;
    $inputOpts = $srcIsUrl
        ? ' -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5'
          . ' -headers "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: https://www.youtube.com/\r\nOrigin: https://www.youtube.com"'
        : '';

    $cmd = escapeshellarg($ffmpegPath)
         . ' -y -hide_banner -loglevel error'
         . $inputOpts
         . ' -fflags +genpts'
         . ' -re -stream_loop -1 -i ' . escapeshellarg($source)
         . $codecOpts . ' -f hls -hls_time 4 -hls_list_size 6'
         . $hlsOpts
         . ' -hls_base_url ' . escapeshellarg($baseUrl)
         . ' ' . escapeshellarg($dir . '/index.m3u8')
         . ' > ' . escapeshellarg(CACHE_DIR . '/hls_' . $id . '.log') . ' 2>&1 & echo $!';

    // Já tem processo vivo, manifest recente e comando igual ao atual?
    $pid = is_file($pidFile) ? trim((string)@file_get_contents($pidFile)) : '';
    $cmdStored = is_file($cmdFile) ? trim((string)@file_get_contents($cmdFile)) : '';
    if ($pid !== '' && is_numeric($pid) && $cmdStored === $cmd) {
        $alive = false;
        if (@is_dir('/proc/' . (int)$pid)) {
            $alive = true;
        } else {
            $o = $rc = null;
            @exec('kill -0 ' . (int)$pid . ' 2>/dev/null', $o, $rc);
            $alive = ($rc === 0);
        }
        $fresh = is_file($manifestFile) && (time() - @filemtime($manifestFile)) < 30;
        if ($alive && $fresh) return true;
    }

    // Mata processo órfão/antigo (comando diferente) e limpa segmentos
    if ($pid !== '' && is_numeric($pid)) {
        @exec('kill -9 ' . (int)$pid . ' 2>/dev/null');
    }
    foreach (glob($dir . '/index*.ts') ?: [] as $f) @unlink($f);
    foreach (glob($dir . '/index*.m4s') ?: [] as $f) @unlink($f);
    @unlink($dir . '/init.mp4');
    @unlink($manifestFile);

    @exec($cmd, $out);
    $newPid = trim($out[0] ?? '');
    if ($newPid !== '') @file_put_contents($pidFile, $newPid);
    @file_put_contents($cmdFile, $cmd);
    log_stream("id={$id} HLS: iniciando gerador de segmentos ({$segType}, pid={$newPid})");

    // Espera o primeiro segmento aparecer (máx. ~15s), desistindo rápido se o
    // processo morreu ou o ffmpeg reportou erro no log (loglevel error).
    $logFile = CACHE_DIR . '/hls_' . $id . '.log';
    for ($i = 0; $i < 30; $i++) {
        if (is_file($manifestFile) && @filesize($manifestFile) > 0) {
            $content = (string)@file_get_contents($manifestFile);
            if (preg_match('/\.(ts|m4s)\b/i', $content)) return true;
        }
        if (is_file($logFile) && @filesize($logFile) > 0) {
            @unlink($pidFile);
            return false;
        }
        if ($newPid !== '' && is_numeric($newPid)) {
            $o = $rc = null;
            @exec('kill -0 ' . (int)$newPid . ' 2>/dev/null', $o, $rc);
            if ($rc !== 0) {
                @unlink($pidFile);
                return false;
            }
        }
        usleep(500000);
    }
    return false;
}

/**
 * Cache local do vídeo que será colocado em loop como canal.
 * Estratégia: baixa o vídeo uma vez com yt-dlp (cliente moderno, que o YouTube
 * não bloqueia com 403) e o ffmpeg só lê o arquivo local — sem depender do
 * YouTube a cada emenda de segmento ou a cada reinício do loop.
 */
function loop_cache_file(string $id): string {
    return CACHE_DIR . '/loop_' . preg_replace('~[^A-Za-z0-9_-]~', '', $id) . '.mp4';
}

function find_loop_cache_file(string $id): ?string {
    $f = loop_cache_file($id);
    if (is_file($f) && @filesize($f) > 1000000) return $f;
    return null;
}

function ensure_loop_download(string $id): bool {
    $id = preg_replace('~[^A-Za-z0-9_-]~', '', $id);
    $file = loop_cache_file($id);
    $pidFile = CACHE_DIR . '/loop_' . $id . '.pid';
    $failFile = CACHE_DIR . '/loop_' . $id . '.fail';

    // Arquivo fresco (baixado há < 6h) -> pronto
    if (is_file($file) && @filesize($file) > 1000000 && (time() - @filemtime($file)) < 6 * 3600) {
        return true;
    }

    // Falha recente? não martela o download (backoff de 2min)
    if (is_file($failFile) && (time() - @filemtime($failFile)) < 120) {
        return is_file($file);
    }

    // Já está baixando? (yt-dlp direto ou o script de background)
    $pid = is_file($pidFile) ? (int)trim((string)@file_get_contents($pidFile)) : 0;
    if ($pid > 0 && process_alive($pid)) {
        return is_file($file);
    }
    if ($pid > 0) {
        @unlink($pidFile);
        if (!is_file($file)) {
            @file_put_contents($failFile, time()); // evita re-trigger imediato
            // Morreu rápido demais? provável falha de extração do yt-dlp atual
            // (não é problema de rede). Põe o binário na lista negra para o
            // próximo ciclo baixar um yt-dlp novo do GitHub.
            $started = is_file(CACHE_DIR . '/loop_' . $id . '.start') ? (int)@file_get_contents(CACHE_DIR . '/loop_' . $id . '.start') : 0;
            $age = $started ? (time() - $started) : PHP_INT_MAX;
            if ($age < 90) {
                $pc = is_file(CACHE_DIR . '/ytdlp_prep.json') ? json_decode(@file_get_contents(CACHE_DIR . '/ytdlp_prep.json'), true) : null;
                $bin = $pc['prep']['binary'] ?? ($pc['prep']['zipapp'] ?? '');
                $type = $pc['prep']['type'] ?? '';
                // Nunca marca o wrapper bin/yt-dlp nem bin/yt-dlp-modern nem
                // fontes Python: são as únicas rotas que funcionam no VPS.
                // Só entra na lista negra um binário PyInstaller baixado
                // (yt-dlp-bin) que falhou — o próximo ciclo baixa outro do GitHub.
                if ($bin && is_file($bin) && $type !== 'py' && !in_array(basename($bin), ['yt-dlp', 'yt-dlp-modern'])) {
                    ytdlp_mark_bad($bin);
                }
            } else {
                @unlink(CACHE_DIR . '/ytdlp_prep.json');
            }
        }
    }

    // Tenta iniciar com o yt-dlp existente (caminho rápido; não baixa nada).
    $prep = ytdlp_prepare(false);
    if ($prep) {
        start_loop_download($id, $prep);
        log_stream("id={$id} LOOP: download em background iniciado");
        return is_file($file);
    }

    // Sem yt-dlp funcional: delega para o script de background, que baixa um
    // yt-dlp novo (GitHub) e depois o vídeo, com retries. A resposta do GET
    // continua rápida (o script roda em processo separado).
    start_bg_loop_download($id);
    return is_file($file);
}

function start_loop_download(string $id, array $prep): void {
    $file = loop_cache_file($id);
    $log = CACHE_DIR . '/loop_' . $id . '.log';
    @unlink(CACHE_DIR . '/loop_' . $id . '.fail');
    $args = [
        '--no-playlist', '-f', '18/bestvideo+bestaudio/b',
        '--no-mtime', '--newline', '--no-warnings', '--no-check-certificates',
        '-o', $file,
    ];
    $args = array_merge($args, yt_cookies_args(), ['https://www.youtube.com/watch?v=' . $id]);
    $cmd = ytdlp_build_cmd($prep, $args);
    @file_put_contents($log, date('c') . ' iniciando: ' . $cmd . "\n", FILE_APPEND);
    @file_put_contents(CACHE_DIR . '/loop_' . $id . '.start', (string)time());
    $pidFile = CACHE_DIR . '/loop_' . $id . '.pid';
    if (strtolower(PHP_OS_FAMILY) === 'windows') {
        @exec('cmd /c start /b "" ' . $cmd . ' >> "' . str_replace('"', '\\"', $log) . '" 2>&1');
        return;
    }
    @exec($cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!', $out);
    $np = trim($out[0] ?? '');
    if ($np !== '') @file_put_contents($pidFile, $np);
}

function start_bg_loop_download(string $id): void {
    $log = CACHE_DIR . '/loop_' . $id . '.log';
    $script = __DIR__ . '/bg_download.php';
    $php = find_php_cli();
    if (!$php || !is_file($script)) {
        @file_put_contents($log, date('c') . ' sem PHP CLI ou script de background disponível' . "\n", FILE_APPEND);
        return;
    }
    $cmd = $php . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($id);
    @file_put_contents($log, date('c') . ' iniciando background (php cli): ' . $cmd . "\n", FILE_APPEND);
    $pidFile = CACHE_DIR . '/loop_' . $id . '.pid';
    if (strtolower(PHP_OS_FAMILY) === 'windows') {
        @exec('cmd /c start /b "" ' . $cmd . ' >> "' . str_replace('"', '\\"', $log) . '" 2>&1');
        return;
    }
    @exec($cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!', $out);
    $np = trim($out[0] ?? '');
    if ($np !== '') @file_put_contents($pidFile, $np);
    @unlink(CACHE_DIR . '/loop_' . $id . '.fail');
}

function find_php_cli(): ?string {
    if (defined('PHP_BINARY') && PHP_BINARY && is_file(PHP_BINARY)) return PHP_BINARY;
    if (strtolower(PHP_OS_FAMILY) === 'windows') return 'php';
    $out = [];
    @exec('command -v php 2>/dev/null', $out);
    return trim($out[0] ?? '') ?: null;
}

function process_alive(int $pid): bool {
    if ($pid <= 0) return false;
    if (strtolower(PHP_OS_FAMILY) !== 'windows') {
        if (@is_dir('/proc/' . $pid)) return true;
        $o = $rc = null;
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $o, $rc);
        return $rc === 0;
    }
    $out = [];
    @exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL', $out);
    foreach ($out as $l) {
        if (preg_match('/\b' . $pid . '\b/', $l)) return true;
    }
    return false;
}

function read_current_manifest(string $id): ?string {
    $manifestFile = __DIR__ . '/hls/' . $id . '/index.m3u8';
    if (!is_file($manifestFile)) return null;
    $content = (string)@file_get_contents($manifestFile);
    if (trim($content) === '') return null;

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $base = '/hls/' . $id . '/';
    $lines = preg_split('/\r?\n/', $content);
    foreach ($lines as &$line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || preg_match('/^https?:\/\//i', $t)) continue;
        $line = ($host ? 'http://' . $host : '') . $base . $t;
    }
    return implode("\n", $lines);
}

?>