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
        case 'syncSequencePlaylist':
            if (isset($_POST['setting'])) {
                pss_syncGeneratedPlaylistSetting((string)$_POST['setting']);
            }
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

function pss_httpRequest($url, $method = 'GET', $body = null, $accept = 'application/json') {
    $headers = array();
    if ($accept !== '') {
        $headers[] = 'Accept: ' . $accept;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $curlInfo = curl_version();
        $curlVersion = isset($curlInfo['version']) ? $curlInfo['version'] : '8.0.0';
        curl_setopt($ch, CURLOPT_USERAGENT, 'curl/' . $curlVersion);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $result = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            pss_logEntry("HTTP request failed for {$url}: {$curlError}");
            return array('ok' => false, 'status' => $httpCode, 'body' => '', 'contentType' => $contentType);
        }

        return array(
            'ok' => ($httpCode >= 200 && $httpCode < 300),
            'status' => $httpCode,
            'body' => (string)$result,
            'contentType' => $contentType
        );
    }

    $streamHeaders = $headers;
    $streamHeaders[] = 'User-Agent: curl/8.0.0';
    if ($body !== null) {
        $streamHeaders[] = 'Content-Type: application/json';
    }

    $options = array(
        'http' => array(
            'method' => $method,
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => implode("\r\n", $streamHeaders) . "\r\n"
        )
    );
    if ($body !== null) {
        $options['http']['content'] = json_encode($body);
    }

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\\s(\\d{3})\\s/', $http_response_header[0], $matches)) {
        $status = (int)$matches[1];
    }
    if ($result === false) {
        pss_logEntry("HTTP request failed for {$url} using PHP stream fallback");
        return array('ok' => false, 'status' => $status, 'body' => '', 'contentType' => '');
    }

    return array(
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'body' => (string)$result,
        'contentType' => ''
    );
}

function pss_httpJson($url, $method = 'GET', $body = null) {
    $response = pss_httpRequest($url, $method, $body, 'application/json');
    if (!$response['ok']) {
        pss_logEntry("HTTP {$response['status']} returned for {$url}");
        return null;
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        pss_logEntry("Invalid JSON returned for {$url}: " . json_last_error_msg());
        return null;
    }

    return $data;
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
    global $settings;

    $sequenceList = array('No Sequence' => '');
    $sequenceDirectory = isset($settings['sequenceDirectory']) ? rtrim((string)$settings['sequenceDirectory'], '/') : '/home/fpp/media/sequences';
    if (!is_dir($sequenceDirectory)) {
        pss_logEntry("FPP sequence directory not found: {$sequenceDirectory}");
        return $sequenceList;
    }

    $files = glob($sequenceDirectory . '/*.fseq');
    if (!is_array($files)) {
        return $sequenceList;
    }

    $sequences = array();
    foreach ($files as $file) {
        $filename = basename($file);
        $label = preg_replace('/\.fseq$/i', '', $filename);
        if ($filename !== '' && $label !== '') {
            $sequences[$label] = $filename;
        }
    }

    ksort($sequences, SORT_NATURAL | SORT_FLAG_CASE);
    return $sequenceList + $sequences;
}

function pss_generatedPlaylistMarker() {
    return 'Managed by Pro Sports Scoring plugin. Do not edit manually.';
}

function pss_generatedPlaylistType($sequenceSuffix) {
    switch ($sequenceSuffix) {
        case 'TouchdownSequence':
            return 'Touchdown';
        case 'FieldgoalSequence':
            return 'FieldGoal';
        case 'ScoreSequence':
            return 'Score';
        case 'WinSequence':
            return 'Win';
        default:
            return '';
    }
}

function pss_safePlaylistPart($value, $fallback = 'TEAM') {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : $fallback;
}

function pss_generatedPlaylistName($league, $sequenceSuffix) {
    $kind = pss_generatedPlaylistType($sequenceSuffix);
    if ($kind === '') {
        return '';
    }

    $teamPart = pss_pluginSetting("{$league}TeamAbbreviation", '');
    if ($teamPart === '') {
        $teamPart = pss_pluginSetting("{$league}TeamID", '');
    }
    if ($teamPart === '') {
        return '';
    }

    return 'PSS_' . pss_safePlaylistPart($league, 'LEAGUE') . '_' . pss_safePlaylistPart($teamPart, 'TEAM') . '_' . $kind;
}

