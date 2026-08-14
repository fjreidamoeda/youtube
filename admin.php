<?php
// admin.php - Painel do administrador: aprovar, editar, bloquear, excluir
// usuarios e ver/editar tudo o que cada usuario cadastrou.
require_once __DIR__ . '/auth.php';
auth_init();
require_admin();

$me = current_user();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_check_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'approve') {
        $e = set_user_status($id, 'active');
        $e ? $err = $e : $msg = 'Usuario aprovado e ativado.';
    } elseif ($action === 'block') {
        $e = set_user_status($id, 'blocked');
        $e ? $err = $e : $msg = 'Usuario bloqueado.';
    } elseif ($action === 'unblock') {
        $e = set_user_status($id, 'active');
        $e ? $err = $e : $msg = 'Usuario desbloqueado.';
    } elseif ($action === 'delete') {
        $e = delete_user($id);
        $e ? $err = $e : $msg = 'Usuario excluido (junto com os canais dele).';
    } elseif ($action === 'update') {
        $e = update_user(
            $id,
            (string)($_POST['username'] ?? ''),
            (string)($_POST['role'] ?? 'user'),
            (string)($_POST['status'] ?? 'active'),
            (string)($_POST['note'] ?? ''),
            (string)($_POST['new_password'] ?? '')
        );
        $e ? $err = $e : $msg = 'Dados do usuario atualizados.';
    } elseif ($action === 'add_channel') {
        $uid = $id;
        $link = trim((string)($_POST['link'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $qty  = max(1, min(500, (int)($_POST['qty'] ?? 50)));
        require_once __DIR__ . '/functions.php';
        $norm = normalize_channel_input($link);
        if (!$norm) {
            $err = 'Nao consegui reconhecer esse link/ID.';
        } else {
            if ($name === '') $name = $link;
            $channels = channels_for_user($uid);
            $exists = false;
            foreach ($channels as $ch) {
                $v = ($norm['type'] === 'video_id') ? ($ch['video_id'] ?? '') : ($ch['channel_id'] ?? '');
                if ($v === $norm['value']) { $exists = true; break; }
            }
            if ($exists) {
                $err = 'Esse canal/video ja esta na lista desse usuario.';
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
                $msg = 'Canal adicionado para o usuario #' . $uid . '.';
            }
        }
    } elseif ($action === 'del_channel') {
        channel_delete_by_id((int)($_POST['channel_id'] ?? 0));
        $msg = 'Canal removido.';
    } elseif ($action === 'update_qty') {
        channel_set_quantity((int)($_POST['channel_id'] ?? 0), (int)($_POST['id'] ?? 0), (int)($_POST['qty'] ?? 50));
        $msg = 'Quantidade atualizada.';
    } elseif ($action === 'clear_channels') {
        channel_clear($id);
        $msg = 'Todos os canais do usuario foram removidos.';
    }
}

$users = list_users();
$viewUserId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewUser = $viewUserId ? user_by_id($viewUserId) : null;
$viewChannels = $viewUser ? channels_for_user($viewUserId) : [];
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = $editId ? user_by_id($editId) : null;

function status_badge(string $s): string {
    $map = ['pending' => 'Pendente', 'active' => 'Ativo', 'blocked' => 'Bloqueado'];
    $color = ['pending' => '#d97706', 'active' => '#059669', 'blocked' => '#dc2626'];
    return '<span style="background:' . $color[$s] . ';color:#fff;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;">' . ($map[$s] ?? $s) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - YouTube IPTV</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { font-family: 'Inter', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #f8fafc; color: #0f172a; padding: 20px; }
    .container { max-width: 1100px; margin: 0 auto; }
    header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
    header h1 { font-size: 24px; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
    .card h2 { font-size: 17px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 10px; border-bottom: 2px solid #e2e8f0; color: #64748b; font-size: 12px; text-transform: uppercase; }
    td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; font-size: 14px; }
    .btn { display: inline-block; background: #3b82f6; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn:hover { background: #2563eb; }
    .btn-green { background: #059669; } .btn-green:hover { background: #047857; }
    .btn-red { background: #dc2626; } .btn-red:hover { background: #b91c1c; }
    .btn-amber { background: #d97706; } .btn-amber:hover { background: #b45309; }
    .btn-gray { background: #64748b; } .btn-gray:hover { background: #475569; }
    .btn-small { padding: 5px 9px; font-size: 11px; }
    .msg { background: #dcfce7; color: #166534; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .err { background: #fee2e2; color: #b91c1c; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    input, select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
    label { font-size: 12px; font-weight: 600; color: #475569; display: block; margin: 8px 0 4px; }
    .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .mono { font-family: monospace; font-size: 12px; word-break: break-all; }
    .stat { display: inline-block; background: #eff6ff; color: #1e40af; border-radius: 8px; padding: 10px 16px; margin-right: 8px; font-size: 13px; font-weight: 600; }
</style>
</head>
<body>
<div class="container">
    <header>
        <div>
            <h1>Painel Admin</h1>
            <p style="color:#64748b; font-size:13px;">Conectado como <?php echo htmlspecialchars($me['username']); ?></p>
        </div>
        <div style="display:flex; gap:8px;">
            <a class="btn btn-gray" href="index.php">Meu Painel</a>
            <a class="btn btn-red" href="login.php?logout=1">Sair</a>
        </div>
    </header>

    <?php if ($msg): ?><div class="msg"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <?php $pending = count(array_filter($users, function ($u) { return $u['status'] === 'pending'; })); ?>
    <div style="margin-bottom: 20px;">
        <span class="stat"><?php echo count($users); ?> usuarios</span>
        <span class="stat"><?php echo $pending; ?> aguardando aprovacao</span>
        <span class="stat"><?php echo array_sum(array_column($users, 'total_channels')); ?> canais no total</span>
    </div>

    <?php if ($editUser): ?>
    <div class="card">
        <h2>Editar usuario: <?php echo htmlspecialchars($editUser['username']); ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
            <div class="row">
                <div><label>Nome de usuario</label><input type="text" name="username" value="<?php echo htmlspecialchars($editUser['username']); ?>" required></div>
                <div><label>Nova senha (deixe vazio para manter)</label><input type="password" name="new_password"></div>
                <div><label>Permissao</label>
                    <select name="role">
                        <option value="user" <?php echo $editUser['role'] === 'user' ? 'selected' : ''; ?>>Usuario</option>
                        <option value="admin" <?php echo $editUser['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div><label>Status</label>
                    <select name="status">
                        <option value="pending" <?php echo $editUser['status'] === 'pending' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="active" <?php echo $editUser['status'] === 'active' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="blocked" <?php echo $editUser['status'] === 'blocked' ? 'selected' : ''; ?>>Bloqueado</option>
                    </select>
                </div>
                <div style="flex:1; min-width:200px;"><label>Nota</label><input type="text" name="note" value="<?php echo htmlspecialchars($editUser['note'] ?? ''); ?>"></div>
                <div><label>&nbsp;</label><button class="btn" type="submit">Salvar</button></div>
            </div>
        </form>
        <p style="margin-top:10px; font-size:12px; color:#64748b;">Link M3U deste usuario: <span class="mono"><?php echo htmlspecialchars(m3u_link((int)$editUser['id']) ?? '-'); ?></span></p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Usuarios</h2>
        <div style="overflow-x:auto;">
        <table>
            <tr><th>ID</th><th>Usuario</th><th>Permissao</th><th>Status</th><th>Canais</th><th>Criado</th><th>Nota</th><th>Acoes</th></tr>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                <td><?php echo $u['role'] === 'admin' ? '<span style="color:#1e40af;font-weight:600;">Admin</span>' : 'Usuario'; ?></td>
                <td><?php echo status_badge($u['status']); ?></td>
                <td><?php echo (int)$u['total_channels']; ?></td>
                <td style="white-space:nowrap;"><?php echo htmlspecialchars($u['created_at']); ?></td>
                <td><?php echo htmlspecialchars($u['note'] ?? ''); ?></td>
                <td style="white-space:nowrap;">
                    <?php if ($u['status'] === 'pending'): ?>
                        <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-green btn-small" type="submit">Aprovar</button></form>
                    <?php elseif ($u['status'] === 'active'): ?>
                        <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>"><input type="hidden" name="action" value="block"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-amber btn-small" type="submit">Bloquear</button></form>
                    <?php else: ?>
                        <form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>"><input type="hidden" name="action" value="unblock"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-green btn-small" type="submit">Desbloquear</button></form>
                    <?php endif; ?>
                    <a class="btn btn-gray btn-small" href="?edit=<?php echo (int)$u['id']; ?>">Editar</a>
                    <a class="btn btn-gray btn-small" href="?view=<?php echo (int)$u['id']; ?>">Canais</a>
                    <?php if ($u['role'] !== 'admin'): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Excluir usuario <?php echo htmlspecialchars(addslashes($u['username'])); ?> e todos os canais dele?');"><input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><button class="btn btn-red btn-small" type="submit">Excluir</button></form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <?php if ($viewUser): ?>
    <div class="card">
        <h2>Canais de <?php echo htmlspecialchars($viewUser['username']); ?> (<?php echo count($viewChannels); ?>)</h2>
        <p style="font-size:12px; color:#64748b; margin-bottom:12px;">Link M3U: <span class="mono"><?php echo htmlspecialchars(m3u_link((int)$viewUser['id']) ?? '-'); ?></span></p>

        <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
            <input type="hidden" name="action" value="add_channel">
            <input type="hidden" name="id" value="<?php echo (int)$viewUser['id']; ?>">
            <div class="row">
                <div style="flex:2; min-width:220px;"><label>Adicionar canal/video para este usuario</label><input type="text" name="link" placeholder="Link ou ID do YouTube" required></div>
                <div style="flex:1;"><label>Nome (opcional)</label><input type="text" name="name" placeholder="Nome para exibir"></div>
                <div><label>Qtd. videos (so canal)</label><input type="number" name="qty" value="50" min="1" max="500" style="width:110px;"></div>
                <div><label>&nbsp;</label><button class="btn" type="submit">Adicionar</button></div>
            </div>
        </form>

        <?php if (empty($viewChannels)): ?>
            <p style="color:#94a3b8; padding:10px;">Nenhum canal cadastrado para este usuario.</p>
        <?php else: ?>
        <table>
            <tr><th>ID</th><th>Nome / ID</th><th>Tipo</th><th>Qtd. Videos</th><th>Acao</th></tr>
            <?php foreach ($viewChannels as $ch): $isVid = !empty($ch['video_id']); ?>
            <tr>
                <td><?php echo (int)$ch['id']; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($ch['name']); ?></strong><br>
                    <span class="mono" style="color:#64748b;"><?php echo htmlspecialchars($isVid ? $ch['video_id'] : $ch['channel_id']); ?></span>
                </td>
                <td><?php echo $isVid ? 'Video Fixo' : 'Canal Dinamico'; ?></td>
                <td>
                    <?php if ($isVid): ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php else: ?>
                        <form method="post" style="display:flex; gap:6px; align-items:center; margin:0;">
                            <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
                            <input type="hidden" name="action" value="update_qty">
                            <input type="hidden" name="id" value="<?php echo (int)$viewUser['id']; ?>">
                            <input type="hidden" name="channel_id" value="<?php echo (int)$ch['id']; ?>">
                            <input type="number" name="qty" value="<?php echo (int)($ch['max_videos'] ?? 50); ?>" min="1" max="500" style="width:80px; padding:5px 8px;">
                            <button class="btn btn-small" type="submit">Salvar</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isVid): ?><a class="btn btn-gray btn-small" href="stream.php?id=<?php echo urlencode($ch['video_id']); ?>" target="_blank">Ver</a><?php endif; ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Remover este canal?');">
                        <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
                        <input type="hidden" name="action" value="del_channel">
                        <input type="hidden" name="channel_id" value="<?php echo (int)$ch['id']; ?>">
                        <button class="btn btn-red btn-small" type="submit">Remover</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <form method="post" style="margin-top:14px;" onsubmit="return confirm('Apagar TODOS os canais deste usuario?');">
            <input type="hidden" name="csrf" value="<?php echo auth_csrf(); ?>">
            <input type="hidden" name="action" value="clear_channels">
            <input type="hidden" name="id" value="<?php echo (int)$viewUser['id']; ?>">
            <button class="btn btn-red btn-small" type="submit">Apagar todos os canais deste usuario</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
