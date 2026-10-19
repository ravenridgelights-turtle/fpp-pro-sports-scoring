<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';

$palettes = array_values(pss_syncTeamPalettes(true));
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

if (isset($_GET['simple']) && (string)$_GET['simple'] === '1') {
    echo json_encode(array_map(function ($palette) {
        return isset($palette['name']) ? (string)$palette['name'] : '';
    }, $palettes));
    exit;
}

echo json_encode(array(
    'paletteMode' => '* Colors Only',
    'palettes' => $palettes
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
