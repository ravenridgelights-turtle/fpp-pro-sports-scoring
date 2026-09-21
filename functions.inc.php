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
                pss_jsonResponse(true, 'Team selection updated.', array(
                    'teamPalettes' => array_values(pss_syncTeamPalettes(false))
                ));
            }
            pss_jsonResponse(false, 'Invalid league.');
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
        case 'runTeamEffect':
            pss_runTeamEffect($_POST);
            break;
        case 'stopTeamEffect':
            pss_stopTeamEffect($_POST);
            break;
        case 'saveTickerSettings':
            pss_saveTickerSettings($_POST);
            break;
        case 'testTicker':
            pss_testTickerOutput($_POST);
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

function pss_normalizeTeamColor($value, $default = '') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    if ($value[0] !== '#') {
        $value = '#' . $value;
    }
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $default;
}

function pss_colorRgb($color) {
    $color = pss_normalizeTeamColor($color, '#000000');
    return array(
        hexdec(substr($color, 1, 2)),
        hexdec(substr($color, 3, 2)),
        hexdec(substr($color, 5, 2))
    );
}

function pss_colorDistance($a, $b) {
    $aa = pss_colorRgb($a);
    $bb = pss_colorRgb($b);
    $dr = $aa[0] - $bb[0];
    $dg = $aa[1] - $bb[1];
    $db = $aa[2] - $bb[2];
    return sqrt(($dr * $dr) + ($dg * $dg) + ($db * $db));
}

function pss_teamPaletteThirdColor($primary, $secondary) {
    // ESPN normally gives a primary and alternate team color.  WLED/FPP can
    // expose three segment colors, so choose a neutral third accent that is as
    // visually distinct as possible from both official colors.  Include gray so
    // black/white teams still get three distinct colors.  An effect that only
    // consumes Color 1/2 simply ignores Color 3.
    $candidates = array('#FFFFFF', '#000000', '#808080');
    $best = '#808080';
    $bestScore = -1;
    foreach ($candidates as $candidate) {
        if (strcasecmp($candidate, $primary) === 0 || strcasecmp($candidate, $secondary) === 0) {
            continue;
        }
        $score = min(pss_colorDistance($candidate, $primary), pss_colorDistance($candidate, $secondary));
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }
    return $best;
}

function pss_teamPaletteColorsFromEspn($teamData) {
    $primary = pss_normalizeTeamColor(isset($teamData['color']) ? $teamData['color'] : '', '#FFFFFF');
    $secondary = pss_normalizeTeamColor(isset($teamData['alternateColor']) ? $teamData['alternateColor'] : '', '');

    if ($secondary === '' || strcasecmp($secondary, $primary) === 0) {
        $secondary = (pss_colorDistance($primary, '#000000') >= pss_colorDistance($primary, '#FFFFFF'))
            ? '#000000' : '#FFFFFF';
    }

    $third = pss_teamPaletteThirdColor($primary, $secondary);
    return array($primary, $secondary, $third);
}

function pss_teamPaletteRegistryPath() {
    global $settings, $pluginName;
    $configDir = isset($settings['configDirectory']) ? rtrim((string)$settings['configDirectory'], '/') : '/home/fpp/media/config';
    return $configDir . '/plugin.' . $pluginName . '.team-palettes.json';
}

function pss_storeTeamPaletteSettings($league, $slot, $teamInfo) {
    $prefix = pss_teamPrefix($league, $slot);
    $colors = isset($teamInfo['paletteColors']) && is_array($teamInfo['paletteColors'])
        ? $teamInfo['paletteColors'] : array('#FFFFFF', '#000000', '#FFFFFF');

    pss_setPluginSetting("{$prefix}TeamPaletteName", isset($teamInfo['name']) ? (string)$teamInfo['name'] : '');
    pss_setPluginSetting("{$prefix}TeamColor1", isset($colors[0]) ? pss_normalizeTeamColor($colors[0], '#FFFFFF') : '#FFFFFF');
    pss_setPluginSetting("{$prefix}TeamColor2", isset($colors[1]) ? pss_normalizeTeamColor($colors[1], '#000000') : '#000000');
    pss_setPluginSetting("{$prefix}TeamColor3", isset($colors[2]) ? pss_normalizeTeamColor($colors[2], '#FFFFFF') : '#FFFFFF');
}

function pss_buildSelectedTeamPalettes($refreshMissing = false) {
    global $leagues;
    $palettes = array();

    foreach ($leagues as $league) {
        $leagueInfo = pss_leagueInfo($league);
        if ($leagueInfo['sport'] === '') {
            continue;
        }

        foreach (array(1, 2) as $slot) {
            $prefix = pss_teamPrefix($league, $slot);
            $teamID = trim(pss_pluginSetting("{$prefix}TeamID", ''));
            if ($teamID === '') {
                continue;
            }

            $name = trim(pss_pluginSetting("{$prefix}TeamPaletteName", pss_pluginSetting("{$prefix}TeamName", '')));
            $c1 = pss_normalizeTeamColor(pss_pluginSetting("{$prefix}TeamColor1", ''), '');
            $c2 = pss_normalizeTeamColor(pss_pluginSetting("{$prefix}TeamColor2", ''), '');
            $c3 = pss_normalizeTeamColor(pss_pluginSetting("{$prefix}TeamColor3", ''), '');

            if ($refreshMissing && ($name === '' || $c1 === '' || $c2 === '' || $c3 === '')) {
                $teamInfo = pss_getTeamInfo($leagueInfo['sport'], $league, $teamID);
                if ($teamInfo['valid']) {
                    pss_storeTeamPaletteSettings($league, $slot, $teamInfo);
                    $name = trim((string)$teamInfo['name']);
                    $colors = $teamInfo['paletteColors'];
                    $c1 = $colors[0];
                    $c2 = $colors[1];
                    $c3 = $colors[2];
                }
            }

            // Never keep a stale palette under a newly selected TeamID.  If ESPN
            // is temporarily unavailable, the palette appears on the next sync
            // after the team metadata/colors can be refreshed.
            if ($name === '' || $c1 === '' || $c2 === '' || $c3 === '') {
                continue;
            }

            $key = strtolower((string)$league) . ':' . (string)$teamID;
            $palettes[$key] = array(
                'id' => $key,
                'name' => $name,
                'league' => strtoupper((string)$league),
                'teamID' => (string)$teamID,
                'palette' => '* Colors Only',
                'colors' => array($c1, $c2, $c3)
            );
        }
    }

    uasort($palettes, function ($a, $b) {
        return strnatcasecmp((string)$a['name'], (string)$b['name']);
    });
    return $palettes;
}

