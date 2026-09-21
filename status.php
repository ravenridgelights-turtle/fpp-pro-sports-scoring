<?php
$pssKioskMode = isset($_GET['kiosk']) && (string)$_GET['kiosk'] === '1';
$pssDataMode = isset($_GET['data']) && (string)$_GET['data'] === '1';
$pssHighlightMode = isset($_GET['highlights']) && (string)$_GET['highlights'] === '1';
$pssHighlightMediaMode = isset($_GET['highlightmedia']) && (string)$_GET['highlightmedia'] === '1';
$pssHighlightCachedMediaMode = isset($_GET['highlightcachemedia']) && (string)$_GET['highlightcachemedia'] === '1';
$pssHighlightCacheRequestMode = isset($_GET['highlightcacherequest']) && (string)$_GET['highlightcacherequest'] === '1';
if ($pssDataMode || $pssHighlightMode || $pssHighlightMediaMode || $pssHighlightCachedMediaMode || $pssHighlightCacheRequestMode) {
    $skipJSsettings = 1;
}

include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';
$pluginSettings = pss_loadPluginSettings();

function pss_statusValue($key, $default = '') {
    global $pluginSettings;
    return isset($pluginSettings[$key]) ? urldecode((string)$pluginSettings[$key]) : $default;
}

function pss_formatStart($value) {
    if ($value === '' || $value === '0') {
        return 'No scheduled event found';
    }
    try {
        $dt = new DateTime($value);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('l, F j @ g:i A');
    } catch (Exception $e) {
        return 'Unknown';
    }
}

function pss_stateLabel($state) {
    if ($state === 'pre') return 'Pregame';
    if ($state === 'in') return 'Playing';
    if ($state === 'post') return 'Final';
    return 'Waiting for ESPN';
}

function pss_statusLogoDataUri($url) {
    static $cache = array();

    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (isset($cache[$url])) {
        return $cache[$url];
    }

    $parts = @parse_url($url);
    $host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
    $allowedHost = ($host === 'a.espncdn.com' || (strlen($host) > 12 && substr($host, -12) === '.espncdn.com'));
    if (!$allowedHost || !function_exists('curl_init')) {
        $cache[$url] = '';
        return '';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'curl/8.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'));
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300 || strlen($body) > 524288) {
        $cache[$url] = '';
        return '';
    }

    $contentType = strtolower(trim(explode(';', $contentType)[0]));
    $allowedTypes = array('image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml');
    if (!in_array($contentType, $allowedTypes, true)) {
        $cache[$url] = '';
        return '';
    }

    $cache[$url] = 'data:' . $contentType . ';base64,' . base64_encode($body);
    return $cache[$url];
}

function pss_teamLogoMarkup($logoUrl, $abbr, $name) {
    $dataUri = pss_statusLogoDataUri($logoUrl);
    if ($dataUri !== '') {
        return '<img class="pss-team-logo" src="' . htmlspecialchars($dataUri, ENT_QUOTES) . '" alt="' . htmlspecialchars($name . ' logo', ENT_QUOTES) . '">';
    }

    $fallback = trim((string)$abbr);
    if ($fallback === '') {
        $fallback = '?';
    }
    return '<div class="pss-team-logo-fallback" aria-label="' . htmlspecialchars($name, ENT_QUOTES) . '">' . htmlspecialchars($fallback) . '</div>';
}

function pss_manualTriggerButtonsMarkup($league, $slot, $prefix) {
    $info = pss_leagueInfo($league);
    if ($info['sport'] === '') {
        return '';
    }

    if ($info['sport'] === 'football') {
        $buttons = array(
            array('trigger' => 'touchdown', 'suffix' => 'TouchdownSequence', 'label' => 'Touchdown'),
            array('trigger' => 'fieldgoal', 'suffix' => 'FieldgoalSequence', 'label' => 'Field Goal'),
            array('trigger' => 'win', 'suffix' => 'WinSequence', 'label' => 'Win')
        );
    } else {
        $buttons = array(
            array('trigger' => 'score', 'suffix' => 'ScoreSequence', 'label' => 'Score'),
            array('trigger' => 'win', 'suffix' => 'WinSequence', 'label' => 'Win')
        );
    }

    $html = '<div class="pss-manual-controls" aria-label="Manual celebration controls">';
    foreach ($buttons as $button) {
        $sequence = trim(pss_statusValue($prefix . $button['suffix']));
        $enabled = ($sequence !== '');
        $title = $enabled
            ? 'Trigger ' . $button['label'] . ': ' . preg_replace('/\\.fseq$/i', '', basename($sequence))
            : 'No ' . $button['label'] . ' sequence configured';

        $html .= '<button type="button" class="pss-manual-trigger"'
            . ' data-pss-manual-trigger="1"'
            . ' data-league="' . htmlspecialchars($league, ENT_QUOTES) . '"'
            . ' data-slot="' . (int)$slot . '"'
            . ' data-trigger="' . htmlspecialchars($button['trigger'], ENT_QUOTES) . '"'
            . ' title="' . htmlspecialchars($title, ENT_QUOTES) . '"'
            . ($enabled ? '' : ' disabled')
            . '>' . htmlspecialchars($button['label']) . '</button>';
    }
    $html .= '</div><div class="pss-manual-feedback" data-pss-manual-feedback="1" aria-live="polite"></div>';
    return $html;
}

function pss_highlightArrayValue($value, $path, $default = '') {
    $current = $value;
    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return $default;
        }
        $current = $current[$key];
    }
    return $current;
}

function pss_highlightNormalizeUrl($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, 'http://') === 0) {
        $url = 'https://' . substr($url, 7);
    }
    if (strpos($url, 'https://') !== 0) {
        return '';
    }
    return $url;
}

function pss_highlightAllowedMediaUrl($url) {
    $url = pss_highlightNormalizeUrl($url);
    if ($url === '') {
        return '';
    }

    $parts = @parse_url($url);
    $host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
    $allowed = false;
    foreach (array('espn.com', 'espncdn.com', 'akamaized.net', 'akamaihd.net') as $suffix) {
        if ($host === $suffix || (strlen($host) > strlen($suffix) && substr($host, -(strlen($suffix) + 1)) === '.' . $suffix)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return '';
    }

    $path = isset($parts['path']) ? strtolower((string)$parts['path']) : '';
    if (!preg_match('/\.(mp4|m3u8)$/', $path)) {
        return '';
    }

    return $url;
}

function pss_highlightAllowedImageUrl($url) {
    $url = pss_highlightNormalizeUrl($url);
    if ($url === '') {
        return '';
    }
    $parts = @parse_url($url);
    $host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
    if ($host === 'espn.com' || substr($host, -9) === '.espn.com' || $host === 'espncdn.com' || substr($host, -12) === '.espncdn.com') {
        return $url;
    }
    return '';
}

function pss_highlightAllowedWebUrl($url) {
    $url = pss_highlightNormalizeUrl($url);
    if ($url === '') {
        return '';
    }
    $parts = @parse_url($url);
    $host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
    if ($host === 'espn.com' || substr($host, -9) === '.espn.com') {
        return $url;
    }
    return '';
}

function pss_collectHighlightVideoNodes($node, &$videos, $depth = 0) {
    if (!is_array($node) || $depth > 10 || count($videos) >= 60) {
        return;
    }

    foreach ($node as $key => $value) {
        if ($key === 'videos' && is_array($value)) {
            foreach ($value as $video) {
                if (is_array($video)) {
                    $videos[] = $video;
                    if (count($videos) >= 60) {
                        return;
                    }
                }
            }
        }
        if (is_array($value)) {
            pss_collectHighlightVideoNodes($value, $videos, $depth + 1);
            if (count($videos) >= 60) {
                return;
            }
        }
    }
}

function pss_collectHighlightMediaCandidates($node, &$candidates, $path = '', $depth = 0) {
    if ($depth > 8) {
        return;
    }
    if (is_string($node)) {
        $mediaUrl = pss_highlightAllowedMediaUrl($node);
        if ($mediaUrl !== '') {
            $candidates[] = array('path' => strtolower($path), 'url' => $mediaUrl);
        }
        return;
    }
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        $childPath = ($path === '') ? (string)$key : $path . '.' . (string)$key;
        pss_collectHighlightMediaCandidates($value, $candidates, $childPath, $depth + 1);
    }
}

function pss_highlightQualityPreference() {
    $quality = strtolower(trim((string)pss_statusValue('HighlightQuality', 'low')));
    return in_array($quality, array('low', 'medium', 'best'), true) ? $quality : 'low';
}

function pss_highlightSourceQualityScore($source, $quality) {
    $type = isset($source['type']) ? strtolower((string)$source['type']) : '';
    $path = isset($source['path']) ? strtolower((string)$source['path']) : '';
    $url = isset($source['url']) ? strtolower((string)$source['url']) : '';
    $text = $path . ' ' . $url;

    // Legacy FPP browsers still prefer progressive MP4. HLS is retained as a
    // last-resort browser fallback, not as the primary cache source.
    $score = ($type === 'mp4') ? 0 : 1000;

    $isMobile = (strpos($text, 'mobile') !== false);
    $isMezzanine = (strpos($text, 'mezzanine') !== false);
    $isHd = (strpos($text, '.hd') !== false || strpos($text, '/hd') !== false || strpos($text, '720') !== false);
    $isFull = (strpos($text, '.full') !== false || strpos($text, 'full.') !== false);
    $has1080 = (strpos($text, '1080') !== false);
    $has540 = (strpos($text, '540') !== false);
    $has480 = (strpos($text, '480') !== false);
    $has360 = (strpos($text, '360') !== false);
    $has240 = (strpos($text, '240') !== false);

    if ($quality === 'best') {
        if ($isMezzanine || $has1080) return $score + 0;
        if ($isHd || strpos($text, '720') !== false) return $score + 2;
        if ($has540 || $isFull) return $score + 5;
        if ($has480) return $score + 7;
        if ($has360 || $isMobile) return $score + 12;
        if ($has240) return $score + 18;
        return $score + 8;
    }

    if ($quality === 'medium') {
        if ($has480 || $has540 || $isFull) return $score + 0;
        if ($has360 || $isMobile) return $score + 3;
        if ($isHd || strpos($text, '720') !== false) return $score + 8;
        if ($has240) return $score + 10;
        if ($isMezzanine || $has1080) return $score + 15;
        return $score + 5;
    }

    // Data Saver: aggressively prefer ESPN's mobile/low rendition when supplied.
    if ($has240) return $score + 0;
    if ($has360 || $isMobile) return $score + 1;
    if ($has480) return $score + 5;
    if ($has540 || $isFull) return $score + 8;
    if ($isHd || strpos($text, '720') !== false) return $score + 16;
    if ($isMezzanine || $has1080) return $score + 24;
    return $score + 6;
}

