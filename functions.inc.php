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
            pss_updateTeam('football', 'nfl', 1);
            pss_updateTickerOutput(true);
            break;
        case 'updateNFLTeam2':
            pss_updateTeam('football', 'nfl', 2);
            pss_updateTickerOutput(true);
            break;
        case 'updateNCAATeam':
            pss_updateTeam('football', 'ncaa', 1);
            pss_updateTickerOutput(true);
            break;
        case 'updateNCAATeam2':
            pss_updateTeam('football', 'ncaa', 2);
            pss_updateTickerOutput(true);
            break;
        case 'updateNHLTeam':
            pss_updateTeam('hockey', 'nhl', 1);
            pss_updateTickerOutput(true);
            break;
        case 'updateNHLTeam2':
            pss_updateTeam('hockey', 'nhl', 2);
            pss_updateTickerOutput(true);
            break;
        case 'updateMLBTeam':
            pss_updateTeam('baseball', 'mlb', 1);
            pss_updateTickerOutput(true);
            break;
        case 'updateMLBTeam2':
            pss_updateTeam('baseball', 'mlb', 2);
            pss_updateTickerOutput(true);
            break;
        case 'updateTeamSelection':
            $league = isset($_POST['league']) ? strtolower(trim((string)$_POST['league'])) : '';
            $slot = (isset($_POST['slot']) && (int)$_POST['slot'] === 2) ? 2 : 1;
            $teamID = isset($_POST['teamID']) ? trim((string)$_POST['teamID']) : '';
            $info = pss_leagueInfo($league);
            if ($info['sport'] !== '') {
                // Use the value sent by the changed select instead of racing the
                // FPP settings AJAX request.  This keeps helper-playlist names
                // on the same team the user just selected on FPP 7-10.
                pss_updateTeam($info['sport'], $league, $slot, $teamID);
                pss_updateTickerOutput(true);
            }
            break;
        case 'syncSequencePlaylist':
            if (isset($_POST['setting'])) {
                pss_syncGeneratedPlaylistSetting((string)$_POST['setting']);
            }
            break;
        case 'saveCelebrationDelay':
            pss_saveCelebrationDelay($_POST);
            break;
        case 'manualTrigger':
            pss_manualTrigger($_POST);
            break;
        case 'saveTickerSettings':
            pss_saveTickerSettings($_POST);
            break;
        case 'testTicker':
            pss_testTickerOutput();
            break;
        case 'clearTicker':
            pss_clearConfiguredTickerOutput();
            break;
    }
}

function pss_teamPrefix($league, $slot = 1) {
    return $league . (((int)$slot === 2) ? '2' : '');
}

function pss_teamLogLabel($league, $slot = 1) {
    return strtoupper((string)$league) . (((int)$slot === 2) ? ' team 2' : ' team 1');
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

    // FPP versions do not all return the same success value from
    // WriteSettingToFile().  In particular, older releases can write the
    // setting successfully while returning null/false.  If we only update
    // our in-memory cache on a truthy return value, the rest of this request
    // sees the previous team metadata.  That made generated helper playlist
    // names lag one team behind the selection.
    $result = WriteSettingToFile($key, $value, $pluginName);

    if (!is_array($pluginSettings)) {
        $pluginSettings = array();
    }
    $pluginSettings[$key] = $value;

    if ($result === true || $result === 1) {
        return true;
    }

    // Older FPP may not report success.  Verify the on-disk value before
    // treating it as a failure so we avoid false error logs while keeping the
    // request-local cache synchronized with what we just wrote.
    $diskSettings = pss_loadPluginSettings();
    if (is_array($diskSettings) && array_key_exists($key, $diskSettings)) {
        if (urldecode((string)$diskSettings[$key]) === $value) {
            return true;
        }
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

function pss_jsonResponse($ok, $message, $extra = array()) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    $payload = array_merge(array(
        'ok' => (bool)$ok,
        'message' => (string)$message
    ), is_array($extra) ? $extra : array());
    echo json_encode($payload);
    exit;
}

function pss_clampInt($value, $min, $max, $default) {
    if (!is_numeric($value)) {
        return (int)$default;
    }
    $value = (int)$value;
    if ($value < $min) return (int)$min;
    if ($value > $max) return (int)$max;
    return $value;
}

function pss_normalizeColor($value, $default = '#FFFFFF') {
    $value = strtoupper(trim((string)$value));
    if (preg_match('/^#[0-9A-F]{6}$/', $value)) {
        return $value;
    }
    if (preg_match('/^[0-9A-F]{6}$/', $value)) {
        return '#' . $value;
    }
    return $default;
}

function pss_overlayModelDimensions($model) {
    if (!is_array($model)) {
        return array(0, 0);
    }

    if (isset($model['Width'], $model['Height']) && is_numeric($model['Width']) && is_numeric($model['Height'])) {
        return array(max(0, (int)$model['Width']), max(0, (int)$model['Height']));
    }

    $orientation = isset($model['Orientation']) ? strtolower((string)$model['Orientation']) : '';
    if ($orientation === 'custom' && isset($model['data']) && is_string($model['data']) && $model['data'] !== '') {
        $rows = explode(';', $model['data']);
        $height = count($rows);
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, count(explode(',', $row)));
        }
        return array($width, $height);
    }

    $channelCount = isset($model['ChannelCount']) ? (int)$model['ChannelCount'] : 0;
    $channelsPerNode = isset($model['ChannelCountPerNode']) ? max(1, (int)$model['ChannelCountPerNode']) : 3;
    $stringCount = isset($model['StringCount']) ? max(0, (int)$model['StringCount']) : 0;
    $strandsPerString = isset($model['StrandsPerString']) ? max(1, (int)$model['StrandsPerString']) : 1;
    $nodes = ($channelsPerNode > 0) ? (int)floor($channelCount / $channelsPerNode) : 0;
    $lines = $stringCount * $strandsPerString;

    if ($nodes <= 0 || $lines <= 0) {
        return array(0, 0);
    }

    $other = (int)floor($nodes / $lines);
    if ($other <= 0) {
        return array(0, 0);
    }

    if ($orientation === 'vertical') {
        return array($lines, $other);
    }
    return array($other, $lines);
}