function pss_sequenceDuration($sequenceName) {
    global $settings;

    $filename = basename((string)$sequenceName);
    $sequenceDirectory = isset($settings['sequenceDirectory']) ? rtrim((string)$settings['sequenceDirectory'], '/') : '/home/fpp/media/sequences';
    $path = $sequenceDirectory . '/' . $filename;
    if ($filename === '' || !is_file($path)) {
        return 0.0;
    }

    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return 0.0;
    }
    $header = fread($fh, 19);
    fclose($fh);

    if (!is_string($header) || strlen($header) < 19 || substr($header, 0, 4) !== 'PSEQ') {
        return 0.0;
    }

    $frameData = unpack('Vframes', substr($header, 14, 4));
    $frames = isset($frameData['frames']) ? (int)$frameData['frames'] : 0;
    $stepMs = ord($header[18]);
    if ($frames <= 0 || $stepMs <= 0) {
        return 0.0;
    }

    return round(($frames * $stepMs) / 1000, 3);
}

function pss_generatedPlaylistData($playlistName, $sequenceName) {
    $duration = pss_sequenceDuration($sequenceName);
    return array(
        'name' => $playlistName,
        'version' => 4,
        'repeat' => 0,
        'loopCount' => 0,
        'desc' => pss_generatedPlaylistMarker(),
        'random' => 0,
        'globalPauseBetweenSequencesMS' => 0,
        'empty' => false,
        'leadIn' => array(),
        'mainPlaylist' => array(
            array(
                'type' => 'sequence',
                'enabled' => 1,
                'playOnce' => 0,
                'sequenceName' => basename((string)$sequenceName),
                'displayMode' => 'argsOnly',
                'timecode' => 'Default',
                'duration' => $duration
            )
        ),
        'leadOut' => array(),
        'playlistInfo' => array(
            'leadIn_duration' => 0,
            'leadIn_items' => 0,
            'mainPlaylist_duration' => $duration,
            'mainPlaylist_items' => 1,
            'leadOut_duration' => 0,
            'leadOut_items' => 0,
            'total_duration' => $duration,
            'total_items' => 1
        )
    );
}

function pss_writeGeneratedPlaylist($playlistName, $sequenceName) {
    global $settings;

    $playlistName = trim((string)$playlistName);
    $sequenceName = basename((string)$sequenceName);
    if ($playlistName === '' || $sequenceName === '') {
        return false;
    }

    $sequenceDirectory = isset($settings['sequenceDirectory']) ? rtrim((string)$settings['sequenceDirectory'], '/') : '/home/fpp/media/sequences';
    if (!is_file($sequenceDirectory . '/' . $sequenceName)) {
        pss_logEntry("Cannot build {$playlistName}; sequence not found: {$sequenceName}");
        return false;
    }

    $playlistDirectory = isset($settings['playlistDirectory']) ? rtrim((string)$settings['playlistDirectory'], '/') : '/home/fpp/media/playlists';
    if (!is_dir($playlistDirectory) || !is_writable($playlistDirectory)) {
        pss_logEntry("Cannot write generated playlist; directory is not writable: {$playlistDirectory}");
        return false;
    }

    $data = pss_generatedPlaylistData($playlistName, $sequenceName);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        pss_logEntry("Unable to encode generated playlist {$playlistName}");
        return false;
    }
    $json .= "\n";

    $path = $playlistDirectory . '/' . $playlistName . '.json';
    $existing = is_file($path) ? @file_get_contents($path) : false;
    if ($existing === $json) {
        return true;
    }

    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        pss_logEntry("Unable to create generated playlist {$playlistName}");
        return false;
    }

    @chmod($path, 0664);
    pss_logEntry("Generated playlist {$playlistName} for sequence {$sequenceName}");
    return true;
}

function pss_isManagedGeneratedPlaylist($path) {
    $json = @file_get_contents($path);
    if ($json === false) {
        return false;
    }
    $data = json_decode($json, true);
    return is_array($data) && isset($data['desc']) && (string)$data['desc'] === pss_generatedPlaylistMarker();
}

function pss_cleanupGeneratedPlaylists($league, $keepNames) {
    global $settings;

    $playlistDirectory = isset($settings['playlistDirectory']) ? rtrim((string)$settings['playlistDirectory'], '/') : '/home/fpp/media/playlists';
    if (!is_dir($playlistDirectory)) {
        return;
    }

    $prefix = 'PSS_' . pss_safePlaylistPart($league, 'LEAGUE') . '_';
    $files = glob($playlistDirectory . '/' . $prefix . '*.json');
    if (!is_array($files)) {
        return;
    }

    $keepLookup = array_fill_keys($keepNames, true);
    foreach ($files as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (isset($keepLookup[$name])) {
            continue;
        }
        if (pss_isManagedGeneratedPlaylist($path) && @unlink($path)) {
            pss_logEntry("Removed stale generated playlist {$name}");
        }
    }
}

