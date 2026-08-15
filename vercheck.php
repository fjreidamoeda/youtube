<?php
// vercheck.php — compara a versão dos arquivos no servidor com a local.
// Cola o resultado aqui; se os md5 não baterem com os locais, o deploy ficou antigo.
header('Content-Type: text/plain; charset=utf-8');
$files = ['functions.php', 'stream.php', 'test_sel.php', 'test_chan_videos.php'];
foreach ($files as $f) {
    $p = __DIR__ . '/' . $f;
    echo str_pad($f, 22) . (is_file($p) ? md5_file($p) : '(ausente)') . "\n";
}
echo "serve_local_file: " . (function_exists('serve_local_file') ? 'OK' : 'NAO existe (functions.php antigo)') . "\n";
echo "ytdlp_modern_prep: " . (function_exists('ytdlp_modern_prep') ? 'OK' : 'NAO existe (functions.php antigo)') . "\n";
echo 'PHP: ' . PHP_VERSION . "\n";
echo 'mem_limit: ' . ini_get('memory_limit') . "\n";
echo 'max_execution_time: ' . ini_get('max_execution_time') . "\n";
echo 'output_buffering: ' . ini_get('output_buffering') . "\n";
