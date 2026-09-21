<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";

$pluginName = basename(dirname(__FILE__));
$pluginConfigFile = $settings['configDirectory'] . "/plugin." . $pluginName;
$logFile = $settings['logDirectory'] . "/plugin-" . $pluginName . ".log";
$leagues = array('nfl', 'ncaa', 'nhl', 'mlb');
$pluginSettings = pss_loadPluginSettings();

if (isset($_POST['action']) && !empty($_POST['action'])) {
    switch ($_POST['action']) {
        case 'updateNFLTeam':
            pss_updateTeam('football', 'nfl');
            break;
        case 'updateNCAATeam':
            pss_updateTeam('football', 'ncaa');
            break;
        case 'updateNHLTeam':
            pss_updateTeam('hockey', 'nhl');
            break;
        case 'updateMLBTeam':
            pss_updateTeam('baseball', 'mlb');
            break;
    }
}

function pss_loadPluginSettings() {
    global $pluginConfigFile;
    if (!file_exists($pluginConfigFile)) {
        return array();
    }
    $data = parse_ini_file($pluginConfigFile);
    return is_array($data) ? $data : array();
}

function pss_pluginSetting($key, $default = '') {
    global $pluginSettings;
    if (!is_array($pluginSettings) || !array_key_exists($key, $pluginSettings)) {
        return $default;
    }
    return urldecode((string)$pluginSettings[$key]);
}

function pss_setPluginSetting($key, $value) {
    global $pluginName, $pluginSettings;
    $value = (string)$value;
    if (WriteSettingToFile($key, $value, $pluginName)) {
        if (!is_array($pluginSettings)) {
            $pluginSettings = array();
        }
        $pluginSettings[$key] = $value;
        return true;
    }
    pss_logEntry("Unable to save setting {$key}");
    return false;
}

function pss_leagueInfo($league) {
    switch ($league) {
        case 'nfl':
            return array('sport' => 'football', 'espnLeague' => 'nfl');
        case 'ncaa':
            return array('sport' => 'football', 'espnLeague' => 'college-football');
        case 'nhl':
            return array('sport' => 'hockey', 'espnLeague' => 'nhl');
        case 'mlb':
            return array('sport' => 'baseball', 'espnLeague' => 'mlb');
        default:
            return array('sport' => '', 'espnLeague' => '');
    }
}

function pss_httpJson($url, $method = 'GET', $body = null) {
    $headers = "User-Agent: Mozilla/5.0 (compatible; FPP-Pro-Sports-Scoring/2.0)\r\nAccept: application/json\r\n";
    $options = array(
        'http' => array(
            'method' => $method,
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => $headers
        )
    );
    if ($body !== null) {
        $options['http']['header'] .= "Content-Type: application/json\r\n";
        $options['http']['content'] = json_encode($body);
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === false || $result === '') {
        return null;
    }
    $data = json_decode($result, true);
    return is_array($data) ? $data : null;
}

function pss_getTeams($sport = 'football', $league = 'nfl') {
    $espnLeague = ($league === 'ncaa') ? 'college-football' : $league;
    $suffix = ($league === 'ncaa') ? '?limit=1000' : '';
    $url = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$espnLeague}/teams{$suffix}";
    $data = pss_httpJson($url);
    $teamNames = array('No team' => '');

    if (!is_array($data) || !isset($data['sports'][0]['leagues'][0]['teams']) || !is_array($data['sports'][0]['leagues'][0]['teams'])) {
        pss_logEntry("Unable to load {$league} teams from ESPN");
        return $teamNames;
    }

    foreach ($data['sports'][0]['leagues'][0]['teams'] as $teamEntry) {
        if (!isset($teamEntry['team']) || !is_array($teamEntry['team'])) {
            continue;
        }
        $team = $teamEntry['team'];
        if (!isset($team['displayName'], $team['id'])) {
            continue;
        }
        $teamNames[$team['displayName']] = (string)$team['id'];
    }

    unset($teamNames['No team']);
    ksort($teamNames, SORT_NATURAL | SORT_FLAG_CASE);
    return array('No team' => '') + $teamNames;
}

function pss_getNCAATeams() {
    return pss_getTeams('football', 'ncaa');
}

