<?php
// Exercise production functions against the changed 10.3 API contracts.
// HTTP responses are fixtures; these checks do not run fppd or play media.
putenv('PSS_TEST_FPP_VERSION=10.3');
require __DIR__ . '/bootstrap.php';
$assertions = 0;
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// 10.3 returns 404 for a missing remote helper, rather than HTTP 200 with
// an empty JSON string. It must still be created before sending the insert.
resetFixture();
pss_setPluginSettings(array('nflCelebrationTarget' => 'remote', 'nflCelebrationRemoteHost' => 'remote.local'));
$testHttp = function ($url, $method) {
    if (strpos($url, '/api/playlist/') !== false && $method === 'GET') {
        return response(array('Status' => 'Error', 'Message' => 'Playlist not found'), 404);
    }
    if (strpos($url, '/api/files/') !== false) {
        return response(array('files' => array(array('name' => 'TD.fseq', 'size' => 4294967296))));
    }
    return null;
};
check(pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'missing remote helper is created on 10.3');
$playlistRequests = array_values(array_filter($requests, function ($r) { return strpos($r['url'], '/api/playlist/') !== false; }));
check(array_column($playlistRequests, 'method') === array('GET', 'POST'), '404 is followed by playlist POST');
check(count(inserted()) === 1, 'remote insert happens after helper sync');
check(inserted()[0]['method'] === 'POST', 'insert uses POST rather than a deprecated GET');

// New strict failures must never acknowledge an automatic score as played.
foreach (array(400, 403, 500) as $status) {
    resetFixture();
    $testHttp = function ($url) use ($status) {
        return strpos($url, '/api/command') !== false ? response('Command refused', $status) : null;
    };
    pss_enqueueCelebration('nfl', 'TouchdownSequence', 'Touchdown', 1, 7);
    pss_drainCelebrations();
    $events = array_values(json_decode(file_get_contents(pss_runtimePath('events')), true));
    check(!$events[0]['done'] && empty($events[0]['doneTargets']), "HTTP $status retains an unacknowledged score");
    $testHttp = null;
    forceRetry();
    pss_drainCelebrations();
    $events = array_values(json_decode(file_get_contents(pss_runtimePath('events')), true));
    check($events[0]['done'], "HTTP $status recovers on a successful retry");
    pss_drainCelebrations();
    check(count(inserted()) === 2, "HTTP $status recovery is not replayed");
}

resetFixture();
pss_setPluginSettings(array('nflCelebrationTarget' => 'remote', 'nflCelebrationRemoteHost' => 'remote.local'));
$testHttp = function ($url, $method) {
    return strpos($url, '/api/playlist/') !== false && $method === 'POST'
        ? response(array('Status' => 'Error', 'Message' => 'Invalid playlist name.'), 400) : null;
};
check(!pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'strict remote playlist refusal propagates');
check(count(inserted()) === 0, 'refused helper is never inserted');

// Video config hardening still accepts our booleans, numeric dimensions and
// supported source types. Switching the preview must preserve other sources.
foreach (array('usb:/dev/video0' => 'v4l2src', 'https://example.test/live.m3u8' => 'urisrc', 'rtsp://example.test/live' => 'rtspsrc') as $source => $type) {
    resetFixture();
    pss_setPluginSettings(array('nflVideoSource' => $type === 'v4l2src' ? $source : 'url',
        'nflVideoStreamUrl' => $type === 'v4l2src' ? '' : $source));
    $other = array('id' => 42, 'name' => 'Existing camera', 'type' => 'v4l2src', 'enabled' => true);
    $old = array('id' => 900001, 'name' => pss_videoPreviewSourceName());
    $testHttp = function ($url, $method, $body) use ($other, $old) {
        if ($url === 'http://127.0.0.1/api/pipewire/video/input-sources' && $method === 'GET') {
            return response(array('videoInputSources' => array($other, $old)));
        }
        if (strpos($url, '/api/pipewire/video/input-sources') !== false && $method === 'POST') return response(array('status' => 'ok'));
        return null;
    };
    $result = null;
    try { pss_startTeamVideoPreview(array('league' => 'nfl')); } catch (PssJsonResult $r) { $result = $r->data; }
    check(is_array($result) && $result['ok'], "$type preview request succeeds");
    $writes = array_values(array_filter($requests, function ($r) { return $r['method'] === 'POST'; }));
    check(array_column($writes, 'url') === array('http://127.0.0.1/api/pipewire/video/input-sources', 'http://127.0.0.1/api/pipewire/video/input-sources/apply'), "$type config and apply both use POST");
    $sources = $writes[0]['body']['videoInputSources'];
    check(count($sources) === 2 && $sources[0] === $other, "$type preserves unrelated camera and replaces its own preview");
    $entry = $sources[1];
    check($entry['type'] === $type && $entry['enabled'] === true && $entry['audioEnabled'] === false, "$type source uses typed flags");
    check(is_int($entry['id']) && is_int($entry['width']) && is_int($entry['height']) && is_int($entry['framerate']), "$type source uses numeric geometry");
    check($entry['id'] === 900001 && $result['previewUrl'] === 'api/pipewire/video/input-sources/900001/preview?width=720', "$type preserves ID and same-origin preview route");
}

resetFixture();
$testHttp = function ($url) { return response(array('Status' => 'Error'), 400); };
check(!pss_saveAndApplyVideoInputSources(array('videoInputSources' => array()))['ok'], 'video save refusal is reported');
check(count($requests) === 1, 'a refused video configuration is not applied');
resetFixture();
$testHttp = function ($url) {
    return substr($url, -6) === '/apply' ? response(array('status' => 'error'), 500) : response(array('status' => 'ok'));
};
check(!pss_saveAndApplyVideoInputSources(array('videoInputSources' => array()))['ok'], 'video application error is reported');
check(count($requests) === 2, 'video apply failure is exercised after a successful save');

echo 'PASS FPP 10.3 strict API/video fixtures: ' . $assertions . " assertions\n";