function pss_syncGeneratedPlaylistsForLeague($league) {
    $info = pss_leagueInfo($league);
    if ($info['sport'] === '') {
        return;
    }

    if (pss_pluginSetting("{$league}TeamID", '') === '') {
        pss_cleanupGeneratedPlaylists($league, array());
        return;
    }

    $suffixes = ($info['sport'] === 'football')
        ? array('TouchdownSequence', 'FieldgoalSequence', 'WinSequence')
        : array('ScoreSequence', 'WinSequence');

    $keepNames = array();
    foreach ($suffixes as $suffix) {
        $sequence = pss_pluginSetting("{$league}{$suffix}", '');
        if ($sequence === '') {
            continue;
        }
        $playlistName = pss_generatedPlaylistName($league, $suffix);
        if ($playlistName === '') {
            continue;
        }
        if (pss_writeGeneratedPlaylist($playlistName, $sequence)) {
            $keepNames[] = $playlistName;
        }
    }

    pss_cleanupGeneratedPlaylists($league, $keepNames);
}

function pss_syncGeneratedPlaylistSetting($setting) {
    global $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();

    if (!preg_match('/^(nfl|ncaa|nhl|mlb)(TouchdownSequence|FieldgoalSequence|ScoreSequence|WinSequence)$/', (string)$setting, $matches)) {
        return false;
    }

    pss_syncGeneratedPlaylistsForLeague($matches[1]);
    return true;
}

function pss_syncAllGeneratedPlaylists() {
    global $leagues;
    foreach ($leagues as $league) {
        pss_syncGeneratedPlaylistsForLeague($league);
    }
}