function pss_readTeamPaletteRegistry() {
    $path = pss_teamPaletteRegistryPath();
    if (!is_file($path)) {
        return array();
    }
    $raw = @file_get_contents($path);
    $data = ($raw !== false) ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['palettes']) || !is_array($data['palettes'])) {
        return array();
    }
    return $data['palettes'];
}

function pss_syncTeamPalettes($refreshMissing = false) {
    global $pluginName;
    $path = pss_teamPaletteRegistryPath();
    $palettes = pss_buildSelectedTeamPalettes($refreshMissing);

    if (empty($palettes)) {
        if (is_file($path)) {
            @unlink($path);
            pss_logEntry('Removed sports team palette registry because no teams are selected');
        }
        return array();
    }

    $payload = array(
        'version' => 1,
        'managedBy' => (string)$pluginName,
        'paletteMode' => '* Colors Only',
        'palettes' => $palettes
    );
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        pss_logEntry('Could not encode sports team palette registry');
        return $palettes;
    }
    $json .= "\n";

    $oldJson = is_file($path) ? @file_get_contents($path) : false;
    if ($oldJson !== $json) {
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path)) {
            @chmod($path, 0664);
            pss_logEntry('Synced ' . count($palettes) . ' selected sports team palette' . (count($palettes) === 1 ? '' : 's'));
        } else {
            @unlink($tmp);
            pss_logEntry('Could not write sports team palette registry ' . $path);
        }
    }

    return $palettes;
}


function pss_teamEffectPresetDefinition($preset) {
    $preset = strtolower(trim((string)$preset));
    $defs = array(
        'colortwinkles' => array(
            'key' => 'colortwinkles',
            'effect' => 'WLED - Colortwinkles',
            'colorCount' => 3,
            'control1Label' => 'Fade Speed',
            'control2Label' => 'Spawn Speed'
        ),
        'android' => array(
            'key' => 'android',
            'effect' => 'WLED - Android',
            'colorCount' => 2,
            'control1Label' => 'Speed',
            'control2Label' => 'Width'
        )
    );
    return isset($defs[$preset]) ? $defs[$preset] : $defs['colortwinkles'];
}

function pss_findTeamPalette($paletteID) {
    $paletteID = strtolower(trim((string)$paletteID));
    if ($paletteID === '') {
        return null;
    }
    $palettes = pss_syncTeamPalettes(true);
    return isset($palettes[$paletteID]) && is_array($palettes[$paletteID]) ? $palettes[$paletteID] : null;
}

function pss_teamEffectCommonPostValues($post) {
    $model = isset($post['model']) ? trim((string)$post['model']) : '';
    $paletteID = isset($post['paletteID']) ? strtolower(trim((string)$post['paletteID'])) : '';
    $preset = isset($post['preset']) ? strtolower(trim((string)$post['preset'])) : 'colortwinkles';
    $mapping = isset($post['mapping']) ? trim((string)$post['mapping']) : 'Horizontal';
    if (!in_array($mapping, array('Horizontal', 'Vertical'), true)) {
        $mapping = 'Horizontal';
    }
    $autoEnable = isset($post['autoEnable']) ? trim((string)$post['autoEnable']) : 'Enabled';
    if (!in_array($autoEnable, array('False', 'Enabled', 'Transparent', 'Transparent RGB'), true)) {
        $autoEnable = 'Enabled';
    }
    $brightness = pss_clampInt(isset($post['brightness']) ? $post['brightness'] : 128, 0, 255, 128);
    $control1 = pss_clampInt(isset($post['control1']) ? $post['control1'] : 128, 0, 255, 128);
    $control2 = pss_clampInt(isset($post['control2']) ? $post['control2'] : 128, 0, 255, 128);
    return array($model, $paletteID, $preset, $mapping, $autoEnable, $brightness, $control1, $control2);
}

function pss_saveTeamEffectSettings($model, $paletteID, $preset, $mapping, $autoEnable, $brightness, $control1, $control2) {
    pss_setPluginSetting('TeamEffectModel', $model);
    pss_setPluginSetting('TeamEffectPaletteID', $paletteID);
    pss_setPluginSetting('TeamEffectPreset', $preset);
    pss_setPluginSetting('TeamEffectMapping', $mapping);
    pss_setPluginSetting('TeamEffectAutoEnable', $autoEnable);
    pss_setPluginSetting('TeamEffectBrightness', (string)$brightness);
    pss_setPluginSetting('TeamEffectControl1', (string)$control1);
    pss_setPluginSetting('TeamEffectControl2', (string)$control2);
}