function pss_highlightMediaSources($video) {
    $candidates = array();

    // Collect every media URL ESPN exposes rather than returning the first generic
    // "source" value. ESPN commonly exposes HLS first even when an MP4 is also
    // present, and older FPP kiosk browsers generally cannot play HLS natively.
    if (isset($video['links']) && is_array($video['links'])) {
        pss_collectHighlightMediaCandidates($video['links'], $candidates);
    }

    $preferredPaths = array(
        array('links', 'mobile', 'source', 'href'),
        array('links', 'source', 'full', 'href'),
        array('links', 'source', 'HD', 'href'),
        array('links', 'source', 'mezzanine', 'href'),
        array('links', 'source', 'href'),
        array('links', 'source', 'HLS', 'href'),
        array('links', 'source', 'HLS', 'HD', 'href')
    );
    foreach ($preferredPaths as $path) {
        $candidate = pss_highlightAllowedMediaUrl(pss_highlightArrayValue($video, $path, ''));
        if ($candidate !== '') {
            $candidates[] = array('path' => strtolower(implode('.', $path)), 'url' => $candidate);
        }
    }

    $seen = array();
    $sources = array();
    foreach ($candidates as $candidate) {
        $url = isset($candidate['url']) ? (string)$candidate['url'] : '';
        if ($url === '' || isset($seen[$url])) continue;
        $seen[$url] = true;

        $type = '';
        if (preg_match('/\.mp4(?:\?|$)/i', $url)) {
            $type = 'mp4';
        } elseif (preg_match('/\.m3u8(?:\?|$)/i', $url)) {
            $type = 'hls';
        }
        if ($type === '') continue;

        $sources[] = array(
            'url' => $url,
            'type' => $type,
            'path' => isset($candidate['path']) ? (string)$candidate['path'] : ''
        );
    }

    $quality = pss_highlightQualityPreference();
    usort($sources, function ($a, $b) use ($quality) {
        $rankA = pss_highlightSourceQualityScore($a, $quality);
        $rankB = pss_highlightSourceQualityScore($b, $quality);
        if ($rankA === $rankB) {
            return strcmp(isset($a['path']) ? (string)$a['path'] : '', isset($b['path']) ? (string)$b['path'] : '');
        }
        return ($rankA < $rankB) ? -1 : 1;
    });

    return $sources;
}

function pss_highlightMediaUrl($video) {
    $sources = pss_highlightMediaSources($video);
    return !empty($sources) ? $sources[0]['url'] : '';
}

function pss_normalizeHighlightVideo($video, $index = 0) {
    if (!is_array($video)) {
        return null;
    }

    $id = isset($video['id']) ? trim((string)$video['id']) : '';
    if ($id === '' && isset($video['videoId'])) {
        $id = trim((string)$video['videoId']);
    }

    $headline = '';
    foreach (array('headline', 'title', 'description', 'caption') as $field) {
        if (isset($video[$field]) && trim((string)$video[$field]) !== '') {
            $headline = trim((string)$video[$field]);
            break;
        }
    }
    if ($headline === '') {
        $headline = 'ESPN game highlight';
    }

    $thumbnail = isset($video['thumbnail']) ? pss_highlightAllowedImageUrl($video['thumbnail']) : '';
    if ($thumbnail === '' && isset($video['image']) && is_array($video['image'])) {
        foreach (array('url', 'href') as $field) {
            if (isset($video['image'][$field])) {
                $thumbnail = pss_highlightAllowedImageUrl($video['image'][$field]);
                if ($thumbnail !== '') break;
            }
        }
    }

    $webUrl = pss_highlightAllowedWebUrl(pss_highlightArrayValue($video, array('links', 'web', 'href'), ''));
    if ($webUrl === '' && $id !== '') {
        $webUrl = 'https://www.espn.com/video/clip/_/id/' . rawurlencode($id);
    }

    $mediaSources = pss_highlightMediaSources($video);
    $mediaUrl = !empty($mediaSources) ? $mediaSources[0]['url'] : '';
    $duration = isset($video['duration']) ? max(0, (int)$video['duration']) : 0;

    $published = '';
    foreach (array('originalPublishDate', 'published', 'publishDate', 'date') as $field) {
        if (isset($video[$field]) && trim((string)$video[$field]) !== '') {
            $published = trim((string)$video[$field]);
            break;
        }
    }
    $sortTime = ($published !== '') ? @strtotime($published) : false;
    if ($sortTime === false) $sortTime = 0;

    if ($id === '') {
        $id = substr(sha1($headline . '|' . $mediaUrl . '|' . $webUrl), 0, 20);
    }

    return array(
        'id' => $id,
        'headline' => $headline,
        'thumbnail' => $thumbnail,
        'duration' => $duration,
        'published' => $published,
        'mediaUrl' => $mediaUrl,
        'mediaType' => (!empty($mediaSources) ? $mediaSources[0]['type'] : ''),
        'mediaSources' => $mediaSources,
        'qualityPreference' => pss_highlightQualityPreference(),
        'selectedSourcePath' => (!empty($mediaSources) && isset($mediaSources[0]['path'])) ? $mediaSources[0]['path'] : '',
        'webUrl' => $webUrl,
        'playable' => ($mediaUrl !== ''),
        '_sortTime' => (int)$sortTime,
        '_order' => (int)$index
    );
}

function pss_fetchEspnHighlights($league, $eventID, $limit = 6) {
    $league = strtolower(trim((string)$league));
    $eventID = trim((string)$eventID);
    $limit = max(1, min(10, (int)$limit));

    $supportedHighlightLeagues = array('nfl', 'ncaa', 'nhl', 'mlb');
    if (!in_array($league, $supportedHighlightLeagues, true)
        || $eventID === ''
        || !preg_match('/^[A-Za-z0-9_-]+$/', $eventID)) {
        return array();
    }

    $info = pss_leagueInfo($league);
    if ($info['sport'] === '' || $info['espnLeague'] === '') {
        return array();
    }

    $url = 'https://site.api.espn.com/apis/site/v2/sports/' . rawurlencode($info['sport']) . '/' . rawurlencode($info['espnLeague']) . '/summary?event=' . rawurlencode($eventID);
    $data = pss_httpJson($url);
    if (!is_array($data)) {
        return array();
    }

    $rawVideos = array();
    if (isset($data['videos']) && is_array($data['videos'])) {
        foreach ($data['videos'] as $video) {
            if (is_array($video)) $rawVideos[] = $video;
        }
    }
    pss_collectHighlightVideoNodes($data, $rawVideos);

    $items = array();
    $seen = array();
    foreach ($rawVideos as $index => $video) {
        $item = pss_normalizeHighlightVideo($video, $index);
        if (!is_array($item)) continue;
        $dedupeKey = $item['id'] !== '' ? $item['id'] : sha1($item['headline'] . '|' . $item['mediaUrl']);
        if (isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;
        $items[] = $item;
    }

    usort($items, function ($a, $b) {
        if ($a['_sortTime'] > 0 || $b['_sortTime'] > 0) {
            if ($a['_sortTime'] === $b['_sortTime']) return $a['_order'] - $b['_order'];
            return ($a['_sortTime'] > $b['_sortTime']) ? -1 : 1;
        }
        return $a['_order'] - $b['_order'];
    });

    $items = array_slice($items, 0, $limit);
    foreach ($items as &$item) {
        unset($item['_sortTime'], $item['_order']);
    }
    unset($item);
    return $items;
}



function pss_highlightCacheDir() {
    return '/home/fpp/media/cache/fpp-nfl-highlights';
}

function pss_highlightSafePart($value) {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$value);
}

function pss_highlightCachedFilePath($league, $slot, $eventID, $clipID) {
    $league = pss_highlightSafePart(strtolower((string)$league));
    $slot = ((int)$slot === 2) ? 2 : 1;
    $eventID = pss_highlightSafePart($eventID);
    $clipID = pss_highlightSafePart($clipID);
    $quality = pss_highlightSafePart(pss_highlightQualityPreference());
    if ($league === '' || $eventID === '' || $clipID === '' || $quality === '') return '';
    return pss_highlightCacheDir() . '/' . $league . '-' . $slot . '-' . $eventID . '-' . $quality . '-' . $clipID . '.mp4';
}

function pss_highlightCacheRequestPath() {
    // The web UI may run as a different user than the root-owned sports daemon.
    // Keep the tiny queue-control file in /tmp so both sides can safely access it.
    // Video files themselves remain in the normal FPP media cache directory.
    return '/tmp/fpp-nfl-highlight-priority-request.json';
}

function pss_highlightCachePartPath($league, $slot, $eventID, $clipID) {
    $file = pss_highlightCachedFilePath($league, $slot, $eventID, $clipID);
    return $file !== '' ? $file . '.part' : '';
}

function pss_highlightReadPriorityRequest() {
    $path = pss_highlightCacheRequestPath();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return array();
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function pss_highlightLaunchCacheWorkerNow() {
    $worker = __DIR__ . '/highlight-cache.php';
    if (!is_file($worker)) return;

    $php = is_file('/usr/bin/php') ? '/usr/bin/php' : 'php';
    $nice = is_executable('/usr/bin/nice') ? '/usr/bin/nice -n 15 ' : '';
    $ionice = is_executable('/usr/bin/ionice') ? '/usr/bin/ionice -c3 ' : '';
    $command = $nice . $ionice . escapeshellcmd($php) . ' ' . escapeshellarg($worker) . ' >/dev/null 2>&1 &';
    @exec($command);
}

function pss_requestHighlightCache($league, $slot, $clipID) {
    $league = strtolower(trim((string)$league));
    $slot = ((int)$slot === 2) ? 2 : 1;
    $clipID = trim((string)$clipID);
    $supported = array('nfl', 'ncaa', 'nhl', 'mlb');

    if (!in_array($league, $supported, true)
        || $clipID === ''
        || !preg_match('/^[A-Za-z0-9_-]+$/', $clipID)) {
        return array('ok' => false, 'message' => 'Invalid highlight cache request.');
    }

    $prefix = pss_teamPrefix($league, $slot);
    $teamID = pss_statusValue($prefix . 'TeamID');
    $eventID = pss_statusValue($prefix . 'TeamNextEventID');
    if ($teamID === '' || $eventID === '') {
        return array('ok' => false, 'message' => 'No selected event for this team slot.');
    }

    $items = pss_fetchEspnHighlights($league, $eventID, 6);
    $matched = null;
    foreach ($items as $item) {
        if (isset($item['id']) && (string)$item['id'] === $clipID) {
            $matched = $item;
            break;
        }
    }
    if (!is_array($matched)) {
        return array('ok' => false, 'message' => 'Highlight is no longer available for this event.');
    }

    $upstream = isset($matched['mediaUrl']) ? trim((string)$matched['mediaUrl']) : '';
    if ($upstream === '' || !preg_match('/^https:\/\/[^ ]+\.mp4(?:\?|$)/i', $upstream)) {
        return array('ok' => false, 'message' => 'This highlight has no cacheable MP4 source.');
    }

    // Do not create/chown the video cache from the web request. The background
    // worker owns that directory and will create it with the daemon's permissions.
    $target = pss_highlightCachedFilePath($league, $slot, $eventID, $clipID);
    if ($target !== '' && is_file($target) && filesize($target) > 1024) {
        @touch($target);
        return array('ok' => true, 'state' => 'cached', 'message' => 'Highlight is already cached.');
    }

    // One priority request on purpose: the most recently selected uncached clip wins.
    // This prevents a user from building a large download queue on a Pi Zero.
    $request = array(
        'league' => $league,
        'slot' => $slot,
        'eventID' => (string)$eventID,
        'clipID' => $clipID,
        'requestedAt' => time()
    );
    $path = pss_highlightCacheRequestPath();
    $tmp = $path . '.' . getmypid() . '.tmp';
    $payload = json_encode($request);

    if ($payload === false || @file_put_contents($tmp, $payload, LOCK_EX) === false) {
        @unlink($tmp);
        return array(
            'ok' => false,
            'message' => 'Unable to write highlight cache queue file.',
            'queuePath' => $path
        );
    }

    @chmod($tmp, 0666);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return array(
            'ok' => false,
            'message' => 'Unable to publish highlight cache queue file.',
            'queuePath' => $path
        );
    }
    @chmod($path, 0666);

    // Do not spawn the video worker from the web/PHP request. The normal sports
    // daemon will pick this request up on its next low-priority cache pass.
    return array(
        'ok' => true,
        'state' => 'queued',
        'message' => 'Highlight queued for background caching.',
        'queuePath' => $path
    );
}