function pss_extractTeamLogo($teamData) {
    if (!is_array($teamData)) {
        return '';
    }

    if (isset($teamData['logos'][0]['href']) && is_string($teamData['logos'][0]['href'])) {
        return (string)$teamData['logos'][0]['href'];
    }
    if (isset($teamData['logos'][0]) && is_string($teamData['logos'][0])) {
        return (string)$teamData['logos'][0];
    }
    if (isset($teamData['logo']) && is_string($teamData['logo'])) {
        return (string)$teamData['logo'];
    }
    if (isset($teamData['logo']['href']) && is_string($teamData['logo']['href'])) {
        return (string)$teamData['logo']['href'];
    }

    return '';
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
    $info['logo'] = pss_extractTeamLogo($teamData);
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
        'oppoLogo' => '',
        'detail' => '',
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
    $status['oppoLogo'] = pss_extractTeamLogo($opponent['team']);
    if (isset($competition['status']['type']['shortDetail'])) {
        $status['detail'] = (string)$competition['status']['type']['shortDetail'];
    } elseif (isset($competition['status']['type']['detail'])) {
        $status['detail'] = (string)$competition['status']['type']['detail'];
    } elseif (isset($competition['status']['displayClock'])) {
        $status['detail'] = (string)$competition['status']['displayClock'];
    }
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


function pss_clearGameSnapshotState($league) {
    $keys = array(
        'OppoID' => '',
        'OppoName' => '',
        'OppoAbbreviation' => '',
        'OppoLogo' => '',
        'GameDetail' => '',
        'MyScore' => '0',
        'OppoScore' => '0',
        'LastScoringPlayID' => '',
        'LastCelebratedScore' => '0',
        'LastCompletedEventID' => '',
        'GameSnapshotEventID' => ''
    );
    foreach ($keys as $suffix => $value) {
        pss_setPluginSetting("{$league}{$suffix}", $value);
    }
}

function pss_baselineGameSnapshot($league, $eventID, $game) {
    if (!is_array($game) || empty($game['valid'])) {
        return false;
    }

    pss_applyGameSnapshot($league, $game, true, $eventID);
    pss_setPluginSetting("{$league}LastScoringPlayID", pss_latestScoringPlayID($game['scoringPlays']));
    pss_setPluginSetting("{$league}LastCelebratedScore", (string)$game['myScore']);

    // If we discover an already-finished game while repairing or changing events,
    // mark it completed so a historical win sequence is not fired on startup.
    if ($game['state'] === 'post') {
        pss_setPluginSetting("{$league}LastCompletedEventID", (string)$eventID);
    }

    return true;
}

function pss_activateEventFromTeamInfo($league, $sport, $teamID, $teamInfo) {
    if (!is_array($teamInfo) || empty($teamInfo['valid']) || empty($teamInfo['nextEventID'])) {
        return false;
    }

    $eventID = (string)$teamInfo['nextEventID'];

    // Treat an event change as an atomic game rollover. Clear every game-specific
    // field before writing the new event so old opponent/score/detail data can
    // never be displayed under a new ESPN event ID.
    pss_setPluginSetting("{$league}TeamNextEventID", $eventID);
    pss_setPluginSetting("{$league}Start", isset($teamInfo['nextEventDate']) ? (string)$teamInfo['nextEventDate'] : '');
    pss_setPluginSetting("{$league}GameStatus", isset($teamInfo['nextEventStatus']) ? (string)$teamInfo['nextEventStatus'] : '');
    pss_clearGameSnapshotState($league);

    // Fetch one full summary immediately, even when the next game is days away.
    // This populates the opponent, logo, score, and detail instead of leaving
    // stale values visible until the normal pregame polling window.
    $game = pss_getGameStatus($sport, $league, $eventID, $teamID);
    if ($game['valid']) {
        pss_baselineGameSnapshot($league, $eventID, $game);
    }

    return true;
}

function pss_updateTeam($sport, $league) {
    global $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();
    $teamID = pss_pluginSetting("{$league}TeamID", '');

    if ($teamID === '') {
        pss_clearLeagueState($league, true);
        pss_syncGeneratedPlaylistsForLeague($league);
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
    pss_clearGameSnapshotState($league);

    if ($teamInfo['nextEventID'] !== '') {
        $game = pss_getGameStatus($sport, $league, $teamInfo['nextEventID'], $teamID);
        if ($game['valid']) {
            pss_baselineGameSnapshot($league, $teamInfo['nextEventID'], $game);
        }
    }

    pss_syncGeneratedPlaylistsForLeague($league);
    pss_logEntry("{$league} team updated to {$teamInfo['name']}");
    return $teamInfo['logo'];
}

function pss_clearLeagueState($league, $clearTeam = false) {
    $keys = array(
        'TeamLogo' => '', 'TeamAbbreviation' => '', 'TeamName' => '', 'TeamNextEventID' => '',
        'Start' => '', 'GameStatus' => '', 'OppoID' => '', 'OppoName' => '', 'OppoAbbreviation' => '',
        'OppoLogo' => '', 'GameDetail' => '', 'MyScore' => '0', 'OppoScore' => '0',
        'LastScoringPlayID' => '', 'LastCelebratedScore' => '0',
        'LastCompletedEventID' => '', 'GameSnapshotEventID' => ''
    );
    if ($clearTeam) {
        $keys['TeamID'] = '';
    }
    foreach ($keys as $suffix => $value) {
        pss_setPluginSetting("{$league}{$suffix}", $value);
    }
}

function pss_applyGameSnapshot($league, $status, $updateStatus = true, $eventID = '') {
    $oppoLogo = isset($status['oppoLogo']) ? trim((string)$status['oppoLogo']) : '';

    // ESPN's game summary does not consistently include team logos in every sport/league.
    // Preserve an already-cached opponent logo, or fetch it once from the team endpoint.
    if ($oppoLogo === '') {
        $cachedOppoID = pss_pluginSetting("{$league}OppoID", '');
        if ((string)$cachedOppoID === (string)$status['oppoID']) {
            $oppoLogo = pss_pluginSetting("{$league}OppoLogo", '');
        }
    }
    if ($oppoLogo === '' && !empty($status['oppoID'])) {
        $leagueInfo = pss_leagueInfo($league);
        if ($leagueInfo['sport'] !== '') {
            $oppoInfo = pss_getTeamInfo($leagueInfo['sport'], $league, (string)$status['oppoID']);
            if ($oppoInfo['valid'] && $oppoInfo['logo'] !== '') {
                $oppoLogo = $oppoInfo['logo'];
            }
        }
    }

    pss_setPluginSetting("{$league}Start", $status['start']);
    pss_setPluginSetting("{$league}OppoID", $status['oppoID']);
    pss_setPluginSetting("{$league}OppoName", $status['oppoName']);
    pss_setPluginSetting("{$league}OppoAbbreviation", $status['oppoAbbreviation']);
    pss_setPluginSetting("{$league}OppoLogo", $oppoLogo);
    pss_setPluginSetting("{$league}GameDetail", isset($status['detail']) ? $status['detail'] : '');
    pss_setPluginSetting("{$league}MyScore", (string)$status['myScore']);
    pss_setPluginSetting("{$league}OppoScore", (string)$status['oppoScore']);
    if ($updateStatus) {
        pss_setPluginSetting("{$league}GameStatus", $status['state']);
    }
    if ($eventID !== '') {
        pss_setPluginSetting("{$league}GameSnapshotEventID", (string)$eventID);
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

    $playlist = pss_generatedPlaylistName($league, $suffix);
    if ($playlist === '' || !pss_writeGeneratedPlaylist($playlist, $sequence)) {
        pss_logEntry("{$league} {$label} detected but helper playlist could not be prepared for {$sequence}");
        return;
    }

    if (pss_insertPlaylistImmediate($playlist)) {
        pss_logEntry("{$league} {$label} detected; inserted {$sequence} using playlist {$playlist}");
    } else {
        pss_logEntry("{$league} {$label} detected but FPP rejected generated playlist {$playlist}");
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
        $snapshotEventID = pss_pluginSetting("{$league}GameSnapshotEventID", '');
        $status = null;

        if ($eventID === '') {
            $newInfo = pss_getTeamInfo($sport, $league, $teamID);
            if ($newInfo['valid'] && $newInfo['nextEventID'] !== '') {
                pss_activateEventFromTeamInfo($league, $sport, $teamID, $newInfo);
                $eventID = pss_pluginSetting("{$league}TeamNextEventID", '');
                $gameState = pss_pluginSetting("{$league}GameStatus", '');
                $start = pss_pluginSetting("{$league}Start", '');
                $snapshotEventID = pss_pluginSetting("{$league}GameSnapshotEventID", '');
            } else {
                continue;
            }
        }

        if ($gameState === 'post') {
            $newInfo = pss_getTeamInfo($sport, $league, $teamID);
            if ($newInfo['valid'] && $newInfo['nextEventID'] !== '' && $newInfo['nextEventID'] !== $eventID) {
                $oldEventID = $eventID;
                pss_activateEventFromTeamInfo($league, $sport, $teamID, $newInfo);
                $eventID = pss_pluginSetting("{$league}TeamNextEventID", '');
                $gameState = pss_pluginSetting("{$league}GameStatus", '');
                $start = pss_pluginSetting("{$league}Start", '');
                $snapshotEventID = pss_pluginSetting("{$league}GameSnapshotEventID", '');
                pss_logEntry("{$league} next event changed from {$oldEventID} to {$eventID}");
            } else {
                // A failed summary request during selection used to leave finished games
                // permanently blank. Repair the snapshot once, then leave the historical
                // game baselined without firing an old win sequence.
                if ($snapshotEventID !== $eventID || pss_pluginSetting("{$league}OppoID", '') === '') {
                    $repair = pss_getGameStatus($sport, $league, $eventID, $teamID);
                    if ($repair['valid']) {
                        pss_baselineGameSnapshot($league, $eventID, $repair);
                        pss_logEntry("{$league} repaired cached snapshot for event {$eventID}");
                    } else {
                        $sleepTimes[$league] = 30;
                    }
                }
                continue;
            }
        }

        // Existing installs may already contain a new event ID together with opponent,
        // score, or status text from the previous game. Force one full summary refresh
        // whenever the cached snapshot does not belong to the active event.
        if ($snapshotEventID !== $eventID || pss_pluginSetting("{$league}OppoID", '') === '') {
            $status = pss_getGameStatus($sport, $league, $eventID, $teamID);
            if ($status['valid']) {
                pss_baselineGameSnapshot($league, $eventID, $status);
                $gameState = $status['state'];
                $start = $status['start'];
                $snapshotEventID = $eventID;
                pss_logEntry("{$league} refreshed full snapshot for event {$eventID}");
            } else {
                pss_logEntry("{$league} ESPN snapshot refresh failed; keeping event metadata and retrying");
                $sleepTimes[$league] = 30;
                continue;
            }
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

        if ($status === null) {
            $status = pss_getGameStatus($sport, $league, $eventID, $teamID);
        }
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

        pss_applyGameSnapshot($league, $status, true, $eventID);
    }

    return min($sleepTimes);
}

function pss_insertPlaylistImmediate($playlist) {
    $playlist = trim((string)$playlist);
    if ($playlist === '') {
        return false;
    }

    $payload = array(
        'command' => 'Insert Playlist Immediate',
        'multisyncCommand' => false,
        'multisyncHosts' => '',
        'args' => array($playlist, '0', '0', 'false')
    );
    $response = pss_httpRequest('http://127.0.0.1/api/command', 'POST', $payload, 'text/plain, application/json');
    if (!$response['ok']) {
        pss_logEntry("FPP command failed with HTTP {$response['status']} for playlist {$playlist}");
        return false;
    }
    return true;
}

function pss_logEntry($message) {
    global $logFile;
    $pid = getmypid();
    $line = date('c') . " [{$pid}] " . trim((string)$message) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
?>