function pss_runTeamEffect($post) {
    list($model, $paletteID, $preset, $mapping, $autoEnable, $brightness, $control1, $control2) = pss_teamEffectCommonPostValues($post);

    if ($model === '') {
        pss_jsonResponse(false, 'Select a Pixel Overlay Model first.');
    }
    $models = pss_getOverlayCommandModels();
    if (!empty($models) && !in_array($model, $models, true)) {
        pss_jsonResponse(false, 'The selected Pixel Overlay Model is not currently returned by FPP.');
    }

    $palette = pss_findTeamPalette($paletteID);
    if (!is_array($palette)) {
        pss_jsonResponse(false, 'Select a currently managed sports team palette first.');
    }

    $def = pss_teamEffectPresetDefinition($preset);
    $colors = isset($palette['colors']) && is_array($palette['colors']) ? array_values($palette['colors']) : array();
    while (count($colors) < 3) {
        $colors[] = '#000000';
    }
    $colors = array(
        pss_normalizeColor($colors[0], '#FFFFFF'),
        pss_normalizeColor($colors[1], '#000000'),
        pss_normalizeColor($colors[2], '#808080')
    );

    // These arrays intentionally mirror the exact fields shown by FPP's
    // Overlay Model Effect command editor for the two initial team-color effects.
    // FPP/WLED's "* Colors Only" palette consumes the segment colors supplied
    // after the palette argument.  Android exposes two colors; Colortwinkles
    // exposes all three.  The registry still always keeps all three team colors.
    if ($def['key'] === 'android') {
        $args = array(
            $model,
            $autoEnable,
            $def['effect'],
            $mapping,
            (string)$brightness,
            (string)$control1,
            (string)$control2,
            '* Colors Only',
            $colors[0],
            $colors[1]
        );
    } else {
        $args = array(
            $model,
            $autoEnable,
            $def['effect'],
            $mapping,
            (string)$brightness,
            (string)$control1,
            (string)$control2,
            '* Colors Only',
            $colors[0],
            $colors[1],
            $colors[2]
        );
    }

    $response = pss_runFppCommandExact('Overlay Model Effect', $args);
    if (!$response['ok']) {
        $detail = pss_overlayCommandErrorText($response);
        pss_logEntry(
            'FPP rejected team palette effect ' . $def['effect'] . ' for ' . $model
            . ' HTTP ' . $response['status']
            . ($detail !== '' ? ' response=' . $detail : '')
            . ' team=' . (isset($palette['name']) ? $palette['name'] : $paletteID)
        );
        pss_jsonResponse(false, 'FPP rejected the team effect. Check the plugin log.', array(
            'httpStatus' => $response['status'],
            'detail' => $detail
        ));
    }

    pss_saveTeamEffectSettings($model, $paletteID, $def['key'], $mapping, $autoEnable, $brightness, $control1, $control2);
    $teamName = isset($palette['name']) ? (string)$palette['name'] : $paletteID;
    pss_logEntry(
        'Started team palette effect ' . $def['effect'] . ' on ' . $model
        . ' team=' . $teamName
        . ' palette=* Colors Only colors=' . implode(',', array_slice($colors, 0, (int)$def['colorCount']))
    );
    pss_jsonResponse(true, $teamName . ' colors started on ' . $model . ' using ' . $def['effect'] . '.', array(
        'model' => $model,
        'team' => $teamName,
        'effect' => $def['effect'],
        'palette' => '* Colors Only',
        'colors' => array_slice($colors, 0, (int)$def['colorCount']),
        'colorCount' => (int)$def['colorCount']
    ));
}