function pss_highlightCachedMediaUrl($league, $slot, $clipID) {
    return 'plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlightcachemedia=1&league='
        . rawurlencode((string)$league)
        . '&slot=' . (int)$slot
        . '&clip=' . rawurlencode((string)$clipID);
}

function pss_streamCachedHighlightMedia($league, $slot, $clipID) {
    $league = strtolower(trim((string)$league));
    $slot = ((int)$slot === 2) ? 2 : 1;
    $clipID = trim((string)$clipID);

    $supportedHighlightLeagues = array('nfl', 'ncaa', 'nhl', 'mlb');
    if (!in_array($league, $supportedHighlightLeagues, true)
        || $clipID === ''
        || !preg_match('/^[A-Za-z0-9_-]+$/', $clipID)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid cached highlight request.';
        exit;
    }

    $prefix = pss_teamPrefix($league, $slot);
    $eventID = pss_statusValue($prefix . 'TeamNextEventID');
    $file = pss_highlightCachedFilePath($league, $slot, $eventID, $clipID);
    if ($file === '' || !is_file($file) || filesize($file) <= 0) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Cached highlight is not available.';
        exit;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @set_time_limit(0);
    @ignore_user_abort(true);

    @touch($file);
    $size = filesize($file);
    $start = 0;
    $end = $size - 1;
    $status = 200;

    $range = isset($_SERVER['HTTP_RANGE']) ? trim((string)$_SERVER['HTTP_RANGE']) : '';
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $suffix = min($size, max(0, (int)$m[2]));
            $start = max(0, $size - $suffix);
        } else {
            $start = ($m[1] !== '') ? max(0, (int)$m[1]) : 0;
            if ($m[2] !== '') {
                $end = min($end, max($start, (int)$m[2]));
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: video/mp4');
    header('Content-Disposition: inline');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $fh = @fopen($file, 'rb');
    if (!$fh) {
        http_response_code(500);
        exit;
    }
    if ($start > 0) {
        @fseek($fh, $start);
    }

    $remaining = $length;
    $chunkSize = 262144;
    while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
        $read = min($chunkSize, $remaining);
        $buffer = fread($fh, $read);
        if ($buffer === false || $buffer === '') break;
        echo $buffer;
        $remaining -= strlen($buffer);
        @flush();
    }
    fclose($fh);
    exit;
}

function pss_highlightProxyUrl($league, $slot, $clipID, $sourceIndex = 0) {
    return 'plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlightmedia=1&league='
        . rawurlencode((string)$league)
        . '&slot=' . (int)$slot
        . '&clip=' . rawurlencode((string)$clipID)
        . '&source=' . max(0, (int)$sourceIndex);
}

function pss_prepareHighlightItemsForBrowser($items, $league, $slot) {
    $prefix = pss_teamPrefix($league, $slot);
    $eventID = pss_statusValue($prefix . 'TeamNextEventID');

    foreach ($items as &$item) {
        $upstreamSources = isset($item['mediaSources']) && is_array($item['mediaSources'])
            ? $item['mediaSources']
            : array();

        // Keep upstream URLs visible in diagnostics.
        $item['upstreamMediaUrl'] = isset($item['mediaUrl']) ? $item['mediaUrl'] : '';
        $item['upstreamMediaSources'] = $upstreamSources;
        $item['cached'] = false;
        $item['cachedBytes'] = 0;
        $item['cacheState'] = 'waiting';

        $cachedFile = pss_highlightCachedFilePath($league, $slot, $eventID, $item['id']);
        if ($cachedFile !== '' && is_file($cachedFile) && filesize($cachedFile) > 0) {
            $cachedUrl = pss_highlightCachedMediaUrl($league, $slot, $item['id']);
            $item['mediaSources'] = array(array(
                'url' => $cachedUrl,
                'type' => 'mp4',
                'path' => 'fpp.cache'
            ));
            $item['mediaUrl'] = $cachedUrl;
            $item['mediaType'] = 'mp4';
            $item['playable'] = true;
            $item['cached'] = true;
            $item['cachedBytes'] = (int)filesize($cachedFile);
            $item['cacheState'] = 'cached';
            continue;
        }

        $partFile = pss_highlightCachePartPath($league, $slot, $eventID, $item['id']);
        if ($partFile !== '' && is_file($partFile)) {
            $item['cacheState'] = 'caching';
        } else {
            $priority = pss_highlightReadPriorityRequest();
            if (isset($priority['league'], $priority['slot'], $priority['eventID'], $priority['clipID'])
                && (string)$priority['league'] === (string)$league
                && (int)$priority['slot'] === (int)$slot
                && (string)$priority['eventID'] === (string)$eventID
                && (string)$priority['clipID'] === (string)$item['id']) {
                $item['cacheState'] = 'queued';
            }
        }

        // Cache miss: keep the same-origin proxy URL only as a diagnostic/fallback
        // source. The safe-cache UI will not fetch it until the server cache is ready.
        $proxied = array();
        foreach ($upstreamSources as $sourceIndex => $source) {
            if (!is_array($source) || empty($source['url'])) continue;
            $proxied[] = array(
                'url' => pss_highlightProxyUrl($league, $slot, $item['id'], $sourceIndex),
                'type' => isset($source['type']) ? (string)$source['type'] : '',
                'path' => isset($source['path']) ? (string)$source['path'] : ''
            );
        }

        $item['mediaSources'] = $proxied;
        $item['mediaUrl'] = !empty($proxied) ? $proxied[0]['url'] : '';
        $item['mediaType'] = !empty($proxied) && isset($proxied[0]['type']) ? $proxied[0]['type'] : '';
        $item['playable'] = !empty($proxied);
    }
    unset($item);
    return $items;
}

function pss_streamHighlightMedia($league, $slot, $clipID, $sourceIndex) {
    $league = strtolower(trim((string)$league));
    $slot = ((int)$slot === 2) ? 2 : 1;
    $clipID = trim((string)$clipID);
    $sourceIndex = max(0, (int)$sourceIndex);

    $supportedHighlightLeagues = array('nfl', 'ncaa', 'nhl', 'mlb');
    if (!in_array($league, $supportedHighlightLeagues, true)
        || $clipID === ''
        || !preg_match('/^[A-Za-z0-9_-]+$/', $clipID)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid highlight request.';
        exit;
    }

    $prefix = pss_teamPrefix($league, $slot);
    $teamID = pss_statusValue($prefix . 'TeamID');
    $eventID = pss_statusValue($prefix . 'TeamNextEventID');
    if ($teamID === '' || $eventID === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No active event is selected for this team slot.';
        exit;
    }

    $items = pss_fetchEspnHighlights($league, $eventID, 10);
    $matched = null;
    foreach ($items as $item) {
        if (isset($item['id']) && (string)$item['id'] === $clipID) {
            $matched = $item;
            break;
        }
    }

    if (!is_array($matched)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Highlight clip is no longer available for the selected event.';
        exit;
    }

    $sources = isset($matched['mediaSources']) && is_array($matched['mediaSources'])
        ? $matched['mediaSources']
        : array();
    if (!isset($sources[$sourceIndex]) || !is_array($sources[$sourceIndex])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Requested highlight source is unavailable.';
        exit;
    }

    $url = isset($sources[$sourceIndex]['url']) ? pss_highlightAllowedMediaUrl($sources[$sourceIndex]['url']) : '';
    if ($url === '' || !function_exists('curl_init')) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unable to prepare ESPN media.';
        exit;
    }

    // Let the FPP box fetch ESPN and stream it back from the same origin as the
    // scoreboard. Forward byte-range requests so browser seeking/preload works.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @set_time_limit(0);
    @ignore_user_abort(true);

    $requestHeaders = array(
        'Accept: video/mp4,video/*;q=0.9,*/*;q=0.5',
        'Accept-Encoding: identity',
        'Referer: https://www.espn.com/'
    );

    $range = isset($_SERVER['HTTP_RANGE']) ? trim((string)$_SERVER['HTTP_RANGE']) : '';
    if ($range !== '' && preg_match('/^bytes=\d*-\d*(?:,\d*-\d*)*$/', $range)) {
        $requestHeaders[] = 'Range: ' . $range;
    }

    $sentHeaders = false;
    $upstreamStatus = 200;
    $safeHeaders = array();
    $proxyOutputBuffer = '';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux armv7l) AppleWebKit/537.36 Chrome/120 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    if (defined('CURLOPT_BUFFERSIZE')) {
        @curl_setopt($ch, CURLOPT_BUFFERSIZE, 262144);
    }
    if (defined('CURLOPT_TCP_NODELAY')) {
        @curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    }

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$upstreamStatus, &$safeHeaders) {
        $length = strlen($headerLine);
        $line = trim($headerLine);
        if ($line === '') return $length;

        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
            $upstreamStatus = (int)$m[1];
            $safeHeaders = array();
            return $length;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) return $length;
        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        $allowed = array(
            'content-type',
            'content-length',
            'content-range',
            'accept-ranges',
            'etag',
            'last-modified',
            'cache-control'
        );
        if (in_array($name, $allowed, true)) {
            $safeHeaders[$name] = $value;
        }
        return $length;
    });

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $chunk) use (&$sentHeaders, &$upstreamStatus, &$safeHeaders, &$proxyOutputBuffer) {
        if (!$sentHeaders) {
            $sentHeaders = true;
            http_response_code($upstreamStatus >= 200 ? $upstreamStatus : 200);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: inline');
            header('Cache-Control: private, max-age=300');

            foreach ($safeHeaders as $name => $value) {
                if ($name === 'cache-control') continue;
                header($name . ': ' . $value);
            }
            if (!isset($safeHeaders['content-type'])) {
                header('Content-Type: video/mp4');
            }
        }

        // Pi Zero optimization: avoid a PHP/web-server flush for every tiny cURL
        // callback. Send larger blocks instead to reduce CPU overhead while relaying.
        $proxyOutputBuffer .= $chunk;
        if (strlen($proxyOutputBuffer) >= 131072) {
            echo $proxyOutputBuffer;
            $proxyOutputBuffer = '';
            @flush();
        }
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    if ($proxyOutputBuffer !== '') {
        echo $proxyOutputBuffer;
        $proxyOutputBuffer = '';
        @flush();
    }
    $curlError = curl_error($ch);
    $curlCode = curl_errno($ch);
    $finalStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$sentHeaders) {
        if ($ok === false || $curlCode !== 0 || $finalStatus < 200 || $finalStatus >= 400) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'ESPN media request failed'
                . ($finalStatus ? ' (HTTP ' . $finalStatus . ')' : '')
                . ($curlError !== '' ? ': ' . $curlError : '.');
        } else {
            http_response_code($finalStatus > 0 ? $finalStatus : 200);
            header('Content-Type: video/mp4');
        }
    }
    exit;
}

