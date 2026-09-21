<?php
$skipJSsettings = true;
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';

function pss_hld_log($message) {
    if (function_exists('pss_logEntry')) {
        pss_logEntry('Highlight downloader: ' . $message);
    }
}

function pss_hld_videoDir() {
    global $videoDirectory;
    return isset($videoDirectory) && trim((string)$videoDirectory) !== ''
        ? rtrim((string)$videoDirectory, '/')
        : '/home/fpp/media/videos';
}

function pss_hld_safe($value) {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$value);
}

function pss_hld_quality() {
    $quality = function_exists('pss_pluginSetting')
        ? strtolower(trim((string)pss_pluginSetting('HighlightQuality', 'low')))
        : 'low';
    return in_array($quality, array('low', 'medium', 'best'), true) ? $quality : 'low';
}

function pss_hld_filename($league, $slot, $eventID, $clipID) {
    return 'PSSHL_'
        . strtoupper(pss_hld_safe($league))
        . '_S' . (((int)$slot === 2) ? '2' : '1')
        . '_' . pss_hld_safe($eventID)
        . '_' . pss_hld_safe($clipID)
        . '_' . pss_hld_safe(pss_hld_quality())
        . '.mp4';
}

function pss_hld_statePath() {
    return '/tmp/fpp-nfl-highlight-download-state.json';
}

function pss_hld_writeState($state) {
    $state['updatedAt'] = time();
    $path = pss_hld_statePath();
    $tmp = $path . '.' . getmypid() . '.tmp';
    @file_put_contents($tmp, json_encode($state), LOCK_EX);
    @chmod($tmp, 0644);
    @rename($tmp, $path);
    @chmod($path, 0644);
}

function pss_hld_httpJson($url) {
    if (!function_exists('curl_init')) return null;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/8.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function pss_hld_chooseUpstream($item) {
    if (!is_array($item)) return '';

    if (isset($item['upstreamMediaUrl'])) {
        $url = trim((string)$item['upstreamMediaUrl']);
        if ($url !== '' && preg_match('/^https:\/\/[^ ]+\.mp4(?:\?|$)/i', $url)) {
            return $url;
        }
    }

    if (isset($item['upstreamMediaSources']) && is_array($item['upstreamMediaSources'])) {
        foreach ($item['upstreamMediaSources'] as $source) {
            if (!is_array($source) || empty($source['url'])) continue;
            $url = trim((string)$source['url']);
            if ($url !== '' && preg_match('/^https:\/\/[^ ]+\.mp4(?:\?|$)/i', $url)) {
                return $url;
            }
        }
    }

    return '';
}

function pss_hld_download($entry, $queueRemaining) {
    if (!function_exists('curl_init')) return false;

    $target = $entry['target'];
    $part = $target . '.part';
    @unlink($part);

    $fh = @fopen($part, 'wb');
    if (!$fh) return false;

    $bytes = 0;
    $total = 0;
    $tooLarge = false;
    $maxBytes = 50 * 1024 * 1024;
    $lastStateWrite = 0.0;

    pss_hld_writeState(array(
        'status' => 'downloading',
        'filename' => $entry['filename'],
        'league' => $entry['league'],
        'slot' => $entry['slot'],
        'eventID' => $entry['eventID'],
        'clipID' => $entry['clipID'],
        'headline' => $entry['headline'],
        'bytes' => 0,
        'total' => 0,
        'queueRemaining' => $queueRemaining
    ));

    $ch = curl_init($entry['url']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux armv7l) AppleWebKit/537.36 Chrome/120 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: video/mp4,video/*;q=0.9,*/*;q=0.5',
        'Accept-Encoding: identity',
        'Referer: https://www.espn.com/'
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 512);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
    if (defined('CURLOPT_BUFFERSIZE')) {
        @curl_setopt($ch, CURLOPT_BUFFERSIZE, 262144);
    }
    if (defined('CURLOPT_TCP_NODELAY')) {
        @curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    }

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$total, $maxBytes, &$tooLarge) {
        $length = strlen($line);
        $trimmed = trim($line);
        if (stripos($trimmed, 'Content-Length:') === 0) {
            $value = trim(substr($trimmed, strlen('Content-Length:')));
            if (ctype_digit($value)) {
                $total = (int)$value;
                if ($total > $maxBytes) $tooLarge = true;
            }
        }
        return $length;
    });

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (
        $fh, &$bytes, &$total, &$tooLarge, $maxBytes, &$lastStateWrite, $entry, $queueRemaining
    ) {
        $length = strlen($chunk);
        $bytes += $length;
        if ($bytes > $maxBytes || $tooLarge) {
            $tooLarge = true;
            return 0;
        }

        $written = fwrite($fh, $chunk);
        if ($written === false) return 0;

        $now = microtime(true);
        if (($now - $lastStateWrite) >= 0.75) {
            $lastStateWrite = $now;
            pss_hld_writeState(array(
                'status' => 'downloading',
                'filename' => $entry['filename'],
                'league' => $entry['league'],
                'slot' => $entry['slot'],
                'eventID' => $entry['eventID'],
                'clipID' => $entry['clipID'],
                'headline' => $entry['headline'],
                'bytes' => $bytes,
                'total' => $total,
                'queueRemaining' => $queueRemaining
            ));
        }

        return $written;
    });

    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $tooLarge || $code < 200 || $code >= 300
        || !is_file($part) || @filesize($part) < 1024) {
        @unlink($part);
        pss_hld_log('failed clip ' . $entry['clipID']
            . ($tooLarge ? ' (over 50 MB)' : '')
            . ($code ? ' HTTP=' . $code : '')
            . ($curlError !== '' ? ' ' . $curlError : ''));
        return false;
    }

    if ($contentType !== ''
        && strpos($contentType, 'video/') !== 0
        && strpos($contentType, 'application/octet-stream') !== 0) {
        @unlink($part);
        pss_hld_log('rejected non-video response for clip ' . $entry['clipID'] . ': ' . $contentType);
        return false;
    }

    @chmod($part, 0664);
    if (!@rename($part, $target)) {
        @unlink($part);
        return false;
    }
    @chmod($target, 0664);

    pss_hld_writeState(array(
        'status' => 'ready',
        'filename' => $entry['filename'],
        'league' => $entry['league'],
        'slot' => $entry['slot'],
        'eventID' => $entry['eventID'],
        'clipID' => $entry['clipID'],
        'headline' => $entry['headline'],
        'bytes' => (int)@filesize($target),
        'total' => (int)@filesize($target),
        'queueRemaining' => $queueRemaining
    ));

    pss_hld_log('saved ' . $entry['filename'] . ' (' . (int)@filesize($target) . ' bytes)');
    return true;
}

