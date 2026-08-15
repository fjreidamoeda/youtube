<?php
// bg_watch_downloads.php — daemon de download contínuo.
// Roda em loop: a cada ~5min processa os canais marcados com download=1,
// baixando/atualizando os vídeos recentes (e pegando novos uploads).
// Uma única instância (pid em cache/watch.pid). Iniciado pelo painel
// (index.php) quando o usuário liga o "Download contínuo".
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('somente CLI');
}
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$pidFile = CACHE_DIR . '/watch.pid';
$pid = is_file($pidFile) ? (int)trim((string)@file_get_contents($pidFile)) : 0;
if ($pid > 0 && process_alive($pid) && $pid !== getmypid()) {
    exit("ja rodando (pid {$pid})\n");
}
@file_put_contents($pidFile, (string)getmypid());
@file_put_contents(CACHE_DIR . '/watch.log', date('c') . " daemon iniciado (pid " . getmypid() . ")\n", FILE_APPEND);

$lastTrigger = 0;
while (true) {
    // Limita a uma passada a cada ~4 min (o ensure_loop_download já reusa
    // downloads em andamento e respeita backoff de falha).
    if (time() - $lastTrigger >= 240) {
        $r = watch_downloads_once(3);
        @file_put_contents(CACHE_DIR . '/watch.log', date('c') . " passada: canais={$r['canais']} videos={$r['videos']} ativos={$r['ativos']}\n", FILE_APPEND);
        $lastTrigger = time();
    }
    sleep(30);
    // Se não há mais nenhum canal marcado, para de rodar (evita processo órfão).
    try {
        $st = auth_db()->query('SELECT COUNT(*) FROM channels WHERE download=1 AND channel_id<>""');
        if ((int)$st->fetchColumn() === 0) {
            @unlink($pidFile);
            @file_put_contents(CACHE_DIR . '/watch.log', date('c') . " sem canais marcados, daemon encerrado\n", FILE_APPEND);
            exit(0);
        }
    } catch (Throwable $e) {
        sleep(60);
    }
}
