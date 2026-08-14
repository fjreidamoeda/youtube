<?php
// index.php - Dashboard do usuario logado: gerencia SOMENTE os canais dele.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
ini_set('display_errors', 0);
auth_init();
require_login();
$user = current_user();
$uid = (int)$user['id'];

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $link = trim($_POST['link'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $qty  = max(1, min(500, (int)($_POST['qty'] ?? 50)));

        if (!$link) {
            $msg = 'Cole um link ou ID.';
        } else {
            $norm = normalize_channel_input($link);
            if (!$norm) {
                $msg = 'Não consegui reconhecer esse link/ID.';
            } else {
                if ($name === '') $name = $link;
                $channels = channels_for_user($uid);

                $exists = false;
                foreach ($channels as $ch) {
                    $v = ($norm['type'] === 'video_id') ? ($ch['video_id'] ?? '') : ($ch['channel_id'] ?? '');
                    if ($v === $norm['value']) { $exists = true; break; }
                }

                if ($exists) {
                    $msg = 'Esse canal/vídeo já está na sua lista.';
                } else {
                    if ($norm['type'] === 'video_id') {
                        channel_add($uid, [
                            'video_id' => $norm['value'],
                            'name' => $name,
                            'logo' => 'https://i.ytimg.com/vi/' . $norm['value'] . '/hqdefault.jpg',
                            'tvg_id' => 'yt_' . substr(md5($norm['value']), 0, 8),
                            'max_videos' => $qty,
                        ]);
                    } else {
                        channel_add($uid, [
                            'channel_id' => $norm['value'],
                            'name' => $name,
                            'logo' => '',
                            'tvg_id' => 'yt_' . substr(md5($norm['value']), 0, 8),
                            'max_videos' => $qty,
                        ]);
                    }
                    $msg = 'Adicionado com sucesso!';
                }
            }
        }
    }

    if ($action === 'delete') {
        channel_delete_by_id((int)($_POST['id'] ?? 0), $uid);
        $msg = 'Removido com sucesso.';
    }

    if ($action === 'update_qty') {
        channel_set_quantity((int)($_POST['id'] ?? 0), $uid, (int)($_POST['qty'] ?? 50));
        $msg = 'Quantidade atualizada.';
    }

    if ($action === 'clear') {
        channel_clear($uid);
        $msg = 'Sua lista foi completamente limpa.';
    }
}

$channels = channels_for_user($uid);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$m3uIptv = m3u_link($uid, false) ?? '';
$m3uVlc  = m3u_link($uid, true) ?? '';

