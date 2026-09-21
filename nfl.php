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
        foreach (array(1, 2) as $slot) {
            $prefix = pss_teamPrefix($league, $slot);

            $defaults["{$prefix}TeamID"] = '';
            $defaults["{$prefix}TeamAbbreviation"] = '';
            $defaults["{$prefix}TeamLogo"] = '';
            $defaults["{$prefix}TeamName"] = '';
            $defaults["{$prefix}TeamNextEventID"] = '';
            $defaults["{$prefix}Start"] = '';
            $defaults["{$prefix}GameStatus"] = '';
            $defaults["{$prefix}GameDetail"] = '';
            $defaults["{$prefix}OppoID"] = '';
            $defaults["{$prefix}OppoAbbreviation"] = '';
            $defaults["{$prefix}OppoName"] = '';
            $defaults["{$prefix}OppoLogo"] = '';
            $defaults["{$prefix}MyScore"] = '0';
            $defaults["{$prefix}OppoScore"] = '0';
            $defaults["{$prefix}WinSequence"] = '';
            $defaults["{$prefix}LastScoringPlayID"] = '';
            $defaults["{$prefix}LastCelebratedScore"] = '0';
            $defaults["{$prefix}LastCompletedEventID"] = '';
            $defaults["{$prefix}GameSnapshotEventID"] = '';

            if ($league === 'nfl' || $league === 'ncaa') {
                $defaults["{$prefix}TouchdownSequence"] = '';
                $defaults["{$prefix}FieldgoalSequence"] = '';
            } else {
                $defaults["{$prefix}ScoreSequence"] = '';
            }
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