function pss_getOverlayModels() {
    global $settings;

    $path = isset($settings['model-overlays']) ? (string)$settings['model-overlays'] : '';
    if ($path === '') {
        $configDir = isset($settings['configDirectory']) ? rtrim((string)$settings['configDirectory'], '/') : '/home/fpp/media/config';
        $path = $configDir . '/model-overlays.json';
    }

    $result = array();
    if (!is_file($path)) {
        return $result;
    }

    $raw = @file_get_contents($path);
    $data = ($raw !== false) ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['models']) || !is_array($data['models'])) {
        return $result;
    }

    foreach ($data['models'] as $model) {
        if (!is_array($model) || !isset($model['Name'])) {
            continue;
        }
        $name = trim((string)$model['Name']);
        if ($name === '') {
            continue;
        }
        list($width, $height) = pss_overlayModelDimensions($model);
        $result[$name] = array(
            'name' => $name,
            'width' => $width,
            'height' => $height
        );
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function pss_tickerIncludeSetting($league, $slot) {
    return 'TickerInclude' . strtoupper((string)$league) . (((int)$slot === 2) ? '2' : '1');
}

function pss_tickerColorSetting($league, $slot) {
    return 'TickerColor' . strtoupper((string)$league) . (((int)$slot === 2) ? '2' : '1');
}

function pss_tickerSpacing() {
    return pss_clampInt(pss_pluginSetting('TickerSpacing', '4'), 1, 12, 4);
}

function pss_tickerLeagueLabel($league) {
    return ($league === 'ncaa') ? 'NCAA' : strtoupper((string)$league);
}

function pss_tickerStartLabel($value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '0') {
        return 'TBD';
    }
    try {
        $dt = new DateTime($value);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('D g:i A');
    } catch (Exception $e) {
        return 'TBD';
    }
}

function pss_tickerCleanDetail($detail) {
    $detail = trim(preg_replace('/\\s+/', ' ', (string)$detail));
    if ($detail === '') {
        return '';
    }
    if (strlen($detail) > 48) {
        $detail = substr($detail, 0, 48);
    }
    return $detail;
}

function pss_buildTickerItems($forOverlay = false) {
    global $leagues;

    $style = strtolower(pss_pluginSetting('TickerStyle', 'normal'));
    if (!in_array($style, array('compact', 'normal', 'detailed'), true)) {
        $style = 'normal';
    }

    $dot = $forOverlay ? ' | ' : ' • ';
    $items = array();

    foreach ($leagues as $league) {
        foreach (array(1, 2) as $slot) {
            if (pss_pluginSetting(pss_tickerIncludeSetting($league, $slot), 'ON') !== 'ON') {
                continue;
            }

            $prefix = pss_teamPrefix($league, $slot);
            $teamID = pss_pluginSetting("{$prefix}TeamID", '');
            if ($teamID === '') {
                continue;
            }

            $leagueLabel = pss_tickerLeagueLabel($league);
            $teamAbbr = pss_pluginSetting("{$prefix}TeamAbbreviation", 'TEAM');
            $oppoAbbr = pss_pluginSetting("{$prefix}OppoAbbreviation", 'OPP');
            $teamName = pss_pluginSetting("{$prefix}TeamName", $teamAbbr);
            $oppoName = pss_pluginSetting("{$prefix}OppoName", $oppoAbbr);
            $myScore = pss_pluginSetting("{$prefix}MyScore", '0');
            $oppoScore = pss_pluginSetting("{$prefix}OppoScore", '0');
            $state = pss_pluginSetting("{$prefix}GameStatus", '');
            $detail = pss_tickerCleanDetail(pss_pluginSetting("{$prefix}GameDetail", ''));
            $start = pss_tickerStartLabel(pss_pluginSetting("{$prefix}Start", ''));

            $left = ($style === 'detailed') ? $teamName : $teamAbbr;
            $right = ($style === 'detailed') ? $oppoName : $oppoAbbr;
            $leaguePrefix = ($style === 'compact') ? '' : ($leagueLabel . $dot);

            if ($state === 'in') {
                $status = 'LIVE';
                if (!$forOverlay && $detail !== '') {
                    $status = $detail;
                }
                $segmentText = $leaguePrefix . $left . ' ' . $myScore . ' - ' . $right . ' ' . $oppoScore . $dot . $status;
            } elseif ($state === 'post') {
                $status = ($detail !== '' && stripos($detail, 'final') !== false) ? $detail : 'FINAL';
                $segmentText = $leaguePrefix . $left . ' ' . $myScore . ' - ' . $right . ' ' . $oppoScore . $dot . $status;
            } elseif ($state === 'pre') {
                $segmentText = $leaguePrefix . 'NEXT' . $dot . $left . ' vs ' . $right . $dot . $start;
            } else {
                $segmentText = $leaguePrefix . $left . $dot . 'Waiting for ESPN';
            }

            $items[] = array(
                'key' => strtoupper((string)$league) . (((int)$slot === 2) ? '2' : '1'),
                'league' => $leagueLabel,
                'slot' => (int)$slot,
                'text' => $segmentText,
                'color' => pss_normalizeColor(pss_pluginSetting(pss_tickerColorSetting($league, $slot), '#FFFFFF'))
            );
        }
    }

    return $items;
}

function pss_buildTickerText($forOverlay = false) {
    $items = pss_buildTickerItems($forOverlay);
    if (empty($items)) {
        return $forOverlay ? 'PRO SPORTS SCORING | NO SELECTED TEAMS' : 'PRO SPORTS SCORING • NO SELECTED TEAMS';
    }

    $texts = array();
    foreach ($items as $item) {
        $texts[] = isset($item['text']) ? (string)$item['text'] : '';
    }

    $spacing = pss_tickerSpacing();
    $pad = str_repeat(' ', $spacing);
    $separator = $forOverlay ? '|' : '•';

    return implode($pad . $separator . $pad, $texts);
}

function pss_runFppCommand($command, $args) {
    $payload = array(
        'command' => (string)$command,
        'multisyncCommand' => false,
        'multisyncHosts' => '',
        'args' => is_array($args) ? $args : array()
    );
    return pss_httpRequest('http://127.0.0.1/api/command', 'POST', $payload, 'text/plain, application/json');
}

