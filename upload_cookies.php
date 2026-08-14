<?php
// upload_cookies.php — Envia o cookies.txt do seu navegador (logado no
// YouTube) para destravar vídeos bloqueados para IP de datacenter.
//
// Como obter o cookies.txt:
//   1) Instale a extensão "Get cookies.txt LOCALLY" no Chrome/Firefox.
//   2) Abra youtube.com, clique na extensão e exporte.
//   3) Copie o CONTEÚDO inteiro do arquivo e cole no campo abaixo.
//
// Abra: http://45.143.7.108:27021/upload_cookies.php
require_once __DIR__ . '/functions.php';

$cookiesFile = CACHE_DIR . '/cookies.txt';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'clear') {
        @unlink($cookiesFile);
        $msg = 'cookies.txt removido.';
    } else {
        $body = (string)($_POST['cookies'] ?? '');
        // Validação básica do formato Netscape
        $hasHttp = preg_match('~(^|\n)#[^\n]*http|\.youtube\.com\t|\.google\.com\t~', $body);
        if (trim($body) === '') {
            $err = 'O campo está vazio.';
        } elseif (!$hasHttp) {
            $err = 'Não parece um cookies.txt no formato Netscape. Exporte com a extensão "Get cookies.txt LOCALLY".';
        } else {
            @mkdir(CACHE_DIR, 0775, true);
            if (@file_put_contents($cookiesFile, $body) === false) {
                $err = 'Não consegui escrever cache/cookies.txt (permissão?).';
            } else {
                $msg = 'cookies.txt salvo (' . number_format(@filesize($cookiesFile)) . ' bytes).';
            }
        }
    }
}

$exists = is_file($cookiesFile);
$preview = $exists ? implode("\n", array_slice(@file($cookiesFile) ?: [], 0, 8)) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cookies do YouTube</title>
<style>
    * { font-family: 'Segoe UI', Arial, sans-serif; box-sizing: border-box; }
    body { background: #f8fafc; color: #0f172a; padding: 24px; margin: 0; }
    .card { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    h1 { font-size: 20px; margin: 0 0 6px 0; }
    p.sub { color: #64748b; font-size: 14px; margin: 0 0 18px 0; }
    textarea { width: 100%; height: 220px; font-family: monospace; font-size: 12px; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
    button { background: #2563eb; color: #fff; border: 0; padding: 10px 18px; border-radius: 8px; font-size: 15px; cursor: pointer; margin-top: 10px; }
    button.gray { background: #64748b; margin-left: 8px; }
    .msg { margin-top: 12px; padding: 10px 14px; border-radius: 8px; font-size: 14px; }
    .ok { background: #dcfce7; color: #166534; }
    .err { background: #fee2e2; color: #991b1b; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 5px; font-size: 12px; }
</style>
</head>
<body>
<div class="card">
    <h1>Cookies do YouTube (yt-dlp)</h1>
    <p class="sub">Para destravar vídeos que o YouTube bloqueia para IP de datacenter (gravações de live do Discovery, geo/idade etc.).</p>

    <?php if ($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <?php if ($exists): ?>
        <p>Status: <b style="color:#166534">cookies.txt presente</b> — <code><?= htmlspecialchars(CACHE_DIR . '/cookies.txt') ?></code> (<?= number_format(@filesize($cookiesFile)) ?> bytes).</p>
        <p style="font-size:13px;color:#64748b">Prévia das primeiras linhas:</p>
        <pre style="background:#f8fafc;padding:10px;border-radius:8px;font-size:11px;overflow:auto"><?= htmlspecialchars($preview) ?: '(vazio)' ?></pre>
    <?php else: ?>
        <p>Status: <b style="color:#b45309">sem cookies.txt</b> — vídeos bloqueados continuarão falhando.</p>
    <?php endif; ?>

    <form method="post">
        <textarea name="cookies" placeholder="Cole aqui o CONTEÚDO do cookies.txt exportado (formato Netscape)..."></textarea>
        <button type="submit">Salvar cookies.txt</button>
    </form>

    <form method="post" style="display:inline" onsubmit="return confirm('Remover o cookies.txt?')">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="gray">Remover cookies.txt</button>
    </form>

    <p style="font-size:12px;color:#94a3b8;margin-top:18px">Depois de salvar, abra <code>test_chan_videos.php?c=@SEUCANAL</code> ou o <code>diag_resolve2.php</code> para conferir se destravou.</p>
</div>
</body>
</html>