function pss_statusSnapshotData() {
    global $leagues;

    $games = array();
    foreach ($leagues as $league) {
        foreach (array(1, 2) as $slot) {
            $prefix = pss_teamPrefix($league, $slot);
            $teamID = pss_statusValue($prefix . 'TeamID');
            if ($teamID === '') {
                continue;
            }

            $games[$prefix] = array(
                'league' => $league,
                'slot' => $slot,
                'teamID' => $teamID,
                'teamName' => pss_statusValue($prefix . 'TeamName', 'Selected team'),
                'teamAbbr' => pss_statusValue($prefix . 'TeamAbbreviation', 'TEAM'),
                'teamLogo' => pss_statusValue($prefix . 'TeamLogo'),
                'myScore' => pss_statusValue($prefix . 'MyScore', '0'),
                'oppoName' => pss_statusValue($prefix . 'OppoName', 'Opponent'),
                'oppoAbbr' => pss_statusValue($prefix . 'OppoAbbreviation', 'OPP'),
                'oppoLogo' => pss_statusValue($prefix . 'OppoLogo'),
                'oppoScore' => pss_statusValue($prefix . 'OppoScore', '0'),
                'eventID' => pss_statusValue($prefix . 'TeamNextEventID'),
                'state' => pss_statusValue($prefix . 'GameStatus'),
                'stateLabel' => pss_stateLabel(pss_statusValue($prefix . 'GameStatus')),
                'detail' => pss_statusValue($prefix . 'GameDetail'),
                'start' => pss_statusValue($prefix . 'Start'),
                'startFormatted' => pss_formatStart(pss_statusValue($prefix . 'Start')),
            );
        }
    }

    return array(
        'enabled' => pss_statusValue('ENABLED', 'OFF') === 'ON',
        'generatedAt' => date(DATE_ATOM),
        'games' => $games,
        'ticker' => array(
            'enabled' => pss_statusValue('TickerEnabled', 'OFF') === 'ON',
            'kioskEnabled' => pss_statusValue('TickerKioskEnabled', 'ON') === 'ON',
            'webSpeed' => max(20, min(300, (int)pss_statusValue('TickerWebSpeed', '90'))),
            'webFontSize' => max(12, min(48, (int)pss_statusValue('TickerWebFontSize', '18'))),
            'spacing' => pss_tickerSpacing(),
            'items' => pss_buildTickerItems(false),
            'text' => pss_buildTickerText(false)
        )
    );
}




if ($pssHighlightCacheRequestMode) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $league = isset($_GET['league']) ? strtolower(trim((string)$_GET['league'])) : '';
    $slot = (isset($_GET['slot']) && (int)$_GET['slot'] === 2) ? 2 : 1;
    $clipID = isset($_GET['clip']) ? trim((string)$_GET['clip']) : '';
    echo json_encode(pss_requestHighlightCache($league, $slot, $clipID));
    exit;
}

if ($pssHighlightCachedMediaMode) {
    $league = isset($_GET['league']) ? strtolower(trim((string)$_GET['league'])) : '';
    $slot = (isset($_GET['slot']) && (int)$_GET['slot'] === 2) ? 2 : 1;
    $clipID = isset($_GET['clip']) ? trim((string)$_GET['clip']) : '';
    pss_streamCachedHighlightMedia($league, $slot, $clipID);
}

if ($pssHighlightMediaMode) {
    $league = isset($_GET['league']) ? strtolower(trim((string)$_GET['league'])) : '';
    $slot = (isset($_GET['slot']) && (int)$_GET['slot'] === 2) ? 2 : 1;
    $clipID = isset($_GET['clip']) ? trim((string)$_GET['clip']) : '';
    $sourceIndex = isset($_GET['source']) ? max(0, (int)$_GET['source']) : 0;
    pss_streamHighlightMedia($league, $slot, $clipID, $sourceIndex);
}

if ($pssHighlightMode) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $league = isset($_GET['league']) ? strtolower(trim((string)$_GET['league'])) : '';
    $slot = (isset($_GET['slot']) && (int)$_GET['slot'] === 2) ? 2 : 1;
    $supportedHighlightLeagues = array('nfl', 'ncaa', 'nhl', 'mlb');
    if (!in_array($league, $supportedHighlightLeagues, true)) {
        http_response_code(400);
        echo json_encode(array(
            'ok' => false,
            'message' => 'Highlights are supported for NFL, NCAA Football, NHL, and MLB.'
        ));
        exit;
    }

    $prefix = pss_teamPrefix($league, $slot);
    $teamID = pss_statusValue($prefix . 'TeamID');
    $eventID = pss_statusValue($prefix . 'TeamNextEventID');
    if ($teamID === '' || $eventID === '') {
        echo json_encode(array('ok' => true, 'league' => $league, 'slot' => $slot, 'eventID' => $eventID, 'items' => array()));
        exit;
    }

    $items = pss_fetchEspnHighlights($league, $eventID, 6);
    $items = pss_prepareHighlightItemsForBrowser($items, $league, $slot);
    echo json_encode(array(
        'ok' => true,
        'league' => $league,
        'slot' => $slot,
        'eventID' => $eventID,
        'qualityPreference' => pss_highlightQualityPreference(),
        'generatedAt' => date(DATE_ATOM),
        'items' => $items
    ));
    exit;
}

if ($pssDataMode) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(pss_statusSnapshotData());
    exit;
}
?>
<style>
.pss-status-wrap {
    width: 100%;
    max-width: 1900px;
    margin: 0 auto;
}
.pss-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(560px, 1fr));
    gap: 16px;
    align-items: start;
}
.pss-scoreboard {
    border: 1px solid rgba(127, 127, 127, 0.28);
    border-radius: 14px;
    overflow: hidden;
    background: rgba(127, 127, 127, 0.07);
}
.pss-scoreboard-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 12px;
    border-bottom: 1px solid rgba(127, 127, 127, 0.22);
    background: rgba(127, 127, 127, 0.08);
}
.pss-league {
    font-size: 0.82rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.pss-slot-label {
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    opacity: 0.62;
}
.pss-event-id {
    opacity: 0.68;
    font-size: 0.78rem;
}
.pss-matchup {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(170px, 0.7fr) minmax(0, 1fr);
    align-items: center;
    gap: 18px;
    padding: 18px 16px 14px;
}
.pss-team {
    min-width: 0;
    text-align: center;
}
.pss-team-selected {
    border-radius: 12px;
    background: rgba(127, 127, 127, 0.08);
    padding: 12px 8px;
}
.pss-selected-tag {
    display: inline-block;
    margin-bottom: 8px;
    padding: 2px 8px;
    border: 1px solid rgba(127, 127, 127, 0.35);
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    opacity: 0.82;
}
.pss-team-logo,
.pss-team-logo-fallback {
    width: 64px;
    height: 64px;
    margin: 0 auto 10px;
}
.pss-team-logo {
    display: block;
    object-fit: contain;
}
.pss-team-logo-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(127, 127, 127, 0.35);
    border-radius: 50%;
    font-size: 1.15rem;
    font-weight: 800;
    background: rgba(127, 127, 127, 0.08);
}
.pss-team-name {
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.pss-team-abbr {
    margin-top: 4px;
    font-size: 0.82rem;
    opacity: 0.62;
}
.pss-manual-controls {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}
.pss-manual-trigger {
    min-height: 30px;
    padding: 4px 9px;
    border: 1px solid rgba(127, 127, 127, 0.42);
    border-radius: 7px;
    background: rgba(127, 127, 127, 0.10);
    color: inherit;
    font: inherit;
    font-size: 0.70rem;
    font-weight: 800;
    line-height: 1.1;
    cursor: pointer;
}
.pss-manual-trigger:hover:not(:disabled),
.pss-manual-trigger:focus-visible:not(:disabled) {
    background: rgba(127, 127, 127, 0.22);
}
.pss-manual-trigger:disabled {
    opacity: 0.34;
    cursor: not-allowed;
}
.pss-manual-trigger.pss-trigger-busy {
    opacity: 0.60;
    cursor: wait;
}
.pss-manual-feedback {
    min-height: 1.1em;
    margin-top: 5px;
    font-size: 0.66rem;
    font-weight: 700;
    line-height: 1.2;
    opacity: 0.76;
}
.pss-manual-feedback.pss-trigger-error {
    opacity: 1;
}
.pss-score-center {
    text-align: center;
}
.pss-score {
    display: flex;
    justify-content: center;
    align-items: baseline;
    gap: 15px;
    font-size: clamp(2.1rem, 4vw, 3.45rem);
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.pss-score-separator {
    opacity: 0.42;
    font-size: 0.55em;
}
.pss-state-pill {
    display: inline-block;
    margin-top: 9px;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border: 1px solid rgba(127, 127, 127, 0.32);
}
.pss-state-in { background: rgba(35, 160, 90, 0.18); }
.pss-state-pre { background: rgba(60, 125, 210, 0.18); }
.pss-state-post { background: rgba(127, 127, 127, 0.16); }
.pss-state-wait { background: rgba(215, 165, 35, 0.16); }
.pss-game-detail {
    margin-top: 6px;
    font-weight: 700;
    min-height: 1.2em;
}
.pss-meta {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0;
    border-top: 1px solid rgba(127, 127, 127, 0.22);
}
.pss-meta-item {
    padding: 9px 12px;
    min-width: 0;
}
.pss-meta-item + .pss-meta-item {
    border-left: 1px solid rgba(127, 127, 127, 0.22);
}
.pss-meta-label {
    display: block;
    margin-bottom: 2px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    opacity: 0.58;
}
.pss-meta-value {
    overflow-wrap: anywhere;
}
.pss-highlights {
    border-top: 1px solid rgba(127, 127, 127, 0.22);
    padding: 12px;
}
.pss-highlights-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 9px;
}
.pss-highlights-title {
    font-size: 0.78rem;
    font-weight: 900;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.pss-highlights-status {
    font-size: 0.72rem;
    opacity: 0.66;
    text-align: right;
}
.pss-highlight-stage {
    display: grid;
    grid-template-columns: minmax(210px, 38%) minmax(0, 1fr);
    gap: 12px;
    align-items: center;
}
.pss-highlight-media {
    position: relative;
    width: 100%;
    min-height: 118px;
    overflow: hidden;
    border-radius: 9px;
    background: #090b10;
}
.pss-highlight-video,
.pss-highlight-poster {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    background: #090b10;
}
.pss-highlight-poster {
    border: 0;
}
.pss-highlight-copy {
    min-width: 0;
}
.pss-highlight-headline {
    font-size: 0.96rem;
    font-weight: 800;
    line-height: 1.25;
    overflow-wrap: anywhere;
}
.pss-highlight-meta {
    margin-top: 5px;
    font-size: 0.74rem;
    opacity: 0.67;
}
.pss-highlight-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 9px;
}
.pss-highlight-button,
.pss-highlight-link,
.pss-highlight-history-button {
    min-height: 34px;
    padding: 6px 10px;
    border: 1px solid rgba(127, 127, 127, 0.42);
    border-radius: 7px;
    background: rgba(127, 127, 127, 0.10);
    color: inherit !important;
    font: inherit;
    font-size: 0.74rem;
    font-weight: 800;
    line-height: 1.15;
    text-decoration: none !important;
    cursor: pointer;
}
.pss-highlight-button:hover,
.pss-highlight-link:hover,
.pss-highlight-history-button:hover {
    background: rgba(127, 127, 127, 0.22);
}
.pss-highlight-button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pss-highlight-new {
    display: inline-block;
    margin-left: 6px;
    padding: 2px 6px;
    border-radius: 999px;
    background: rgba(35, 160, 90, 0.25);
    border: 1px solid rgba(35, 160, 90, 0.48);
    font-size: 0.62rem;
    font-weight: 900;
    letter-spacing: 0.05em;
    vertical-align: middle;
}
.pss-highlight-history {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}
.pss-highlight-history-button {
    max-width: 100%;
    text-align: left;
    white-space: normal;
}
.pss-highlight-empty {
    padding: 10px 0 2px;
    font-size: 0.82rem;
    opacity: 0.66;
}
.pss-kiosk-page .pss-highlights {
    border-color: #343b4a;
}
@media (max-width: 1180px) {
    .pss-status-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 720px) {
    .pss-status-grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .pss-matchup {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        padding: 18px 12px;
    }
    .pss-score-center {
        grid-column: 1 / -1;
        grid-row: 1;
        margin-bottom: 6px;
    }
    .pss-team-logo,
    .pss-team-logo-fallback {
        width: 62px;
        height: 62px;
    }
    .pss-meta {
        grid-template-columns: 1fr;
    }
    .pss-meta-item + .pss-meta-item {
        border-left: 0;
        border-top: 1px solid rgba(127, 127, 127, 0.22);
    }
    .pss-highlight-stage {
        grid-template-columns: 1fr;
    }
    .pss-highlight-media {
        min-height: 0;
    }
}
.pss-status-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 12px;
}
.pss-status-toolbar h2 {
    margin: 0;
}
.pss-kiosk-open,
.pss-kiosk-fullscreen {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 38px;
    padding: 7px 13px;
    border: 1px solid rgba(127, 127, 127, 0.38);
    border-radius: 8px;
    background: rgba(127, 127, 127, 0.10);
    color: inherit !important;
    text-decoration: none !important;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}