function pss_clearOverlayModel($model) {
    $model = trim((string)$model);
    if ($model === '') {
        return false;
    }
    $response = pss_runFppCommand('Overlay Model Clear', array($model));
    if (!$response['ok']) {
        pss_logEntry("FPP rejected Overlay Model Clear for {$model} with HTTP {$response['status']}");
        return false;
    }
    return true;
}

function pss_resolveOverlayFont($requestedFont) {
    $requestedFont = trim((string)$requestedFont);

    // An absolute font file path is the most deterministic option for ImageMagick.
    if ($requestedFont !== '' && is_file($requestedFont)) {
        return $requestedFont;
    }

    // Older versions of this plugin defaulted to "Helvetica", but the FPP 10
    // image does not necessarily ship a Helvetica font. Map common friendly
    // names to font files that are normally present on FPP 10.
    $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', $requestedFont));
    $known = array(
        'helvetica' => '/usr/share/fonts/truetype/lato/Lato-Bold.ttf',
        'latobold' => '/usr/share/fonts/truetype/lato/Lato-Bold.ttf',
        'lato' => '/usr/share/fonts/truetype/lato/Lato-Regular.ttf',
        'latoregular' => '/usr/share/fonts/truetype/lato/Lato-Regular.ttf',
        'freesansbold' => '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        'freesans' => '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        'freemonobold' => '/usr/share/fonts/truetype/freefont/FreeMonoBold.ttf',
        'freemono' => '/usr/share/fonts/truetype/freefont/FreeMono.ttf',
        'notosansmonobold' => '/usr/share/fonts/truetype/noto/NotoSansMono-Bold.ttf',
        'notosansmono' => '/usr/share/fonts/truetype/noto/NotoSansMono-Regular.ttf'
    );

    if ($normalized !== '' && isset($known[$normalized]) && is_file($known[$normalized])) {
        return $known[$normalized];
    }

    // Also allow a user to type a font filename/name shown by FPP. Search only
    // normal system font locations and return an exact normalized basename match.
    if ($normalized !== '') {
        $patterns = array(
            '/usr/share/fonts/truetype/*/*.ttf',
            '/usr/share/fonts/truetype/*/*.otf',
            '/usr/share/fonts/opentype/*/*.ttf',
            '/usr/share/fonts/opentype/*/*.otf',
            '/usr/local/share/fonts/*/*.ttf',
            '/usr/local/share/fonts/*/*.otf'
        );
        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (!is_array($files)) continue;
            foreach ($files as $file) {
                $base = pathinfo($file, PATHINFO_FILENAME);
                $baseNormalized = strtolower(preg_replace('/[^a-z0-9]+/', '', $base));
                if ($baseNormalized === $normalized && is_file($file)) {
                    return $file;
                }
            }
        }
    }

    $fallbacks = array(
        '/usr/share/fonts/truetype/lato/Lato-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        '/usr/share/fonts/truetype/noto/NotoSansMono-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf'
    );
    foreach ($fallbacks as $fallback) {
        if (is_file($fallback)) {
            return $fallback;
        }
    }

    // Last resort: preserve the user's value. FPP will return a useful command
    // error which we now include in the plugin log.
    return $requestedFont !== '' ? $requestedFont : 'Lato-Bold';
}

function pss_overlayCommandErrorText($response) {
    if (!is_array($response)) return '';
    $body = isset($response['body']) ? trim((string)$response['body']) : '';
    if ($body === '') return '';
    if (strlen($body) > 600) {
        $body = substr($body, 0, 600) . '...';
    }
    return preg_replace('/\s+/', ' ', $body);
}

function pss_sendOverlayTickerText($text, $force = false) {
    static $lastSignature = '';
    static $lastModel = '';

    if (pss_pluginSetting('TickerEnabled', 'OFF') !== 'ON' || pss_pluginSetting('TickerOverlayEnabled', 'OFF') !== 'ON') {
        if ($lastModel !== '') {
            pss_clearOverlayModel($lastModel);
            $lastModel = '';
            $lastSignature = '';
        }
        return false;
    }

    $model = trim(pss_pluginSetting('TickerOverlayModel', ''));
    if ($model === '') {
        return false;
    }

    $color = pss_normalizeColor(pss_pluginSetting('TickerTextColor', '#FFFFFF'));
    $requestedFont = trim(pss_pluginSetting('TickerFont', 'Helvetica'));
    $font = pss_resolveOverlayFont($requestedFont);

    // FPP 10's Text effect advertises FontSize 4-100 and Scroll Speed 0-200.
    $fontSize = pss_clampInt(pss_pluginSetting('TickerFontSize', '16'), 4, 100, 16);
    $direction = pss_pluginSetting('TickerDirection', 'Right to Left');
    if ($direction !== 'Left to Right' && $direction !== 'Right to Left') {
        $direction = 'Right to Left';
    }
    $speed = pss_clampInt(pss_pluginSetting('TickerScrollSpeed', '10'), 0, 200, 10);

    $text = trim((string)$text);
    if ($text === '') {
        $text = 'PRO SPORTS SCORING';
    }
    if (strlen($text) > 1200) {
        $text = substr($text, 0, 1200);
    }

    $signature = md5(implode('|', array($model, $color, $font, $fontSize, $direction, $speed, $text)));
    if (!$force && $signature === $lastSignature) {
        return true;
    }

    if ($lastModel !== '' && $lastModel !== $model) {
        pss_clearOverlayModel($lastModel);
    }

    // FPP 10 native command:
    // Models, AutoEnable, Effect,
    // Color, Font, FontSize, FontAntiAlias, Position, Speed, Duration, Text
    $modernArgs = array(
        $model,
        'Enabled',
        'Text',
        $color,
        $font,
        (string)$fontSize,
        'false',
        $direction,
        (string)$speed,
        '0',
        $text
    );

    $response = pss_runFppCommand('Overlay Model Effect', $modernArgs);

    if (!$response['ok']) {
        $detail = pss_overlayCommandErrorText($response);
        pss_logEntry(
            "FPP 10 Overlay Model Effect rejected sports ticker for {$model}"
            . " HTTP {$response['status']}"
            . ($detail !== '' ? " response={$detail}" : '')
            . " font={$font}"
        );

        // Compatibility fallback. FPP 10 still contains the hidden legacy
        // Overlay Model Text translator. If the direct modern command is ever
        // unavailable on a particular build, retry through that translator.
        $legacyArgs = array(
            $model,
            $color,
            $font,
            (string)$fontSize,
            'false',
            $direction,
            (string)$speed,
            'true',
            $text
        );
        $legacyResponse = pss_runFppCommand('Overlay Model Text', $legacyArgs);
        if (!$legacyResponse['ok']) {
            $legacyDetail = pss_overlayCommandErrorText($legacyResponse);
            pss_logEntry(
                "Legacy Overlay Model Text fallback also failed for {$model}"
                . " HTTP {$legacyResponse['status']}"
                . ($legacyDetail !== '' ? " response={$legacyDetail}" : '')
            );
            return false;
        }

        $response = $legacyResponse;
    }

    if ($requestedFont !== $font) {
        pss_logEntry("Pixel ticker font '{$requestedFont}' resolved to '{$font}'");
    }

    $lastModel = $model;
    $lastSignature = $signature;
    return true;
}

