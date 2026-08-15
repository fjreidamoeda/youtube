<?php
// update_from_github.php — baixa os arquivos mais recentes do repositório
// (https://github.com/fjreidamoeda/youtube) e substitui os locais, fazendo o
// "deploy" sozinho. Abra via navegador e depois confirme com /vercheck.php.
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);
$base = 'https://raw.githubusercontent.com/fjreidamoeda/youtube/main/';
$files = ['functions.php', 'stream.php', 'lista.php', 'stream_diag.php', 'vercheck.php'];
foreach ($files as $f) {
    $dest = __DIR__ . '/' . $f;
    $data = false;
    if (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($base . $f, false, stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'update_from_github/1.0'],
        ]));
    }
    if (!is_string($data) || strlen($data) < 100) {
        $ch = @curl_init($base . $f);
        if ($ch) {
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30,
            ]);
            $data = @curl_exec($ch);
            @curl_close($ch);
        }
    }
    if (is_string($data) && strlen($data) > 100) {
        $ok = @file_put_contents($dest, $data);
        echo str_pad($f, 20) . ($ok !== false ? 'OK  md5=' . md5($data) : 'ERRO ao salvar') . "\n";
    } else {
        echo str_pad($f, 20) . "FALHOU ao baixar\n";
    }
}
echo "---\nAbra /vercheck.php para confirmar os md5.\n";
