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
        'HighlightQuality' => 'low',
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


function pss_launchHighlightDownloader() {
    $worker = __DIR__ . '/highlight-downloader.php';
    if (!is_file($worker)) {
        return;
    }

    // The downloader has its own flock, so calling this regularly is safe.
    // The short stamp prevents needless process creation while still letting
    // new ESPN clips get noticed quickly during long score-poll sleeps.
    $stamp = '/tmp/fpp-nfl-highlight-downloader-launch.stamp';
    $now = time();
    $last = is_file($stamp) ? (int)@filemtime($stamp) : 0;
    if ($last > 0 && ($now - $last) < 20) {
        return;
    }
    @touch($stamp);

    $php = is_file('/usr/bin/php') ? '/usr/bin/php' : 'php';
    $nice = is_executable('/usr/bin/nice') ? '/usr/bin/nice -n 15 ' : '';
    $ionice = is_executable('/usr/bin/ionice') ? '/usr/bin/ionice -c3 ' : '';

    $command = $nice . $ionice . escapeshellcmd($php) . ' '
        . escapeshellarg($worker) . ' >/dev/null 2>&1 &';
    @exec($command);
}

function pss_sleepWithHighlightDownloader($seconds) {
    $remaining = max(1, (int)$seconds);
    while ($remaining > 0) {
        pss_launchHighlightDownloader();
        $chunk = min(20, $remaining);
        sleep($chunk);
        $remaining -= $chunk;
    }
}

pss_initializePluginDefaults();
pss_logEntry('Sports scoring daemon started');
pss_launchHighlightDownloader();

while (true) {
    $pluginSettings = pss_loadPluginSettings();
    if (pss_pluginSetting('ENABLED', 'OFF') !== 'ON') {
        sleep(10);
        continue;
    }

    try {
        $sleepTime = pss_updateTeamStatus(false);
        pss_updateTickerOutput(false);
        pss_sleepWithHighlightDownloader(max(5, (int)$sleepTime));
    } catch (Throwable $e) {
        pss_logEntry('Daemon error: ' . $e->getMessage());
        sleep(30);
    }
}
