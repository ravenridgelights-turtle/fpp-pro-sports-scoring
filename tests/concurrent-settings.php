<?php
require __DIR__ . '/bootstrap.php';
$pluginName = 'fpp-nfl';
$settings = array('configDirectory' => $argv[1]);
$pluginConfigFile = $argv[1] . '/plugin.fpp-nfl';
$logFile = $argv[1] . '/log';
$pluginSettings = pss_loadPluginSettings();
if (isset($argv[2])) {
    for ($i = 0; $i < 20; $i++) {
        if (!pss_setPluginSetting('writer' . $argv[2], (string)$i)) exit(1);
    }
} else {
    $data = pss_loadPluginSettings();
    for ($i = 0; $i < 6; $i++) if (!isset($data['writer' . $i]) || $data['writer' . $i] !== '19') exit(1);
    echo "PASS concurrent settings: all 6 writers retained their updates\n";
}