.pss-kiosk-open:hover,
.pss-kiosk-fullscreen:hover {
    background: rgba(127, 127, 127, 0.18);
}
.pss-kiosk-page {
    --pss-ticker-font-size: 18px;
    --pss-ticker-height: 50px;
    min-height: 100vh;
    background: #0d1017;
    color: #f3f5f9;
}
.pss-kiosk-topbar {
    position: sticky;
    top: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 58px;
    padding: 8px 18px;
    background: #11151f;
    border-bottom: 1px solid #303746;
    box-sizing: border-box;
}
.pss-kiosk-home {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    color: #f3f5f9 !important;
    text-decoration: none !important;
}
.pss-kiosk-logo {
    display: block;
    width: 92px;
    max-height: 34px;
    object-fit: contain;
}
.pss-kiosk-logo-fallback {
    display: none;
    font-size: 1.35rem;
    font-weight: 900;
    font-style: italic;
    letter-spacing: 0.04em;
}
.pss-kiosk-title {
    font-size: 1.15rem;
    font-weight: 800;
    white-space: nowrap;
}
.pss-kiosk-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.pss-kiosk-refresh-note {
    color: #aab1bf;
    font-size: 0.78rem;
}
.pss-kiosk-board {
    width: 100%;
    max-width: 1920px;
    margin: 0 auto;
    padding: 14px 14px calc(var(--pss-ticker-height, 50px) + 24px);
    box-sizing: border-box;
}
.pss-kiosk-page .pss-status-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
/* When kiosk mode has only one active team, let its scoreboard span the full display. */
.pss-kiosk-page .pss-status-grid > .pss-scoreboard:only-child {
    grid-column: 1 / -1;
}
.pss-kiosk-page .pss-scoreboard {
    background: #171b25;
    border-color: #343b4a;
}
.pss-kiosk-page .pss-scoreboard-head {
    background: #202530;
    border-color: #343b4a;
}
.pss-kiosk-page .pss-team-selected,
.pss-kiosk-page .pss-team-logo-fallback {
    background: #202530;
}
.pss-kiosk-page .pss-meta,
.pss-kiosk-page .pss-meta-item + .pss-meta-item {
    border-color: #343b4a;
}
.pss-kiosk-page .pss-event-id,
.pss-kiosk-page .pss-team-abbr,
.pss-kiosk-page .pss-meta-label,
.pss-kiosk-page .pss-kiosk-refresh-note {
    color: #aab1bf;
    opacity: 1;
}
.pss-kiosk-page .alert {
    margin: 0 0 14px;
    padding: 10px 14px;
    border: 1px solid #6b5a27;
    border-radius: 8px;
    background: #332c18;
    color: #ffe6a3;
}
.pss-kiosk-ticker {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 80;
    height: var(--pss-ticker-height, 50px);
    overflow: hidden;
    border-top: 1px solid #3a4252;
    background: #11151f;
    color: #f5f7fb;
    box-sizing: border-box;
}
.pss-kiosk-ticker::before {
    content: "SCORES";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    padding: 0 14px;
    background: #202633;
    border-right: 1px solid #3a4252;
    font-size: 0.78rem;
    font-weight: 900;
    letter-spacing: 0.08em;
}
.pss-kiosk-ticker-window {
    position: absolute;
    left: 82px;
    right: 0;
    top: 0;
    bottom: 0;
    overflow: hidden;
}
.pss-kiosk-ticker-track {
    --pss-ticker-start: 1000px;
    --pss-ticker-end: -600px;
    --pss-ticker-duration: 18s;
    position: absolute;
    left: 0;
    top: 0;
    display: inline-flex;
    align-items: center;
    width: max-content;
    height: 100%;
    white-space: nowrap;
    will-change: transform;
    animation: pssTickerScroll var(--pss-ticker-duration) linear infinite;
}
.pss-kiosk-ticker-content {
    display: inline-flex;
    align-items: center;
    width: max-content;
    white-space: nowrap;
    font-size: var(--pss-ticker-font-size, 18px);
    font-weight: 750;
    letter-spacing: 0.015em;
}
.pss-kiosk-ticker-segment {
    display: inline-block;
}
.pss-kiosk-ticker-separator {
    display: inline-block;
    opacity: .62;
    margin-left: var(--pss-ticker-gap, 1.4em);
    margin-right: var(--pss-ticker-gap, 1.4em);
}
@keyframes pssTickerScroll {
    from { transform: translateX(var(--pss-ticker-start)); }
    to { transform: translateX(var(--pss-ticker-end)); }
}
@media (prefers-reduced-motion: reduce) {
    .pss-kiosk-ticker-track {
        animation: none;
        position: static;
        padding-left: 12px;
    }
}
@media (min-width: 1500px) {
    .pss-kiosk-page .pss-matchup {
        padding-top: 20px;
        padding-bottom: 18px;
    }
    .pss-kiosk-page .pss-team-logo,
    .pss-kiosk-page .pss-team-logo-fallback {
        width: 72px;
        height: 72px;
    }
}
@media (max-width: 1180px) {
    .pss-kiosk-page .pss-status-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 720px) {
    .pss-status-toolbar {
        align-items: stretch;
        flex-direction: column;
    }
    .pss-kiosk-topbar {
        align-items: flex-start;
        flex-direction: column;
    }
    .pss-kiosk-actions {
        width: 100%;
        justify-content: space-between;
    }
    .pss-kiosk-title {
        white-space: normal;
    }
}
<?php if ($pssKioskMode): ?>
html, body {
    margin: 0 !important;
    min-height: 100%;
    background: #0d1017 !important;
    color: #f3f5f9 !important;
}
body {
    overflow-x: hidden;
}
<?php endif; ?>

</style>

<?php if ($pssKioskMode): ?>
<div class="pss-kiosk-page">
    <header class="pss-kiosk-topbar">
        <a class="pss-kiosk-home" href="plugin.php?plugin=fpp-nfl&amp;page=status.php" title="Return to Pro Sports Scoring">
            <img class="pss-kiosk-logo"
                 src="/images/redesign/fpp-logo.svg"
                 alt="FPP"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-block';">
            <span class="pss-kiosk-logo-fallback">FPP</span>
            <span class="pss-kiosk-title">Pro Sports Scoreboard</span>
        </a>
        <div class="pss-kiosk-actions">
            <span class="pss-kiosk-refresh-note">Live refresh: 10 sec</span>
            <button type="button" class="pss-kiosk-fullscreen" onclick="pssKioskFullscreen()">Fullscreen</button>
        </div>
    </header>
    <main class="pss-kiosk-board">
<?php else: ?>
<div class="container-fluid pss-status-wrap">
    <div class="pss-status-toolbar">
        <h2>Pro Sports Scoring Status</h2>
        <a class="pss-kiosk-open" href="plugin.php?plugin=fpp-nfl&amp;page=status.php&amp;nopage=1&amp;kiosk=1">Kiosk Display</a>
    </div>