function pss_getSequences() {
    $data = pss_httpJson('http://127.0.0.1/api/sequence/');
    $sequenceList = array('No Sequence' => '');
    if (!is_array($data)) {
        return $sequenceList;
    }
    foreach ($data as $sequence) {
        if (is_string($sequence) && $sequence !== '') {
            $sequenceList[$sequence] = $sequence;
        }
    }
    ksort($sequenceList, SORT_NATURAL | SORT_FLAG_CASE);
    return array('No Sequence' => '') + array_diff_key($sequenceList, array('No Sequence' => ''));
}

function pss_getTeamInfo($sport, $league, $team) {
    $info = array(
        'valid' => false,
        'logo' => '',
        'abbreviation' => '',
        'name' => '',
        'nextEventID' => '',
        'nextEventDate' => '',
        'nextEventStatus' => ''
    );
    if ($team === '') {
        return $info;
    }

    $espnLeague = ($league === 'ncaa') ? 'college-football' : $league;
    $url = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$espnLeague}/teams/" . rawurlencode($team);
    $data = pss_httpJson($url);
    if (!is_array($data) || !isset($data['team']) || !is_array($data['team'])) {
        return $info;
    }

    $teamData = $data['team'];
    $info['valid'] = true;
    $info['logo'] = isset($teamData['logos'][0]['href']) ? (string)$teamData['logos'][0]['href'] : '';
    $info['abbreviation'] = isset($teamData['abbreviation']) ? (string)$teamData['abbreviation'] : '';
    $info['name'] = isset($teamData['displayName']) ? (string)$teamData['displayName'] : '';

    if (isset($teamData['nextEvent'][0]) && is_array($teamData['nextEvent'][0])) {
        $event = $teamData['nextEvent'][0];
        $info['nextEventID'] = isset($event['id']) ? (string)$event['id'] : '';
        $info['nextEventDate'] = isset($event['date']) ? (string)$event['date'] : '';
        if (isset($event['competitions'][0]['status']['type']['state'])) {
            $info['nextEventStatus'] = (string)$event['competitions'][0]['status']['type']['state'];
        }
    }
    return $info;
}

function pss_getGameStatus($sport, $league, $gameID, $teamID) {
    $status = array(
        'valid' => false,
        'start' => '',
        'state' => '',
        'oppoID' => '',
        'oppoAbbreviation' => '',
        'oppoName' => '',
        'myScore' => 0,
        'oppoScore' => 0,
        'scoringPlays' => array()
    );
    if ($gameID === '' || $teamID === '') {
        return $status;
    }

    $espnLeague = ($league === 'ncaa') ? 'college-football' : $league;
    $url = "https://site.api.espn.com/apis/site/v2/sports/{$sport}/{$espnLeague}/summary?event=" . rawurlencode($gameID);
    $data = pss_httpJson($url);
    if (!is_array($data) || !isset($data['header']['competitions'][0]) || !is_array($data['header']['competitions'][0])) {
        return $status;
    }

    $competition = $data['header']['competitions'][0];
    if (!isset($competition['competitors']) || !is_array($competition['competitors'])) {
        return $status;
    }

    $myTeam = null;
    $opponent = null;
    foreach ($competition['competitors'] as $competitor) {
        if (!isset($competitor['team']) || !is_array($competitor['team']) || !isset($competitor['team']['id'])) {
            continue;
        }
        if ((string)$competitor['team']['id'] === (string)$teamID) {
            $myTeam = $competitor;
        } else {
            $opponent = $competitor;
        }
    }
    if ($myTeam === null || $opponent === null) {
        return $status;
    }

    $status['start'] = isset($competition['date']) ? (string)$competition['date'] : '';
    $status['state'] = isset($competition['status']['type']['state']) ? (string)$competition['status']['type']['state'] : '';
    $status['oppoID'] = isset($opponent['team']['id']) ? (string)$opponent['team']['id'] : '';
    $status['oppoAbbreviation'] = isset($opponent['team']['abbreviation']) ? (string)$opponent['team']['abbreviation'] : '';
    $status['oppoName'] = isset($opponent['team']['displayName']) ? (string)$opponent['team']['displayName'] : '';
    $status['myScore'] = isset($myTeam['score']) ? (int)$myTeam['score'] : 0;
    $status['oppoScore'] = isset($opponent['score']) ? (int)$opponent['score'] : 0;
    $status['scoringPlays'] = isset($data['scoringPlays']) && is_array($data['scoringPlays']) ? $data['scoringPlays'] : array();
    $status['valid'] = ($status['state'] !== '');
    return $status;
}

