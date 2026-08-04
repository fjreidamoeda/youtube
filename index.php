<?php
require_once __DIR__ . '/functions.php';
ini_set('display_errors', 0);

$msg = '';

// Ações via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $link = trim($_POST['link'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $group = trim($_POST['group'] ?? 'YouTube');

        if (!$link) {
            $msg = 'Cole um link ou ID.';
        } else {
            $norm = normalize_channel_input($link);
            if (!$norm) {
                $msg = 'Não consegui reconhecer esse link/ID.';
            } else {
                if ($name === '') $name = $link;
                $channels = load_channels();

                // evita duplicados
                $exists = false;
                foreach ($channels as $ch) {
                    $v = ($norm['type'] === 'video_id') ? ($ch['video_id'] ?? '') : ($ch['channel_id'] ?? '');
                    if ($v === $norm['value']) { $exists = true; break; }
                }

                if ($exists) {
                    $msg = 'Esse canal/vídeo já está na lista.';
                } else {
                    if ($norm['type'] === 'video_id') {
                        $channels[] = [
                            'video_id' => $norm['value'],
                            'name' => $name,
                            'logo' => 'https://i.ytimg.com/vi/' . $norm['value'] . '/hqdefault.jpg',
                            'group' => $group,
                            'tvg_id' => 'yt_' . substr(md5($norm['value']), 0, 8),
                        ];
                    } else {
                        $channels[] = [
                            'channel_id' => $norm['value'],
                            'name' => $name,
                            'logo' => '',
                            'group' => $group,
                            'tvg_id' => 'yt_' . substr(md5($norm['value']), 0, 8),
                        ];
                    }
                    save_channels($channels);
                    $msg = 'Adicionado com sucesso!';
                }
            }
        }
    }

    if ($action === 'delete') {
        $idx = intval($_POST['index'] ?? -1);
        $channels = load_channels();
        if ($idx >= 0 && $idx < count($channels)) {
            array_splice($channels, $idx, 1);
            save_channels($channels);
            $msg = 'Removido.';
        }
    }

    if ($action === 'clear') {
        save_channels([]);
        $msg = 'Lista limpa.';
    }
}