// Verificação de modo de exibição de Grade (View Grid)
$viewChannelId = $_GET['view'] ?? '';
$pageToken = $_GET['pt'] ?? '';
$gridData = [];
if ($viewChannelId) {
    $gridData = get_channel_videos_paginated($viewChannelId, YT_API_KEY, 15, $pageToken);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Painel IPTV YouTube</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --bg: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border: #e2e8f0;
        --primary: #3b82f6;
        --primary-hover: #2563eb;
        --danger: #ef4444;
        --danger-hover: #dc2626;
        --radius: 12px;
    }
    * { font-family: 'Inter', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
    body { background-color: var(--bg); color: var(--text-main); padding: 20px; line-height: 1.5; }
    .container { max-width: 1000px; margin: 0 auto; }
    
    header { margin-bottom: 30px; text-align: center; }
    header h1 { font-size: 28px; font-weight: 700; color: var(--text-main); margin-bottom: 8px; }
    header p { color: var(--text-muted); font-size: 15px; }

    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px; }
    .topbar .user { font-size: 14px; color: var(--text-muted); }
    .topbar .user strong { color: var(--text-main); }

    .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
    .card h2 { font-size: 18px; font-weight: 600; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
    
    .msg { background: #dbeafe; color: #1e40af; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
    
    label { display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; margin-top: 12px; }
    input[type=text] { width: 100%; padding: 12px; background: #fff; border: 1px solid var(--border); border-radius: 8px; font-size: 15px; transition: border 0.2s; }
    input[type=text]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    
    .btn { display: inline-flex; align-items: center; justify-content: center; background: var(--primary); color: #fff; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.2s; }
    .btn:hover { background: var(--primary-hover); }
    .btn-danger { background: var(--danger); }
    .btn-danger:hover { background: var(--danger-hover); }
    .btn-outline { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
    .btn-outline:hover { background: #eff6ff; }
    .btn-small { padding: 6px 12px; font-size: 12px; }
    .btn-gray { background: #64748b; }
    .btn-gray:hover { background: #475569; }

    .flex-row { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
    .flex-row > div { flex: 1; min-width: 200px; }
    
    .table-responsive { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border); color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
    td { padding: 16px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    
    .tag { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .tag-channel { background: #dcfce7; color: #166534; }
    .tag-video { background: #f3e8ff; color: #6b21a8; }
    
    .url-box { background: var(--bg); border: 1px dashed var(--border); padding: 16px; border-radius: 8px; font-family: monospace; font-size: 14px; color: var(--primary); word-break: break-all; cursor: pointer; }
    
    /* Grid de Vídeos */
    .video-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-top: 20px; }
    .video-item { background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; transition: transform 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .video-item:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .video-thumb { width: 100%; aspect-ratio: 16/9; background: #e2e8f0; object-fit: cover; }
    .video-info { padding: 12px; }
    .video-title { font-size: 13px; font-weight: 600; color: var(--text-main); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 8px; }
    .pagination { display: flex; justify-content: space-between; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border); }
</style>
</head>
<body>
<div class="container">
    <header>
        <h1>Painel IPTV YouTube</h1>
        <p>Gerencie seus canais e playlists — o conteudo e exclusivo do seu login</p>
    </header>

    <div class="topbar">
        <div class="user">Conectado como <strong><?php echo htmlspecialchars($user['username']); ?></strong></div>
        <div style="display:flex; gap:8px;">
            <?php if ($user['role'] === 'admin'): ?>
                <a href="admin.php" class="btn btn-gray btn-small">Painel Admin</a>
            <?php endif; ?>
            <a href="login.php?logout=1" class="btn btn-danger btn-small">Sair</a>
        </div>
    </div>

    <?php if ($msg): ?><div class="msg"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <?php if ($viewChannelId && !empty($gridData)): ?>
    <!-- Visualização de Grade do Canal -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">
            <h2 style="border: none; margin: 0; padding: 0;">Grade de Vídeos do Canal</h2>
            <a href="index.php" class="btn btn-outline btn-small">Voltar para a Lista</a>
        </div>
        <div class="video-grid">
            <?php foreach ($gridData['items'] as $item): ?>
            <?php 
                $vId = $item['snippet']['resourceId']['videoId'] ?? '';
                $thumb = $item['snippet']['thumbnails']['medium']['url'] ?? '';
                $title = $item['snippet']['title'] ?? '';
            ?>
            <div class="video-item">
                <a href="stream.php?id=<?php echo urlencode($vId); ?>" target="_blank">
                    <img src="<?php echo htmlspecialchars($thumb); ?>" class="video-thumb" alt="Thumbnail">
                </a>
                <div class="video-info">
                    <div class="video-title" title="<?php echo htmlspecialchars($title); ?>"><?php echo htmlspecialchars($title); ?></div>
                    <a href="stream.php?id=<?php echo urlencode($vId); ?>" target="_blank" class="btn btn-outline btn-small" style="width: 100%;">Testar Stream</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="pagination">
            <?php if (!empty($gridData['prevPageToken'])): ?>
                <a href="?view=<?php echo urlencode($viewChannelId); ?>&pt=<?php echo urlencode($gridData['prevPageToken']); ?>" class="btn">Página Anterior</a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            
            <?php if (!empty($gridData['nextPageToken'])): ?>
                <a href="?view=<?php echo urlencode($viewChannelId); ?>&pt=<?php echo urlencode($gridData['nextPageToken']); ?>" class="btn">Próxima Página</a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <div class="card">
        <h2>Adicionar Nova Fonte</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
            <input type="hidden" name="action" value="add">
            <div class="flex-row">
                <div style="flex: 2;">
                    <label>Link ou ID (Ex: @LofiGirl, UC... ou URL)</label>
                    <input type="text" name="link" placeholder="Cole o link do canal ou vídeo aqui" required>
                </div>
                <div style="flex: 1;">
                    <label>Nome Amigável (Opcional)</label>
                    <input type="text" name="name" placeholder="Nome para exibição">
                </div>
                <div>
                    <label>Qtd. Vídeos (só p/ canal)</label>
                    <input type="number" name="qty" value="50" min="1" max="500" style="width: 100%;">
                </div>
                <div>
                    <button class="btn" type="submit" style="width: 100%; height: 46px;">Adicionar</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Seus Canais (<?php echo count($channels); ?>)</h2>
        <?php if (empty($channels)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: 30px;">Nenhum canal configurado. Use o formulário acima.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <tr>
                    <th>Nome / ID</th>
                    <th>Tipo</th>
                    <th>Qtd. Vídeos</th>
                    <th>Ações</th>
                </tr>
                <?php foreach ($channels as $c): $isVid = !empty($c['video_id']); ?>
                <tr>
                    <td>
                        <strong style="font-size: 15px;"><?php echo htmlspecialchars($c['name']); ?></strong><br>
                        <span style="color: var(--text-muted); font-size: 12px; font-family: monospace;">
                            <?php echo $isVid ? $c['video_id'] : $c['channel_id']; ?>
                        </span>
                    </td>
                    <td>
                        <span class="tag <?php echo $isVid ? 'tag-video' : 'tag-channel'; ?>">
                            <?php echo $isVid ? 'Vídeo Fixo' : 'Canal Dinâmico'; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($isVid): ?>
                            <span style="color: var(--text-muted); font-size: 13px;">—</span>
                        <?php else: ?>
                            <form method="post" style="display: flex; gap: 6px; align-items: center; margin: 0;">
                                <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
                                <input type="hidden" name="action" value="update_qty">
                                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                <input type="number" name="qty" value="<?php echo (int)($c['max_videos'] ?? 50); ?>" min="1" max="500" style="width: 80px; padding: 6px; border: 1px solid var(--border); border-radius: 6px;">
                                <button class="btn btn-small" type="submit">Salvar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                        <div style="display: flex; gap: 8px;">
                            <?php if (!$isVid): ?>
                                <a href="?view=<?php echo urlencode($c['channel_id']); ?>" class="btn btn-outline btn-small">Ver Grade</a>
                            <?php else: ?>
                                <a href="stream.php?id=<?php echo urlencode($c['video_id']); ?>" target="_blank" class="btn btn-outline btn-small">Ver Vídeo</a>
                            <?php endif; ?>
                            
                            <form method="post" onsubmit="return confirm('Tem certeza?')" style="margin: 0;">
                                <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-small">Remover</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Exportar Sua Lista M3U</h2>
        <p style="margin-bottom: 16px; font-size: 14px; color: var(--text-muted);">Este link contem um token secreto unico do seu login. Quem tiver o link consegue baixar a SUA lista — nao compartilhe.</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <!-- Botão IPTV -->
            <div style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border-radius: 12px; padding: 20px; color: #fff; text-align: center;">
                <div style="margin-bottom: 10px;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </div>
                <div style="font-weight: 700; font-size: 16px; margin-bottom: 4px;">Lista IPTV</div>
                <div style="font-size: 12px; opacity: 0.85; margin-bottom: 14px;">Para Xtream, XUI.One e servidores IPTV</div>
                <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($m3uIptv); ?>" target="_blank" class="btn" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); font-size: 12px; padding: 8px 14px;">Copiar URL</a>
                    <a href="<?php echo htmlspecialchars($m3uIptv . '&download=1'); ?>" class="btn" style="background: #fff; color: #6d28d9; font-size: 12px; padding: 8px 14px;">Baixar .m3u8</a>
                </div>
                <div style="margin-top: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; padding: 10px; font-family: monospace; font-size: 11px; word-break: break-all; cursor: pointer; user-select: all;" onclick="navigator.clipboard.writeText(this.textContent.trim()).then(()=>alert('URL IPTV copiada!'))">
                    <?php echo htmlspecialchars($m3uIptv); ?>
                </div>
            </div>

            <!-- Botão VLC -->
            <div style="background: linear-gradient(135deg, #ea580c, #dc2626); border-radius: 12px; padding: 20px; color: #fff; text-align: center;">
                <div style="margin-bottom: 10px;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                </div>
                <div style="font-weight: 700; font-size: 16px; margin-bottom: 4px;">Lista VLC</div>
                <div style="font-size: 12px; opacity: 0.85; margin-bottom: 14px;">Para VLC, KODI e players de desktop</div>
                <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($m3uVlc); ?>" target="_blank" class="btn" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); font-size: 12px; padding: 8px 14px;">Copiar URL</a>
                    <a href="<?php echo htmlspecialchars($m3uVlc . '&download=1'); ?>" class="btn" style="background: #fff; color: #dc2626; font-size: 12px; padding: 8px 14px;">Baixar .m3u8</a>
                </div>
                <div style="margin-top: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; padding: 10px; font-family: monospace; font-size: 11px; word-break: break-all; cursor: pointer; user-select: all;" onclick="navigator.clipboard.writeText(this.textContent.trim()).then(()=>alert('URL VLC copiada!'))">
                    <?php echo htmlspecialchars($m3uVlc); ?>
                </div>
            </div>

            <!-- Botão Download -->
            <div style="background: linear-gradient(135deg, #0891b2, #0e7490); border-radius: 12px; padding: 20px; color: #fff; text-align: center;">
                <div style="margin-bottom: 10px;">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                </div>
                <div style="font-weight: 700; font-size: 16px; margin-bottom: 4px;">Download Direto</div>
                <div style="font-size: 12px; opacity: 0.85; margin-bottom: 14px;">Arquivo .m3u8 genérico para importar</div>
                <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?php echo htmlspecialchars($m3uIptv . '&download=1'); ?>" class="btn" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); font-size: 12px; padding: 8px 14px;">IPTV .m3u8</a>
                    <a href="<?php echo htmlspecialchars($m3uVlc . '&download=1'); ?>" class="btn" style="background: #fff; color: #0e7490; font-size: 12px; padding: 8px 14px;">VLC .m3u8</a>
                </div>
                <div style="margin-top: 12px; background: rgba(0,0,0,0.2); border-radius: 8px; padding: 10px; font-family: monospace; font-size: 11px; word-break: break-all; cursor: pointer; user-select: all;" onclick="navigator.clipboard.writeText(this.textContent.trim()).then(()=>alert('URL copiada!'))">
                    <?php echo htmlspecialchars($m3uIptv); ?>
                </div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <form method="post" onsubmit="return confirm('Isso apagará TODOS os SEUS canais. Continuar?')" style="margin: 0;">
                <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-danger">Apagar Todos os Meus Canais</button>
            </form>
        </div>
    </div>
    
    <?php endif; ?>
</div>
</body>
</html>