function pss_stopTeamEffect($post) {
    $model = isset($post['model']) ? trim((string)$post['model']) : trim(pss_pluginSetting('TeamEffectModel', ''));
    if ($model === '') {
        pss_jsonResponse(false, 'Select a Pixel Overlay Model first.');
    }

    // Same Stop Effects selection exposed by FPP's Overlay Model Effect command.
    $response = pss_runFppCommandExact('Overlay Model Effect', array($model, 'Enabled', 'Stop Effects'));
    if (!$response['ok']) {
        $detail = pss_overlayCommandErrorText($response);
        pss_logEntry(
            'FPP rejected Stop Effects for team palette model ' . $model
            . ' HTTP ' . $response['status']
            . ($detail !== '' ? ' response=' . $detail : '')
        );
        pss_jsonResponse(false, 'FPP could not stop the team effect. Check the plugin log.');
    }

    pss_logEntry('Stopped team palette effects on ' . $model);
    pss_jsonResponse(true, 'Stopped overlay effects on ' . $model . '.');
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
        $orientation = isset($model['Orientation']) ? trim((string)$model['Orientation']) : '';
        $startCorner = isset($model['StartCorner']) ? trim((string)$model['StartCorner']) : '';
        $result[$name] = array(
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'orientation' => $orientation,
            'startCorner' => $startCorner,
            'xlights' => !empty($model['xLights'])
        );
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function pss_getOverlayCommandModels() {
    $models = array();
    $data = pss_httpJson('http://127.0.0.1/api/models?simple=true&all=true');
    if (is_array($data)) {
        foreach ($data as $entry) {
            if (is_string($entry)) {
                $name = trim($entry);
            } elseif (is_array($entry) && isset($entry['name'])) {
                $name = trim((string)$entry['name']);
            } elseif (is_array($entry) && isset($entry['Name'])) {
                $name = trim((string)$entry['Name']);
            } else {
                $name = '';
            }
            if ($name === '' || $name === '--All Models--') {
                continue;
            }
            $models[$name] = $name;
        }
    }

    // Fall back to model-overlays.json if the live command API is unavailable.
    if (empty($models)) {
        foreach (pss_getOverlayModels() as $name => $info) {
            $name = trim((string)$name);
            if ($name !== '') {
                $models[$name] = $name;
            }
        }
    }

    ksort($models, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($models);
}

function pss_getOverlayFonts() {
    $fonts = array();
    $data = pss_httpJson('http://127.0.0.1/api/overlays/fonts');
    if (is_array($data) && isset($data['fonts']) && is_array($data['fonts'])) {
        $data = $data['fonts'];
    }
    if (is_array($data)) {
        foreach ($data as $entry) {
            $font = '';
            if (is_string($entry)) {
                $font = trim($entry);
            } elseif (is_array($entry)) {
                foreach (array('value', 'name', 'Name', 'font', 'label', 'path') as $key) {
                    if (isset($entry[$key]) && is_scalar($entry[$key])) {
                        $font = trim((string)$entry[$key]);
                        if ($font !== '') break;
                    }
                }
            }
            if ($font !== '') {
                $fonts[$font] = $font;
            }
        }
    }

    // The FPP command window on this player has already confirmed this alias.
    if (empty($fonts)) {
        $fonts['C059-Bdlta'] = 'C059-Bdlta';
    }
    ksort($fonts, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($fonts);
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

// Pixel Overlay command-window compatible request.  FPP's own Pixel Overlay UI
// posts only {command,args}; do not add multisync fields or model geometry.
function pss_runFppCommandExact($command, $args) {
    $payload = array(
        'command' => (string)$command,
        'args' => is_array($args) ? array_values($args) : array()
    );
    return pss_httpRequest('http://127.0.0.1/api/command', 'POST', $payload, 'text/plain, application/json');
}

// FPP 10 also exposes commands through /api/command/<command>/<arg...>.
// Pixel Overlay commands are sent through this route because FPP 10.1.2's
// generic POST /api/command path can return HTTP 500 for overlay commands on
// some players even though the same command succeeds from FPP's command UI.
function pss_runFppCommandPath($command, $args) {
    $parts = array(rawurlencode((string)$command));
    if (is_array($args)) {
        foreach ($args as $arg) {
            $parts[] = rawurlencode((string)$arg);
        }
    }

    $url = 'http://127.0.0.1/api/command/' . implode('/', $parts);
    return pss_httpRequest($url, 'GET', null, 'text/plain, application/json');
}

function pss_legacyOverlayTickerPaths() {
    return array(
        'script' => '/tmp/fpp-pro-sports-scoring-overlay-ticker.php',
        'pid' => '/tmp/fpp-pro-sports-scoring-overlay-ticker.pid'
    );
}

function pss_legacyOverlayTickerProcessMatches($pid) {
    $pid = (int)$pid;
    if ($pid <= 1 || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    return is_string($cmdline)
        && strpos($cmdline, 'fpp-pro-sports-scoring-overlay-ticker.php') !== false;
}

function pss_legacyOverlayTickerPid() {
    $paths = pss_legacyOverlayTickerPaths();
    if (!is_file($paths['pid'])) {
        return 0;
    }
    $pid = (int)trim((string)@file_get_contents($paths['pid']));
    if (!pss_legacyOverlayTickerProcessMatches($pid)) {
        @unlink($paths['pid']);
        return 0;
    }
    return $pid;
}

function pss_legacyOverlayTickerIsRunning() {
    return pss_legacyOverlayTickerPid() > 1;
}

function pss_stopLegacyOverlayTicker() {
    $paths = pss_legacyOverlayTickerPaths();
    $pid = pss_legacyOverlayTickerPid();
    if ($pid > 1) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } elseif (function_exists('exec')) {
            @exec('kill -TERM ' . (int)$pid . ' >/dev/null 2>&1');
        }

        for ($i = 0; $i < 20 && pss_legacyOverlayTickerProcessMatches($pid); $i++) {
            usleep(25000);
        }
        if (pss_legacyOverlayTickerProcessMatches($pid)) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            } elseif (function_exists('exec')) {
                @exec('kill -KILL ' . (int)$pid . ' >/dev/null 2>&1');
            }
        }
    }
    @unlink($paths['pid']);
}

function pss_findPhpCli() {
    $candidates = array();
    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function pss_writeLegacyOverlayTickerHelper() {
    $paths = pss_legacyOverlayTickerPaths();
    $script = <<<'PHPHELPER'
#!/usr/bin/php
<?php
// FPP 10 direct shared-memory sports ticker fallback.
// This intentionally does not depend on FPP::MemoryMap, GD, or ImageMagick.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI PHP required\n");
    exit(2);
}

function fail($msg, $code = 2) {
    fwrite(STDERR, $msg . "\n");
    exit($code);
}

function loadModel($name) {
    $file = '/home/fpp/media/config/model-overlays.json';
    $raw = @file_get_contents($file);
    if ($raw === false) fail("Cannot read {$file}");
    $json = json_decode($raw, true);
    if (!is_array($json)) fail("Invalid model-overlays.json");
    $models = isset($json['models']) && is_array($json['models']) ? $json['models'] : $json;
    foreach ($models as $m) {
        if (is_array($m) && isset($m['Name']) && (string)$m['Name'] === $name) return $m;
    }
    fail("Pixel Overlay model not found in model-overlays.json: {$name}");
}

function sharedDataPath($name) {
    $exact = '/dev/shm/FPP-Model-Data-' . $name;
    if (is_file($exact)) return $exact;
    foreach ((array)glob('/dev/shm/FPP-Model-Data-*') as $path) {
        if (substr($path, strlen('/dev/shm/FPP-Model-Data-')) === $name) return $path;
    }
    return '';
}

function rgbFromHex($hex) {
    $hex = ltrim(trim((string)$hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = 'FFFFFF';
    return array(hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)));
}

function fontMap() {
    // Compact 5x7 bitmap font. Each glyph is seven 5-bit rows.
    return array(
        ' '=>array(0,0,0,0,0,0,0),'!'=>array(4,4,4,4,4,0,4),'"'=>array(10,10,10,0,0,0,0),
        '#'=>array(10,31,10,10,31,10,0),'$'=>array(4,15,20,14,5,30,4),'%'=>array(24,25,2,4,8,19,3),
        '&'=>array(12,18,20,8,21,18,13),"'"=>array(4,4,8,0,0,0,0),'('=>array(2,4,8,8,8,4,2),')'=>array(8,4,2,2,2,4,8),
        '*'=>array(0,4,21,14,21,4,0),'+'=>array(0,4,4,31,4,4,0),','=>array(0,0,0,0,4,4,8),'-'=>array(0,0,0,31,0,0,0),
        '.'=>array(0,0,0,0,0,12,12),'/'=>array(1,2,2,4,8,8,16),
        '0'=>array(14,17,19,21,25,17,14),'1'=>array(4,12,4,4,4,4,14),'2'=>array(14,17,1,2,4,8,31),
        '3'=>array(30,1,1,14,1,1,30),'4'=>array(2,6,10,18,31,2,2),'5'=>array(31,16,16,30,1,1,30),
        '6'=>array(14,16,16,30,17,17,14),'7'=>array(31,1,2,4,8,8,8),'8'=>array(14,17,17,14,17,17,14),
        '9'=>array(14,17,17,15,1,1,14),':'=>array(0,12,12,0,12,12,0),';'=>array(0,12,12,0,12,4,8),
        '<'=>array(2,4,8,16,8,4,2),'='=>array(0,31,0,31,0,0,0),'>'=>array(8,4,2,1,2,4,8),'?'=>array(14,17,1,2,4,0,4),
        '@'=>array(14,17,23,21,23,16,14),
        'A'=>array(14,17,17,31,17,17,17),'B'=>array(30,17,17,30,17,17,30),'C'=>array(14,17,16,16,16,17,14),
        'D'=>array(28,18,17,17,17,18,28),'E'=>array(31,16,16,30,16,16,31),'F'=>array(31,16,16,30,16,16,16),
        'G'=>array(14,17,16,23,17,17,15),'H'=>array(17,17,17,31,17,17,17),'I'=>array(14,4,4,4,4,4,14),
        'J'=>array(7,2,2,2,2,18,12),'K'=>array(17,18,20,24,20,18,17),'L'=>array(16,16,16,16,16,16,31),
        'M'=>array(17,27,21,21,17,17,17),'N'=>array(17,25,21,19,17,17,17),'O'=>array(14,17,17,17,17,17,14),
        'P'=>array(30,17,17,30,16,16,16),'Q'=>array(14,17,17,17,21,18,13),'R'=>array(30,17,17,30,20,18,17),
        'S'=>array(15,16,16,14,1,1,30),'T'=>array(31,4,4,4,4,4,4),'U'=>array(17,17,17,17,17,17,14),
        'V'=>array(17,17,17,17,17,10,4),'W'=>array(17,17,17,21,21,21,10),'X'=>array(17,17,10,4,10,17,17),
        'Y'=>array(17,17,10,4,4,4,4),'Z'=>array(31,1,2,4,8,16,31),'['=>array(14,8,8,8,8,8,14),
        '\\'=>array(16,8,8,4,2,2,1),']'=>array(14,2,2,2,2,2,14),'^'=>array(4,10,17,0,0,0,0),'_'=>array(0,0,0,0,0,0,31),
        '|'=>array(4,4,4,4,4,4,4)
    );
}

function logicalToPhysicalMap($m, $w, $h, $cpp) {
    $orientation = strtolower(isset($m['Orientation']) ? (string)$m['Orientation'] : 'vertical');
    $corner = strtoupper(isset($m['StartCorner']) ? (string)$m['StartCorner'] : 'TL');
    $map = array_fill(0, $w * $h, 0);

    if ($orientation === 'horizontal') {
        $startTop = strpos($corner, 'T') !== false;
        $startLeft = strpos($corner, 'L') !== false;
        for ($y = 0; $y < $h; $y++) {
            $strand = $startTop ? $y : ($h - 1 - $y);
            $forward = (($strand % 2) === 0) ? $startLeft : !$startLeft;
            for ($x = 0; $x < $w; $x++) {
                $within = $forward ? $x : ($w - 1 - $x);
                $map[$y * $w + $x] = ($strand * $w + $within) * $cpp;
            }
        }
    } else {
        $startLeft = strpos($corner, 'L') !== false;
        $startTop = strpos($corner, 'T') !== false;
        for ($x = 0; $x < $w; $x++) {
            $strand = $startLeft ? $x : ($w - 1 - $x);
            $forward = (($strand % 2) === 0) ? $startTop : !$startTop;
            for ($y = 0; $y < $h; $y++) {
                $within = $forward ? $y : ($h - 1 - $y);
                $map[$y * $w + $x] = ($strand * $h + $within) * $cpp;
            }
        }
    }
    return $map;
}

$name = isset($argv[1]) ? (string)$argv[1] : '';
$msg = isset($argv[2]) ? (string)$argv[2] : 'PRO SPORTS SCORING';
$color = isset($argv[3]) ? (string)$argv[3] : '#FFFFFF';
$fontSize = isset($argv[4]) ? max(4, min(100, (int)$argv[4])) : 16;
$direction = isset($argv[5]) && $argv[5] === 'L2R' ? 'L2R' : 'R2L';
$speed = isset($argv[6]) ? max(1, min(200, (int)$argv[6])) : 10;
if ($name === '') fail('Missing Pixel Overlay model');

$m = loadModel($name);
$cpp = max(3, isset($m['ChannelCountPerNode']) ? (int)$m['ChannelCountPerNode'] : 3);
$channels = isset($m['ChannelCount']) ? (int)$m['ChannelCount'] : 0;
$nodes = $channels > 0 ? intdiv($channels, $cpp) : 0;
$strands = max(1, (int)(isset($m['StringCount']) ? $m['StringCount'] : 1) * (int)(isset($m['StrandsPerString']) ? $m['StrandsPerString'] : 1));
$orientation = strtolower(isset($m['Orientation']) ? (string)$m['Orientation'] : 'vertical');
if ($nodes <= 0 || $strands <= 0 || ($nodes % $strands) !== 0) fail('Unsupported Pixel Overlay matrix dimensions');
if ($orientation === 'horizontal') {
    $h = $strands;
    $w = intdiv($nodes, $strands);
} else {
    $w = $strands;
    $h = intdiv($nodes, $strands);
}
if ($w <= 0 || $h <= 0) fail('Invalid Pixel Overlay dimensions');

$path = '';
for ($i = 0; $i < 30; $i++) {
    $path = sharedDataPath($name);
    if ($path !== '') break;
    usleep(100000);
}
if ($path === '') fail('FPP shared model buffer not found for ' . $name);
$fh = @fopen($path, 'r+b');
if (!$fh) fail('Cannot open FPP shared model buffer: ' . $path);

$map = logicalToPhysicalMap($m, $w, $h, $cpp);
$font = fontMap();
$msg = strtoupper($msg);
$scale = max(1, min(4, (int)round($fontSize / 7)));
$glyphW = 5 * $scale;
$cellW = 6 * $scale;
$textW = max(1, strlen($msg) * $cellW);
$textH = 7 * $scale;
$y0 = max(0, intdiv($h - $textH, 2));
list($red,$green,$blue) = rgbFromHex($color);
$frameLen = $nodes * $cpp;
$fps = 20.0;
$step = $speed / $fps;
if ($step < 0.25) $step = 0.25;
$pos = ($direction === 'R2L') ? (float)$w : (float)(-$textW);
$frameDelay = (int)round(1000000.0 / $fps);

while (true) {
    $frame = str_repeat("\0", $frameLen);
    $left = (int)floor($pos);
    for ($x = 0; $x < $w; $x++) {
        $tx = $x - $left;
        if ($tx < 0 || $tx >= $textW) continue;
        $charIndex = intdiv($tx, $cellW);
        $charX = $tx % $cellW;
        if ($charX >= $glyphW || $charIndex < 0 || $charIndex >= strlen($msg)) continue;
        $ch = $msg[$charIndex];
        $rows = isset($font[$ch]) ? $font[$ch] : $font['?'];
        $glyphX = intdiv($charX, $scale);
        $mask = 1 << (4 - $glyphX);
        for ($gy = 0; $gy < 7; $gy++) {
            if (($rows[$gy] & $mask) === 0) continue;
            for ($sy = 0; $sy < $scale; $sy++) {
                $y = $y0 + $gy * $scale + $sy;
                if ($y < 0 || $y >= $h) continue;
                $off = $map[$y * $w + $x];
                if ($off + 2 >= $frameLen) continue;
                $frame[$off] = chr($red);
                $frame[$off + 1] = chr($green);
                $frame[$off + 2] = chr($blue);
            }
        }
    }

    @fseek($fh, 0);
    $written = @fwrite($fh, $frame);
    @fflush($fh);
    if ($written === false || $written <= 0) fail('Lost write access to FPP shared model buffer');

    if ($direction === 'R2L') {
        $pos -= $step;
        if ($pos < -$textW - $w) $pos = (float)$w;
    } else {
        $pos += $step;
        if ($pos > $w + $textW) $pos = (float)(-$textW);
    }
    usleep($frameDelay);
}
PHPHELPER;

    $tmp = $paths['script'] . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $script) === false) {
        return false;
    }
    @chmod($tmp, 0700);
    if (!@rename($tmp, $paths['script'])) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function pss_startLegacyOverlayTicker($model, $text, $color, $fontSize, $direction, $speed) {
    global $logFile;

    if (!function_exists('exec')) {
        pss_logEntry('FPP shared-memory ticker fallback is unavailable because PHP exec() is disabled');
        return false;
    }
    $php = pss_findPhpCli();
    if ($php === '') {
        pss_logEntry('FPP shared-memory ticker fallback is unavailable because PHP CLI was not found');
        return false;
    }

    pss_stopLegacyOverlayTicker();

    // The direct model-data buffer only drives output while the model is enabled.
    $stateResponse = pss_runFppCommandPath('Overlay Model State', array($model, 'Enabled', '0', '100'));
    if (!is_array($stateResponse) || !$stateResponse['ok']) {
        $status = is_array($stateResponse) && isset($stateResponse['status']) ? $stateResponse['status'] : 0;
        pss_logEntry("Could not enable Pixel Overlay model {$model} for shared-memory ticker fallback (HTTP {$status})");
        return false;
    }

    if (!pss_writeLegacyOverlayTickerHelper()) {
        pss_logEntry('Could not write the FPP shared-memory ticker helper under /tmp');
        return false;
    }

    $paths = pss_legacyOverlayTickerPaths();
    $legacyDirection = ($direction === 'Left to Right') ? 'L2R' : 'R2L';
    $legacySpeed = max(1, min(200, (int)$speed));
    $legacySize = max(4, min(100, (int)$fontSize));
    $helperLog = '/tmp/fpp-pro-sports-scoring-overlay-ticker-helper.log';
    @file_put_contents($helperLog, '');

    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($paths['script'])
        . ' ' . escapeshellarg((string)$model)
        . ' ' . escapeshellarg((string)$text)
        . ' ' . escapeshellarg((string)$color)
        . ' ' . escapeshellarg((string)$legacySize)
        . ' ' . escapeshellarg($legacyDirection)
        . ' ' . escapeshellarg((string)$legacySpeed)
        . ' >> ' . escapeshellarg($helperLog) . ' 2>&1 & echo $!';

    $output = array();
    $rc = 0;
    @exec($cmd, $output, $rc);
    $pid = !empty($output) ? (int)trim((string)end($output)) : 0;
    if ($rc !== 0 || $pid <= 1) {
        pss_logEntry("Could not launch FPP shared-memory ticker fallback for {$model}");
        return false;
    }

    @file_put_contents($paths['pid'], (string)$pid);
    usleep(250000);
    if (!pss_legacyOverlayTickerProcessMatches($pid)) {
        @unlink($paths['pid']);
        $detail = trim((string)@file_get_contents($helperLog));
        if (strlen($detail) > 500) $detail = substr($detail, -500);
        pss_logEntry("FPP shared-memory ticker fallback exited immediately for {$model}" . ($detail !== '' ? ": {$detail}" : ''));
        return false;
    }

    pss_logEntry("Using FPP shared-memory ticker fallback for {$model} (pid {$pid})");
    return true;
}