function pss_updateTickerOutput($force = false) {
    if (pss_pluginSetting('TickerEnabled', 'OFF') !== 'ON' || pss_pluginSetting('TickerOverlayEnabled', 'OFF') !== 'ON') {
        return pss_sendOverlayTickerText('', $force);
    }
    return pss_sendOverlayTickerText(pss_buildTickerText(true), $force);
}

function pss_clearConfiguredTickerOutput() {
    $model = trim(pss_pluginSetting('TickerOverlayModel', ''));
    $ok = ($model !== '') ? pss_clearOverlayModel($model) : true;
    pss_jsonResponse($ok, $ok ? 'Pixel Overlay ticker cleared.' : 'FPP could not clear the selected Pixel Overlay Model.');
}

function pss_saveTickerSettings($post) {
    $oldModel = trim(pss_pluginSetting('TickerOverlayModel', ''));
    $oldActive = (pss_pluginSetting('TickerEnabled', 'OFF') === 'ON' && pss_pluginSetting('TickerOverlayEnabled', 'OFF') === 'ON');

    $style = isset($post['TickerStyle']) ? strtolower(trim((string)$post['TickerStyle'])) : 'normal';
    if (!in_array($style, array('compact', 'normal', 'detailed'), true)) $style = 'normal';

    $direction = isset($post['TickerDirection']) ? trim((string)$post['TickerDirection']) : 'Right to Left';
    if ($direction !== 'Left to Right' && $direction !== 'Right to Left') $direction = 'Right to Left';

    $values = array(
        'TickerEnabled' => (isset($post['TickerEnabled']) && (string)$post['TickerEnabled'] === 'ON') ? 'ON' : 'OFF',
        'TickerKioskEnabled' => (isset($post['TickerKioskEnabled']) && (string)$post['TickerKioskEnabled'] === 'ON') ? 'ON' : 'OFF',
        'TickerStyle' => $style,
        'TickerWebSpeed' => (string)pss_clampInt(isset($post['TickerWebSpeed']) ? $post['TickerWebSpeed'] : 90, 20, 300, 90),
        'TickerWebFontSize' => (string)pss_clampInt(isset($post['TickerWebFontSize']) ? $post['TickerWebFontSize'] : 18, 12, 48, 18),
        'TickerSpacing' => (string)pss_clampInt(isset($post['TickerSpacing']) ? $post['TickerSpacing'] : 4, 1, 12, 4),
        'TickerOverlayEnabled' => (isset($post['TickerOverlayEnabled']) && (string)$post['TickerOverlayEnabled'] === 'ON') ? 'ON' : 'OFF',
        'TickerOverlayModel' => isset($post['TickerOverlayModel']) ? trim((string)$post['TickerOverlayModel']) : '',
        'TickerWidth' => (string)pss_clampInt(isset($post['TickerWidth']) ? $post['TickerWidth'] : 128, 1, 4096, 128),
        'TickerHeight' => (string)pss_clampInt(isset($post['TickerHeight']) ? $post['TickerHeight'] : 32, 1, 4096, 32),
        'TickerFont' => isset($post['TickerFont']) ? trim((string)$post['TickerFont']) : 'Helvetica',
        'TickerFontSize' => (string)pss_clampInt(isset($post['TickerFontSize']) ? $post['TickerFontSize'] : 16, 6, 128, 16),
        'TickerTextColor' => pss_normalizeColor(isset($post['TickerTextColor']) ? $post['TickerTextColor'] : '#FFFFFF'),
        'TickerDirection' => $direction,
        'TickerScrollSpeed' => (string)pss_clampInt(isset($post['TickerScrollSpeed']) ? $post['TickerScrollSpeed'] : 10, 1, 100, 10)
    );

    global $leagues;
    foreach ($leagues as $league) {
        foreach (array(1, 2) as $slot) {
            $key = pss_tickerIncludeSetting($league, $slot);
            $values[$key] = (isset($post[$key]) && (string)$post[$key] === 'ON') ? 'ON' : 'OFF';

            $colorKey = pss_tickerColorSetting($league, $slot);
            $values[$colorKey] = pss_normalizeColor(isset($post[$colorKey]) ? $post[$colorKey] : '#FFFFFF');
        }
    }

    if ($values['TickerFont'] === '') $values['TickerFont'] = 'Helvetica';

    foreach ($values as $key => $value) {
        pss_setPluginSetting($key, $value);
    }

    $newActive = ($values['TickerEnabled'] === 'ON' && $values['TickerOverlayEnabled'] === 'ON');
    $newModel = $values['TickerOverlayModel'];
    if ($oldActive && $oldModel !== '' && (!$newActive || $oldModel !== $newModel)) {
        pss_clearOverlayModel($oldModel);
    }

    $message = 'Ticker settings saved.';
    if ($newActive) {
        if ($newModel === '') {
            $message .= ' Select a Pixel Overlay Model before enabling matrix output.';
        } else {
            $ok = pss_updateTickerOutput(true);
            $message .= $ok ? ' Pixel Overlay ticker updated.' : ' Pixel Overlay output did not start; check the plugin log.';
        }
    }

    pss_jsonResponse(true, $message, array(
        'tickerText' => pss_buildTickerText(false),
        'tickerItems' => pss_buildTickerItems(false),
        'tickerSpacing' => pss_tickerSpacing(),
        'tickerWebFontSize' => pss_clampInt(pss_pluginSetting('TickerWebFontSize', '18'), 12, 48, 18)
    ));
}