$pluginSettings = pss_loadPluginSettings();
if (function_exists('pss_pluginSetting') && pss_pluginSetting('ENABLED', 'OFF') !== 'ON') {
    exit(0);
}

$videoDir = pss_hld_videoDir();
if (!is_dir($videoDir) && !@mkdir($videoDir, 0775, true) && !is_dir($videoDir)) {
    pss_hld_log('unable to create video directory ' . $videoDir);
    exit(1);
}

$lockPath = '/tmp/fpp-nfl-highlight-downloader.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    if ($lock) fclose($lock);
    exit(0);
}

pss_hld_writeState(array('status' => 'scanning', 'filename' => '', 'bytes' => 0, 'total' => 0));

$supported = array('nfl', 'ncaa', 'nhl', 'mlb');
$activeSlots = array();
$successfulSlots = array();
$desired = array();
$slotQueues = array();

foreach ($supported as $league) {
    foreach (array(1, 2) as $slot) {
        $prefix = pss_teamPrefix($league, $slot);
        $teamID = function_exists('pss_pluginSetting')
            ? urldecode((string)pss_pluginSetting($prefix . 'TeamID', ''))
            : '';
        $eventID = function_exists('pss_pluginSetting')
            ? urldecode((string)pss_pluginSetting($prefix . 'TeamNextEventID', ''))
            : '';

        if ($teamID === '' || $eventID === '') continue;

        $slotKey = $league . ':' . $slot;
        $activeSlots[$slotKey] = array(
            'league' => $league,
            'slot' => $slot,
            'eventID' => $eventID,
            'quality' => pss_hld_quality()
        );

        $url = 'http://127.0.0.1/plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlights=1&league='
            . rawurlencode($league) . '&slot=' . $slot;

        $data = pss_hld_httpJson($url);
        if (!is_array($data) || empty($data['ok']) || !isset($data['items']) || !is_array($data['items'])) {
            pss_hld_log('metadata unavailable for ' . $slotKey);
            continue;
        }

        if ((string)(isset($data['eventID']) ? $data['eventID'] : '') !== (string)$eventID) {
            continue;
        }

        $successfulSlots[$slotKey] = true;
        $queueForSlot = array();

        foreach ($data['items'] as $item) {
            if (!is_array($item)) continue;
            $clipID = isset($item['id']) ? trim((string)$item['id']) : '';
            if ($clipID === '') continue;

            $filename = pss_hld_filename($league, $slot, $eventID, $clipID);
            $target = $videoDir . '/' . $filename;
            $desired[$filename] = true;

            $upstream = pss_hld_chooseUpstream($item);
            if ($upstream === '') {
                continue;
            }

            $queueForSlot[] = array(
                'league' => $league,
                'slot' => $slot,
                'eventID' => $eventID,
                'clipID' => $clipID,
                'headline' => isset($item['headline']) ? (string)$item['headline'] : 'ESPN highlight',
                'filename' => $filename,
                'target' => $target,
                'url' => $upstream
            );
        }

        $slotQueues[$slotKey] = $queueForSlot;
    }
}

