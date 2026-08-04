<?php
require_once __DIR__ . '/functions.php';
header('Content-Type: application/xml; charset=utf-8');

$channels = load_channels();
$blockHours = 6;
$blocksAhead = 4;
$now = time();

function xmltv_time($ts) { $offset = date('O', $ts); return date('YmdHis', $ts) . ' ' . $offset; }

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<tv generator-info-name=\"yt-auto-xmltv\" source-info-name=\"YouTube\">\n";

foreach ($channels as $c) {
    $name = $c['name'] ?? 'YouTube';
    $logo = $c['logo'] ?? '';
    $tvg_id = $c['tvg_id'] ?? ('yt_' . substr(md5($name), 0, 8));
    echo "  <channel id=\"{$tvg_id}\">\n";
    echo "    <display-name>" . htmlspecialchars($name, ENT_XML1) . "</display-name>\n";
    if ($logo) echo "    <icon src=\"" . htmlspecialchars($logo, ENT_XML1) . "\" />\n";
    echo "  </channel>\n";
}

foreach ($channels as $c) {
    $name = $c['name'] ?? 'YouTube';
    $tvg_id = $c['tvg_id'] ?? ('yt_' . substr(md5($name), 0, 8));
    $start = $now;
    for ($i = 0; $i < $blocksAhead; $i++) {
        $end = $start + ($blockHours * 3600);
        echo "  <programme start=\"" . xmltv_time($start) . "\" stop=\"" . xmltv_time($end) . "\" channel=\"{$tvg_id}\">\n";
        echo "    <title lang=\"pt\">Ao vivo</title>\n";
        echo "    <desc lang=\"pt\">Transmissão contínua: " . htmlspecialchars($name, ENT_XML1) . "</desc>\n";
        echo "    <category lang=\"pt\">Ao vivo</category>\n";
        echo "  </programme>\n";
        $start = $end;
    }
}
echo "</tv>\n";