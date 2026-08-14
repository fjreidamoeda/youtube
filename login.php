<?php
// login.php - Tela de login e cadastro. Cadastro novo entra como "pendente"
// e so pode logar depois da aprovacao do admin (admin.php).
require_once __DIR__ . '/auth.php';
auth_init();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';
    if ($form === 'login') {
        $e = login_user((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($e === '') {
            header('Location: index.php');
            exit;
        }
        $error = $e;
    } elseif ($form === 'register') {
        $e = register_user((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($e === null) {
            $notice = 'Cadastro criado! Aguarde a aprovacao do admin para poder entrar.';
        } else {
            $error = $e;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar - YouTube IPTV</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { font-family: 'Inter', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
    body { background: linear-gradient(135deg, #0f172a, #1e3a8a); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .card { background: #fff; border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.35); width: 100%; max-width: 420px; overflow: hidden; }
    .head { background: #1e40af; color: #fff; padding: 24px; text-align: center; }
    .head h1 { font-size: 22px; margin-bottom: 4px; }
    .head p { font-size: 13px; opacity: .85; }
    .tabs { display: flex; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; }
    .tab { flex: 1; text-align: center; padding: 12px; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; }
    .tab.active { color: #1e40af; border-bottom-color: #1e40af; background: #fff; }
    .body { padding: 24px; }
    label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px; }
    input { width: 100%; padding: 11px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; margin-bottom: 16px; }
    input:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,.1); }
    .btn { display: block; width: 100%; background: #1e40af; color: #fff; border: none; border-radius: 8px; padding: 12px; font-size: 15px; font-weight: 600; cursor: pointer; }
    .btn:hover { background: #1e3a8a; }
    .msg { padding: 12px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    .error { background: #fee2e2; color: #b91c1c; }
    .notice { background: #dcfce7; color: #166534; }
    .hint { font-size: 12px; color: #94a3b8; margin-top: 12px; text-align: center; }
</style>
</head>
<body>
<div class="card">
    <div class="head">
        <h1>YouTube IPTV</h1>
        <p>Painel de canais por usuario</p>
    </div>
    <div class="tabs">
        <div class="tab active" onclick="showTab('login')">Entrar</div>
        <div class="tab" onclick="showTab('register')">Cadastrar</div>
    </div>
    <div class="body">
        <?php if ($error): ?><div class="msg error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="msg notice"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <form id="form-login" method="post">
            <input type="hidden" name="form" value="login">
            <label>Usuario</label>
            <input type="text" name="username" required autocomplete="username">
            <label>Senha</label>
            <input type="password" name="password" required autocomplete="current-password">
            <button class="btn" type="submit">Entrar</button>
        </form>

        <form id="form-register" method="post" style="display:none;">
            <input type="hidden" name="form" value="register">
            <label>Nome de usuario (para o login)</label>
            <input type="text" name="username" required autocomplete="username">
            <label>Senha</label>
            <input type="password" name="password" required autocomplete="new-password">
            <button class="btn" type="submit">Solicitar cadastro</button>
            <p class="hint">Apos o cadastro, o admin precisa aprovar antes de voce entrar.</p>
        </form>
    </div>
</div>
<script>
function showTab(id) {
    document.getElementById('form-login').style.display = id === 'login' ? '' : 'none';
    document.getElementById('form-register').style.display = id === 'register' ? '' : 'none';
    document.querySelectorAll('.tab').forEach(function (t) {
        t.classList.toggle('active', t.textContent.trim().toLowerCase() === (id === 'login' ? 'entrar' : 'cadastrar'));
    });
}
</script>
</body>
</html>