function pss_testTickerOutput() {
    if (pss_pluginSetting('TickerOverlayEnabled', 'OFF') !== 'ON') {
        pss_jsonResponse(false, 'Enable Pixel Overlay output and save the ticker settings first.');
    }
    $model = trim(pss_pluginSetting('TickerOverlayModel', ''));
    if ($model === '') {
        pss_jsonResponse(false, 'Select a Pixel Overlay Model and save the ticker settings first.');
    }

    $text = pss_buildTickerText(true);
    if ($text === 'PRO SPORTS SCORING | NO SELECTED TEAMS') {
        $text = 'PRO SPORTS SCORING | PIXEL OVERLAY TEST | ' . date('g:i A');
    }

    $ok = pss_sendOverlayTickerText($text, true);
    pss_jsonResponse($ok, $ok ? 'Test ticker sent to ' . $model . '.' : 'FPP rejected the test ticker. Check the plugin log.');
}

function pss_manualTrigger($post) {
    global $pluginSettings;

    // Re-read settings so the button always uses the currently selected team,
    // sequence, delay and slot.  The client never sends a playlist name.
    $pluginSettings = pss_loadPluginSettings();

    $league = isset($post['league']) ? strtolower(trim((string)$post['league'])) : '';
    $slot = (isset($post['slot']) && (int)$post['slot'] === 2) ? 2 : 1;
    $trigger = isset($post['trigger']) ? strtolower(trim((string)$post['trigger'])) : '';
    $info = pss_leagueInfo($league);

    if ($info['sport'] === '') {
        pss_jsonResponse(false, 'Invalid league.');
    }

    $map = array();
    if ($info['sport'] === 'football') {
        $map = array(
            'touchdown' => array('suffix' => 'TouchdownSequence', 'label' => 'Touchdown'),
            'fieldgoal' => array('suffix' => 'FieldgoalSequence', 'label' => 'Field goal'),
            'win' => array('suffix' => 'WinSequence', 'label' => 'Win')
        );
    } else {
        $map = array(
            'score' => array('suffix' => 'ScoreSequence', 'label' => 'Score'),
            'win' => array('suffix' => 'WinSequence', 'label' => 'Win')
        );
    }

    if (!isset($map[$trigger])) {
        pss_jsonResponse(false, 'Invalid manual trigger for this sport.');
    }

    $prefix = pss_teamPrefix($league, $slot);
    $teamID = trim(pss_pluginSetting("{$prefix}TeamID", ''));
    if ($teamID === '') {
        pss_jsonResponse(false, 'No team is selected for this slot.');
    }

    $suffix = $map[$trigger]['suffix'];
    $label = $map[$trigger]['label'];
    $sequence = trim(pss_pluginSetting("{$prefix}{$suffix}", ''));
    if ($sequence === '') {
        pss_jsonResponse(false, "No {$label} sequence is configured for this team.");
    }

    $teamName = trim(pss_pluginSetting("{$prefix}TeamName", ''));
    if ($teamName === '') {
        $teamName = strtoupper($league) . ' team ' . $slot;
    }

    $delay = pss_teamCelebrationDelay($league, $slot);
    $playlist = pss_generatedPlaylistName($league, $suffix, $slot);
    $ok = pss_playConfiguredSequence($league, $suffix, 'Manual ' . strtolower($label), $slot);
    if (!$ok) {
        pss_jsonResponse(false, "FPP could not trigger the {$label} playlist. Check the plugin log.");
    }

    $message = $teamName . ': ' . $label . ' triggered';
    if ($delay > 0) {
        $message .= ' (' . $delay . ' sec delay)';
    }

    pss_jsonResponse(true, $message . '.', array(
        'playlist' => $playlist,
        'sequence' => $sequence,
        'delay' => $delay,
        'league' => $league,
        'slot' => $slot,
        'trigger' => $trigger
    ));
}

function pss_teamCelebrationDelay($league, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
    return pss_clampInt(pss_pluginSetting("{$prefix}CelebrationDelay", '0'), 0, 300, 0);
}

