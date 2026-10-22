<?php
require __DIR__ . '/bootstrap.php';
$assertions = 0;
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/espn-pregame.json'), true)['games'];
loadPssFunctions($root . '/status.php');
date_default_timezone_set('America/New_York');
foreach ($fixtures as $fixture) {
    resetFixture();
    $testHttp = function ($url) use ($fixture) {
        return strpos($url, '/summary?event=') !== false ? response($fixture['response']) : null;
    };
    $game = pss_getGameStatus($fixture['sport'], $fixture['league'], $fixture['eventID'], $fixture['teamID']);
    check($game['valid'] && $game['state'] === 'pre', $fixture['name'] . ': actual scoreless pregame response is accepted');
    check($game['myScore'] === 0 && $game['oppoScore'] === 0, $fixture['name'] . ': pregame scores default to zero');
    check($game['oppoID'] !== '' && $game['oppoID'] !== $fixture['teamID'] && $game['oppoName'] !== '', $fixture['name'] . ': opponent is retained');
    check(pss_baselineGameSnapshot($fixture['league'], $fixture['eventID'], $game), $fixture['name'] . ': snapshot is saved');
    pss_drainCelebrations();
    check(count(inserted()) === 0, $fixture['name'] . ': loading pregame never celebrates');
}

function upcomingFixture($fixture) {
    // Keep worker timing tests independent of the date CI runs. The parser
    // tests above use the captured dates and omitted fields without changes.
    $fixture['response']['header']['competitions'][0]['date'] = date(DATE_ATOM, time() + 86400);
    return $fixture;
}
function teamResponse($fixture) {
    $competition = $fixture['response']['header']['competitions'][0];
    foreach ($competition['competitors'] as $competitor) {
        if ($competitor['team']['id'] !== $fixture['teamID']) continue;
        $team = $competitor['team'];
        $team['nextEvent'] = array(array('id' => $fixture['eventID'], 'date' => $competition['date'],
            'timeValid' => $fixture['response']['header']['timeValid'], 'competitions' => array($competition)));
        return array('team' => $team);
    }
    throw new RuntimeException('Fixture lacks selected team');
}
function useUpcomingFixture($fixture) {
    global $testHttp;
    $testHttp = function ($url) use ($fixture) {
        if (strpos($url, '/summary?event=') !== false) return response($fixture['response']);
        if (strpos($url, '/teams/') !== false) return response(teamResponse($fixture));
        return null;
    };
}
function espnRequests() {
    global $requests;
    return array_values(array_filter($requests, function ($r) { return strpos($r['url'], 'site.api.espn.com') !== false; }));
}

// Reproduce the NFL card in the screenshots: known next-event ID and date,
// no opponent, a responding worker, and a scheduled summary without scores.
resetFixture();
$leagues = array('nfl');
$fixture = upcomingFixture($fixtures[0]);
pss_setPluginSettings(array('nflTeamNextEventID' => $fixture['eventID'], 'nflGameStatus' => 'pre',
    'nflStart' => $fixture['response']['header']['competitions'][0]['date'],
    'nflGameSnapshotEventID' => '', 'nflOppoID' => '', 'nflOppoName' => '', 'nflOppoLogo' => ''));
useUpcomingFixture($fixture);
pss_updateTeamStatus();
check(pss_pluginSetting('nflOppoID') === '18' && pss_pluginSetting('nflOppoAbbreviation') === 'NO', 'worker repairs the blank Steelers opponent');
check(pss_pluginSetting('nflGameSnapshotEventID') === $fixture['eventID'], 'worker acknowledges repaired pregame snapshot');
check($GLOBALS['pssLastEspnError'] === '', 'valid scheduled response clears refresh failure');
check(pss_statusSnapshotData()['games']['nfl']['oppoName'] === 'New Orleans Saints', 'normal and kiosk status data include the repaired matchup');
$before = count(espnRequests());
pss_updateTeamStatus();
check(count(espnRequests()) === $before, 'frequent worker polls do not repeatedly fetch future games');
pss_drainCelebrations();
check(count(inserted()) === 0, 'repairing a pregame card does not play a celebration');

