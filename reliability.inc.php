<?php
// Durable outbox: recording a score and successfully dispatching its celebration
// are separate operations. A short fppd outage must not lose a scoring event.
function pss_withCelebrationQueue($callback) {
    $path = pss_runtimePath('events');
    $fd = @fopen($path, 'c+');
    if (!$fd || !flock($fd, LOCK_EX)) {
        if ($fd) fclose($fd);
        return false;
    }
    $raw = stream_get_contents($fd);
    $events = $raw === '' ? array() : json_decode($raw, true);
    if (!is_array($events)) {
        flock($fd, LOCK_UN);
        fclose($fd);
        pss_logEntry('Celebration queue is unreadable; preserving it for diagnosis');
        return false;
    }
    // Completed IDs protect retries/restarts. Keep a bounded 24-hour history.
    foreach ($events as $key => $event) {
        if (isset($event['created']) && time() - $event['created'] > 86400) unset($events[$key]);
    }
    $result = $callback($events);
    $json = json_encode($events, JSON_UNESCAPED_SLASHES);
    $ok = $json !== false;
    if ($ok && $json !== $raw) {
        rewind($fd);
        $ok = fwrite($fd, $json) === strlen($json);
        if ($ok) $ok = ftruncate($fd, strlen($json)) && fflush($fd);
    }
    flock($fd, LOCK_UN);
    fclose($fd);
    pss_shareFileWithFpp($path);
    return $ok ? $result : false;
}

function pss_enqueueCelebration($league, $suffix, $label, $slot, $score, $playID = '') {
    $prefix = pss_teamPrefix($league, $slot);
    if (pss_pluginSetting($prefix . $suffix, '') === '') {
        pss_logEntry(pss_teamLogLabel($league, $slot) . " {$label} detected but no sequence/effect is selected");
        return true;
    }
    $eventID = pss_pluginSetting($prefix . 'TeamNextEventID', '');
    $teamID = pss_pluginSetting($prefix . 'TeamID', '');
    $key = hash('sha256', implode('|', array($prefix, $teamID, $eventID, $suffix,
        $playID !== '' ? $playID : (string)$score, pss_pluginSetting($prefix . 'ScoringEpoch', '0'))));
    $ok = pss_withCelebrationQueue(function (&$events) use ($key, $league, $slot, $suffix, $label, $eventID, $teamID, $score, $prefix) {
        if (!isset($events[$key])) {
            $events[$key] = array('league' => $league, 'slot' => $slot, 'suffix' => $suffix,
                'label' => $label, 'eventID' => $eventID, 'teamID' => $teamID,
                'score' => $score, 'epoch' => pss_pluginSetting($prefix . 'ScoringEpoch', '0'), 'created' => time(), 'nextAttempt' => 0, 'attempts' => 0, 'doneTargets' => array(), 'done' => false);
        }
        return true;
    });
    if (!$ok) throw new RuntimeException('Could not save scoring event; game markers were not advanced');
    return true;
}

function pss_drainCelebrations() {
    return pss_withCelebrationQueue(function (&$events) {
        $GLOBALS['pssQueueBlockedTargets'] = array();
        foreach ($events as &$event) {
            if (!empty($event['done'])) continue;
            $prefix = pss_teamPrefix($event['league'], $event['slot']);
            if (time() - $event['created'] > 900
                || (isset($event['failedSince']) && time() - $event['failedSince'] > 120)
                || pss_pluginSetting('ENABLED', 'OFF') !== 'ON'
                || pss_pluginSetting($prefix . 'ScoringEpoch', '0') !== (isset($event['epoch']) ? $event['epoch'] : '0')
                || pss_pluginSetting($prefix . 'TeamID', '') !== $event['teamID']
                || pss_pluginSetting($prefix . 'TeamNextEventID', '') !== $event['eventID']) {
                $event['done'] = true;
                pss_logEntry('Cancelled stale/disabled celebration: ' . $prefix . ' ' . $event['label']);
                continue;
            }
            if ($event['nextAttempt'] > time()) {
                // Maintain order independently for each destination. A failed
                // remote must not block later celebrations on a healthy local
                // player, or allow a win to overtake its touchdown there.
                $target = pss_teamCelebrationTarget($event['league'], $event['slot']);
                $keys = $target === 'local' ? array('local') : ($target === 'both' ? array('local', pss_teamCelebrationRemoteBaseUrl($event['league'], $event['slot'])) : array(pss_teamCelebrationRemoteBaseUrl($event['league'], $event['slot'])));
                foreach ($keys as $key) {
                    if ($key !== '' && empty($event['doneTargets'][$key])) $GLOBALS['pssQueueBlockedTargets'][$key] = true;
                }
                continue;
            }
            $event['attempts']++;
            // Each successful destination is retained independently. If the
            // remote is offline, the local player is not celebrated again.
            $GLOBALS['pssDispatchDoneTargets'] = $event['doneTargets'];
            $ok = pss_playConfiguredSequence($event['league'], $event['suffix'], $event['label'], $event['slot']);
            $event['doneTargets'] = $GLOBALS['pssDispatchDoneTargets'];
            unset($GLOBALS['pssDispatchDoneTargets']);
            $event['done'] = $ok;
            if (!$ok && (!isset($GLOBALS['pssPlaybackFailureKind']) || $GLOBALS['pssPlaybackFailureKind'] !== 'busy') && !isset($event['failedSince'])) $event['failedSince'] = time();
            $event['nextAttempt'] = time() + 5;
            if (!$ok && $event['attempts'] === 1) pss_logEntry('Celebration retained for retry (up to 120 seconds): ' . $prefix . ' ' . $event['label']);
            // Insert Playlist Immediate has one pending slot, not a queue. Do
            // not overwrite it with another team's score in the same cycle.
            break;
        }
        unset($event, $GLOBALS['pssQueueBlockedTargets']);
        return true;
    });
}

