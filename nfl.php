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


function pss_launchHighlightCacheWorker() {
    $stamp = '/tmp/fpp-nfl-highlight-cache-launch.stamp';
    $now = time();

    // Pi-safe cadence. ESPN clips are not published instantly anyway, and a
    // 45-second cache check avoids constant PHP/cURL churn on low-power hardware.
    $last = is_file($stamp) ? (int)@filemtime($stamp) : 0;
    if ($last > 0 && ($now - $last) < 45) {
        return;
    }
    @touch($stamp);

    $php = is_file('/usr/bin/php') ? '/usr/bin/php' : 'php';
    $worker = __DIR__ . '/highlight-cache.php';
    if (!is_file($worker)) {
        return;
    }

    // Run cache work below FPP's normal workload priority. ionice may not exist on
    // every image, so use it only when available.
    $nice = is_executable('/usr/bin/nice') ? '/usr/bin/nice -n 15 ' : '';
    $ionice = is_executable('/usr/bin/ionice') ? '/usr/bin/ionice -c3 ' : '';
    $command = $nice . $ionice . escapeshellcmd($php) . ' ' . escapeshellarg($worker) . ' >/dev/null 2>&1 &';
    @exec($command);
}

function pss_sleepWithHighlightCache($seconds) {
    $remaining = max(1, (int)$seconds);
    while ($remaining > 0) {
        pss_launchHighlightCacheWorker();
        $chunk = min(45, $remaining);
        sleep($chunk);
        $remaining -= $chunk;
    }
}

pss_initializePluginDefaults();
pss_logEntry('Sports scoring daemon started');
pss_launchHighlightCacheWorker();

while (true) {
    $pluginSettings = pss_loadPluginSettings();
    if (pss_pluginSetting('ENABLED', 'OFF') !== 'ON') {
        sleep(10);
        continue;
    }

    try {
        $sleepTime = pss_updateTeamStatus(false);
        pss_updateTickerOutput(false);
        pss_sleepWithHighlightCache(max(5, (int)$sleepTime));
    } catch (Throwable $e) {
        pss_logEntry('Daemon error: ' . $e->getMessage());
        sleep(30);
    }
}