// A complete saved card used to bypass ESPN until twenty minutes before its
// cached kickoff. It must discover an earlier next event on worker startup.
resetFixture();
$leagues = array('nfl');
$twoTeams = array(upcomingFixture($fixtures[0]), upcomingFixture($fixtures[2]));
foreach ($twoTeams as $i => $selected) {
    $prefix = pss_teamPrefix('nfl', $i + 1);
    pss_setPluginSettings(array($prefix . 'TeamID' => $selected['teamID'], $prefix . 'GameStatus' => 'pre',
        $prefix . 'TeamNextEventID' => $selected['eventID'], $prefix . 'GameSnapshotEventID' => '',
        $prefix . 'OppoID' => '', $prefix . 'Start' => $selected['response']['header']['competitions'][0]['date']));
}
$testHttp = function ($url) use ($twoTeams) {
    foreach ($twoTeams as $selected) {
        if (strpos($url, '/summary?event=' . $selected['eventID']) !== false) return response($selected['response']);
        if (substr($url, -strlen('/teams/' . $selected['teamID'])) === '/teams/' . $selected['teamID']) return response(teamResponse($selected));
    }
    return null;
};
pss_updateTeamStatus();
check(pss_pluginSetting('nflOppoAbbreviation') === 'NO', 'first NFL slot gets Saints opponent');
check(pss_pluginSetting('nfl2OppoAbbreviation') === 'LAR', 'second NFL slot gets Rams opponent independently');
$before = count(espnRequests());
pss_updateTeamStatus();
check(count(espnRequests()) === $before, 'both NFL slots throttle their own future-game refresh');

resetFixture();
$leagues = array('nfl');
pss_setPluginSettings(array('nflTeamNextEventID' => 'later-event', 'nflGameSnapshotEventID' => 'later-event',
    'nflGameStatus' => 'pre', 'nflStart' => date(DATE_ATOM, time() + 21 * 86400),
    'nflOppoID' => '4', 'nflOppoAbbreviation' => 'CIN'));
useUpcomingFixture($fixture);
pss_updateTeamStatus();
check(pss_pluginSetting('nflTeamNextEventID') === $fixture['eventID'], 'a cached later game advances to the actual next event');
check(pss_pluginSetting('nflOppoID') === '18', 'event refresh replaces the previous opponent');
check(pss_pluginSetting('nflStart') === $fixture['response']['header']['competitions'][0]['date'], 'event refresh corrects the kickoff date');
pss_drainCelebrations();
check(count(inserted()) === 0, 'changing a future event never celebrates');

resetFixture();
$leagues = array('nfl');
pss_setPluginSettings(array('nflTeamNextEventID' => $fixture['eventID'], 'nflGameSnapshotEventID' => $fixture['eventID'],
    'nflGameStatus' => 'pre', 'nflStart' => date(DATE_ATOM, time() + 14 * 86400), 'nflOppoID' => '18'));
useUpcomingFixture($fixture);
pss_updateTeamStatus();
check(pss_pluginSetting('nflStart') === $fixture['response']['header']['competitions'][0]['date'], 'same-event kickoff changes refresh before the old kickoff');

resetFixture();
$leagues = array('nfl');
$savedStart = date(DATE_ATOM, time() + 86400);
pss_setPluginSettings(array('nflGameStatus' => 'pre', 'nflStart' => $savedStart, 'nflOppoName' => 'Saved opponent'));
$testHttp = function ($url) { return strpos($url, 'site.api.espn.com') !== false ? response(array(), 503) : null; };
$retry = pss_updateTeamStatus();
check(pss_pluginSetting('nflTeamNextEventID') === 'event-1' && pss_pluginSetting('nflStart') === $savedStart
    && pss_pluginSetting('nflOppoName') === 'Saved opponent', 'failed future refresh preserves saved matchup');
check($retry <= 60 && $GLOBALS['pssLastEspnError'] !== '', 'failed future refresh schedules a bounded retry and reports failure');
$before = count(espnRequests());
pss_updateTeamStatus();
check(count(espnRequests()) === $before, 'failed future refresh is not retried on every live-loop tick');