function pss_targetReadyForCelebration($baseUrl = '') {
    $base = $baseUrl === '' ? 'http://127.0.0.1' : $baseUrl;
    $key = $baseUrl === '' ? 'local' : $baseUrl;
    if (!empty($GLOBALS['pssQueueBlockedTargets'][$key])) {
        $GLOBALS['pssPlaybackFailureKind'] = 'busy';
        return false;
    }
    $GLOBALS['pssPlaybackFailureKind'] = 'unavailable';
    $response = pss_httpRequest($base . '/api/fppd/status');
    if (!pss_fppResponseOk($response)) return false;
    $status = json_decode($response['body'], true);
    if (!is_array($status) || !isset($status['status'])) return false;
    $playlist = isset($status['current_playlist']['playlist']) ? (string)$status['current_playlist']['playlist'] : '';
    // Let a previous celebration finish before inserting the next one. Game
    // background playlists intentionally remain interruptible.
    if ((int)$status['status'] !== 0 && strpos($playlist, 'PSS_') === 0 && strpos($playlist, pss_gameSchedulePlaylistPrefix()) !== 0) {
        $GLOBALS['pssPlaybackFailureKind'] = 'busy';
        return false;
    }
    return true;
}

function pss_remotePlaylistAssetsAvailable($playlist, $base) {
    $needed = array('sequences' => array(), 'music' => array(), 'videos' => array());
    foreach (array('leadIn', 'mainPlaylist', 'leadOut') as $section) {
        foreach (isset($playlist[$section]) ? $playlist[$section] : array() as $entry) {
            if (!empty($entry['sequenceName'])) $needed['sequences'][] = $entry['sequenceName'];
            if (!empty($entry['mediaName'])) $needed['music'][] = $entry['mediaName'];
        }
    }
    $available = array();
    foreach (array('sequences', 'music', 'videos') as $dir) {
        if ($dir === 'sequences' && empty($needed[$dir])) continue;
        if ($dir !== 'sequences' && empty($needed['music'])) continue;
        $response = pss_httpRequest($base . '/api/files/' . $dir);
        $data = pss_fppResponseOk($response) ? json_decode($response['body'], true) : null;
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            $GLOBALS['pssLastPlaybackError'] = 'Could not read files on remote FPP ' . $base;
            return false;
        }
        foreach ($data['files'] as $file) {
            $name = is_array($file) && isset($file['name']) ? $file['name'] : (is_string($file) ? $file : '');
            $available[$dir][$name] = true;
        }
    }
    foreach ($needed['sequences'] as $name) {
        if (empty($available['sequences'][$name])) {
            $GLOBALS['pssLastPlaybackError'] = 'Upload sequence ' . $name . ' to remote FPP ' . $base . ' before triggering it.';
            pss_logEntry($GLOBALS['pssLastPlaybackError']);
            return false;
        }
    }
    foreach ($needed['music'] as $name) {
        if (empty($available['music'][$name]) && empty($available['videos'][$name])) {
            $GLOBALS['pssLastPlaybackError'] = 'Upload media ' . $name . ' to remote FPP ' . $base . ' before triggering it.';
            pss_logEntry($GLOBALS['pssLastPlaybackError']);
            return false;
        }
    }
    return true;
}