$channels = load_channels();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$m3uUrl = $base . '/lista.php';
$m3uDownloadUrl = $base . '/lista.php?download=1';
$epgUrl = $base . '/epg.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YouTube IPTV - Gerenciador</title>
<style>
* { font-family: 'Segoe UI', Arial, sans-serif; box-sizing: border-box; }
body { background:#0d1117; color:#e6edf3; margin:0; padding:16px; }
.wrap { max-width:900px; margin:0 auto; }
h1 { font-size:22px; margin:0 0 4px; color:#f97316; }
.sub { color:#8b949e; font-size:13px; margin-bottom:20px; }
.card { background:#161b22; border:1px solid #30363d; border-radius:8px; padding:16px; margin-bottom:16px; }
.card h2 { font-size:14px; text-transform:uppercase; letter-spacing:1px; color:#f97316; margin:0 0 12px; }
label { display:block; font-size:12px; color:#8b949e; margin:10px 0 4px; }
input[type=text], select {
    width:100%; padding:9px 10px; background:#0d1117; color:#e6edf3;
    border:1px solid #30363d; border-radius:6px; font-size:14px;
}
input:focus, select:focus { outline:none; border-color:#f97316; }
.row { display:flex; gap:10px; flex-wrap:wrap; }
.row > div { flex:1; min-width:140px; }
.btn {
    display:inline-block; background:#f97316; color:#0d1117; font-weight:600;
    border:none; border-radius:6px; padding:10px 18px; font-size:14px; cursor:pointer; text-decoration:none;
}
.btn:hover { background:#fb923c; }
.btn.ghost { background:transparent; color:#f97316; border:1px solid #f97316; }
.btn.danger { background:#da3633; color:#fff; }
.btn.danger:hover { background:#f85149; }
.btn:disabled { opacity:.5; cursor:not-allowed; }
.urlbox {
    background:#0d1117; border:1px solid #30363d; border-radius:6px;
    padding:10px; font-size:12px; color:#58a6ff; word-break:break-all; margin:6px 0;
}
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; color:#8b949e; font-size:11px; text-transform:uppercase; padding:6px 8px; border-bottom:1px solid #30363d; }
td { padding:8px; border-bottom:1px solid #21262d; vertical-align:middle; }
img.thumb { width:64px; height:36px; object-fit:cover; border-radius:4px; background:#21262d; }
.tag { display:inline-block; font-size:10px; padding:2px 8px; border-radius:10px; text-transform:uppercase; }
.tag.vid { background:#1f6feb33; color:#58a6ff; }
.tag.ch { background:#3fb95033; color:#3fb950; }
a.test { color:#f97316; text-decoration:none; font-size:12px; }
a.test:hover { text-decoration:underline; }
.msg { background:#1f6feb22; color:#58a6ff; border:1px solid #1f6feb; border-radius:6px; padding:10px; margin-bottom:14px; font-size:13px; }
.empty { color:#8b949e; text-align:center; padding:24px; font-size:13px; }
footer { color:#484f58; font-size:11px; text-align:center; margin-top:24px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>YouTube IPTV</h1>
    <div class="sub">Gerenciador de canais para IPTV</div>

    <?php if ($msg): ?><div class="msg"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></div><?php endif; ?>

    <!-- Adicionar canal -->
    <div class="card">
        <h2>Adicionar canal / vídeo</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <label>Link ou ID (vídeo, @canal, ou URL do canal)</label>
            <input type="text" name="link" placeholder="https://www.youtube.com/watch?v=...  |  @nomedocanal  |  https://youtube.com/@canal">
            <div class="row">
                <div>
                    <label>Nome (opcional)</label>
                    <input type="text" name="name" placeholder="Ex: Lofi Girl">
                </div>
                <div>
                    <label>Categoria</label>
                    <select name="group">
                        <option>YouTube</option>
                        <option>Música</option>
                        <option>Documentários</option>
                        <option>Notícias</option>
                        <option>Relaxante</option>
                        <option>Jogos</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:14px;">
                <button class="btn" type="submit">Adicionar</button>
            </div>
        </form>
        <p style="font-size:11px; color:#8b949e; margin:12px 0 0;">
            💡 Você pode colar: um link de vídeo (vai virar um canal fixo), um @canal ou URL do canal
            (vai detectar a live ao vivo automaticamente).
        </p>
    </div>

    <!-- Lista -->
    <div class="card">
        <h2>Canais na lista (<?php echo count($channels); ?>)</h2>
        <?php if (empty($channels)): ?>
            <div class="empty">Nenhum canal ainda. Adicione acima.</div>
        <?php else: ?>
        <table>
            <tr><th></th><th>Nome</th><th>Tipo</th><th>Testar</th><th></th></tr>
            <?php foreach ($channels as $i => $c): $isVid = !empty($c['video_id']); ?>
            <tr>
                <td>
                    <?php if (!empty($c['logo'])): ?>
                        <img class="thumb" src="<?php echo htmlspecialchars($c['logo'], ENT_QUOTES); ?>" onerror="this.style.visibility='hidden'">
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?></strong><br>
                    <span style="color:#8b949e; font-size:11px;"><?php echo $isVid ? $c['video_id'] : $c['channel_id']; ?></span>
                </td>
                <td><span class="tag <?php echo $isVid ? 'vid' : 'ch'; ?>"><?php echo $isVid ? 'Vídeo' : 'Canal'; ?></span></td>
                <td>
                    <a class="test" target="_blank"
                       href="<?php echo $isVid ? $base.'/stream.php?id='.rawurlencode($c['video_id']) : $base.'/stream_auto.php?channel='.rawurlencode($c['channel_id']); ?>">
                       ▶ testar
                    </a>
                </td>
                <td>
                    <form method="post" onsubmit="return confirm('Remover este canal?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="index" value="<?php echo $i; ?>">
                        <button class="btn danger" type="submit" style="padding:5px 10px; font-size:12px;">Remover</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <!-- Exportar -->
    <div class="card">
        <h2>Exportar playlist</h2>
        <p style="font-size:13px; color:#8b949e;">A URL abaixo é a sua playlist M3U8. Use no VLC, TiviMate, IPTV Smarters ou importe no StreamFlow:</p>
        <div class="urlbox" onclick="this.select()"><?php echo htmlspecialchars($m3uUrl, ENT_QUOTES); ?></div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
            <a class="btn" href="<?php echo htmlspecialchars($m3uDownloadUrl, ENT_QUOTES); ?>">Baixar .m3u8</a>
            <a class="btn ghost" href="<?php echo htmlspecialchars($m3uUrl, ENT_QUOTES); ?>" target="_blank">Abrir lista no player</a>
            <a class="btn ghost" href="<?php echo htmlspecialchars($epgUrl, ENT_QUOTES); ?>" target="_blank">Ver EPG</a>
            <form method="post" onsubmit="return confirm('Apagar TODOS os canais?')" style="margin:0;">
                <input type="hidden" name="action" value="clear">
                <button class="btn danger" type="submit">Limpar lista</button>
            </form>
        </div>
    </div>

    <footer>YouTube IPTV — os streams são resolvidos via Invidious/YouTube no momento do acesso.</footer>
</div>
</body>
</html>
