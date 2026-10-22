<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';

function pss_initializePluginDefaults() {
    global $leagues, $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();

    $defaults = array(
        'ENABLED' => 'OFF',
        'logLevel' => '4',
        'TickerEnabled' => 'OFF',
        'TickerKioskEnabled' => 'ON',
        'TickerStyle' => 'normal',
        'TickerWebSpeed' => '90',
        'TickerWebFontSize' => '18',
        'TickerSpacing' => '4',
        'TickerOverlayEnabled' => 'OFF',
        'TickerOverlayModel' => '',
        'TickerWidth' => '128',
        'TickerHeight' => '32',
        'TickerFont' => 'Helvetica',
        'TickerFontSize' => '16',
        'TickerTextColor' => '#FFFFFF',
        'TickerDirection' => 'Right to Left',
        'TickerScrollSpeed' => '10'
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
            $defaults["{$prefix}StartTimeValid"] = 'ON';
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
                $celebrationSuffixes = array('TouchdownSequence', 'FieldgoalSequence', 'WinSequence');
            } else {
                $defaults["{$prefix}ScoreSequence"] = '';
                $celebrationSuffixes = array('ScoreSequence', 'WinSequence');
            }

            $defaults["{$prefix}CelebrationTarget"] = 'local';
            $defaults["{$prefix}CelebrationRemoteHost"] = '';

            foreach ($celebrationSuffixes as $celebrationSuffix) {
                $defaults["{$prefix}{$celebrationSuffix}PlaybackMode"] = 'sequence';
                $defaults["{$prefix}{$celebrationSuffix}Media"] = '';
            }
        }
    }

    foreach ($leagues as $league) {
        foreach (array(1, 2) as $slot) {
            $defaults[pss_tickerIncludeSetting($league, $slot)] = 'ON';
            $defaults[pss_tickerColorSetting($league, $slot)] = '#FFFFFF';
        }
    }

    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $pluginSettings)) {
            pss_setPluginSetting($key, $value);
        }
    }
}

// A PID file is advisory; flock prevents duplicate workers even when two
// lifecycle calls race or an old PID file disappears.
$workerLock = @fopen(pss_runtimePath('worker-lock'), 'c+');
if (!$workerLock || !flock($workerLock, LOCK_EX | LOCK_NB)) exit(0);
pss_shareFileWithFpp(pss_runtimePath('worker-lock'));
if (pss_fppMajorVersion() > 0 && (pss_fppMajorVersion() < 8 || pss_fppMajorVersion() > 10)) {
    pss_logEntry('This plugin supports FPP 8, 9 and 10; detected ' . pss_fppVersion());
    exit(1);
}
pss_initializePluginDefaults();
pss_logEntry('Sports scoring ' . PSS_VERSION . ' daemon started on FPP ' . pss_fppVersion());
$nextPoll = 0;
$lastPoll = 0;
$configHash = '';
$helperRepairAt = 0;
$error = '';

while (true) {
    $pluginSettings = pss_loadPluginSettings();
    try {
        // React to user configuration within three seconds, even while ESPN
        // polling sleeps between games. Exclude frequently changing game data.
        $config = array();
        foreach ($pluginSettings as $key => $value) {
            if (strpos($key, 'TickerLast') !== 0 && preg_match('/(Sequence|Wled|Celebration|Schedule|TeamID|Ticker|^ENABLED$)/', $key)) $config[$key] = $value;
        }
        $hash = sha1(json_encode($config));
        if ($hash !== $configHash || time() >= $helperRepairAt) {
            pss_syncAllGeneratedPlaylists();
            if ($hash !== $configHash) $nextPoll = 0;
            $configHash = $hash;
            $helperRepairAt = time() + 60;
        }
        $enabled = pss_pluginSetting('ENABLED', 'OFF') === 'ON';
        if ($enabled && time() >= $nextPoll) {
            $sleepTime = pss_updateTeamStatus(false);
            pss_updateTickerOutput(false);
            $lastPoll = time();
            $nextPoll = time() + max(3, (int)$sleepTime);
            $error = isset($GLOBALS['pssLastEspnError']) ? $GLOBALS['pssLastEspnError'] : '';
        }
        pss_drainCelebrations();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        pss_logEntry('Daemon error: ' . $error);
        $nextPoll = time() + 15;
    }
    pss_writeWorkerHealth($lastPoll, $nextPoll, $error);
    sleep(3);
}