function pss_latestScoringPlayID($plays) {
    if (!is_array($plays) || count($plays) === 0) {
        return '';
    }
    for ($i = count($plays) - 1; $i >= 0; $i--) {
        if (isset($plays[$i]['id'])) {
            return (string)$plays[$i]['id'];
        }
    }
    return '';
}

function pss_updateTeam($sport, $league) {
    global $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();
    $teamID = pss_pluginSetting("{$league}TeamID", '');

    if ($teamID === '') {
        pss_clearLeagueState($league, true);
        pss_logEntry(strtoupper($league) . ' team cleared');
        return '';
    }

    $teamInfo = pss_getTeamInfo($sport, $league, $teamID);
    if (!$teamInfo['valid']) {
        pss_logEntry("{$league} team update failed; keeping existing state");
        return pss_pluginSetting("{$league}TeamLogo", '');
    }

    pss_setPluginSetting("{$league}TeamLogo", $teamInfo['logo']);
    pss_setPluginSetting("{$league}TeamAbbreviation", $teamInfo['abbreviation']);
    pss_setPluginSetting("{$league}TeamName", $teamInfo['name']);
    pss_setPluginSetting("{$league}TeamNextEventID", $teamInfo['nextEventID']);
    pss_setPluginSetting("{$league}Start", $teamInfo['nextEventDate']);
    pss_setPluginSetting("{$league}GameStatus", $teamInfo['nextEventStatus']);
    pss_setPluginSetting("{$league}OppoID", '');
    pss_setPluginSetting("{$league}OppoName", '');
    pss_setPluginSetting("{$league}OppoAbbreviation", '');
    pss_setPluginSetting("{$league}MyScore", '0');
    pss_setPluginSetting("{$league}OppoScore", '0');
    pss_setPluginSetting("{$league}LastScoringPlayID", '');
    pss_setPluginSetting("{$league}LastCelebratedScore", '0');
    pss_setPluginSetting("{$league}LastCompletedEventID", '');

    if ($teamInfo['nextEventID'] !== '') {
        $game = pss_getGameStatus($sport, $league, $teamInfo['nextEventID'], $teamID);
        if ($game['valid']) {
            pss_applyGameSnapshot($league, $game, false);
            pss_setPluginSetting("{$league}LastScoringPlayID", pss_latestScoringPlayID($game['scoringPlays']));
            pss_setPluginSetting("{$league}LastCelebratedScore", (string)$game['myScore']);
        }
    }

    pss_logEntry("{$league} team updated to {$teamInfo['name']}");
    return $teamInfo['logo'];
}

function pss_clearLeagueState($league, $clearTeam = false) {
    $keys = array(
        'TeamLogo' => '', 'TeamAbbreviation' => '', 'TeamName' => '', 'TeamNextEventID' => '',
        'Start' => '', 'GameStatus' => '', 'OppoID' => '', 'OppoName' => '', 'OppoAbbreviation' => '',
        'MyScore' => '0', 'OppoScore' => '0', 'LastScoringPlayID' => '', 'LastCelebratedScore' => '0',
        'LastCompletedEventID' => ''
    );
    if ($clearTeam) {
        $keys['TeamID'] = '';
    }
    foreach ($keys as $suffix => $value) {
        pss_setPluginSetting("{$league}{$suffix}", $value);
    }
}

function pss_applyGameSnapshot($league, $status, $updateStatus = true) {
    pss_setPluginSetting("{$league}Start", $status['start']);
    pss_setPluginSetting("{$league}OppoID", $status['oppoID']);
    pss_setPluginSetting("{$league}OppoName", $status['oppoName']);
    pss_setPluginSetting("{$league}OppoAbbreviation", $status['oppoAbbreviation']);
    pss_setPluginSetting("{$league}MyScore", (string)$status['myScore']);
    pss_setPluginSetting("{$league}OppoScore", (string)$status['oppoScore']);
    if ($updateStatus) {
        pss_setPluginSetting("{$league}GameStatus", $status['state']);
    }
}

