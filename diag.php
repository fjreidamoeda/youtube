<?php
// diag.php - Diagnóstico rápido do HLS. Abra no navegador:
//   http://SEU_IP:PORTA/diag.php?id=VIDEO_ID
//   http://SEU_IP:PORTA/diag.php?id=VIDEO_ID&start=1   (testa gerar segmentos)
// Cole o resultado aqui na conversa.
require_once __DIR__ . '/functions.php';
header('Content-Type: text/plain; charset=utf-8');

$id = isset($_GET['id']) ? preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id']) : '';
$start = isset($_GET['start']) && $_GET['start'] === '1';

echo "=== SERVIDOR ===\n";
echo "software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'n/a') . "\n";
echo "php: " . PHP_VERSION . "\n";
echo "pasta app: " . __DIR__ . "\n";

echo "\n=== FFMPEG ===\n";
$ff = find_ffmpeg();
if (!$ff) {
    echo "NAO ENCONTRADO (procurou bin/ e PATH)\n";
} else {
    echo "path: {$ff}\n";
    @exec(escapeshellarg($ff) . ' -version 2>&1', $v);
    echo implode("\n", array_slice($v, 0, 2)) . "\n";
}

if ($id === '') {
    echo "\nUse ?id=VIDEO_ID para diagnosticar um canal.\n";
    exit;
}

echo "\n=== FONTE (cache) ===\n";
$cacheFile = CACHE_DIR . "/yt_video_{$id}.json";
if (is_file($cacheFile)) {
    $c = json_decode(@file_get_contents($cacheFile), true);
    echo "idade do cache: " . (time() - ($c['time'] ?? 0)) . "s (max 14400s)\n";
    $u = $c['url'] ?? '';
    echo "url (inicio): " . substr($u, 0, 140) . "\n";
    if (preg_match('~[?&]expire=(\d+)~', $u, $m)) echo "expira em: " . ((int)$m[1] - time()) . "s\n";
} else {
    echo "sem cache\n";
}

echo "\n=== RESOLVE AGORA ===\n";
$t0 = microtime(true);
$u = resolve_stream_url($id, 25);
echo "tempo: " . round(microtime(true) - $t0, 1) . "s\n";
echo ($u ? 'OK (inicio): ' . substr($u, 0, 140) . "\n" : "FALHOU\n");

echo "\n=== TESTE YT-DLP / GITHUB ===\n";
$py = ytdlp_python();
$v = $o2 = $rc2 = null;
if ($py) { @exec($py . ' --version 2>&1', $o2, $rc2); $v = trim($o2[0] ?? ''); }
echo "python: " . ($py ?: 'NAO ENCONTRADO') . " (" . ($v ?: 'sem versao') . ")\n";
$t0 = microtime(true);
$data = ytdlp_download('https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux');
echo "download github: " . round(microtime(true) - $t0, 1) . "s, " . ($data ? strlen($data) . ' bytes' : 'FALHOU') . "\n";
if ($data && strlen($data) > 1000000) {
    $tmp = CACHE_DIR . '/test_ytdlp';
    @file_put_contents($tmp, $data);
    @chmod($tmp, 0755);
    $o2 = $rc2 = null;
    @exec(escapeshellarg($tmp) . ' --version 2>&1', $o2, $rc2);
    echo "teste --version: rc=" . $rc2 . " -> " . (trim($o2[0] ?? '') ?: 'sem saida') . "\n";
    if ($rc2 !== 0) echo "detalhe: " . implode(' ', array_slice($o2, 0, 2)) . "\n";
    @unlink($tmp);
}
echo "yt-dlp bin local: " . (is_file(__DIR__ . '/bin/yt-dlp-bin') ? 'existe' : 'nao existe') . "\n";