resetFixture();
pss_setPluginSettings(array('nflTeamNextEventID' => $fixture['eventID'], 'nflGameStatus' => 'pre'));
$testHttp = function ($url) use ($fixture) {
    if (strpos($url, '/teams/') !== false) return response(teamResponse($fixture));
    if (strpos($url, '/summary?event=') !== false) {
        pss_setPluginSetting('nflTeamID', '24');
        return response($fixture['response']);
    }
    return null;
};
check(pss_refreshUpcomingGame('football', 'nfl', '23') === null, 'team changed during request discards the old response');
check(pss_pluginSetting('nflOppoID') === '1', 'old request does not overwrite a newly selected team snapshot');

// An early kickoff or stale kickoff time must still use the normal scoring
// path when refreshing the same event: never baseline away a new touchdown.
foreach (array('in' => 1, 'post' => 2) as $state => $expected) {
    resetFixture();
    $leagues = array('nfl');
    $live = $fixture;
    $competition =& $live['response']['header']['competitions'][0];
    $competition['status']['type']['state'] = $state;
    foreach ($competition['competitors'] as &$competitor) $competitor['score'] = $competitor['team']['id'] === '23' ? '6' : '0';
    unset($competition, $competitor);
    pss_setPluginSettings(array('nflTeamNextEventID' => $fixture['eventID'], 'nflGameSnapshotEventID' => $fixture['eventID'],
        'nflGameStatus' => 'pre', 'nflStart' => date(DATE_ATOM, time() + 86400), 'nflOppoID' => '18'));
    useUpcomingFixture($live);
    pss_updateTeamStatus();
    pss_drainCelebrations();
    pss_drainCelebrations();
    check(count(inserted()) === $expected, "$state refresh of same event retains touchdown/win scoring");
    pss_updateTeamStatus();
    pss_drainCelebrations();
    check(count(inserted()) === $expected, "$state repeated poll never replays the score");
}

// Missing/empty pregame scores may be zero; malformed scores must still be
// rejected, and in-progress/final scores must always be present and numeric.
foreach (array('pre', 'in', 'post') as $state) {
    foreach (array(null, '', 'not-a-score', array('value' => 0)) as $value) {
        resetFixture();
        $data = $fixtures[0]['response'];
        $data['header']['competitions'][0]['status']['type']['state'] = $state;
        foreach ($data['header']['competitions'][0]['competitors'] as &$competitor) $competitor['score'] = $value;
        unset($competitor);
        $testHttp = function ($url) use ($data) { return response($data); };
        $parsed = pss_getGameStatus('football', 'nfl', $fixtures[0]['eventID'], '23');
        check($parsed['valid'] === ($state === 'pre' && ($value === null || $value === '')), "$state validates optional versus malformed scores");
    }
}

// ESPN currently marks the Braves' future kickoff TBD. Its placeholder
// midnight must not become a displayed or scheduled start time.
resetFixture();
$braves = $fixtures[4];
useUpcomingFixture($braves);
$game = pss_getGameStatus('baseball', 'mlb', $braves['eventID'], '15');
check($game['valid'] && $game['timeValid'] === false, 'MLB pregame preserves ESPN unknown-time flag');
pss_setPluginSetting('mlbTeamID', '15');
pss_baselineGameSnapshot('mlb', $braves['eventID'], $game);
check(pss_pluginSetting('mlbStartTimeValid') === 'OFF', 'unknown-time flag survives settings storage');
$label = pss_formatStart($game['start'], false);
check(strpos($label, 'February 19') !== false && strpos($label, 'Time TBD') !== false && strpos($label, '12:00 AM') === false, 'status keeps the game date and displays Time TBD');
check(strpos(pss_statusSnapshotData()['games']['mlb']['startFormatted'], 'Time TBD') !== false, 'refresh JSON also displays Time TBD');
check(strpos(pss_tickerStartLabel($game['start'], false), 'Time TBD') !== false, 'ticker avoids placeholder midnight');
check(pss_gameScheduleWindow('mlb') === null, 'unknown kickoff cannot create a midnight game-time schedule');
check(pss_getTeamInfo('baseball', 'mlb', '15')['nextEventTimeValid'] === false, 'team endpoint also retains unknown kickoff flag');
check(strpos(pss_formatStart($fixtures[0]['response']['header']['competitions'][0]['date']), '9:30 AM') !== false, 'known kickoff retains its local time');

echo 'PASS ESPN pregame fixtures: ' . $assertions . " assertions\n";
