<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';

function pss_initializePluginDefaults() {
    global $leagues, $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();

    $defaults = array(
        'ENABLED' => 'OFF',
        'logLevel' => '4'
    );
    foreach ($leagues as $league) {
        $defaults["{$league}TeamID"] = '';
        $defaults["{$league}TeamAbbreviation"] = '';
        $defaults["{$league}TeamLogo"] = '';
        $defaults["{$league}TeamName"] = '';
        $defaults["{$league}TeamNextEventID"] = '';
        $defaults["{$league}Start"] = '';
        $defaults["{$league}GameStatus"] = '';
        $defaults["{$league}OppoID"] = '';
        $defaults["{$league}OppoAbbreviation"] = '';
        $defaults["{$league}OppoName"] = '';
        $defaults["{$league}MyScore"] = '0';
        $defaults["{$league}OppoScore"] = '0';
        $defaults["{$league}WinSequence"] = '';
        $defaults["{$league}LastScoringPlayID"] = '';
        $defaults["{$league}LastCelebratedScore"] = '0';
        $defaults["{$league}LastCompletedEventID"] = '';
        if ($league === 'nfl' || $league === 'ncaa') {
            $defaults["{$league}TouchdownSequence"] = '';
            $defaults["{$league}FieldgoalSequence"] = '';
        } else {
            $defaults["{$league}ScoreSequence"] = '';
        }
    }

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $pluginSettings)) {
            pss_setPluginSetting($key, $value);
        }
    }
}

pss_initializePluginDefaults();
pss_logEntry('Sports scoring daemon started');

while (true) {
    $pluginSettings = pss_loadPluginSettings();
    pss_syncAllGeneratedPlaylists();
    if (pss_pluginSetting('ENABLED', 'OFF') !== 'ON') {
        sleep(10);
        continue;
    }

    try {
        $sleepTime = pss_updateTeamStatus(false);
        sleep(max(5, (int)$sleepTime));
    } catch (Throwable $e) {
        pss_logEntry('Daemon error: ' . $e->getMessage());
        sleep(30);
    }
}