function pss_saveCelebrationDelay($post) {
    global $pluginSettings;

    $setting = isset($post['setting']) ? trim((string)$post['setting']) : '';
    $value = isset($post['value']) ? $post['value'] : 0;

    if (!preg_match('/^(nfl|ncaa|nhl|mlb)(2)?CelebrationDelay$/', $setting, $matches)) {
        pss_jsonResponse(false, 'Invalid celebration delay setting.');
    }

    $delay = pss_clampInt($value, 0, 300, 0);
    if (!pss_setPluginSetting($setting, (string)$delay)) {
        pss_jsonResponse(false, 'Unable to save celebration delay.');
    }

    $pluginSettings = pss_loadPluginSettings();
    pss_syncGeneratedPlaylistsForLeague($matches[1]);
    pss_jsonResponse(true, 'Celebration delay saved.', array('value' => $delay));
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

function pss_generatedPlaylistName($league, $sequenceSuffix, $slot = 1) {
    $kind = pss_generatedPlaylistType($sequenceSuffix);
    if ($kind === '') {
        return '';
    }

    $prefix = pss_teamPrefix($league, $slot);
    $teamPart = pss_pluginSetting("{$prefix}TeamAbbreviation", '');
    if ($teamPart === '') {
        $teamPart = pss_pluginSetting("{$prefix}TeamID", '');
    }
    if ($teamPart === '') {
        return '';
    }

    $slotPart = ((int)$slot === 2) ? '_S2' : '';
    return 'PSS_' . pss_safePlaylistPart($league, 'LEAGUE') . $slotPart . '_' . pss_safePlaylistPart($teamPart, 'TEAM') . '_' . $kind;
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

function pss_generatedPlaylistData($playlistName, $sequenceName, $delaySeconds = 0) {
    $duration = pss_sequenceDuration($sequenceName);
    $delaySeconds = pss_clampInt($delaySeconds, 0, 300, 0);

    $mainPlaylist = array();
    if ($delaySeconds > 0) {
        $mainPlaylist[] = array(
            'type' => 'pause',
            'enabled' => 1,
            'playOnce' => 0,
            'duration' => $delaySeconds,
            'note' => 'Pro Sports Scoring celebration delay',
            'displayMode' => 'argsOnly'
        );
    }

    $mainPlaylist[] = array(
        'type' => 'sequence',
        'enabled' => 1,
        'playOnce' => 0,
        'sequenceName' => basename((string)$sequenceName),
        'displayMode' => 'argsOnly',
        'timecode' => 'Default',
        'duration' => $duration
    );

    $totalDuration = $duration + $delaySeconds;
    $itemCount = count($mainPlaylist);

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
        'mainPlaylist' => $mainPlaylist,
        'leadOut' => array(),
        'playlistInfo' => array(
            'leadIn_duration' => 0,
            'leadIn_items' => 0,
            'mainPlaylist_duration' => $totalDuration,
            'mainPlaylist_items' => $itemCount,
            'leadOut_duration' => 0,
            'leadOut_items' => 0,
            'total_duration' => $totalDuration,
            'total_items' => $itemCount
        )
    );
}

function pss_writeGeneratedPlaylist($playlistName, $sequenceName, $delaySeconds = 0) {
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

    $data = pss_generatedPlaylistData($playlistName, $sequenceName, $delaySeconds);
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

    $suffixes = ($info['sport'] === 'football')
        ? array('TouchdownSequence', 'FieldgoalSequence', 'WinSequence')
        : array('ScoreSequence', 'WinSequence');

    $keepNames = array();
    foreach (array(1, 2) as $slot) {
        $prefix = pss_teamPrefix($league, $slot);
        if (pss_pluginSetting("{$prefix}TeamID", '') === '') {
            continue;
        }

        foreach ($suffixes as $suffix) {
            $sequence = pss_pluginSetting("{$prefix}{$suffix}", '');
            if ($sequence === '') {
                continue;
            }
            $playlistName = pss_generatedPlaylistName($league, $suffix, $slot);
            if ($playlistName === '') {
                continue;
            }
            $delaySeconds = pss_teamCelebrationDelay($league, $slot);
            if (pss_writeGeneratedPlaylist($playlistName, $sequence, $delaySeconds)) {
                $keepNames[] = $playlistName;
            }
        }
    }

    pss_cleanupGeneratedPlaylists($league, $keepNames);
}

function pss_syncGeneratedPlaylistSetting($setting) {
    global $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();

    if (!preg_match('/^(nfl|ncaa|nhl|mlb)(2)?(TouchdownSequence|FieldgoalSequence|ScoreSequence|WinSequence)$/', (string)$setting, $matches)) {
        return false;
    }

    // Rebuild both team slots together so cleaning one team's helper playlists
    // can never remove the other team's generated playlists.
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


function pss_clearGameSnapshotState($league, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
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
        pss_setPluginSetting("{$prefix}{$suffix}", $value);
    }
}

function pss_baselineGameSnapshot($league, $eventID, $game, $slot = 1) {
    if (!is_array($game) || empty($game['valid'])) {
        return false;
    }

    $prefix = pss_teamPrefix($league, $slot);
    pss_applyGameSnapshot($league, $game, true, $eventID, $slot);
    pss_setPluginSetting("{$prefix}LastScoringPlayID", pss_latestScoringPlayID($game['scoringPlays']));
    pss_setPluginSetting("{$prefix}LastCelebratedScore", (string)$game['myScore']);

    // If we discover an already-finished game while repairing or changing events,
    // mark it completed so a historical win sequence is not fired on startup.
    if ($game['state'] === 'post') {
        pss_setPluginSetting("{$prefix}LastCompletedEventID", (string)$eventID);
    }

    return true;
}

function pss_activateEventFromTeamInfo($league, $sport, $teamID, $teamInfo, $slot = 1) {
    if (!is_array($teamInfo) || empty($teamInfo['valid']) || empty($teamInfo['nextEventID'])) {
        return false;
    }

    $prefix = pss_teamPrefix($league, $slot);
    $eventID = (string)$teamInfo['nextEventID'];

    // Treat an event change as an atomic game rollover. Clear every game-specific
    // field before writing the new event so old opponent/score/detail data can
    // never be displayed under a new ESPN event ID.
    pss_setPluginSetting("{$prefix}TeamNextEventID", $eventID);
    pss_setPluginSetting("{$prefix}Start", isset($teamInfo['nextEventDate']) ? (string)$teamInfo['nextEventDate'] : '');
    pss_setPluginSetting("{$prefix}GameStatus", isset($teamInfo['nextEventStatus']) ? (string)$teamInfo['nextEventStatus'] : '');
    pss_clearGameSnapshotState($league, $slot);

    // Fetch one full summary immediately, even when the next game is days away.
    $game = pss_getGameStatus($sport, $league, $eventID, $teamID);
    if ($game['valid']) {
        pss_baselineGameSnapshot($league, $eventID, $game, $slot);
    }

    return true;
}

function pss_updateTeam($sport, $league, $slot = 1, $selectedTeamID = null) {
    global $pluginSettings;
    $pluginSettings = pss_loadPluginSettings();
    $prefix = pss_teamPrefix($league, $slot);

    if ($selectedTeamID !== null) {
        // Team-select callbacks can arrive before FPP's own async setting save
        // is visible to PHP. Persist and use the value from the browser so the
        // team metadata and generated playlist name cannot lag one selection.
        $teamID = trim((string)$selectedTeamID);
        pss_setPluginSetting("{$prefix}TeamID", $teamID);
    } else {
        $teamID = pss_pluginSetting("{$prefix}TeamID", '');
    }

    if ($teamID === '') {
        pss_clearLeagueState($league, true, $slot);
        pss_syncGeneratedPlaylistsForLeague($league);
        pss_logEntry(pss_teamLogLabel($league, $slot) . ' cleared');
        return '';
    }

    $teamInfo = pss_getTeamInfo($sport, $league, $teamID);
    if (!$teamInfo['valid']) {
        pss_logEntry(pss_teamLogLabel($league, $slot) . ' update failed; keeping existing state');
        return pss_pluginSetting("{$prefix}TeamLogo", '');
    }

    pss_setPluginSetting("{$prefix}TeamLogo", $teamInfo['logo']);
    pss_setPluginSetting("{$prefix}TeamAbbreviation", $teamInfo['abbreviation']);
    pss_setPluginSetting("{$prefix}TeamName", $teamInfo['name']);
    pss_setPluginSetting("{$prefix}TeamNextEventID", $teamInfo['nextEventID']);
    pss_setPluginSetting("{$prefix}Start", $teamInfo['nextEventDate']);
    pss_setPluginSetting("{$prefix}GameStatus", $teamInfo['nextEventStatus']);
    pss_clearGameSnapshotState($league, $slot);

    if ($teamInfo['nextEventID'] !== '') {
        $game = pss_getGameStatus($sport, $league, $teamInfo['nextEventID'], $teamID);
        if ($game['valid']) {
            pss_baselineGameSnapshot($league, $teamInfo['nextEventID'], $game, $slot);
        }
    }

    pss_syncGeneratedPlaylistsForLeague($league);
    pss_logEntry(pss_teamLogLabel($league, $slot) . " updated to {$teamInfo['name']}");
    return $teamInfo['logo'];
}

function pss_clearLeagueState($league, $clearTeam = false, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
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
        pss_setPluginSetting("{$prefix}{$suffix}", $value);
    }
}

