<?php
// install_ytdlp.php - Instala/verifica o yt-dlp via Python (fonte) e dispara o download do canal.
// Suba este arquivo na pasta do app (/home/container) e abra no navegador UMA vez.
// No final ele inicia o download do video em background. Depois pode apagar o arquivo.

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
$root = __DIR__;

function ilog(string $m): void {
    echo htmlspecialchars($m) . "<br>\n";
    flush();
}

$python = '';
foreach (['python3', 'python'] as $c) {
    $o = null;
    $rc = null;
    @exec($c . ' -c "import sys" 2>&1', $o, $rc);
    if ($rc === 0) { $python = $c; break; }
}
if ($python === '') { ilog('ERRO: sem python3 no PATH'); exit(1); }
ilog("python encontrado: $python");

// 1) fonte do yt-dlp (ytdlp_pkg/ ou cache/ytdlp_src/)
$srcRoot = $root . '/ytdlp_pkg';
@mkdir($srcRoot, 0775, true);
$srcDir = null;
foreach (array_merge(glob($srcRoot . '/*') ?: [], glob($root . '/cache/ytdlp_src/*') ?: []) as $d) {
    if (is_dir($d) && is_file($d . '/yt-dlp')) { $srcDir = $d; break; }
}
ilog($srcDir ? 'fonte existente: ' . basename($srcDir) : 'nenhuma fonte local, baixando...');

if (!$srcDir) {
    $versions = ['2023.11.16', '2023.12.30', '2024.03.10'];
    foreach ($versions as $v) {
        $url = "https://github.com/yt-dlp/yt-dlp/archive/refs/tags/{$v}.tar.gz";
        ilog("  baixando {$v}...");
        $ctx = stream_context_create([
            'http' => ['timeout' => 300, 'header' => "User-Agent: Mozilla/5.0\r\n", 'follow_location' => 1],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if (!$data || strlen($data) < 100000) { ilog('  download falhou'); continue; }
        $tgz = $srcRoot . "/yt-dlp-{$v}.tar.gz";
        file_put_contents($tgz, $data);
        $o = null;
        $rc = null;
        @exec('tar xzf ' . escapeshellarg($tgz) . ' -C ' . escapeshellarg($srcRoot) . ' 2>&1', $o, $rc);
        @unlink($tgz);
        if ($rc === 0 && is_file($srcRoot . "/yt-dlp-{$v}/yt-dlp")) {
            $srcDir = $srcRoot . "/yt-dlp-{$v}";
            ilog("  extraido: {$v}");
            break;
        }
        ilog('  extracao falhou');
    }
}
if (!$srcDir || !is_file($srcDir . '/yt-dlp')) { ilog('ERRO: nao foi possivel obter a fonte do yt-dlp'); exit(1); }
$wrapper = $srcDir . '/yt-dlp';

// 2) testa direto: python3 <wrapper> --version
$o = null;
$rc = null;
@exec(escapeshellarg($python) . ' ' . escapeshellarg($wrapper) . ' --version 2>&1', $o, $rc);
ilog('--version rc=' . $rc . ' -> ' . trim($o[0] ?? '(sem saida)'));

// 3) cria bin/yt-dlp (wrapper p/ o codigo antigo achar via find_existing_binary)
@mkdir($root . '/bin', 0775, true);
$bin = $root . '/bin/yt-dlp';
$content = "#!/bin/sh\nexec $python " . escapeshellarg($wrapper) . " \"\$@\"\n";
file_put_contents($bin, $content);
@chmod($bin, 0755);
ilog('wrapper criado: ' . $bin);
$o = null;
$rc = null;
@exec(escapeshellarg($bin) . ' --version 2>&1', $o, $rc);
ilog('bin/yt-dlp --version rc=' . $rc . ' -> ' . trim($o[0] ?? '(sem saida)'));

// 4) integracao com o app
if (is_file($root . '/functions.php')) {
    require $root . '/functions.php';
    if (function_exists('ytdlp_prepare')) {
        $prep = ytdlp_prepare(false);
        ilog($prep
            ? 'APP (ytdlp_prepare): type=' . ($prep['type'] ?? '?') . ' bin=' . ($prep['binary'] ?? $prep['zipapp'] ?? '?')
            : 'ATENCAO: ytdlp_prepare(false) retornou null');
    } else {
        ilog('aviso: functions.php nao tem ytdlp_prepare');
    }
}

// 5) resolve o video do canal (teste rapido, ~10s)
$id = 'kfy_SZ_wCe8';
if (function_exists('resolve_via_ytdlp')) {
    $u = resolve_via_ytdlp($id);
    ilog('resolve ' . $id . ': ' . ($u ? substr($u, 0, 100) . '...' : 'FALHOU'));
} else {
    ilog('aviso: sem resolve_via_ytdlp no functions.php');
}

// 6) dispara o download do video em background (limpa marcadores antigos)
if (function_exists('ensure_loop_download') && defined('CACHE_DIR')) {
    @unlink(CACHE_DIR . '/loop_' . $id . '.pid');
    @unlink(CACHE_DIR . '/loop_' . $id . '.fail');
    @unlink(CACHE_DIR . '/loop_' . $id . '.start');
    if (function_exists('loop_cache_file')) @unlink(loop_cache_file($id));
    ensure_loop_download($id);
    ilog("download iniciado. Acompanhe: cache/loop_{$id}.log e o tamanho de cache/loop_{$id}.mp4");
} else {
    ilog('aviso: sem ensure_loop_download no functions.php (nao deu para iniciar o download)');
}
ilog('PRONTO. Aguarde 2-10 min pelo download e depois abra o canal no IBO.');