<?php endif; ?>

    <div id="pss-disabled-banner" class="alert alert-warning"<?=pss_statusValue('ENABLED', 'OFF') === 'ON' ? ' style="display:none"' : ''?>>The plugin is currently disabled.</div>

    <div class="pss-status-grid">
    <?php
    $rendered = 0;
    foreach ($leagues as $league):
        foreach (array(1, 2) as $slot):
            $prefix = pss_teamPrefix($league, $slot);
            $teamID = pss_statusValue($prefix . 'TeamID');
            if ($teamID === '') continue;
            $rendered++;

            $label = ($league === 'ncaa') ? 'NCAA Football' : strtoupper($league);
            $state = pss_statusValue($prefix . 'GameStatus');
            $stateClass = ($state === 'in') ? 'pss-state-in' : (($state === 'pre') ? 'pss-state-pre' : (($state === 'post') ? 'pss-state-post' : 'pss-state-wait'));
            $detail = pss_statusValue($prefix . 'GameDetail');

            $myName = pss_statusValue($prefix . 'TeamName', 'Selected team');
            $myAbbr = pss_statusValue($prefix . 'TeamAbbreviation', 'TEAM');
            $myLogo = pss_statusValue($prefix . 'TeamLogo');
            $myScore = pss_statusValue($prefix . 'MyScore', '0');

            $oppoName = pss_statusValue($prefix . 'OppoName', 'Opponent');
            $oppoAbbr = pss_statusValue($prefix . 'OppoAbbreviation', 'OPP');
            $oppoLogo = pss_statusValue($prefix . 'OppoLogo');
            $oppoScore = pss_statusValue($prefix . 'OppoScore', '0');

            $eventID = pss_statusValue($prefix . 'TeamNextEventID');
    ?>
        <section class="pss-scoreboard"
                 data-pss-key="<?=htmlspecialchars($prefix, ENT_QUOTES)?>"
                 data-event-id="<?=htmlspecialchars($eventID, ENT_QUOTES)?>"
                 data-team-name="<?=htmlspecialchars($myName, ENT_QUOTES)?>"
                 data-opponent-name="<?=htmlspecialchars($oppoName, ENT_QUOTES)?>"
                 data-team-logo="<?=htmlspecialchars($myLogo, ENT_QUOTES)?>"
                 data-opponent-logo="<?=htmlspecialchars($oppoLogo, ENT_QUOTES)?>"
                 aria-label="<?=htmlspecialchars($label . ' team ' . $slot)?> game status">
            <div class="pss-scoreboard-head">
                <span class="pss-league"><?=htmlspecialchars($label)?> <span class="pss-slot-label">· Team <?=$slot?></span></span>
                <?php if ($eventID !== ''): ?>
                    <span class="pss-event-id" data-pss-field="event-id">ESPN event <?=htmlspecialchars($eventID)?></span>
                <?php endif; ?>
            </div>

            <div class="pss-matchup">
                <div class="pss-team">
                    <?=pss_teamLogoMarkup($oppoLogo, $oppoAbbr, $oppoName)?>
                    <div class="pss-team-name" data-pss-field="opponent-name"><?=htmlspecialchars($oppoName)?></div>
                    <div class="pss-team-abbr" data-pss-field="opponent-abbr"><?=htmlspecialchars($oppoAbbr)?></div>
                </div>

                <div class="pss-score-center">
                    <div class="pss-score" aria-label="<?=htmlspecialchars($oppoName . ' ' . $oppoScore . ', ' . $myName . ' ' . $myScore)?>">
                        <span data-pss-field="opponent-score"><?=htmlspecialchars($oppoScore)?></span>
                        <span class="pss-score-separator">–</span>
                        <span data-pss-field="team-score"><?=htmlspecialchars($myScore)?></span>
                    </div>
                    <div class="pss-state-pill <?=$stateClass?>" data-pss-field="state"><?=htmlspecialchars(pss_stateLabel($state))?></div>
                    <div class="pss-game-detail" data-pss-field="detail"><?=htmlspecialchars($detail !== '' ? $detail : pss_stateLabel($state))?></div>
                </div>

                <div class="pss-team pss-team-selected">
                    <div class="pss-selected-tag">Selected team <?=$slot?></div>
                    <?=pss_teamLogoMarkup($myLogo, $myAbbr, $myName)?>
                    <div class="pss-team-name" data-pss-field="team-name"><?=htmlspecialchars($myName)?></div>
                    <div class="pss-team-abbr" data-pss-field="team-abbr"><?=htmlspecialchars($myAbbr)?></div>
                    <?=pss_manualTriggerButtonsMarkup($league, $slot, $prefix)?>
                </div>
            </div>

            <div class="pss-meta">
                <div class="pss-meta-item">
                    <span class="pss-meta-label">Start</span>
                    <span class="pss-meta-value" data-pss-field="start"><?=htmlspecialchars(pss_formatStart(pss_statusValue($prefix . 'Start')))?></span>
                </div>
                <div class="pss-meta-item">
                    <span class="pss-meta-label">Matchup</span>
                    <span class="pss-meta-value" data-pss-field="matchup"><?=htmlspecialchars($oppoAbbr . ' vs ' . $myAbbr)?></span>
                </div>
            </div>
            <?php if (in_array($league, array('nfl', 'ncaa', 'nhl', 'mlb'), true) && $eventID !== ''): ?>
            <div class="pss-highlights"
                 data-pss-highlights="1"
                 data-league="<?=htmlspecialchars($league, ENT_QUOTES)?>"
                 data-slot="<?=intval($slot)?>"
                 data-event-id="<?=htmlspecialchars($eventID, ENT_QUOTES)?>">
                <div class="pss-highlights-head">
                    <span class="pss-highlights-title">ESPN Highlights <span class="pss-highlight-new" data-highlight-new="1" style="display:none">NEW</span></span>
                    <span class="pss-highlights-status" data-highlight-status="1">Checking ESPN…</span>
                </div>
                <div data-highlight-body="1">
                    <div class="pss-highlight-empty">Looking for game highlights…</div>
                </div>
            </div>
            <?php endif; ?>
        </section>
    <?php
        endforeach;
    endforeach;
    ?>
    </div>

    <?php if ($rendered === 0): ?>
        <div class="alert alert-info">Select a team on the Pro Sports Scoring setup page to display game status here.</div>
    <?php endif; ?>

<?php if ($pssKioskMode): ?>
    </main>
    <?php
        $pssTickerVisible = pss_statusValue('TickerEnabled', 'OFF') === 'ON' && pss_statusValue('TickerKioskEnabled', 'ON') === 'ON';
        $pssTickerText = pss_buildTickerText(false);
        $pssTickerItems = pss_buildTickerItems(false);
        $pssTickerSpacing = pss_tickerSpacing();
        $pssTickerGapEm = number_format($pssTickerSpacing * 0.35, 2, '.', '');
        $pssTickerWebSpeed = max(20, min(300, (int)pss_statusValue('TickerWebSpeed', '90')));
        $pssTickerWebFontSize = max(12, min(48, (int)pss_statusValue('TickerWebFontSize', '18')));
        $pssTickerHeight = max(50, min(80, $pssTickerWebFontSize + 32));
    ?>
    <style>.pss-kiosk-page{--pss-ticker-font-size:<?=$pssTickerWebFontSize?>px;--pss-ticker-height:<?=$pssTickerHeight?>px;}</style>
    <div id="pss-kiosk-ticker" class="pss-kiosk-ticker" data-speed="<?=intval($pssTickerWebSpeed)?>" data-spacing="<?=intval($pssTickerSpacing)?>" data-font-size="<?=intval($pssTickerWebFontSize)?>"<?=$pssTickerVisible ? '' : ' style="display:none"'?> aria-label="Sports score ticker">
        <div class="pss-kiosk-ticker-window">
            <div class="pss-kiosk-ticker-track">
                <span class="pss-kiosk-ticker-content" data-pss-ticker-content="1" style="--pss-ticker-gap:<?=$pssTickerGapEm?>em;">
                <?php if (empty($pssTickerItems)): ?>
                    <span class="pss-kiosk-ticker-segment"><?=htmlspecialchars($pssTickerText)?></span>
                <?php else: foreach ($pssTickerItems as $pssTickerIndex => $pssTickerItem): ?>
                    <?php if ($pssTickerIndex > 0): ?><span class="pss-kiosk-ticker-separator">•</span><?php endif; ?>
                    <span class="pss-kiosk-ticker-segment" style="color:<?=htmlspecialchars($pssTickerItem['color'], ENT_QUOTES)?>"><?=htmlspecialchars($pssTickerItem['text'])?></span>
                <?php endforeach; endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<script>
function pssKioskFullscreen() {
    var root = document.documentElement;
    var request = root.requestFullscreen || root.webkitRequestFullscreen || root.msRequestFullscreen;
    if (request) {
        request.call(root);
    }
}