echo "\n=== LOOP (arquivo local em cache) ===\n";
$loopFile = loop_cache_file($id);
if (is_file($loopFile)) {
    echo "arquivo: {$loopFile}\n";
    echo "tamanho: " . round(@filesize($loopFile) / 1048576, 1) . " MB\n";
    echo "idade: " . (time() - @filemtime($loopFile)) . "s\n";
} else {
    echo "sem arquivo ainda (baixando?)\n";
}
$dlPidFile = CACHE_DIR . '/loop_' . $id . '.pid';
if (is_file($dlPidFile)) {
    $dp = trim((string)@file_get_contents($dlPidFile));
    $o = $rc = null;
    @exec('kill -0 ' . (int)$dp . ' 2>/dev/null', $o, $rc);
    echo "download pid: {$dp} vivo=" . (($rc === 0 || @is_dir('/proc/' . (int)$dp)) ? 'SIM' : 'NAO') . "\n";
}
$dlLogFile = CACHE_DIR . '/loop_' . $id . '.log';
if (is_file($dlLogFile)) {
    echo "--- download log (ultimas 8) ---\n";
    echo implode('', array_slice(file($dlLogFile), -8));
}
$dlFailFile = CACHE_DIR . '/loop_' . $id . '.fail';
if (is_file($dlFailFile)) {
    $fe = (int)@file_get_contents($dlFailFile);
    echo "FALHA marcada ha " . (time() - $fe) . "s (backoff 120s)\n";
} else {
    echo "sem marcador de falha\n";
}
$prepCache = CACHE_DIR . '/ytdlp_prep.json';
if (is_file($prepCache)) {
    $pc = json_decode(@file_get_contents($prepCache), true);
    echo "yt-dlp prep cache: " . (time() - ($pc['time'] ?? 0)) . "s\n";
    $bin = $pc['prep']['binary'] ?? ($pc['prep']['zipapp'] ?? '');
    if ($bin) echo "yt-dlp em uso: {$bin}\n";
}
$bad = ytdlp_bad_list();
if ($bad) {
    echo "--- lista negra yt-dlp ---\n";
    foreach ($bad as $b => $t) echo "  {$b} (ha " . (time() - $t) . "s)\n";
} else {
    echo "lista negra yt-dlp: vazia\n";
}
echo "php cli: " . (find_php_cli() ?: 'NAO ENCONTRADO') . "\n";
echo "bg_download.php: " . (is_file(__DIR__ . '/bg_download.php') ? 'OK' : 'FALTANDO') . "\n";

echo "\n=== TESTE DE GERACAO HLS" . ($start ? ' (start=1)' : '') . " ===\n";
$dir = __DIR__ . '/hls/' . $id;
if (is_dir($dir)) {
    $pidFile = $dir . '/ffmpeg.pid';
    $pid = is_file($pidFile) ? trim((string)@file_get_contents($pidFile)) : '';
    echo "pid atual: " . ($pid ?: 'nenhum') . "\n";
    if ($pid !== '') {
        $o = $rc = null;
        @exec('kill -0 ' . (int)$pid . ' 2>/dev/null', $o, $rc);
        $alive = ($rc === 0) || @is_dir('/proc/' . (int)$pid);
        echo "processo vivo: " . ($alive ? 'SIM' : 'NAO') . "\n";
    }
} else {
    echo "dir hls/<id> ainda nao existe\n";
}

if ($start && $ff) {
    echo "iniciando download do loop (aguarde ~25s)...\n";
    @ob_flush();
    flush();
    ensure_loop_download($id);
    $lf = null;
    for ($i = 0; $i < 40; $i++) {
        $lf = find_loop_cache_file($id);
        if ($lf) break;
        usleep(500000);
    }
    if ($lf) {
        echo "arquivo disponível. iniciando ffmpeg...\n";
        @ob_flush();
        flush();
        $ok = ensure_hls_ffmpeg($ff, $id, $lf);
        echo "resultado ensure: " . ($ok ? 'OK' : 'FALHOU') . "\n";
        sleep(2);
    } else {
        echo "download ainda nao terminou (veja o log do download acima e rode de novo)\n";
    }
}

echo "\n=== HLS DIR ===\n";
if (is_dir($dir)) {
    $files = array_map('basename', glob($dir . '/*') ?: []);
    echo "arquivos: " . ($files ? implode(', ', $files) : '(vazio)') . "\n";
    $mf = $dir . '/index.m3u8';
    if (is_file($mf)) {
        echo "manifest idade: " . (time() - @filemtime($mf)) . "s\n---\n" . @file_get_contents($mf) . "\n---\n";
    }
} else {
    echo "ausente\n";
}

echo "\n=== LOG HLS (cache/hls_{$id}.log) ===\n";
$log = CACHE_DIR . '/hls_' . $id . '.log';
if (is_file($log)) {
    $content = (string)@file_get_contents($log);
    echo $content === '' ? "(vazio - sem erros)\n" : $content . "\n";
} else {
    echo "sem log\n";
}

echo "\n=== REQLOG (ultimas 15 linhas) ===\n";
$rl = CACHE_DIR . '/reqlog.txt';
if (is_file($rl)) {
    $lines = file($rl);
    echo implode('', array_slice($lines, -15));
}

echo "\n=== AUTO-TRIGGER DOWNLOAD ===\n";
// Sempre que o diag é aberto, dispara o download/conserto do loop em background
// (não precisa mais de start=1). Se o yt-dlp estiver quebrado, o bg_download.php
// baixa um novo do GitHub e depois o vídeo.
ensure_loop_download($id);
echo "ensure_loop_download chamado — veja o log do download acima.\n";

echo "\nFIM\n";