function pss_applyGameSnapshot($league, $status, $updateStatus = true, $eventID = '', $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
    $oppoLogo = isset($status['oppoLogo']) ? trim((string)$status['oppoLogo']) : '';

    // ESPN's game summary does not consistently include team logos in every sport/league.
    // Preserve an already-cached opponent logo, or fetch it once from the team endpoint.
    if ($oppoLogo === '') {
        $cachedOppoID = pss_pluginSetting("{$prefix}OppoID", '');
        if ((string)$cachedOppoID === (string)$status['oppoID']) {
            $oppoLogo = pss_pluginSetting("{$prefix}OppoLogo", '');
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

    pss_setPluginSetting("{$prefix}Start", $status['start']);
    pss_setPluginSetting("{$prefix}OppoID", $status['oppoID']);
    pss_setPluginSetting("{$prefix}OppoName", $status['oppoName']);
    pss_setPluginSetting("{$prefix}OppoAbbreviation", $status['oppoAbbreviation']);
    pss_setPluginSetting("{$prefix}OppoLogo", $oppoLogo);
    pss_setPluginSetting("{$prefix}GameDetail", isset($status['detail']) ? $status['detail'] : '');
    pss_setPluginSetting("{$prefix}MyScore", (string)$status['myScore']);
    pss_setPluginSetting("{$prefix}OppoScore", (string)$status['oppoScore']);
    if ($updateStatus) {
        pss_setPluginSetting("{$prefix}GameStatus", $status['state']);
    }
    if ($eventID !== '') {
        pss_setPluginSetting("{$prefix}GameSnapshotEventID", (string)$eventID);
    }
}

function pss_processFootballScoring($league, $teamID, $plays, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
    $lastID = pss_pluginSetting("{$prefix}LastScoringPlayID", '');
    if (!is_array($plays) || count($plays) === 0) {
        return;
    }

    if ($lastID === '') {
        pss_setPluginSetting("{$prefix}LastScoringPlayID", pss_latestScoringPlayID($plays));
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
        pss_setPluginSetting("{$prefix}LastScoringPlayID", pss_latestScoringPlayID($plays));
        pss_logEntry(pss_teamLogLabel($league, $slot) . ' scoring-play marker was no longer in ESPN response; re-baselined safely');
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
            pss_playConfiguredSequence($league, 'TouchdownSequence', 'Touchdown', $slot);
        } elseif (strpos($haystack, 'field goal') !== false && strpos($haystack, 'no good') === false && strpos($haystack, 'miss') === false) {
            pss_playConfiguredSequence($league, 'FieldgoalSequence', 'Field goal', $slot);
        }
    }

    pss_setPluginSetting("{$prefix}LastScoringPlayID", pss_latestScoringPlayID($plays));
}

function pss_processSimpleScoreIncrease($league, $oldScore, $newScore, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
    $oldScore = (int)$oldScore;
    $newScore = (int)$newScore;
    $lastCelebrated = (int)pss_pluginSetting("{$prefix}LastCelebratedScore", '0');

    if ($newScore > $oldScore && $newScore > $lastCelebrated) {
        pss_playConfiguredSequence($league, 'ScoreSequence', 'Score', $slot);
        pss_setPluginSetting("{$prefix}LastCelebratedScore", (string)$newScore);
    } elseif ($newScore > $lastCelebrated) {
        pss_setPluginSetting("{$prefix}LastCelebratedScore", (string)$newScore);
    }
}

function pss_playConfiguredSequence($league, $suffix, $label, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
    $sequence = pss_pluginSetting("{$prefix}{$suffix}", '');
    $logLabel = pss_teamLogLabel($league, $slot);

    if ($sequence === '') {
        pss_logEntry("{$logLabel} {$label} detected but no sequence is selected");
        return false;
    }

    $playlist = pss_generatedPlaylistName($league, $suffix, $slot);
    $delaySeconds = pss_teamCelebrationDelay($league, $slot);
    if ($playlist === '' || !pss_writeGeneratedPlaylist($playlist, $sequence, $delaySeconds)) {
        pss_logEntry("{$logLabel} {$label} detected but helper playlist could not be prepared for {$sequence}");
        return false;
    }

    if (pss_insertPlaylistImmediate($playlist)) {
        $delayText = ($delaySeconds > 0) ? " after {$delaySeconds}s delay" : '';
        pss_logEntry("{$logLabel} {$label} detected; inserted {$sequence}{$delayText} using playlist {$playlist}");
        return true;
    }

    pss_logEntry("{$logLabel} {$label} detected but FPP rejected generated playlist {$playlist}");
    return false;
}

function pss_updateTeamStatus($reparseSettings = true) {
    global $pluginSettings, $leagues;
    if ($reparseSettings) {
        $pluginSettings = pss_loadPluginSettings();
    }

    $sleepTimes = array(600);
    $logLevel = (int)pss_pluginSetting('logLevel', '4');

    foreach ($leagues as $league) {
        $info = pss_leagueInfo($league);
        $sport = $info['sport'];

        foreach (array(1, 2) as $slot) {
            $prefix = pss_teamPrefix($league, $slot);
            $logLabel = pss_teamLogLabel($league, $slot);
            $teamID = pss_pluginSetting("{$prefix}TeamID", '');
            if ($teamID === '') {
                continue;
            }

            $teamSleep = 600;
            $eventID = pss_pluginSetting("{$prefix}TeamNextEventID", '');
            $gameState = pss_pluginSetting("{$prefix}GameStatus", '');
            $start = pss_pluginSetting("{$prefix}Start", '');
            $snapshotEventID = pss_pluginSetting("{$prefix}GameSnapshotEventID", '');
            $status = null;

            if ($eventID === '') {
                $newInfo = pss_getTeamInfo($sport, $league, $teamID);
                if ($newInfo['valid'] && $newInfo['nextEventID'] !== '') {
                    pss_activateEventFromTeamInfo($league, $sport, $teamID, $newInfo, $slot);
                    $eventID = pss_pluginSetting("{$prefix}TeamNextEventID", '');
                    $gameState = pss_pluginSetting("{$prefix}GameStatus", '');
                    $start = pss_pluginSetting("{$prefix}Start", '');
                    $snapshotEventID = pss_pluginSetting("{$prefix}GameSnapshotEventID", '');
                } else {
                    $sleepTimes[] = 60;
                    continue;
                }
            }

            if ($gameState === 'post') {
                $newInfo = pss_getTeamInfo($sport, $league, $teamID);
                if ($newInfo['valid'] && $newInfo['nextEventID'] !== '' && $newInfo['nextEventID'] !== $eventID) {
                    $oldEventID = $eventID;
                    pss_activateEventFromTeamInfo($league, $sport, $teamID, $newInfo, $slot);
                    $eventID = pss_pluginSetting("{$prefix}TeamNextEventID", '');
                    $gameState = pss_pluginSetting("{$prefix}GameStatus", '');
                    $start = pss_pluginSetting("{$prefix}Start", '');
                    $snapshotEventID = pss_pluginSetting("{$prefix}GameSnapshotEventID", '');
                    pss_logEntry("{$logLabel} next event changed from {$oldEventID} to {$eventID}");
                } else {
                    if ($snapshotEventID !== $eventID || pss_pluginSetting("{$prefix}OppoID", '') === '') {
                        $repair = pss_getGameStatus($sport, $league, $eventID, $teamID);
                        if ($repair['valid']) {
                            pss_baselineGameSnapshot($league, $eventID, $repair, $slot);
                            pss_logEntry("{$logLabel} repaired cached snapshot for event {$eventID}");
                        } else {
                            $teamSleep = 30;
                        }
                    }
                    $sleepTimes[] = $teamSleep;
                    continue;
                }
            }

            if ($snapshotEventID !== $eventID || pss_pluginSetting("{$prefix}OppoID", '') === '') {
                $status = pss_getGameStatus($sport, $league, $eventID, $teamID);
                if ($status['valid']) {
                    pss_baselineGameSnapshot($league, $eventID, $status, $slot);
                    $gameState = $status['state'];
                    $start = $status['start'];
                    $snapshotEventID = $eventID;
                    pss_logEntry("{$logLabel} refreshed full snapshot for event {$eventID}");
                } else {
                    pss_logEntry("{$logLabel} ESPN snapshot refresh failed; keeping event metadata and retrying");
                    $sleepTimes[] = 30;
                    continue;
                }
            }

            if ($gameState === 'pre' && $start !== '') {
                try {
                    $gameDate = new DateTime($start);
                    $secondsToGame = $gameDate->getTimestamp() - time();
                    if ($secondsToGame > 1200) {
                        $teamSleep = min(600, max(60, $secondsToGame - 900));
                        $sleepTimes[] = $teamSleep;
                        continue;
                    }
                } catch (Exception $e) {
                    pss_logEntry("{$logLabel} has invalid start time; polling ESPN for correction");
                }
            }

            if ($status === null) {
                $status = pss_getGameStatus($sport, $league, $eventID, $teamID);
            }
            if (!$status['valid']) {
                pss_logEntry("{$logLabel} ESPN game status request failed; keeping existing game state");
                $sleepTimes[] = 30;
                continue;
            }

            if ($logLevel >= 5) {
                pss_logEntry("{$logLabel} poll: state={$status['state']} score={$status['myScore']}-{$status['oppoScore']}");
            }

            $oldScore = (int)pss_pluginSetting("{$prefix}MyScore", '0');
            if ($status['state'] === 'in') {
                if ($sport === 'football') {
                    pss_processFootballScoring($league, $teamID, $status['scoringPlays'], $slot);
                } else {
                    pss_processSimpleScoreIncrease($league, $oldScore, $status['myScore'], $slot);
                }
                $teamSleep = 10;
            } elseif ($status['state'] === 'pre') {
                $teamSleep = 30;
            } elseif ($status['state'] === 'post') {
                $completed = pss_pluginSetting("{$prefix}LastCompletedEventID", '');
                if ($completed !== $eventID) {
                    if ($status['myScore'] > $status['oppoScore']) {
                        pss_playConfiguredSequence($league, 'WinSequence', 'Win', $slot);
                    }
                    pss_setPluginSetting("{$prefix}LastCompletedEventID", $eventID);
                }
                $teamSleep = 600;
            }

            pss_applyGameSnapshot($league, $status, true, $eventID, $slot);
            $sleepTimes[] = $teamSleep;
        }
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