function pss_clearOverlayModel($model) {
    $model = trim((string)$model);
    if ($model === '') {
        return false;
    }

    // Stop any helper left behind by older plugin builds, then issue only the
    // same Overlay Model Clear command available in FPP's command window.
    pss_stopLegacyOverlayTicker();
    $response = pss_runFppCommandExact('Overlay Model Clear', array($model));
    if (!$response['ok']) {
        $detail = pss_overlayCommandErrorText($response);
        pss_logEntry(
            "FPP rejected Overlay Model Clear for {$model} with HTTP {$response['status']}"
            . ($detail !== '' ? " response={$detail}" : '')
        );
        return false;
    }
    return true;
}

function pss_resolveOverlayFont($requestedFont) {
    $requestedFont = trim((string)$requestedFont);

    // IMPORTANT: FPP's Text effect expects the font value shown by its own
    // Overlay Model Effect -> Text dropdown.  Do not translate those names to
    // filesystem paths here.  On FPP 10.1.2 the player can advertise both
    // ImageMagick font aliases and font-file paths, but the alias C059-Bdlta is
    // confirmed to start and scroll Text successfully on this player while the
    // previously forced Lato-Bold.ttf path returns "Could not start effect: Text".
    //
    // Older plugin builds stored "Helvetica" and then rewrote it to a Lato
    // path.  Keep existing settings compatible by mapping that historical value
    // (and the Lato path we previously injected) to the known-good FPP alias.
    if ($requestedFont === ''
        || strcasecmp($requestedFont, 'Helvetica') === 0
        || strcasecmp($requestedFont, 'Lato-Bold') === 0
        || strcasecmp($requestedFont, 'Lato-Bold.ttf') === 0
        || $requestedFont === '/usr/share/fonts/truetype/lato/Lato-Bold.ttf') {
        return 'C059-Bdlta';
    }

    // Otherwise preserve exactly what the user entered/selected.  FPP owns
    // font discovery and validation; changing a valid FPP alias here can turn a
    // working command into a 500 before TextEffect ever begins scrolling.
    return $requestedFont;
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

function pss_runExactOverlayTextCommand($model, $autoEnable, $color, $font, $fontSize, $antiAlias, $position, $speed, $duration, $text) {
    $model = trim((string)$model);
    if ($model === '') {
        return false;
    }

    $allowedAutoEnable = array('False', 'Enabled', 'Transparent', 'Transparent RGB');
    if (!in_array($autoEnable, $allowedAutoEnable, true)) {
        $autoEnable = 'Enabled';
    }
    $color = pss_normalizeColor($color, '#FFFFFF');
    $font = trim((string)$font);
    if ($font === '') {
        $font = 'C059-Bdlta';
    }
    $fontSize = pss_clampInt($fontSize, 4, 100, 20);
    $antiAlias = $antiAlias ? 'true' : 'false';
    $allowedPositions = array('Center', 'Right to Left', 'Left to Right', 'Bottom to Top', 'Top to Bottom');
    if (!in_array($position, $allowedPositions, true)) {
        $position = 'Right to Left';
    }
    $speed = pss_clampInt($speed, 0, 200, 10);
    $duration = pss_clampInt($duration, -1, 2000, 0);
    $text = trim((string)$text);
    if ($text === '') {
        $text = 'PRO SPORTS SCORING';
    }
    if (strlen($text) > 1200) {
        $text = substr($text, 0, 1200);
    }

    // EXACT FPP Overlay Model Effect -> Text argument order. Nothing else is
    // appended: no width, height, orientation, channel count, or model geometry.
    $args = array(
        $model,
        $autoEnable,
        'Text',
        $color,
        $font,
        (string)$fontSize,
        $antiAlias,
        $position,
        (string)$speed,
        (string)$duration,
        $text
    );

    $response = pss_runFppCommandExact('Overlay Model Effect', $args);
    if (!$response['ok']) {
        $detail = pss_overlayCommandErrorText($response);
        pss_logEntry(
            "FPP Overlay Model Effect/Text rejected for {$model} HTTP {$response['status']}"
            . ($detail !== '' ? " response={$detail}" : '')
            . " autoEnable={$autoEnable} color={$color} font={$font} fontSize={$fontSize}"
            . " antiAlias={$antiAlias} position={$position} speed={$speed} duration={$duration}"
            . " textLength=" . strlen($text)
        );
        return false;
    }

    pss_logEntry(
        "FPP Overlay Model Effect/Text started for {$model}"
        . " font={$font} fontSize={$fontSize} position={$position} speed={$speed}"
        . " duration={$duration} textLength=" . strlen($text)
    );
    return true;
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

    $autoEnable = pss_pluginSetting('TickerOverlayAutoEnable', 'Enabled');
    $color = pss_pluginSetting('TickerTextColor', '#FFFFFF');
    // Pass the font exactly as FPP returned/stored it. No font rewriting.
    $font = trim(pss_pluginSetting('TickerFont', 'C059-Bdlta'));
    $fontSize = pss_pluginSetting('TickerFontSize', '20');
    $antiAlias = (pss_pluginSetting('TickerFontAntiAlias', 'OFF') === 'ON');
    $position = pss_pluginSetting('TickerDirection', 'Right to Left');
    $speed = pss_pluginSetting('TickerScrollSpeed', '10');
    $duration = pss_pluginSetting('TickerDuration', '0');

    $text = trim((string)$text);
    if ($text === '') {
        $text = 'PRO SPORTS SCORING';
    }
    if (strlen($text) > 1200) {
        $text = substr($text, 0, 1200);
    }

    $signature = md5(implode('|', array(
        $model, $autoEnable, $color, $font, $fontSize,
        $antiAlias ? 'true' : 'false', $position, $speed, $duration, $text
    )));
    if (!$force && $signature === $lastSignature) {
        return true;
    }

    if ($lastModel !== '' && $lastModel !== $model) {
        pss_clearOverlayModel($lastModel);
    }

    // Do not fall back to MemoryMap/shared memory. The selected FPP model has
    // already been proven to support the native Text command; use only that path.
    pss_stopLegacyOverlayTicker();
    $ok = pss_runExactOverlayTextCommand(
        $model, $autoEnable, $color, $font, $fontSize,
        $antiAlias, $position, $speed, $duration, $text
    );
    if (!$ok) {
        return false;
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
    $allowedPositions = array('Center', 'Right to Left', 'Left to Right', 'Bottom to Top', 'Top to Bottom');
    if (!in_array($direction, $allowedPositions, true)) $direction = 'Right to Left';

    $autoEnable = isset($post['TickerOverlayAutoEnable']) ? trim((string)$post['TickerOverlayAutoEnable']) : 'Enabled';
    $allowedAutoEnable = array('False', 'Enabled', 'Transparent', 'Transparent RGB');
    if (!in_array($autoEnable, $allowedAutoEnable, true)) $autoEnable = 'Enabled';

    $values = array(
        'TickerEnabled' => (isset($post['TickerEnabled']) && (string)$post['TickerEnabled'] === 'ON') ? 'ON' : 'OFF',
        'TickerKioskEnabled' => (isset($post['TickerKioskEnabled']) && (string)$post['TickerKioskEnabled'] === 'ON') ? 'ON' : 'OFF',
        'TickerStyle' => $style,
        'TickerWebSpeed' => (string)pss_clampInt(isset($post['TickerWebSpeed']) ? $post['TickerWebSpeed'] : 90, 20, 300, 90),
        'TickerWebFontSize' => (string)pss_clampInt(isset($post['TickerWebFontSize']) ? $post['TickerWebFontSize'] : 18, 12, 48, 18),
        'TickerSpacing' => (string)pss_clampInt(isset($post['TickerSpacing']) ? $post['TickerSpacing'] : 4, 1, 12, 4),
        'TickerOverlayEnabled' => (isset($post['TickerOverlayEnabled']) && (string)$post['TickerOverlayEnabled'] === 'ON') ? 'ON' : 'OFF',
        'TickerOverlayModel' => isset($post['TickerOverlayModel']) ? trim((string)$post['TickerOverlayModel']) : '',
        'TickerOverlayAutoEnable' => $autoEnable,
        'TickerFont' => isset($post['TickerFont']) ? trim((string)$post['TickerFont']) : 'C059-Bdlta',
        'TickerFontSize' => (string)pss_clampInt(isset($post['TickerFontSize']) ? $post['TickerFontSize'] : 20, 4, 100, 20),
        'TickerFontAntiAlias' => (isset($post['TickerFontAntiAlias']) && (string)$post['TickerFontAntiAlias'] === 'ON') ? 'ON' : 'OFF',
        'TickerTextColor' => pss_normalizeColor(isset($post['TickerTextColor']) ? $post['TickerTextColor'] : '#FFFFFF'),
        'TickerDirection' => $direction,
        'TickerScrollSpeed' => (string)pss_clampInt(isset($post['TickerScrollSpeed']) ? $post['TickerScrollSpeed'] : 10, 0, 200, 10),
        'TickerDuration' => (string)pss_clampInt(isset($post['TickerDuration']) ? $post['TickerDuration'] : 0, -1, 2000, 0)
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

    if ($values['TickerFont'] === '') $values['TickerFont'] = 'C059-Bdlta';

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
        'overlayTickerText' => pss_buildTickerText(true),
        'tickerItems' => pss_buildTickerItems(false),
        'tickerSpacing' => pss_tickerSpacing(),
        'tickerWebFontSize' => pss_clampInt(pss_pluginSetting('TickerWebFontSize', '18'), 12, 48, 18)
    ));
}

function pss_testTickerOutput($post = array()) {
    if (!isset($post['TickerOverlayEnabled']) || (string)$post['TickerOverlayEnabled'] !== 'ON') {
        pss_jsonResponse(false, 'Enable Pixel Overlay output first.');
    }

    $model = isset($post['TickerOverlayModel']) ? trim((string)$post['TickerOverlayModel']) : '';
    if ($model === '') {
        pss_jsonResponse(false, 'Select a Pixel Overlay Model first.');
    }

    $autoEnable = isset($post['TickerOverlayAutoEnable']) ? trim((string)$post['TickerOverlayAutoEnable']) : 'Enabled';
    $color = isset($post['TickerTextColor']) ? (string)$post['TickerTextColor'] : '#FFFFFF';
    $font = isset($post['TickerFont']) ? trim((string)$post['TickerFont']) : 'C059-Bdlta';
    $fontSize = isset($post['TickerFontSize']) ? $post['TickerFontSize'] : 20;
    $antiAlias = isset($post['TickerFontAntiAlias']) && (string)$post['TickerFontAntiAlias'] === 'ON';
    $position = isset($post['TickerDirection']) ? trim((string)$post['TickerDirection']) : 'Right to Left';
    $speed = isset($post['TickerScrollSpeed']) ? $post['TickerScrollSpeed'] : 10;
    $duration = isset($post['TickerDuration']) ? $post['TickerDuration'] : 0;
    $text = isset($post['TickerCommandText']) ? trim((string)$post['TickerCommandText']) : pss_buildTickerText(true);
    if ($text === '') {
        $text = pss_buildTickerText(true);
    }

    pss_stopLegacyOverlayTicker();
    $ok = pss_runExactOverlayTextCommand(
        $model, $autoEnable, $color, $font, $fontSize,
        $antiAlias, $position, $speed, $duration, $text
    );
    pss_jsonResponse(
        $ok,
        $ok ? 'Exact FPP Overlay Model Effect/Text command sent to ' . $model . '.' : 'FPP rejected the exact Text command. Check the plugin log.'
    );
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
        'nextEventStatus' => '',
        'paletteColors' => array('#FFFFFF', '#000000', '#FFFFFF')
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
    $info['paletteColors'] = pss_teamPaletteColorsFromEspn($teamData);

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

    $previousTeamID = trim(pss_pluginSetting("{$prefix}TeamID", ''));
    if ($selectedTeamID !== null) {
        // Team-select callbacks can arrive before FPP's own async setting save
        // is visible to PHP. Persist and use the value from the browser so the
        // team metadata and generated playlist name cannot lag one selection.
        $teamID = trim((string)$selectedTeamID);
        pss_setPluginSetting("{$prefix}TeamID", $teamID);
        if ($teamID !== $previousTeamID) {
            // Clear metadata from the previous team immediately.  This prevents
            // a transient ESPN failure from keeping the old team's palette under
            // the new TeamID.
            pss_clearLeagueState($league, false, $slot);
        }
    } else {
        $teamID = pss_pluginSetting("{$prefix}TeamID", '');
    }

    if ($teamID === '') {
        pss_clearLeagueState($league, true, $slot);
        pss_syncGeneratedPlaylistsForLeague($league);
        pss_syncTeamPalettes(false);
        pss_logEntry(pss_teamLogLabel($league, $slot) . ' cleared');
        return '';
    }

    $teamInfo = pss_getTeamInfo($sport, $league, $teamID);
    if (!$teamInfo['valid']) {
        pss_syncTeamPalettes(false);
        pss_logEntry(pss_teamLogLabel($league, $slot) . ' update failed; palette will retry on the next sync');
        return pss_pluginSetting("{$prefix}TeamLogo", '');
    }

    pss_setPluginSetting("{$prefix}TeamLogo", $teamInfo['logo']);
    pss_setPluginSetting("{$prefix}TeamAbbreviation", $teamInfo['abbreviation']);
    pss_setPluginSetting("{$prefix}TeamName", $teamInfo['name']);
    pss_storeTeamPaletteSettings($league, $slot, $teamInfo);
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
    pss_syncTeamPalettes(false);
    pss_logEntry(pss_teamLogLabel($league, $slot) . " updated to {$teamInfo['name']}");
    return $teamInfo['logo'];
}

function pss_clearLeagueState($league, $clearTeam = false, $slot = 1) {
    $prefix = pss_teamPrefix($league, $slot);
    $keys = array(
        'TeamLogo' => '', 'TeamAbbreviation' => '', 'TeamName' => '', 'TeamPaletteName' => '',
        'TeamColor1' => '', 'TeamColor2' => '', 'TeamColor3' => '', 'TeamNextEventID' => '',
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
    static $teamPalettesSynced = false;
    if ($reparseSettings) {
        $pluginSettings = pss_loadPluginSettings();
    }

    // Build/backfill the palette registry once when the scoring daemon starts.
    // This makes upgrades/installations self-healing for already-selected teams
    // without polling ESPN for colors on every scoring loop.
    if (!$teamPalettesSynced) {
        pss_syncTeamPalettes(true);
        $teamPalettesSynced = true;
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
