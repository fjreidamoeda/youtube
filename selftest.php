<?php
// selftest.php — Diagnóstico do servidor para o YouTube IPTV
// Abra este arquivo no navegador: http://45.143.7.108:27021/selftest.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (is_file(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

$rows = [];

function row(string $status, string $msg): void {
    global $rows;
    $icon   = ['ok' => '&#9989;', 'fail' => '&#10060;', 'warn' => '&#9888;&#65039;', 'info' => '&#8505;&#65039;'][$status] ?? '&#8505;&#65039;';
    $color  = ['ok' => '#16a34a', 'fail' => '#dc2626', 'warn' => '#d97706', 'info' => '#475569'][$status] ?? '#475569';
    $rows[] = "<tr><td style='font-size:20px;color:{$color}'>{$icon}</td><td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:15px;line-height:1.6'>{$msg}</td></tr>";
}

$testId = isset($_GET['id']) ? preg_replace('~[^A-Za-z0-9_-]~', '', $_GET['id']) : 'X4VbdwhkE10';

// 1) Ambiente
row('info', 'PHP <b>' . PHP_VERSION . '</b> &mdash; ' . PHP_OS_FAMILY . ' (' . php_uname('s') . ' ' . php_uname('m') . ')');
row('info', 'Servidor web: ' . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'n/d'));
row('info', 'Pasta do app: <code>' . htmlspecialchars(__DIR__) . '</code>');

// 2) exec()
$canExec   = function_exists('exec');
$disabled  = array_map('trim', explode(',', ini_get('disable_functions')));
if (in_array('exec', $disabled)) $canExec = false;
row(
    $canExec ? 'ok' : 'fail',
    $canExec
        ? 'Função <b>exec()</b> liberada: <b>SIM</b> &mdash; o yt-dlp pode rodar.'
        : 'Função <b>exec()</b> liberada: <b>NÃO</b> &mdash; sem isso o yt-dlp não roda. Quem administra o servidor precisa liberar exec() no PHP.'
);

$auf = ini_get('allow_url_fopen');
row(
    $auf ? 'ok' : 'fail',
    'Download via PHP (<code>allow_url_fopen</code>): ' . ($auf ? '<b>SIM</b>' : '<b>NÃO</b> &mdash; bloqueia baixar o yt-dlp sozinho.')
);

// 3) Python + yt-dlp
$py = null;
if ($canExec && function_exists('ytdlp_python')) {
    $py = ytdlp_python();
    row($py ? 'ok' : 'warn', 'Python 3 no servidor: <b>' . ($py ? 'SIM (' . htmlspecialchars($py) . ')' : 'NÃO &mdash; vou tentar o binário standalone do yt-dlp') . '</b>');
}

$prep = null;
if ($canExec && function_exists('ytdlp_prepare')) {
    row('info', 'Baixando/verificando o yt-dlp (primeira vez pode levar ~1 min)...');
    $prep = ytdlp_prepare();
    if ($prep) {
        $file = ($prep['type'] === 'py') ? $prep['zipapp'] : $prep['binary'];
        $modo = ($prep['type'] === 'py') ? 'Python (' . htmlspecialchars($prep['python']) . ')' : 'binário standalone';
        row('ok', 'yt-dlp: <b>OK</b> &mdash; modo <b>' . $modo . '</b> em <code>' . htmlspecialchars($file) . '</code> (' . number_format(filesize($file) / 1048576, 1) . ' MB)');
    } else {
        row('fail', 'Não consegui baixar o yt-dlp. O servidor não está conseguindo acessar github.com (ou a pasta bin/ não tem permissão de escrita).');
    }
} elseif (!function_exists('ytdlp_prepare')) {
    row('warn', 'functions.php está desatualizado (sem ytdlp_prepare). Envie a nova versão dos arquivos.');
}

if ($prep) {
    $out = $last = null;
    @exec(ytdlp_build_cmd($prep, ['--version']), $out, $last);
    $ver = trim($out[0] ?? '');
    row($ver ? 'ok' : 'fail', 'Versão do yt-dlp: <b>' . ($ver ? htmlspecialchars($ver) : 'não respondeu') . '</b>');

    row('info', 'Testando resolução do vídeo <b>' . $testId . '</b> (pode levar até 40s)...');
    @set_time_limit(90);
    $url = resolve_via_ytdlp($testId);
    if ($url) {
        $tipo = (stripos($url, '.m3u8') !== false || stripos($url, 'manifest') !== false) ? 'HLS (ao vivo)' : 'MP4 direto';
        row('ok', 'O yt-dlp <b>conseguiu</b> resolver o vídeo. Tipo: <b>' . $tipo . '</b><br><code style="font-size:11px">' . htmlspecialchars(substr($url, 0, 130)) . '&hellip;</code>');
    } else {
        row('fail', 'O yt-dlp <b>não</b> conseguiu resolver o vídeo <b>' . $testId . '</b>. O YouTube está bloqueando o IP do servidor (comum em IP de datacenter).');
    }
} elseif (!$canExec) {
    row('fail', 'Sem exec(), não dá para testar o yt-dlp.');
}

// 4) Próximo passo
row('info', 'Próximo passo: abra no navegador <code>stream.php?id=' . $testId . '</code>. Se começar a carregar/tocar vídeo, o proxy está funcionando e é só recarregar a playlist no StreamFlow.');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Diagnóstico do Servidor</title>
<style>
    * { font-family: 'Segoe UI', Arial, sans-serif; box-sizing: border-box; }
    body { background: #f8fafc; color: #0f172a; padding: 24px; margin: 0; }
    .card { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    h1 { font-size: 22px; margin: 0 0 4px 0; }
    p.sub { color: #64748b; font-size: 14px; margin: 0 0 20px 0; }
    table { width: 100%; border-collapse: collapse; }
    td:first-child { width: 42px; text-align: center; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 5px; font-size: 13px; }
</style>
</head>
<body>
<div class="card">
    <h1>&#128270; Diagnóstico do servidor</h1>
    <p class="sub">Envie o resultado completo deste relatório para a pessoa que está configurando.</p>
    <table>
        <?php foreach ($rows as $r) echo $r; ?>
    </table>
</div>
</body>
</html>
