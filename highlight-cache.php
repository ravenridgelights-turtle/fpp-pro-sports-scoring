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

function pss_hc_quality() {
    $quality = function_exists('pss_pluginSetting')
        ? strtolower(trim((string)pss_pluginSetting('HighlightQuality', 'low')))
        : 'low';
    return in_array($quality, array('low', 'medium', 'best'), true) ? $quality : 'low';
}

function pss_hc_file($league, $slot, $eventID, $clipID) {
    return pss_hc_cacheDir() . '/'
        . pss_hc_safe(strtolower($league)) . '-'
        . (((int)$slot === 2) ? 2 : 1) . '-'
        . pss_hc_safe($eventID) . '-'
        . pss_hc_safe(pss_hc_quality()) . '-'
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

    $maxBytes = 35 * 1024 * 1024;
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
    // Keep video caching from monopolizing a Pi Zero's Wi-Fi/CPU. About 512 KB/s
    // is fast enough for short ESPN clips while leaving headroom for FPP itself.
    if (defined('CURLOPT_MAX_RECV_SPEED_LARGE')) {
        @curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, 524288);
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
$backgroundKeepPerTeam = 2;
$maxCachedPerCurrentEvent = 3;
$keepFiles = array();
$currentEventPrefixes = array();
$backgroundCandidates = array();
$allItemsBySlot = array();

function pss_hc_priorityRequestPath() {
    // Shared control file written by the web UI and consumed by the daemon worker.
    // /tmp avoids permission mismatches on older FPP images.
    return '/tmp/fpp-nfl-highlight-priority-request.json';
}

function pss_hc_readPriorityRequest() {
    $path = pss_hc_priorityRequestPath();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();

    $requestedAt = isset($data['requestedAt']) ? (int)$data['requestedAt'] : 0;
    if ($requestedAt <= 0 || (time() - $requestedAt) > 600) {
        @unlink($path);
        return array();
    }
    return $data;
}

function pss_hc_clearPriorityRequest($expectedClipID = '') {
    $path = pss_hc_priorityRequestPath();
    if (!is_file($path)) return;
    if ($expectedClipID !== '') {
        $data = pss_hc_readPriorityRequest();
        if (isset($data['clipID']) && (string)$data['clipID'] !== (string)$expectedClipID) {
            return;
        }
    }
    @unlink($path);
}

function pss_hc_candidateFromItem($league, $slot, $eventID, $item) {
    if (!is_array($item)) return null;
    $clipID = isset($item['id']) ? trim((string)$item['id']) : '';
    if ($clipID === '') return null;

    $upstream = '';
    $sourcePath = '';

    if (isset($item['upstreamMediaSources']) && is_array($item['upstreamMediaSources'])) {
        foreach ($item['upstreamMediaSources'] as $source) {
            if (!is_array($source)) continue;
            $candidateUrl = isset($source['url']) ? trim((string)$source['url']) : '';
            if ($candidateUrl !== '' && preg_match('/^https:\/\/[^ ]+\.mp4(?:\?|$)/i', $candidateUrl)) {
                $upstream = $candidateUrl;
                $sourcePath = isset($source['path']) ? (string)$source['path'] : '';
                break;
            }
        }
    }

    if ($upstream === '') {
        $candidateUrl = isset($item['upstreamMediaUrl']) ? trim((string)$item['upstreamMediaUrl']) : '';
        if ($candidateUrl !== '' && preg_match('/^https:\/\/[^ ]+\.mp4(?:\?|$)/i', $candidateUrl)) {
            $upstream = $candidateUrl;
            $sourcePath = isset($item['selectedSourcePath']) ? (string)$item['selectedSourcePath'] : '';
        }
    }

    if ($upstream === '') {
        return null;
    }

    return array(
        'league' => $league,
        'slot' => (int)$slot,
        'eventID' => (string)$eventID,
        'clipID' => $clipID,
        'upstream' => $upstream,
        'sourcePath' => $sourcePath,
        'quality' => pss_hc_quality(),
        'target' => pss_hc_file($league, $slot, $eventID, $clipID)
    );
}

// Discover all current slots first. No downloads happen during discovery.
foreach ($supported as $league) {
    foreach (array(1, 2) as $slot) {
        $prefix = pss_teamPrefix($league, $slot);
        $teamID = function_exists('pss_pluginSetting') ? urldecode((string)pss_pluginSetting($prefix . 'TeamID', '')) : '';
        $eventID = function_exists('pss_pluginSetting') ? urldecode((string)pss_pluginSetting($prefix . 'TeamNextEventID', '')) : '';
        $state = function_exists('pss_pluginSetting') ? urldecode((string)pss_pluginSetting($prefix . 'GameStatus', '')) : '';

        if ($teamID === '' || $eventID === '' || $state === 'pre') continue;

        $slotKey = $league . ':' . $slot;
        $currentEventPrefixes[] = pss_hc_cacheDir() . '/'
            . pss_hc_safe($league) . '-' . $slot . '-' . pss_hc_safe($eventID) . '-'
            . pss_hc_safe(pss_hc_quality()) . '-';

        $url = 'http://127.0.0.1/plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlights=1&league='
            . rawurlencode($league) . '&slot=' . $slot;

        $data = pss_hc_httpJson($url);
        if (!is_array($data) || empty($data['ok']) || empty($data['items']) || !is_array($data['items'])) {
            continue;
        }

        $allItemsBySlot[$slotKey] = array(
            'league' => $league,
            'slot' => $slot,
            'eventID' => $eventID,
            'items' => $data['items']
        );

        $kept = 0;
        foreach ($data['items'] as $item) {
            $candidate = pss_hc_candidateFromItem($league, $slot, $eventID, $item);
            if (!is_array($candidate)) continue;

            if ($kept < $backgroundKeepPerTeam) {
                $keepFiles[$candidate['target']] = true;
                $backgroundCandidates[] = $candidate;
                $kept++;
            }
        }
    }
}

// Most recently selected uncached clip gets first priority, even if it is an older
// history item outside the two automatic background clips.
$priority = pss_hc_readPriorityRequest();
$selected = null;
if (!empty($priority['league']) && !empty($priority['clipID'])) {
    $league = strtolower((string)$priority['league']);
    $slot = ((int)$priority['slot'] === 2) ? 2 : 1;
    $eventID = isset($priority['eventID']) ? (string)$priority['eventID'] : '';
    $clipID = (string)$priority['clipID'];
    $slotKey = $league . ':' . $slot;

    if (isset($allItemsBySlot[$slotKey])
        && (string)$allItemsBySlot[$slotKey]['eventID'] === $eventID) {
        foreach ($allItemsBySlot[$slotKey]['items'] as $item) {
            if (isset($item['id']) && (string)$item['id'] === $clipID) {
                $candidate = pss_hc_candidateFromItem($league, $slot, $eventID, $item);
                if (is_array($candidate)) {
                    $keepFiles[$candidate['target']] = true;
                    if (is_file($candidate['target']) && filesize($candidate['target']) > 1024) {
                        @touch($candidate['target']);
                        pss_hc_clearPriorityRequest($clipID);
                    } else {
                        $selected = $candidate;
                    }
                } else {
                    pss_hc_clearPriorityRequest($clipID);
                }
                break;
            }
        }
    } else {
        // Event/team changed; don't keep a stale request forever.
        pss_hc_clearPriorityRequest($clipID);
    }
}

// No priority click waiting: choose ONE automatic background candidate fairly.
// Persisting a cursor prevents NFL Team 1 from starving MLB/NHL/NCAA forever.
if (!is_array($selected) && !empty($backgroundCandidates)) {
    $missing = array();
    foreach ($backgroundCandidates as $candidate) {
        if (!is_file($candidate['target']) || filesize($candidate['target']) <= 1024) {
            $missing[] = $candidate;
        }
    }

    if (!empty($missing)) {
        $cursorPath = pss_hc_cacheDir() . '/background-cursor.txt';
        $cursor = is_file($cursorPath) ? max(0, (int)@file_get_contents($cursorPath)) : 0;
        $index = $cursor % count($missing);
        $selected = $missing[$index];
        @file_put_contents($cursorPath, (string)(($index + 1) % max(1, count($missing))), LOCK_EX);
    }
}

if (is_array($selected)) {
    pss_hc_log($selected['league'] . ' slot ' . $selected['slot']
        . ' caching clip ' . $selected['clipID']
        . ' quality=' . $selected['quality']
        . ($selected['sourcePath'] !== '' ? ' source=' . $selected['sourcePath'] : '')
        . ' at low priority');

    if (pss_hc_download($selected['upstream'], $selected['target'])) {
        @touch($selected['target']);
        $keepFiles[$selected['target']] = true;
        pss_hc_log($selected['league'] . ' slot ' . $selected['slot']
            . ' cached clip ' . $selected['clipID'] . ' (' . filesize($selected['target']) . ' bytes)');

        if (!empty($priority['clipID']) && (string)$priority['clipID'] === (string)$selected['clipID']) {
            pss_hc_clearPriorityRequest($selected['clipID']);
        }
    } else {
        pss_hc_log($selected['league'] . ' slot ' . $selected['slot']
            . ' failed to cache clip ' . $selected['clipID']);
        // Don't let one permanently bad clip starve every other sport.
        if (!empty($priority['clipID']) && (string)$priority['clipID'] === (string)$selected['clipID']) {
            pss_hc_clearPriorityRequest($selected['clipID']);
        }
    }
}

// LRU cleanup:
// - delete old-event files immediately;
// - for each current event keep at most 3 complete clips, newest/recently-viewed first.
$allCached = glob(pss_hc_cacheDir() . '/*.mp4');
if (is_array($allCached)) {
    $groups = array();
    foreach ($allCached as $file) {
        $matchedPrefix = '';
        foreach ($currentEventPrefixes as $prefix) {
            if (strpos($file, $prefix) === 0) {
                $matchedPrefix = $prefix;
                break;
            }
        }
        if ($matchedPrefix === '') {
            @unlink($file);
            continue;
        }
        if (!isset($groups[$matchedPrefix])) $groups[$matchedPrefix] = array();
        $groups[$matchedPrefix][] = $file;
    }

    foreach ($groups as $prefix => $files) {
        usort($files, function ($a, $b) {
            $ma = (int)@filemtime($a);
            $mb = (int)@filemtime($b);
            if ($ma === $mb) return strcmp($a, $b);
            return ($ma > $mb) ? -1 : 1;
        });
        foreach ($files as $index => $file) {
            if ($index >= $maxCachedPerCurrentEvent) {
                @unlink($file);
            }
        }
    }
}

foreach (glob(pss_hc_cacheDir() . '/*.part') ?: array() as $part) {
    if ((time() - (int)@filemtime($part)) > 900) {
        @unlink($part);
    }
}


@flock($lock, LOCK_UN);
fclose($lock);
exit(0);
