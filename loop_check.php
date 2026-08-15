<?php
// loop_check.php — estado do loop local (arquivo baixado) de um vídeo.
// Uso: loop_check.php?id=ID
require_once __DIR__ . '/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$id = preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id'] ?? '');
if (!$id) { echo "Faltou ?id=ID\n"; exit; }

$file = loop_cache_file($id);
echo "id: {$id}\n";
if (is_file($file)) {
    echo "arquivo: {$file}\n";
    echo "tamanho: " . @filesize($file) . " bytes\n";
    echo "modificado: " . date('Y-m-d H:i:s', @filemtime($file)) . "\n";
} else {
    echo "arquivo: (ainda nao baixado)\n";
}
$pidFile = CACHE_DIR . '/loop_' . $id . '.pid';
$pid = is_file($pidFile) ? trim((string)@file_get_contents($pidFile)) : '';
echo "pid: " . ($pid ?: '(nenhum)');
if ($pid !== '' && is_numeric($pid)) {
    echo '  vivo=' . (process_alive((int)$pid) ? 'sim' : 'nao');
}
echo "\n";
echo "falha recente: " . (is_file(CACHE_DIR . '/loop_' . $id . '.fail') ? 'sim' : 'nao') . "\n";
$log = CACHE_DIR . '/loop_' . $id . '.log';
if (is_file($log)) {
    $lines = @file($log);
    echo "\n--- ultimas linhas do log ---\n";
    echo implode('', array_slice($lines, -8));
}