function pss_processFootballScoring($league, $teamID, $plays) {
    $lastID = pss_pluginSetting("{$league}LastScoringPlayID", '');
    if (!is_array($plays) || count($plays) === 0) {
        return;
    }

    if ($lastID === '') {
        pss_setPluginSetting("{$league}LastScoringPlayID", pss_latestScoringPlayID($plays));
        return;
    }

    $seenLast = false;
    $newPlays = array();
    foreach ($plays as $play) {
        $id = isset($play['id']) ? (string)$play['id'] : '';
        if ($id === $lastID) {
            $seenLast = true;
            $newPlays = array();
            continue;
        }
        if ($seenLast) {
            $newPlays[] = $play;
        }
    }

    if (!$seenLast) {
        pss_setPluginSetting("{$league}LastScoringPlayID", pss_latestScoringPlayID($plays));
        pss_logEntry("{$league} scoring-play marker was no longer in ESPN response; re-baselined safely");
        return;
    }

    foreach ($newPlays as $play) {
        if (!isset($play['team']['id']) || (string)$play['team']['id'] !== (string)$teamID) {
            continue;
        }
        $typeText = isset($play['type']['text']) ? strtolower((string)$play['type']['text']) : '';
        $playText = isset($play['text']) ? strtolower((string)$play['text']) : '';
        $haystack = $typeText . ' ' . $playText;

        if (strpos($haystack, 'touchdown') !== false) {
            pss_playConfiguredSequence($league, 'TouchdownSequence', 'Touchdown');
        } elseif (strpos($haystack, 'field goal') !== false && strpos($haystack, 'no good') === false && strpos($haystack, 'miss') === false) {
            pss_playConfiguredSequence($league, 'FieldgoalSequence', 'Field goal');
        }
    }

    pss_setPluginSetting("{$league}LastScoringPlayID", pss_latestScoringPlayID($plays));
}

function pss_processSimpleScoreIncrease($league, $oldScore, $newScore) {
    $oldScore = (int)$oldScore;
    $newScore = (int)$newScore;
    $lastCelebrated = (int)pss_pluginSetting("{$league}LastCelebratedScore", '0');

    if ($newScore > $oldScore && $newScore > $lastCelebrated) {
        pss_playConfiguredSequence($league, 'ScoreSequence', 'Score');
        pss_setPluginSetting("{$league}LastCelebratedScore", (string)$newScore);
    } elseif ($newScore > $lastCelebrated) {
        pss_setPluginSetting("{$league}LastCelebratedScore", (string)$newScore);
    }
}

function pss_playConfiguredSequence($league, $suffix, $label) {
    $sequence = pss_pluginSetting("{$league}{$suffix}", '');
    if ($sequence === '') {
        pss_logEntry("{$league} {$label} detected but no sequence is selected");
        return;
    }
    if (pss_insertPlaylistImmediate($sequence)) {
        pss_logEntry("{$league} {$label} detected; played {$sequence}");
    } else {
        pss_logEntry("{$league} {$label} detected but FPP rejected sequence {$sequence}");
    }
}

