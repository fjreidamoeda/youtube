<?php
// auth.php - Usuarios + canais em SQLite (PDO), login por sessao,
// cadastro com aprovacao do admin, token por usuario nas playlists.

if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        @session_save_path(__DIR__ . '/cache');
        @session_start();
    }
}

define('AUTH_DB', __DIR__ . '/app.db');

function auth_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            http_response_code(500);
            exit('PDO/SQLite indisponivel no PHP do servidor (falta a extensao pdo_sqlite).');
        }
        $pdo = new PDO('sqlite:' . AUTH_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL;');
        $pdo->exec('PRAGMA foreign_keys=ON;');
    }
    return $pdo;
}

function auth_init(): void {
    $pdo = auth_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        status TEXT NOT NULL DEFAULT 'pending',
        token TEXT NOT NULL UNIQUE,
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );");
    $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        video_id TEXT NOT NULL DEFAULT '',
        channel_id TEXT NOT NULL DEFAULT '',
        name TEXT NOT NULL DEFAULT '',
        logo TEXT NOT NULL DEFAULT '',
        tvg_id TEXT NOT NULL DEFAULT '',
        max_videos INTEGER NOT NULL DEFAULT 50,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");

    // Migracao: bancos criados antes da coluna max_videos.
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(channels)') as $col) $cols[] = $col['name'];
    if (!in_array('max_videos', $cols, true)) {
        $pdo->exec('ALTER TABLE channels ADD COLUMN max_videos INTEGER NOT NULL DEFAULT 50');
    }
    if (!in_array('download', $cols, true)) {
        $pdo->exec('ALTER TABLE channels ADD COLUMN download INTEGER NOT NULL DEFAULT 0');
    }

    // Seed do admin Luciano/132004 (so na primeira vez).
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO users (username,password_hash,role,status,token,note) VALUES (?,?,?,?,?,?)');
        $st->execute(['Luciano', password_hash('132004', PASSWORD_DEFAULT), 'admin', 'active', bin2hex(random_bytes(16)), 'Administrador']);
    }

    // Migracao do data.json (canais antigos) para a conta do admin, uma vez.
    $admin = user_by_username('Luciano');
    if ($admin) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM channels WHERE user_id=?');
        $st->execute([$admin['id']]);
        if ((int)$st->fetchColumn() === 0) {
            $dataFile = __DIR__ . '/data.json';
            if (is_file($dataFile)) {
                $arr = json_decode(@file_get_contents($dataFile), true);
                if (is_array($arr)) {
                    $ins = $pdo->prepare('INSERT INTO channels (user_id,video_id,channel_id,name,logo,tvg_id) VALUES (?,?,?,?,?,?)');
                    foreach ($arr as $c) {
                        if (!is_array($c)) continue;
                        $ins->execute([
                            $admin['id'],
                            $c['video_id'] ?? '',
                            $c['channel_id'] ?? '',
                            $c['name'] ?? 'YouTube',
                            $c['logo'] ?? '',
                            $c['tvg_id'] ?? '',
                        ]);
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------------
// Sessao / autenticacao
// ------------------------------------------------------------------

function user_by_id(int $id): ?array {
    $st = auth_db()->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function user_by_username(string $u): ?array {
    $st = auth_db()->prepare('SELECT * FROM users WHERE username=?');
    $st->execute([$u]);
    $r = $st->fetch();
    return $r ?: null;
}

function user_by_token(string $u, string $t): ?array {
    $st = auth_db()->prepare('SELECT * FROM users WHERE username=? AND token=?');
    $st->execute([$u, $t]);
    $r = $st->fetch();
    return $r ?: null;
}

function auth_strlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function register_user(string $u, string $p): ?string {
    $u = trim($u);
    if (auth_strlen($u) < 3) return 'Usuario muito curto (minimo 3 caracteres).';
    if (auth_strlen($p) < 4) return 'Senha muito curta (minimo 4 caracteres).';
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $u)) return 'Usuario pode ter apenas letras, numeros, ponto, hifen e underline.';
    if (user_by_username($u)) return 'Esse usuario ja existe. Escolha outro.';
    $st = auth_db()->prepare('INSERT INTO users (username,password_hash,role,status,token,note) VALUES (?,?,?,?,?,?)');
    $st->execute([$u, password_hash($p, PASSWORD_DEFAULT), 'user', 'pending', bin2hex(random_bytes(16)), '']);
    return null;
}

function login_user(string $u, string $p): string {
    $user = user_by_username(trim($u));
    if (!$user || !password_verify($p, $user['password_hash'])) return 'Usuario ou senha invalidos.';
    if ($user['status'] === 'pending') return 'Cadastro ainda aguardando aprovacao do admin.';
    if ($user['status'] === 'blocked') return 'Usuario bloqueado. Contate o admin.';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
    }
    return '';
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u = user_by_id((int)$_SESSION['uid']);
    if (!$u || $u['status'] !== 'active') {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Acesso negado (apenas administradores).');
    }
}

// ------------------------------------------------------------------
// CSRF (protege os formularios de POST)
// ------------------------------------------------------------------

function auth_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function auth_check_csrf(): void {
    $t = (string)($_POST['csrf'] ?? '');
    if (!empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t)) return;
    http_response_code(400);
    exit('Falha de validacao do formulario. Volte e tente de novo.');
}

// ------------------------------------------------------------------
// Canais por usuario
// ------------------------------------------------------------------

function channels_for_user(int $uid): array {
    $st = auth_db()->prepare('SELECT * FROM channels WHERE user_id=? ORDER BY id');
    $st->execute([$uid]);
    return $st->fetchAll();
}

function channel_add(int $uid, array $c): void {
    $st = auth_db()->prepare('INSERT INTO channels (user_id,video_id,channel_id,name,logo,tvg_id,max_videos) VALUES (?,?,?,?,?,?,?)');
    $st->execute([
        $uid,
        $c['video_id'] ?? '',
        $c['channel_id'] ?? '',
        $c['name'] ?? '',
        $c['logo'] ?? '',
        $c['tvg_id'] ?? '',
        max(1, min(500, (int)($c['max_videos'] ?? 50))),
    ]);
}

function channel_set_quantity(int $cid, int $uid, int $qty): void {
    $qty = max(1, min(500, $qty));
    $st = auth_db()->prepare('UPDATE channels SET max_videos=? WHERE id=? AND user_id=?');
    $st->execute([$qty, $cid, $uid]);
}

function channel_set_download(int $cid, int $uid, bool $on): void {
    $st = auth_db()->prepare('UPDATE channels SET download=? WHERE id=? AND user_id=?');
    $st->execute([$on ? 1 : 0, $cid, $uid]);
}

function channel_delete_by_id(int $cid, ?int $uid = null): void {
    if ($uid !== null) {
        $st = auth_db()->prepare('DELETE FROM channels WHERE id=? AND user_id=?');
        $st->execute([$cid, $uid]);
    } else {
        $st = auth_db()->prepare('DELETE FROM channels WHERE id=?');
        $st->execute([$cid]);
    }
}

function channel_clear(int $uid): void {
    $st = auth_db()->prepare('DELETE FROM channels WHERE user_id=?');
    $st->execute([$uid]);
}

// ------------------------------------------------------------------
// Administracao de usuarios (somente admin)
// ------------------------------------------------------------------

function list_users(): array {
    return auth_db()->query(
        'SELECT u.*, (SELECT COUNT(*) FROM channels c WHERE c.user_id=u.id) AS total_channels FROM users u ORDER BY u.id'
    )->fetchAll();
}

function set_user_status(int $id, string $status): ?string {
    if (!in_array($status, ['pending', 'active', 'blocked'], true)) return 'Status invalido.';
    $target = user_by_id($id);
    if (!$target) return 'Usuario nao encontrado.';
    if ($target['role'] === 'admin' && $status !== 'active') return 'Nao e possivel bloquear/excluir a conta admin.';
    $st = auth_db()->prepare('UPDATE users SET status=? WHERE id=?');
    $st->execute([$status, $id]);
    return null;
}

function delete_user(int $id): ?string {
    $target = user_by_id($id);
    if (!$target) return 'Usuario nao encontrado.';
    if ($target['role'] === 'admin') return 'Nao e possivel excluir a conta admin.';
    $st = auth_db()->prepare('DELETE FROM users WHERE id=?');
    $st->execute([$id]);
    return null;
}

function update_user(int $id, string $username, string $role, string $status, string $note, string $newPass = ''): ?string {
    $target = user_by_id($id);
    if (!$target) return 'Usuario nao encontrado.';
    $username = trim($username);
    if (auth_strlen($username) < 3 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) return 'Nome de usuario invalido.';
    if (!in_array($role, ['user', 'admin'], true)) return 'Role invalida.';
    if (!in_array($status, ['pending', 'active', 'blocked'], true)) return 'Status invalido.';
    if ($target['role'] === 'admin') {
        if ($role !== 'admin') return 'Nao e possivel rebaixar a conta admin.';
        if ($status !== 'active') return 'Nao e possivel mudar o status da conta admin.';
    }
    $dupe = user_by_username($username);
    if ($dupe && (int)$dupe['id'] !== $id) return 'Esse nome de usuario ja existe.';
    if ($newPass !== '') {
        if (auth_strlen($newPass) < 4) return 'Senha nova muito curta (minimo 4).';
        $st = auth_db()->prepare('UPDATE users SET username=?, role=?, status=?, note=?, password_hash=? WHERE id=?');
        $st->execute([$username, $role, $status, $note, password_hash($newPass, PASSWORD_DEFAULT), $id]);
    } else {
        $st = auth_db()->prepare('UPDATE users SET username=?, role=?, status=?, note=? WHERE id=?');
        $st->execute([$username, $role, $status, $note, $id]);
    }
    return null;
}

function m3u_link(int $uid, bool $vlc = false): ?string {
    $u = user_by_id($uid);
    if (!$u) return null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $base . '/lista.php?u=' . rawurlencode($u['username']) . '&t=' . rawurlencode($u['token']) . ($vlc ? '&mode=vlc' : '');
}
