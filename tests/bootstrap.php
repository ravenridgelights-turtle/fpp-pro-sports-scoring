<?php
// Load actual named production functions without booting an FPP installation.
// Only the HTTP boundary and JSON response/exit are replaced in fixture tests.
$root = dirname(__DIR__);
require_once $root . '/compatibility.inc.php';
require_once $root . '/reliability.inc.php';
function loadPssFunctions($path, $exclude = array()) {
    $tokens = token_get_all(file_get_contents($path));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $start = $i;
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
        $name = $tokens[$j][1];
        $body = ''; $depth = 0; $opened = false;
        for (; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $depth++; $opened = true; }
            if ($token === '}') $depth--;
            $body .= $text;
            if ($opened && $depth === 0) break;
        }
        if (!in_array($name, $exclude, true)) eval(str_replace('__DIR__', var_export(dirname($path), true), $body));
    }
}
loadPssFunctions($root . '/functions.inc.php', array('pss_httpRequest', 'pss_jsonResponse'));
class PssJsonResult extends Exception {
    public $data;
    public function __construct($data) { $this->data = $data; parent::__construct($data['message']); }
}
function pss_jsonResponse($ok, $message, $extra = array()) { throw new PssJsonResult(array_merge(array('ok' => $ok, 'message' => $message), $extra)); }
function getFPPVersion() { return getenv('PSS_TEST_FPP_VERSION') ?: '9.4'; }
$requests = array();
$testHttp = null;
function pss_httpRequest($url, $method = 'GET', $body = null, $accept = 'application/json') {
    global $requests, $testHttp;
    $requests[] = array('url' => $url, 'method' => $method, 'body' => $body);
    $result = $testHttp ? $testHttp($url, $method, $body) : null;
    if ($result !== null) return $result;
    if (strpos($url, '/api/fppd/status') !== false) return response(array('status' => 0, 'current_playlist' => array('playlist' => '')));
    if (strpos($url, '/api/files/') !== false) return response(array('status' => 'ok', 'files' => array(array('name' => 'TD.fseq'), array('name' => 'cheer.mp3'))));
    if (strpos($url, '/api/playlist/') !== false) return response($method === 'POST' ? $body : array());
    if (strpos($url, '/api/schedule/reload') !== false) return response(array('Status' => 'OK'));
    if (strpos($url, '/api/command') !== false) return response('Playlist Inserted');
    if (strpos($url, '/api/models') !== false) return response(array('Patio', 'Roof'));
    if (strpos($url, '/api/overlays/effects') !== false) return response(array('WLED - Android', 'WLED - Colortwinkles'));
    if (strpos($url, '/api/overlays/fonts') !== false) return response(array('DejaVu-Sans'));
    if (strpos($url, '/v4l2-devices') !== false) return response(array('devices' => array()));
    if (strpos($url, '/api/media/') !== false) return response(array('cheer.mp3' => array('duration' => 7)));
    return response(array(), 404);
}
function response($body, $status = 200) { return array('ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => is_string($body) ? $body : json_encode($body), 'contentType' => 'application/json'); }
function check($ok, $label) {
    global $assertions;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $assertions++;
}
function inserted($host = null) {
    global $requests;
    return array_values(array_filter($requests, function ($r) use ($host) {
        return strpos($r['url'], '/api/command') !== false && isset($r['body']['command']) && $r['body']['command'] === 'Insert Playlist Immediate' && ($host === null || parse_url($r['url'], PHP_URL_HOST) === $host);
    }));
}
function resetFixture() {
    global $settings, $pluginName, $pluginSettings, $pluginConfigFile, $logFile, $leagues, $requests, $testHttp;
    $dir = sys_get_temp_dir() . '/pss-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $settings = array('configDirectory' => $dir, 'playlistDirectory' => $dir,
        'sequenceDirectory' => $dir, 'musicDirectory' => $dir, 'videoDirectory' => $dir, 'logDirectory' => $dir);
    $pluginName = 'fpp-nfl'; $pluginConfigFile = $dir . '/plugin.fpp-nfl'; $logFile = $dir . '/log';
    $leagues = array('nfl', 'ncaa', 'nhl', 'mlb');
    $header = 'PSEQ' . str_repeat("\0", 28);
    $header = substr_replace($header, pack('V', 500), 14, 4);
    $header[18] = chr(20);
    file_put_contents($dir . '/TD.fseq', $header);
    file_put_contents($dir . '/cheer.mp3', 'fixture');
    file_put_contents($dir . '/schedule.json', '[]');
    $pluginSettings = array(); $requests = array(); $testHttp = null;
    pss_setPluginSettings(array('ENABLED' => 'ON', 'nflTeamID' => '23', 'nflTeamAbbreviation' => 'PIT',
        'nflTeamName' => 'Pittsburgh Steelers', 'nflTeamNextEventID' => 'event-1',
        'nflTouchdownSequence' => 'TD.fseq', 'nflFieldgoalSequence' => 'TD.fseq', 'nflWinSequence' => 'TD.fseq',
        'nflGameSnapshotEventID' => 'event-1', 'nflOppoID' => '1', 'nflOppoLogo' => 'cached', 'nflGameStatus' => 'in',
        'nflMyScore' => '0', 'nflLastCelebratedScore' => '0', 'nflLastScoringPlayID' => 'old',
        'nflTeamColor1' => '#FFB612', 'nflTeamColor2' => '#101820', 'nflTeamColor3' => '#FFFFFF'));
    $healthPath = pss_runtimePath('worker');
    register_shutdown_function(function () use ($dir, $healthPath) { @unlink($healthPath); foreach (glob($dir . '/*') as $file) @unlink($file); @rmdir($dir); });
}
function forceRetry() { pss_withCelebrationQueue(function (&$events) { foreach ($events as &$event) $event['nextAttempt'] = 0; return true; }); }