// Cleanup is deliberately limited to our own PSSHL_ files.
// Old event/quality files are removed immediately. For active slots, a clip is
// removed only when ESPN metadata was successfully fetched and the clip is no
// longer in the current six-item list.
foreach (glob($videoDir . '/PSSHL_*.mp4') ?: array() as $file) {
    $base = basename($file);
    if (!preg_match('/^PSSHL_([A-Z0-9_-]+)_S([12])_([A-Za-z0-9_-]+)_([A-Za-z0-9_-]+)_(low|medium|best)\.mp4$/', $base, $m)) {
        continue;
    }

    $league = strtolower($m[1]);
    $slot = (int)$m[2];
    $eventID = $m[3];
    $quality = $m[5];
    $slotKey = $league . ':' . $slot;

    $isActive = isset($activeSlots[$slotKey])
        && (string)$activeSlots[$slotKey]['eventID'] === (string)$eventID
        && (string)$activeSlots[$slotKey]['quality'] === (string)$quality;

    if (!$isActive) {
        @unlink($file);
        continue;
    }

    if (isset($successfulSlots[$slotKey]) && !isset($desired[$base])) {
        @unlink($file);
    }
}

foreach (glob($videoDir . '/PSSHL_*.mp4.part') ?: array() as $part) {
    if ((time() - (int)@filemtime($part)) > 900) {
        @unlink($part);
    }
}

// Build a fair single-file queue: newest clip from every selected team first,
// then second-newest from every team, and so on.
$queue = array();
$maxDepth = 0;
foreach ($slotQueues as $items) {
    $maxDepth = max($maxDepth, count($items));
}
for ($depth = 0; $depth < $maxDepth; $depth++) {
    foreach ($slotQueues as $items) {
        if (!isset($items[$depth])) continue;
        $entry = $items[$depth];
        if (is_file($entry['target']) && @filesize($entry['target']) > 1024) {
            continue;
        }
        $queue[] = $entry;
    }
}

// One process, one transfer at a time. Limit each run so a newly published clip
// is rediscovered quickly instead of waiting behind an enormous startup backlog.
$maxDownloadsPerRun = 8;
$downloadedThisRun = 0;

foreach ($queue as $index => $entry) {
    if ($downloadedThisRun >= $maxDownloadsPerRun) break;

    // ESPN may remove a clip while we are downloading the queue. Files are still
    // safe because the next scan will delete anything no longer desired.
    $remaining = max(0, count($queue) - $index - 1);
    pss_hld_log('downloading ' . $entry['filename'] . ' queueRemaining=' . $remaining);
    pss_hld_download($entry, $remaining);
    $downloadedThisRun++;
}

pss_hld_writeState(array(
    'status' => 'idle',
    'filename' => '',
    'bytes' => 0,
    'total' => 0,
    'queueRemaining' => max(0, count($queue) - $downloadedThisRun)
));

@flock($lock, LOCK_UN);
fclose($lock);
exit(0);