(function () {
    var dataUrl = 'plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&data=1';
    var refreshNote = document.querySelector('.pss-kiosk-refresh-note');

    var ticker = document.getElementById('pss-kiosk-ticker');
    var tickerState = {
        text: <?=json_encode($pssTickerText)?>,
        items: <?=json_encode($pssTickerItems)?>,
        speed: <?=intval($pssTickerWebSpeed)?>,
        spacing: <?=intval($pssTickerSpacing)?>,
        fontSize: <?=intval($pssTickerWebFontSize)?>
    };

    function tickerGapEm(spacing) {
        var safeSpacing = Math.max(1, Math.min(12, parseInt(spacing || 4, 10)));
        return (safeSpacing * 0.35).toFixed(2) + 'em';
    }

    function applyTickerSize(fontSize) {
        var safeFontSize = Math.max(12, Math.min(48, parseInt(fontSize || 18, 10)));
        var tickerHeight = Math.max(50, Math.min(80, safeFontSize + 32));
        var kioskPage = document.querySelector('.pss-kiosk-page');
        if (kioskPage) {
            kioskPage.style.setProperty('--pss-ticker-font-size', safeFontSize + 'px');
            kioskPage.style.setProperty('--pss-ticker-height', tickerHeight + 'px');
        }
        if (ticker) {
            ticker.setAttribute('data-font-size', String(safeFontSize));
        }
        return safeFontSize;
    }

    function renderTickerItems(items, fallbackText, spacing) {
        if (!ticker) return null;

        var content = ticker.querySelector('[data-pss-ticker-content="1"]');
        if (!content) return null;

        while (content.firstChild) {
            content.removeChild(content.firstChild);
        }

        content.style.setProperty('--pss-ticker-gap', tickerGapEm(spacing));

        if (!items || !items.length) {
            var emptySegment = document.createElement('span');
            emptySegment.className = 'pss-kiosk-ticker-segment';
            emptySegment.textContent = fallbackText || 'PRO SPORTS SCORING • NO SELECTED TEAMS';
            content.appendChild(emptySegment);
            return content;
        }

        for (var i = 0; i < items.length; i++) {
            if (i > 0) {
                var separator = document.createElement('span');
                separator.className = 'pss-kiosk-ticker-separator';
                separator.textContent = '•';
                content.appendChild(separator);
            }

            var segment = document.createElement('span');
            segment.className = 'pss-kiosk-ticker-segment';
            segment.textContent = String(items[i].text || '');
            var color = String(items[i].color || '');
            if (/^#[0-9A-Fa-f]{6}$/.test(color)) {
                segment.style.color = color;
            }
            content.appendChild(segment);
        }

        return content;
    }

    function updateTickerAnimation(text, speed, items, spacing, fontSize) {
        if (!ticker) return;

        var track = ticker.querySelector('.pss-kiosk-ticker-track');
        var windowEl = ticker.querySelector('.pss-kiosk-ticker-window');
        var content = renderTickerItems(items, text, spacing);
        if (!track || !content || !windowEl) return;

        ticker.setAttribute('data-speed', String(speed || 90));
        ticker.setAttribute('data-spacing', String(spacing || 4));
        applyTickerSize(fontSize);

        window.requestAnimationFrame(function () {
            /*
             * Move one complete ticker message from fully off-screen on the
             * right to fully off-screen on the left. The reset happens only
             * while the content is invisible.
             */
            var windowWidth = Math.max(1, windowEl.getBoundingClientRect().width);
            var textWidth = Math.max(1, content.getBoundingClientRect().width);
            var pxPerSecond = Math.max(20, Math.min(300, parseInt(speed || 90, 10)));
            var travel = windowWidth + textWidth;
            var duration = Math.max(6, travel / pxPerSecond);

            track.style.setProperty('--pss-ticker-start', windowWidth + 'px');
            track.style.setProperty('--pss-ticker-end', (-textWidth) + 'px');
            track.style.setProperty('--pss-ticker-duration', duration.toFixed(2) + 's');
            track.style.animation = 'none';
            void track.offsetWidth;
            track.style.animation = '';
        });
    }

    function stateClass(state) {
        if (state === 'in') return 'pss-state-in';
        if (state === 'pre') return 'pss-state-pre';
        if (state === 'post') return 'pss-state-post';
        return 'pss-state-wait';
    }

    function setField(card, field, value) {
        var element = card.querySelector('[data-pss-field="' + field + '"]');
        if (element) {
            element.textContent = value == null ? '' : String(value);
        }
    }

    function identityChanged(card, game) {
        return card.getAttribute('data-event-id') !== String(game.eventID || '') ||
            card.getAttribute('data-team-name') !== String(game.teamName || '') ||
            card.getAttribute('data-opponent-name') !== String(game.oppoName || '') ||
            card.getAttribute('data-team-logo') !== String(game.teamLogo || '') ||
            card.getAttribute('data-opponent-logo') !== String(game.oppoLogo || '');
    }

    function applySnapshot(snapshot) {
        if (!snapshot || !snapshot.games) {
            return;
        }

        var cards = document.querySelectorAll('.pss-scoreboard[data-pss-key]');
        var gameKeys = Object.keys(snapshot.games);

        if (cards.length !== gameKeys.length) {
            window.location.reload();
            return;
        }

        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var gameKey = card.getAttribute('data-pss-key');
            var game = snapshot.games[gameKey];

            if (!game || identityChanged(card, game)) {
                window.location.reload();
                return;
            }

            setField(card, 'opponent-score', game.oppoScore);
            setField(card, 'team-score', game.myScore);
            setField(card, 'opponent-name', game.oppoName);
            setField(card, 'opponent-abbr', game.oppoAbbr);
            setField(card, 'team-name', game.teamName);
            setField(card, 'team-abbr', game.teamAbbr);
            setField(card, 'start', game.startFormatted);
            setField(card, 'matchup', String(game.oppoAbbr || '') + ' vs ' + String(game.teamAbbr || ''));

            var state = card.querySelector('[data-pss-field="state"]');
            if (state) {
                state.className = 'pss-state-pill ' + stateClass(game.state);
                state.textContent = game.stateLabel || 'Waiting for ESPN';
            }

            setField(card, 'detail', game.detail || game.stateLabel || 'Waiting for ESPN');

            var score = card.querySelector('.pss-score');
            if (score) {
                score.setAttribute(
                    'aria-label',
                    String(game.oppoName || 'Opponent') + ' ' + String(game.oppoScore || '0') +
                    ', ' + String(game.teamName || 'Selected team') + ' ' + String(game.myScore || '0')
                );
            }
        }

        if (ticker && snapshot.ticker) {
            var showTicker = !!snapshot.ticker.enabled && !!snapshot.ticker.kioskEnabled;
            ticker.style.display = showTicker ? '' : 'none';
            if (showTicker) {
                var newText = String(snapshot.ticker.text || '');
                var newSpeed = parseInt(snapshot.ticker.webSpeed || 90, 10);
                var newSpacing = parseInt(snapshot.ticker.spacing || 4, 10);
                var newFontSize = parseInt(snapshot.ticker.webFontSize || 18, 10);
                var newItems = Array.isArray(snapshot.ticker.items) ? snapshot.ticker.items : [];
                var newSignature = JSON.stringify(newItems) + '|' + newText + '|' + newSpeed + '|' + newSpacing + '|' + newFontSize;
                var oldSignature = ticker.getAttribute('data-current-signature') || '';

                tickerState = {
                    text: newText,
                    items: newItems,
                    speed: newSpeed,
                    spacing: newSpacing,
                    fontSize: newFontSize
                };

                if (oldSignature !== newSignature) {
                    ticker.setAttribute('data-current-signature', newSignature);
                    updateTickerAnimation(newText, newSpeed, newItems, newSpacing, newFontSize);
                }
            }
        }

        var disabledBanner = document.getElementById('pss-disabled-banner');
        if (disabledBanner) {
            disabledBanner.style.display = snapshot.enabled ? 'none' : '';
        }

        if (refreshNote) {
            refreshNote.textContent = 'Live refresh: 10 sec';
        }
    }

    function refreshScoreboard() {
        fetch(dataUrl, { cache: 'no-store' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(applySnapshot)
            .catch(function () {
                if (refreshNote) {
                    refreshNote.textContent = 'Waiting to refresh…';
                }
            });
    }

    if (ticker && ticker.style.display !== 'none') {
        var initialSignature = JSON.stringify(tickerState.items) + '|' + tickerState.text + '|' + tickerState.speed + '|' + tickerState.spacing + '|' + tickerState.fontSize;
        ticker.setAttribute('data-current-signature', initialSignature);
        updateTickerAnimation(tickerState.text, tickerState.speed, tickerState.items, tickerState.spacing, tickerState.fontSize);
    }

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        if (!ticker || ticker.style.display === 'none') return;
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            updateTickerAnimation(tickerState.text, tickerState.speed, tickerState.items, tickerState.spacing, tickerState.fontSize);
        }, 150);
    });

    window.setInterval(refreshScoreboard, 10000);
})();
</script>
<?php else: ?>
</div>
<?php endif; ?>

<script>
(function () {
    var buttons = document.querySelectorAll('[data-pss-manual-trigger="1"]');
    if (!buttons.length) return;

    var triggerUrl = 'plugin.php?plugin=fpp-nfl&page=status.php&nopage=1';

    function setFeedback(button, message, isError) {
        var panel = button.closest ? button.closest('.pss-team-selected') : null;
        var feedback = panel ? panel.querySelector('[data-pss-manual-feedback="1"]') : null;
        if (!feedback) return;
        feedback.textContent = message || '';
        if (isError) {
            feedback.classList.add('pss-trigger-error');
        } else {
            feedback.classList.remove('pss-trigger-error');
        }
    }

    function finishButton(button, oldText) {
        button.classList.remove('pss-trigger-busy');
        button.disabled = false;
        button.textContent = oldText;
    }

    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            var button = this;
            if (button.disabled || button.classList.contains('pss-trigger-busy')) return;

            var oldText = button.textContent;
            var params = new URLSearchParams();
            params.append('action', 'manualTrigger');
            params.append('league', button.getAttribute('data-league') || '');
            params.append('slot', button.getAttribute('data-slot') || '1');
            params.append('trigger', button.getAttribute('data-trigger') || '');

            button.disabled = true;
            button.classList.add('pss-trigger-busy');
            button.textContent = 'Sending…';
            setFeedback(button, 'Sending celebration to FPP…', false);

            fetch(triggerUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: params.toString(),
                cache: 'no-store'
            })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                setFeedback(button, data && data.message ? data.message : 'Trigger sent.', !(data && data.ok));
                finishButton(button, oldText);
            })
            .catch(function () {
                setFeedback(button, 'Unable to trigger the playlist. Check the plugin log.', true);
                finishButton(button, oldText);
            });
        });
    }
})();
</script>

