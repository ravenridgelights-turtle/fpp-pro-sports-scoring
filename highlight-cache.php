<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';

function pss_hc_log($message) {
    if (function_exists('pss_logEntry')) {
        pss_logEntry('Highlight cache: ' . $message);
    }
}

function pss_hc_safe($value) {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$value);
}

function pss_hc_cacheDir() {
    return '/home/fpp/media/cache/fpp-nfl-highlights';
}

function pss_hc_file($league, $slot, $eventID, $clipID) {
    return pss_hc_cacheDir() . '/'
        . pss_hc_safe(strtolower($league)) . '-'
        . (((int)$slot === 2) ? 2 : 1) . '-'
        . pss_hc_safe($eventID) . '-'
        . pss_hc_safe($clipID) . '.mp4';
}

function pss_hc_httpJson($url) {
    if (!function_exists('curl_init')) return null;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/8.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function pss_hc_download($url, $target) {
    if (!function_exists('curl_init')) return false;

    $tmp = $target . '.part';
    @unlink($tmp);
    $fh = @fopen($tmp, 'wb');
    if (!$fh) return false;

    $maxBytes = 50 * 1024 * 1024;
    $written = 0;
    $tooLarge = false;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux armv7l) AppleWebKit/537.36 Chrome/120 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: video/mp4,video/*;q=0.9,*/*;q=0.5',
        'Accept-Encoding: identity',
        'Referer: https://www.espn.com/'
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 20);
    if (defined('CURLOPT_BUFFERSIZE')) {
        @curl_setopt($ch, CURLOPT_BUFFERSIZE, 262144);
    }
    if (defined('CURLOPT_TCP_NODELAY')) {
        @curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    }

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use ($fh, &$written, &$tooLarge, $maxBytes) {
        $length = strlen($chunk);
        $written += $length;
        if ($written > $maxBytes) {
            $tooLarge = true;
            return 0;
        }
        $result = fwrite($fh, $chunk);
        return ($result === false) ? 0 : $result;
    });

    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $tooLarge || $code < 200 || $code >= 300 || !is_file($tmp) || filesize($tmp) < 1024) {
        @unlink($tmp);
        return false;
    }

    if ($contentType !== '' && strpos($contentType, 'video/') !== 0 && strpos($contentType, 'application/octet-stream') !== 0) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, 0644);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function pss_hc_cleanup($keepFiles) {
    $dir = pss_hc_cacheDir();
    $files = glob($dir . '/*.mp4');
    if (!is_array($files)) return;

    $now = time();
    foreach ($files as $file) {
        $keep = isset($keepFiles[$file]);
        $age = $now - (int)@filemtime($file);

        // Keep only the newest requested clips. Also remove anything stale in case
        // a team/event changed while the box was offline.
        if (!$keep || $age > 86400) {
            @unlink($file);
        }
    }

    foreach (glob($dir . '/*.part') ?: array() as $part) {
        if (($now - (int)@filemtime($part)) > 900) {
            @unlink($part);
        }
    }
}

$cacheDir = pss_hc_cacheDir();
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
    pss_hc_log('unable to create cache directory');
    exit(1);
}

$lockPath = $cacheDir . '/cache-worker.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    if ($lock) fclose($lock);
    exit(0);
}

$pluginSettings = pss_loadPluginSettings();
if (function_exists('pss_pluginSetting') && pss_pluginSetting('ENABLED', 'OFF') !== 'ON') {
    @flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$supported = array('nfl', 'ncaa', 'nhl', 'mlb');
$keepFiles = array();
$keepPerTeam = 3;

foreach ($supported as $league) {
    foreach (array(1, 2) as $slot) {
        $prefix = pss_teamPrefix($league, $slot);
        $teamID = function_exists('pss_pluginSetting') ? urldecode((string)pss_pluginSetting($prefix . 'TeamID', '')) : '';
        $eventID = function_exists('pss_pluginSetting') ? urldecode((string)pss_pluginSetting($prefix . 'TeamNextEventID', '')) : '';
        $state = function_exists('pss_pluginSetting') ? urldecode((string)pss_pluginSetting($prefix . 'GameStatus', '')) : '';

        if ($teamID === '' || $eventID === '') continue;

        // No useful video exists before a game has begun. Once a game is live or final,
        // continuously look for new clips in the background.
        if ($state === 'pre') continue;

        $url = 'http://127.0.0.1/plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlights=1&league='
            . rawurlencode($league) . '&slot=' . $slot;

        $data = pss_hc_httpJson($url);
        if (!is_array($data) || empty($data['ok']) || empty($data['items']) || !is_array($data['items'])) {
            continue;
        }

        $count = 0;
        foreach ($data['items'] as $item) {
            if ($count >= $keepPerTeam) break;
            if (!is_array($item)) continue;

            $clipID = isset($item['id']) ? trim((string)$item['id']) : '';
            if ($clipID === '') continue;

            $upstream = isset($item['upstreamMediaUrl']) ? trim((string)$item['upstreamMediaUrl']) : '';
            if ($upstream === '' && isset($item['upstreamMediaSources'][0]['url'])) {
                $upstream = trim((string)$item['upstreamMediaSources'][0]['url']);
            }
            if ($upstream === '' || !preg_match('/^https:\/\/[^ ]+\.mp4(?:\?|$)/i', $upstream)) {
                continue;
            }

            $target = pss_hc_file($league, $slot, $eventID, $clipID);
            $keepFiles[$target] = true;
            $count++;

            if (is_file($target) && filesize($target) > 1024) {
                continue;
            }

            pss_hc_log($league . ' slot ' . $slot . ' caching clip ' . $clipID);
            if (pss_hc_download($upstream, $target)) {
                pss_hc_log($league . ' slot ' . $slot . ' cached clip ' . $clipID . ' (' . filesize($target) . ' bytes)');
            } else {
                pss_hc_log($league . ' slot ' . $slot . ' failed to cache clip ' . $clipID);
            }
        }
    }
}

pss_hc_cleanup($keepFiles);

@flock($lock, LOCK_UN);
fclose($lock);
exit(0);