function pss_updateTeamStatus($reparseSettings = true) {
    global $pluginSettings, $leagues;
    if ($reparseSettings) {
        $pluginSettings = pss_loadPluginSettings();
    }

    $sleepTimes = array('nfl' => 600, 'ncaa' => 600, 'nhl' => 600, 'mlb' => 600);
    $logLevel = (int)pss_pluginSetting('logLevel', '4');

    foreach ($leagues as $league) {
        $teamID = pss_pluginSetting("{$league}TeamID", '');
        if ($teamID === '') {
            continue;
        }

        $info = pss_leagueInfo($league);
        $sport = $info['sport'];
        $eventID = pss_pluginSetting("{$league}TeamNextEventID", '');
        $gameState = pss_pluginSetting("{$league}GameStatus", '');
        $start = pss_pluginSetting("{$league}Start", '');

        if ($eventID === '') {
            $newInfo = pss_getTeamInfo($sport, $league, $teamID);
            if ($newInfo['valid'] && $newInfo['nextEventID'] !== '') {
                pss_setPluginSetting("{$league}TeamNextEventID", $newInfo['nextEventID']);
                pss_setPluginSetting("{$league}Start", $newInfo['nextEventDate']);
                pss_setPluginSetting("{$league}GameStatus", $newInfo['nextEventStatus']);
                $eventID = $newInfo['nextEventID'];
                $gameState = $newInfo['nextEventStatus'];
            } else {
                continue;
            }
        }

        if ($gameState === 'post') {
            $newInfo = pss_getTeamInfo($sport, $league, $teamID);
            if ($newInfo['valid'] && $newInfo['nextEventID'] !== '' && $newInfo['nextEventID'] !== $eventID) {
                pss_setPluginSetting("{$league}TeamNextEventID", $newInfo['nextEventID']);
                pss_setPluginSetting("{$league}Start", $newInfo['nextEventDate']);
                pss_setPluginSetting("{$league}GameStatus", $newInfo['nextEventStatus']);
                pss_setPluginSetting("{$league}MyScore", '0');
                pss_setPluginSetting("{$league}OppoScore", '0');
                pss_setPluginSetting("{$league}LastScoringPlayID", '');
                pss_setPluginSetting("{$league}LastCelebratedScore", '0');
                pss_setPluginSetting("{$league}LastCompletedEventID", '');
                pss_logEntry("{$league} next event changed to {$newInfo['nextEventID']}");
            }
            continue;
        }

        if ($gameState === 'pre' && $start !== '') {
            try {
                $gameDate = new DateTime($start);
                $secondsToGame = $gameDate->getTimestamp() - time();
                if ($secondsToGame > 1200) {
                    $sleepTimes[$league] = min(600, max(60, $secondsToGame - 900));
                    continue;
                }
            } catch (Exception $e) {
                pss_logEntry("{$league} has invalid start time; polling ESPN for correction");
            }
        }

        $status = pss_getGameStatus($sport, $league, $eventID, $teamID);
        if (!$status['valid']) {
            pss_logEntry("{$league} ESPN game status request failed; keeping existing game state");
            $sleepTimes[$league] = 30;
            continue;
        }

        if ($logLevel >= 5) {
            pss_logEntry("{$league} poll: state={$status['state']} score={$status['myScore']}-{$status['oppoScore']}");
        }

        $oldScore = (int)pss_pluginSetting("{$league}MyScore", '0');
        if ($status['state'] === 'in') {
            if ($sport === 'football') {
                pss_processFootballScoring($league, $teamID, $status['scoringPlays']);
            } else {
                pss_processSimpleScoreIncrease($league, $oldScore, $status['myScore']);
            }
            $sleepTimes[$league] = 10;
        } elseif ($status['state'] === 'pre') {
            $sleepTimes[$league] = 30;
        } elseif ($status['state'] === 'post') {
            $completed = pss_pluginSetting("{$league}LastCompletedEventID", '');
            if ($completed !== $eventID) {
                if ($status['myScore'] > $status['oppoScore']) {
                    pss_playConfiguredSequence($league, 'WinSequence', 'Win');
                }
                pss_setPluginSetting("{$league}LastCompletedEventID", $eventID);
            }
            $sleepTimes[$league] = 600;
        }

        pss_applyGameSnapshot($league, $status, true);
    }

    return min($sleepTimes);
}

function pss_insertPlaylistImmediate($sequence) {
    $sequence = trim((string)$sequence);
    if ($sequence === '') {
        return false;
    }
    if (substr($sequence, -5) !== '.fseq') {
        $sequence .= '.fseq';
    }

    $payload = array(
        'command' => 'Insert Playlist Immediate',
        'multisyncCommand' => false,
        'multisyncHosts' => '',
        'args' => array($sequence, '0', '0', 'false')
    );
    $result = pss_httpJson('http://127.0.0.1/api/command', 'POST', $payload);
    return $result !== null;
}

function pss_logEntry($message) {
    global $logFile;
    $pid = getmypid();
    $line = date('c') . " [{$pid}] " . trim((string)$message) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
?>
