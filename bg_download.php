<?php
// bg_download.php - Self-heal + download do loop em background.
// Executado via PHP CLI (launchado por start_bg_loop_download no GET do m3u8).
// Faz: 1) garante um yt-dlp funcional (baixa do GitHub se necessário),
//      2) baixa o vídeo para cache/loop_<id>.mp4 com retries.
require __DIR__ . '/functions.php';

$id = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $id)) {
    echo "id invalido\n";
    exit(1);
}

$file = loop_cache_file($id);
$log = CACHE_DIR . '/loop_' . $id . '.log';
@file_put_contents(CACHE_DIR . '/loop_' . $id . '.pid', (string)getmypid());
@file_put_contents($log, date('c') . ' bg_download iniciado (pid ' . getmypid() . ")\n", FILE_APPEND);

$prep = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    if (find_loop_cache_file($id)) break;

    if (!$prep) {
        $prep = ytdlp_prepare(true);
    }
    if (!$prep) {
        @file_put_contents($log, date('c') . " tentativa {$attempt}: sem yt-dlp funcional\n", FILE_APPEND);
        sleep(10);
        continue;
    }

    start_loop_download($id, $prep);
    $deadline = time() + 480; // até 8 min por tentativa (vídeo de 124MB em link lento)
    while (time() < $deadline) {
        if (find_loop_cache_file($id)) break;
        $pid = (int)trim((string)@file_get_contents(CACHE_DIR . '/loop_' . $id . '.pid'));
        if ($pid <= 0 || !process_alive($pid)) break;
        usleep(4000000);
    }
    if (find_loop_cache_file($id)) break;

    @file_put_contents($log, date('c') . " tentativa {$attempt} nao completou\n", FILE_APPEND);
    @unlink(CACHE_DIR . '/loop_' . $id . '.pid');
    $prep = null; // força re-testar/re-baixar na próxima tentativa
    sleep(5);
}

if (find_loop_cache_file($id)) {
    @unlink(CACHE_DIR . '/loop_' . $id . '.fail');
    @file_put_contents($log, date('c') . " download OK\n", FILE_APPEND);
    exit(0);
}

@file_put_contents(CACHE_DIR . '/loop_' . $id . '.fail', (string)time());
@file_put_contents($log, date('c') . " download FALHOU\n", FILE_APPEND);
exit(1);
