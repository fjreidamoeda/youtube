<?php
// install_py312.php — instala Python 3.12 + yt-dlp MODERNO no VPS.
// Resolve o problema do THROTTLED/nsig: o yt-dlp 2023.11.16 não decifra o
// nsig atual do YouTube (vídeos novos caem em "Requested format is not
// available"). O yt-dlp atual roda em Python 3.9+; aqui instalamos um Python
// 3.12 portátil (python-build-standalone, não precisa de apt) e o yt-dlp
// mais recente via pip.
// Abra: http://45.143.7.108:27021/install_py312.php
set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);
echo '<pre>';
function ilog(string $m): void { echo $m . "\n"; @flush(); }

$base = __DIR__ . '/cache/pybuild';
@mkdir($base, 0775, true);
$py = $base . '/python/bin/python3.12';

// 1) Python 3.12 standalone (build glibc 2.17 -> roda no Debian 10)
if (!is_file($py)) {
    $url = 'https://github.com/astral-sh/python-build-standalone/releases/download/20240726/cpython-3.12.4%2B20240726-x86_64-unknown-linux-gnu-install_only.tar.gz';
    ilog("Baixando Python 3.12 (~63MB)...\n$url");
    $fh = fopen($base . '/py.tar.gz', 'wb');
    if (!$fh) { ilog('FALHA: não consegui abrir arquivo de destino.'); echo '</pre>'; exit; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    $sz = @filesize($base . '/py.tar.gz');
    ilog('download: ' . ($ok ? 'OK' : 'FALHOU') . " ($sz bytes)" . ($err ? " curl: $err" : ''));
    if (!$ok || !$sz || $sz < 10000000) {
        @unlink($base . '/py.tar.gz');
        ilog('FALHA no download do Python.');
        echo '</pre>';
        exit;
    }
    ilog('Extraindo para ' . $base . ' ...');
    $o = null; $rc = null;
    @exec('tar xzf ' . escapeshellarg($base . '/py.tar.gz') . ' -C ' . escapeshellarg($base) . ' 2>&1', $o, $rc);
    @unlink($base . '/py.tar.gz');
    if ($rc !== 0 || !is_file($py)) {
        ilog('FALHA na extração: ' . implode(' | ', array_slice($o, 0, 5)));
        echo '</pre>';
        exit;
    }
} else {
    ilog("Python 3.12 já presente: $py");
}

// 2) Testa o Python
$o = null; $rc = null;
@exec(escapeshellarg($py) . ' --version 2>&1', $o, $rc);
ilog('Python: rc=' . $rc . ' ' . trim($o[0] ?? '') . ' (' . $py . ')');

// 3) Instala o yt-dlp atual via pip (vem do PyPI, pacote completo)
ilog('Instalando yt-dlp atual via pip (pode levar 1-2 min)...');
$o = null; $rc = null;
@exec(escapeshellarg($py) . ' -m pip install --quiet --upgrade yt-dlp 2>&1', $o, $rc);
ilog('pip install yt-dlp: rc=' . $rc . ' ' . implode(' | ', array_slice($o, 0, 6)));
$o = null; $rc = null;
@exec(escapeshellarg($py) . ' -m yt_dlp --version 2>&1', $o, $rc);
$ver = trim($o[0] ?? '');
ilog('yt-dlp moderno: rc=' . $rc . ' versao=' . ($ver ?: '(falhou)'));

// 4) Wrapper bin/yt-dlp-modern
$wr = __DIR__ . '/bin/yt-dlp-modern';
@mkdir(__DIR__ . '/bin', 0775, true);
@file_put_contents($wr, "#!/bin/sh\nexec " . escapeshellarg($py) . " -m yt_dlp \"\$@\"\n");
@chmod($wr, 0775);
ilog('Wrapper: ' . $wr . ' (executavel: ' . (is_executable($wr) ? 'sim' : 'NAO') . ')');

// 5) Testa resolver um vídeo que falhava (limpa o cache de prep p/ usar o moderno)
if (function_exists('ytdlp_prepare')) {
    @unlink(CACHE_DIR . '/ytdlp_prep.json');
    @unlink(CACHE_DIR . '/ytdlp_bad.txt');
    foreach (glob(CACHE_DIR . '/yt_video_*_fail.json') ?: [] as $f) @unlink($f);
    ilog('');
    ilog('Testando nN4ShA8T8e4 (que falhava) com o yt-dlp moderno...');
    $prep = ytdlp_prepare();
    ilog('prep: ' . json_encode($prep));
    if ($prep) {
        $u = resolve_via_ytdlp('nN4ShA8T8e4');
        ilog($u ? 'RESOLVE: ' . substr($u, 0, 80) . '...' : 'RESOLVE: (FALHOU)');
    }
}
ilog("\nPronto.");
echo '</pre>';