<script>
(function () {
    var panels = document.querySelectorAll('[data-pss-highlights="1"]');
    if (!panels.length || typeof fetch !== 'function') return;

    var activeVideo = null;

    function playedStorageKey(eventID) {
        return 'pss-highlight-played-' + String(eventID || 'none');
    }

    function readPlayed(eventID) {
        try {
            var raw = window.localStorage.getItem(playedStorageKey(eventID));
            var value = raw ? JSON.parse(raw) : [];
            return Array.isArray(value) ? value : [];
        } catch (e) {
            return [];
        }
    }

    function hasPlayed(eventID, clipID) {
        return readPlayed(eventID).indexOf(String(clipID)) !== -1;
    }

    function markPlayed(eventID, clipID) {
        try {
            var played = readPlayed(eventID);
            var id = String(clipID);
            if (played.indexOf(id) === -1) played.push(id);
            if (played.length > 50) played = played.slice(played.length - 50);
            window.localStorage.setItem(playedStorageKey(eventID), JSON.stringify(played));
        } catch (e) {
            // localStorage can be unavailable in privacy modes. Playback still works;
            // the in-page newest-ID check prevents duplicate new-item handling during this session.
        }
    }

    function durationLabel(seconds) {
        seconds = Math.max(0, parseInt(seconds || 0, 10));
        if (!seconds) return '';
        var minutes = Math.floor(seconds / 60);
        var remain = seconds % 60;
        return minutes ? (minutes + ':' + (remain < 10 ? '0' : '') + remain) : (remain + ' sec');
    }

    function setStatus(panel, text) {
        var status = panel.querySelector('[data-highlight-status="1"]');
        if (status) status.textContent = text || '';
    }

    function showNewBadge(panel, show) {
        var badge = panel.querySelector('[data-highlight-new="1"]');
        if (badge) badge.style.display = show ? '' : 'none';
    }

    function stopOtherVideos(current) {
        var videos = document.querySelectorAll('.pss-highlight-video');
        for (var i = 0; i < videos.length; i++) {
            if (videos[i] !== current && !videos[i].paused) {
                try { videos[i].pause(); } catch (e) {}
            }
        }
        if (activeVideo && activeVideo !== current && !activeVideo.paused) {
            try { activeVideo.pause(); } catch (e) {}
        }
        activeVideo = current;
    }

    function findItem(panel, id) {
        var items = panel._pssHighlightItems || [];
        for (var i = 0; i < items.length; i++) {
            if (String(items[i].id) === String(id)) return items[i];
        }
        return null;
    }

    function playableHighlightSources(item, video) {
        var sources = [];
        if (item && Array.isArray(item.mediaSources)) {
            sources = item.mediaSources.slice(0);
        } else if (item && item.mediaUrl) {
            sources = [{url: item.mediaUrl, type: item.mediaType || ''}];
        }

        var result = [];
        var seen = {};
        for (var i = 0; i < sources.length; i++) {
            var source = sources[i] || {};
            var url = String(source.url || '');
            var type = String(source.type || '');
            if (!url || seen[url]) continue;
            seen[url] = true;

            if (type === 'hls') {
                var hlsSupport = '';
                try {
                    hlsSupport = video && typeof video.canPlayType === 'function'
                        ? video.canPlayType('application/vnd.apple.mpegurl')
                        : '';
                } catch (e) {}
                if (!hlsSupport) continue;
            }
            result.push({url: url, type: type});
        }
        return result;
    }

    function setVideoSource(video, sources, index) {
        if (!video || !sources || index < 0 || index >= sources.length) return false;
        video._pssSourceIndex = index;
        video.src = sources[index].url;
        try { video.load(); } catch (e) {}
        return true;
    }

    function formatBytes(bytes) {
        bytes = Math.max(0, parseInt(bytes || 0, 10));
        if (!bytes) return '';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    function disposeHighlightMedia(panel) {
        if (!panel) return;
        var oldVideos = panel.querySelectorAll('video.pss-highlight-video');
        for (var i = 0; i < oldVideos.length; i++) {
            try { oldVideos[i].pause(); } catch (e) {}
            try {
                oldVideos[i].removeAttribute('src');
                while (oldVideos[i].firstChild) {
                    oldVideos[i].removeChild(oldVideos[i].firstChild);
                }
                oldVideos[i].load();
            } catch (e) {}
        }
    }

    function requestHighlightCache(panel, item) {
        if (!panel || !item || item.cached || !item.id) return;
        var league = panel.getAttribute('data-league') || '';
        var slot = panel.getAttribute('data-slot') || '1';
        var requestKey = league + ':' + slot + ':' + String(item.id);

        if (panel._pssRequestedCacheKey === requestKey) return;
        panel._pssRequestedCacheKey = requestKey;

        setStatus(panel, 'Queueing selected highlight on FPP…');
        var url = 'plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlightcacherequest=1&league=' +
            encodeURIComponent(league) + '&slot=' + encodeURIComponent(slot) +
            '&clip=' + encodeURIComponent(String(item.id)) + '&_=' + Date.now();

        fetch(url, { cache: 'no-store' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    panel._pssRequestedCacheKey = '';
                    setStatus(panel, data && data.message ? data.message : 'Unable to queue highlight');
                    return;
                }
                setStatus(panel, data.state === 'cached'
                    ? 'Preloaded on FPP · ready to play'
                    : 'Queued next for safe background caching…');
                // Refresh metadata soon instead of waiting the full 20 seconds.
                window.setTimeout(function () { refreshPanel(panel); }, 1500);
            })
            .catch(function () {
                panel._pssRequestedCacheKey = '';
                setStatus(panel, 'Unable to queue highlight cache request');
            });
    }

    function loadHighlight(panel, item, autoPlay, isNew) {
        if (!item) return;
        var body = panel.querySelector('[data-highlight-body="1"]');
        if (!body) return;

        // Important on Pi Zero: changing clips must immediately stop the previous
        // video request before the next UI is created.
        disposeHighlightMedia(panel);
        while (body.firstChild) body.removeChild(body.firstChild);

        var stage = document.createElement('div');
        stage.className = 'pss-highlight-stage';

        var media = document.createElement('div');
        media.className = 'pss-highlight-media';

        var video = null;
        var videoSources = [];
        if (item.mediaUrl || (Array.isArray(item.mediaSources) && item.mediaSources.length)) {
            video = document.createElement('video');
            video.className = 'pss-highlight-video';
            video.controls = true;
            video.preload = item.cached ? 'metadata' : 'none';
            video.autoplay = false;
            video.playsInline = true;
            if (item.thumbnail) video.poster = item.thumbnail;
            video.setAttribute('aria-label', item.headline || 'ESPN highlight');

            videoSources = playableHighlightSources(item, video);
            if (!videoSources.length) {
                video = null;
            } else {
                if (item.cached) {
                    // Already on the FPP disk: use the local same-origin file.
                    setVideoSource(video, videoSources, 0);
                } else {
                    // Deliberately do not touch ESPN/the live proxy from the browser.
                    // The background worker will cache this clip at low priority.
                    video.removeAttribute('src');
                }
                video.addEventListener('play', function () {
                    stopOtherVideos(video);
                    markPlayed(panel.getAttribute('data-event-id') || '', item.id);
                    if (replay) replay.textContent = 'Replay';
                    showNewBadge(panel, false);
                    setStatus(panel, 'Playing highlight');
                });
                video.addEventListener('loadedmetadata', function () {
                    setStatus(panel, 'Buffered · ready to play');
                });
                video.addEventListener('canplay', function () {
                    setStatus(panel, 'Ready · tap ' + (hasPlayed(panel.getAttribute('data-event-id') || '', item.id) ? 'Replay' : 'Play'));
                });
                video.addEventListener('ended', function () {
                    setStatus(panel, 'Played once · Replay available');
                });
                video.addEventListener('error', function () {
                    setStatus(panel, 'Cached video playback unavailable · use ESPN link');
                });
                media.appendChild(video);
            }
        }
        if (!video && item.thumbnail) {
            var poster = document.createElement('img');
            poster.className = 'pss-highlight-poster';
            poster.src = item.thumbnail;
            poster.alt = item.headline || 'ESPN highlight thumbnail';
            media.appendChild(poster);
        } else if (!video) {
            var noMedia = document.createElement('div');
            noMedia.className = 'pss-highlight-empty';
            noMedia.textContent = 'ESPN did not provide a browser-playable source for this clip.';
            media.appendChild(noMedia);
        }

        var copy = document.createElement('div');
        copy.className = 'pss-highlight-copy';
        var headline = document.createElement('div');
        headline.className = 'pss-highlight-headline';
        headline.textContent = item.headline || 'ESPN game highlight';
        copy.appendChild(headline);

        var meta = document.createElement('div');
        meta.className = 'pss-highlight-meta';
        var parts = [];
        var duration = durationLabel(item.duration);
        if (duration) parts.push(duration);
        if (video) parts.push('plays inside scoreboard');
        else if (item.playable) parts.push('stream format not supported by this browser');
        else parts.push('ESPN link only');
        meta.textContent = parts.join(' · ');
        copy.appendChild(meta);

        var actions = document.createElement('div');
        actions.className = 'pss-highlight-actions';
        var replay = document.createElement('button');
        replay.type = 'button';
        replay.className = 'pss-highlight-button';
        replay.textContent = item.cached
            ? (hasPlayed(panel.getAttribute('data-event-id') || '', item.id) ? 'Replay' : 'Play')
            : 'Caching…';
        replay.disabled = !video || !item.cached;
        replay.addEventListener('click', function () {
            if (!video) return;
            stopOtherVideos(video);
            try { video.currentTime = 0; } catch (e) {}
            var promise = video.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () { setStatus(panel, 'Tap the video play control to start'); });
            }
        });
        actions.appendChild(replay);

        if (item.webUrl) {
            var espnLink = document.createElement('a');
            espnLink.className = 'pss-highlight-link';
            espnLink.href = item.webUrl;
            espnLink.target = '_blank';
            espnLink.rel = 'noopener noreferrer';
            espnLink.textContent = 'Open on ESPN';
            actions.appendChild(espnLink);
        }
        copy.appendChild(actions);

        stage.appendChild(media);
        stage.appendChild(copy);
        body.appendChild(stage);

        var items = panel._pssHighlightItems || [];
        if (items.length > 1) {
            var history = document.createElement('div');
            history.className = 'pss-highlight-history';
            for (var i = 0; i < items.length; i++) {
                if (String(items[i].id) === String(item.id)) continue;
                var historyButton = document.createElement('button');
                historyButton.type = 'button';
                historyButton.className = 'pss-highlight-history-button';
                historyButton.setAttribute('data-highlight-id', String(items[i].id));
                historyButton.textContent = (items[i].cached ? 'Play: ' : 'Cache: ') +
                    String(items[i].headline || 'Earlier highlight');
                historyButton.addEventListener('click', function () {
                    var selected = findItem(panel, this.getAttribute('data-highlight-id'));
                    if (selected) {
                        loadHighlight(panel, selected, false, false);
                        if (!selected.cached) requestHighlightCache(panel, selected);
                    }
                });
                history.appendChild(historyButton);
            }
            body.appendChild(history);
        }

        panel._pssCurrentHighlightID = String(item.id || '');
        showNewBadge(panel, !!isNew);

        panel._pssRenderedCached = !!item.cached;
        panel._pssRenderedCacheState = String(item.cacheState || (item.cached ? 'cached' : 'waiting'));

        if (video && videoSources.length && item.cached) {
            replay.disabled = false;
            replay.textContent = hasPlayed(panel.getAttribute('data-event-id') || '', item.id) ? 'Replay' : 'Play';
            var cachedLabel = item.cachedBytes ? (' · ' + formatBytes(item.cachedBytes)) : '';
            setStatus(panel, 'Preloaded on FPP' + cachedLabel + ' · ready to play');
        } else if (video && videoSources.length) {
            // Browser never downloads uncached media. Show the real queue state.
            replay.disabled = true;
            replay.textContent = 'Caching…';
            if (item.cacheState === 'caching') {
                setStatus(panel, 'Caching on FPP at low priority…');
            } else if (item.cacheState === 'queued') {
                setStatus(panel, 'Queued next for background caching…');
            } else {
                setStatus(panel, isNew
                    ? 'New highlight · waiting for background cache…'
                    : 'Waiting for background cache…');
            }
        }


        if (!video) {
            setStatus(panel, isNew ? 'New highlight · ESPN link available' : 'Watch on ESPN');
        }
    }

    function renderEmpty(panel) {
        var body = panel.querySelector('[data-highlight-body="1"]');
        if (!body) return;
        while (body.firstChild) body.removeChild(body.firstChild);
        var empty = document.createElement('div');
        empty.className = 'pss-highlight-empty';
        empty.textContent = 'No ESPN highlights are available for this game yet.';
        body.appendChild(empty);
        showNewBadge(panel, false);
        setStatus(panel, 'No highlights yet');
    }

    function applyHighlightResponse(panel, data) {
        if (!data || !data.ok || !Array.isArray(data.items)) {
            setStatus(panel, 'Unable to load highlights');
            return;
        }

        var currentEvent = String(panel.getAttribute('data-event-id') || '');
        if (String(data.eventID || '') !== currentEvent) {
            // The scoring snapshot will reload the card when the event rolls over.
            return;
        }

        panel._pssHighlightItems = data.items;
        if (!data.items.length) {
            if (!panel._pssHighlightInitialized) renderEmpty(panel);
            panel._pssHighlightInitialized = true;
            return;
        }

        var newest = data.items[0];
        var newestID = String(newest.id || '');
        var previousNewestID = panel._pssNewestHighlightID || '';
        var firstLoad = !panel._pssHighlightInitialized;
        var newArrival = !firstLoad && newestID !== previousNewestID;

        panel._pssHighlightInitialized = true;
        panel._pssNewestHighlightID = newestID;

        // If the clip currently on screen changed from cache-miss to cached, rebuild
        // just that clip so Play becomes available without reloading the page.
        if (panel._pssCurrentHighlightID) {
            var currentItem = findItem(panel, panel._pssCurrentHighlightID);
            var currentState = currentItem
                ? String(currentItem.cacheState || (currentItem.cached ? 'cached' : 'waiting'))
                : '';
            if (currentItem && (
                !!currentItem.cached !== !!panel._pssRenderedCached ||
                currentState !== String(panel._pssRenderedCacheState || '')
            )) {
                loadHighlight(panel, currentItem, false, false);
                return;
            }
        }

        // On initial load or when a new clip arrives, render it immediately.
        // The browser does not download uncached media; the server cache worker owns that job.
        if (firstLoad || newArrival) {
            loadHighlight(panel, newest, false, newArrival);
            if (!newest.cached) requestHighlightCache(panel, newest);
            return;
        }

        // Keep whatever clip the viewer is currently watching/replaying. If nothing
        // has been rendered yet, restore the newest clip without starting playback.
        if (!panel._pssCurrentHighlightID) {
            loadHighlight(panel, newest, false, false);
        }
    }

    function refreshPanel(panel) {
        if (panel._pssHighlightLoading) return;
        var league = panel.getAttribute('data-league') || '';
        var slot = panel.getAttribute('data-slot') || '1';
        var url = 'plugin.php?plugin=fpp-nfl&page=status.php&nopage=1&highlights=1&league=' +
            encodeURIComponent(league) + '&slot=' + encodeURIComponent(slot) + '&_=' + Date.now();

        panel._pssHighlightLoading = true;
        fetch(url, { cache: 'no-store' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) { applyHighlightResponse(panel, data); })
            .catch(function () { setStatus(panel, 'Waiting for ESPN highlights…'); })
            .then(function () { panel._pssHighlightLoading = false; });
    }

    function refreshAll() {
        for (var i = 0; i < panels.length; i++) refreshPanel(panels[i]);
    }

    refreshAll();
    window.setInterval(refreshAll, 20000);
})();
</script>
