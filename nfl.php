<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';

function initializePluginDefaults() {
    global $leagues, $pluginSettings;
    $pluginSettings = loadPluginSettings();

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
            setPluginSetting($key, $value);
        }
    }
}

initializePluginDefaults();
logEntry('Sports scoring daemon started');

while (true) {
    $pluginSettings = loadPluginSettings();
    if (pluginSetting('ENABLED', 'OFF') !== 'ON') {
        sleep(10);
        continue;
    }

    try {
        $sleepTime = updateTeamStatus(false);
        sleep(max(5, (int)$sleepTime));
    } catch (Throwable $e) {
        logEntry('Daemon error: ' . $e->getMessage());
        sleep(30);
    }
}
