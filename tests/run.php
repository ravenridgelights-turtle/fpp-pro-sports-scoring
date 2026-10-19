<?php
require __DIR__ . '/bootstrap.php';
$assertions = 0;
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
resetFixture();
// An older plugin wrote raw JSON inside quoted INI values. Preserve it, then
// serialize it so FPP 8's own parse_ini_file() cannot strip the model quotes.
file_put_contents($pluginConfigFile, "ENABLED = \"ON\"\nnflWledModel = \"[\"Patio\",\"Roof\"]\"\nnflTeamID = \"23\"\n");
$pluginSettings = pss_loadPluginSettings();
check(pss_pluginOverlayModels('nflWledModel') === array('Patio', 'Roof'), 'read legacy raw JSON model list');
check(pss_normalizeOverlayModelSelection('[Patio,Roof]') === array('Patio', 'Roof'), 'recover simple model list already damaged by old INI writer');
pss_setPluginSetting('nflMyScore', '7');
$coreSettings = parse_ini_file($pluginConfigFile);
check(json_decode(urldecode($coreSettings['nflWledModel']), true) === array('Patio', 'Roof'), 'FPP 8 parser preserves encoded model list');
check(pss_pluginSetting('ENABLED') === 'ON', 'settings retain ON as string');
$before = file_get_contents($pluginConfigFile);
pss_setPluginSetting('nflMyScore', '7');
check(file_get_contents($pluginConfigFile) === $before, 'unchanged score does not rewrite settings');
pss_setPluginSetting('nflTeamName', "A \"quoted\" team\nname");
$pluginSettings = pss_loadPluginSettings();
check(pss_pluginSetting('nflTeamName') === "A \"quoted\" team\nname", 'quoted/newline settings round trip');

resetFixture();
check(pss_processFootballScoreIncreaseFallback('nfl', 0, 6), 'first touchdown accepted');
check(count(inserted()) === 0, 'score is persisted before dispatch');
pss_drainCelebrations();
check(count(inserted()) === 1, 'automatic touchdown dispatched');
$r = inserted()[0];
check($r['method'] === 'POST' && $r['body']['args'] === array('PSS_NFL_PIT_Touchdown', '0', '0', 'true'), 'verified FPP insert contract');
pss_drainCelebrations();
check(count(inserted()) === 1, 'completed celebration not replayed');
$pluginSettings = pss_loadPluginSettings();
pss_setPluginSetting('nflLastCelebratedScore', '6');
$plays = array(array('id' => 'old', 'team' => array('id' => '1')), array('id' => 'new', 'team' => array('id' => '23'), 'type' => array('text' => 'Touchdown')));
check(!pss_processFootballScoring('nfl', '23', $plays, 1, 6), 'late scoring play does not duplicate score fallback');
pss_drainCelebrations();
check(count(inserted()) === 1, 'restart/late ESPN record stays deduplicated');
pss_setPluginSetting('nflLastScoringPlayID', 'old');
check(!pss_processFootballScoring('nfl', '23', $plays, 1, 7), 'late TD record arriving with PAT does not duplicate touchdown');
check(!pss_processFootballScoreIncreaseFallback('nfl', 6, 7), 'PAT does not trigger touchdown');
check(!pss_processFootballScoreIncreaseFallback('nfl', 7, 9), 'two point conversion not field goal');
check(pss_processFootballScoreIncreaseFallback('nfl', 9, 12), 'field goal accepted');
pss_drainCelebrations();
check(count(inserted()) === 2 && inserted()[1]['body']['args'][0] === 'PSS_NFL_PIT_FieldGoal', 'field goal dispatch');

resetFixture();
$testHttp = function ($url) { return strpos($url, '/api/command') !== false ? response('FPPD unavailable', 503) : null; };
pss_processFootballScoreIncreaseFallback('nfl', 0, 7); pss_drainCelebrations();
check(count(inserted()) === 1, 'failed command attempted once');
$events = json_decode(file_get_contents(pss_runtimePath('events')), true);
check(!array_values($events)[0]['done'], 'failure retained for retry');
$testHttp = null; forceRetry(); pss_drainCelebrations();
check(count(inserted()) === 2, 'failed celebration retried after recovery');
pss_drainCelebrations(); check(count(inserted()) === 2, 'successful retry is final');

resetFixture();
pss_setPluginSettings(array('nflCelebrationTarget' => 'both', 'nflCelebrationRemoteHost' => 'remote.local'));
$testHttp = function ($url) { return strpos($url, 'remote.local/api/command') !== false ? response('offline', 503) : null; };
pss_processFootballScoreIncreaseFallback('nfl', 0, 7); pss_drainCelebrations();
check(count(inserted('127.0.0.1')) === 1 && count(inserted('remote.local')) === 1, 'both targets attempted');
$testHttp = null; forceRetry(); pss_drainCelebrations();
check(count(inserted('127.0.0.1')) === 1 && count(inserted('remote.local')) === 2, 'remote retry does not replay successful local destination');

resetFixture();
pss_setPluginSettings(array('nflCelebrationTarget' => 'remote', 'nflCelebrationRemoteHost' => 'remote.local'));
$testHttp = function ($url) { return strpos($url, '/api/files/sequences') !== false ? response(array('status' => 'ok', 'files' => array())) : null; };
check(!pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'missing remote sequence rejected before command');
check(count(inserted()) === 0, 'no false-success insert for missing remote file');
$testHttp = function ($url, $method) { return $method === 'POST' && strpos($url, '/api/playlist/') !== false ? response(array('Status' => 'Error', 'Message' => 'not writable')) : null; };
check(!pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'HTTP 200 playlist-write error rejected');
check(count(inserted()) === 0, 'failed remote helper sync never plays');

resetFixture();
pss_enqueueCelebration('nfl', 'TouchdownSequence', 'Touchdown', 1, 7);
pss_enqueueCelebration('nfl', 'FieldgoalSequence', 'Field goal', 1, 10);
pss_drainCelebrations(); check(count(inserted()) === 1, 'one insert per drain prevents overwriting FPP pending slot');
$testHttp = function ($url) { return strpos($url, '/api/fppd/status') !== false ? response(array('status' => 1, 'current_playlist' => array('playlist' => 'PSS_NFL_PIT_Touchdown'))) : null; };
pss_drainCelebrations(); check(count(inserted()) === 1, 'next celebration waits for previous playback');
$testHttp = null; forceRetry(); pss_drainCelebrations(); check(count(inserted()) === 2, 'waiting celebration dispatches when player ready');
pss_enqueueCelebration('nfl', 'TouchdownSequence', 'Touchdown', 1, 17);
pss_setPluginSetting('ENABLED', 'OFF'); pss_drainCelebrations();
pss_setPluginSetting('ENABLED', 'ON'); pss_drainCelebrations();
check(count(inserted()) === 2, 'disabling cancels outstanding celebrations');

resetFixture();
try { pss_manualTrigger(array('league' => 'nfl', 'slot' => '1', 'trigger' => 'touchdown')); } catch (PssJsonResult $r) { check($r->data['ok'], 'manual touchdown JSON success'); }
check(count(inserted()) === 1, 'status button reaches actual playback path');
$testHttp = function ($url) { return strpos($url, '/api/fppd/status') !== false ? response(array(), 503) : null; };
try { pss_manualTrigger(array('league' => 'nfl', 'trigger' => 'win')); } catch (PssJsonResult $r) { check(!$r->data['ok'] && strpos($r->data['message'], 'unavailable') !== false, 'manual failure returns actionable error'); }
try { pss_manualTrigger(array('league' => 'nfl', 'trigger' => 'invalid')); } catch (PssJsonResult $r) { check(!$r->data['ok'], 'invalid manual action rejected'); }

resetFixture();
pss_setPluginSettings(array('nflCelebrationDelay' => '4', 'nflTouchdownSequencePlaybackMode' => 'both', 'nflTouchdownSequenceMedia' => 'cheer.mp3'));
check(pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'sequence plus audio preserved');
$pl = pss_readGeneratedPlaylistData('PSS_NFL_PIT_Touchdown');
check($pl['mainPlaylist'][0]['type'] === 'pause' && $pl['mainPlaylist'][0]['duration'] === 4, 'celebration delay');
check($pl['mainPlaylist'][1]['type'] === 'both' && $pl['mainPlaylist'][1]['mediaName'] === 'cheer.mp3', 'audio/media helper schema');
check($pl['mainPlaylist'][1]['duration'] === 10, 'FSEQ duration header');

resetFixture();
pss_setPluginSettings(array('nflWledModel' => '["Patio","Roof"]', 'nflTouchdownSequence' => pss_wledSelectionToken('WLED - Android')));
pss_syncTeamPalettes(false);
check(pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'multi-model WLED helper playback');
$pl = pss_readGeneratedPlaylistData('PSS_NFL_PIT_Touchdown');
$starts = array_values(array_filter($pl['mainPlaylist'], function ($e) { return isset($e['command'], $e['args'][2]) && $e['command'] === 'Overlay Model Effect' && $e['args'][2] === 'WLED - Android'; }));
check(count($starts) === 2, 'one start command for each WLED model');
check($starts[0]['args'][count($starts[0]['args']) - 2] === '#FFB612', 'team color used in WLED args');
check(pss_runExactOverlayTextCommand('Patio', 'Enabled', '#FFFFFF', 'DejaVu-Sans', 16, false, 'Right to Left', 10, 0, 'PIT / TD #1'), 'text command accepted');
check(end($requests)['body']['args'][10] === 'PIT / TD #1', 'ticker text is preserved in JSON, not a GET path');
check(!pss_fppResponseOk(response(array('Status' => 'Error'))), 'HTTP 200 application error rejected');
check(pss_fppResponseOk(response('Playlist Inserted')), 'text/plain command success accepted');
check(!pss_fppResponseOk(response('<html>Login</html>')), 'login page not accepted as success');
check(pss_coreVideoPreviewAvailable() === version_compare(getFPPVersion(), '10.1', '>='), 'version-gated optional live preview');

resetFixture();
pss_setPluginSettings(array('mlbTeamID' => '1', 'mlbTeamAbbreviation' => 'ATL', 'mlbTeamNextEventID' => 'baseball-1', 'mlbScoreSequence' => 'TD.fseq'));
pss_processSimpleScoreIncrease('mlb', 0, 1); pss_drainCelebrations();
check(count(inserted()) === 1, 'MLB score path');
pss_processSimpleScoreIncrease('mlb', 1, 1); pss_drainCelebrations();
check(count(inserted()) === 1, 'MLB repeated poll does not replay');
pss_setPluginSettings(array('nhlTeamID' => '2', 'nhlTeamAbbreviation' => 'BOS', 'nhlTeamNextEventID' => 'hockey-1', 'nhlScoreSequence' => 'TD.fseq'));
pss_processSimpleScoreIncrease('nhl', 0, 1); pss_drainCelebrations();
check(count(inserted()) === 2, 'NHL score path');
pss_writeWorkerHealth(time(), time() + 600);
check(pss_workerHealth()['healthy'], 'worker heartbeat is independent of long ESPN sleep');
resetFixture();
$summary = array('header' => array('competitions' => array(array(
    'date' => date(DATE_ATOM), 'status' => array('type' => array('state' => 'post', 'shortDetail' => 'Final')),
    'competitors' => array(array('team' => array('id' => '23'), 'score' => '7'), array('team' => array('id' => '1', 'displayName' => 'Opponent', 'logo' => 'cached'), 'score' => '0'))
))), 'scoringPlays' => array());
$testHttp = function ($url) use (&$summary) { return strpos($url, '/summary?event=') !== false ? response($summary) : null; };
pss_updateTeamStatus(false);
$events = json_decode(file_get_contents(pss_runtimePath('events')), true);
check(count($events) === 2, 'final score queues score and win celebrations');
pss_drainCelebrations(); pss_drainCelebrations();
check(count(inserted()) === 2, 'game-ending touchdown and win both dispatch');
pss_updateTeamStatus(false); pss_drainCelebrations();
check(count(inserted()) === 2, 'repeated final state does not replay win');
$summary['header']['competitions'][0]['competitors'][0]['score'] = null;
check(!pss_getGameStatus('football', 'nfl', 'event-1', '23')['valid'], 'incomplete ESPN scores are rejected');
check(pss_pluginSetting('nflMyScore') === '7', 'bad ESPN response preserves saved score');

resetFixture();
pss_setPluginSettings(array('ncaa2TeamID' => '61', 'ncaa2TeamAbbreviation' => 'UGA', 'ncaa2TeamNextEventID' => 'ncaa-1', 'ncaa2TouchdownSequence' => 'TD.fseq'));
pss_processFootballScoreIncreaseFallback('ncaa', 0, 7, 2); pss_drainCelebrations();
check(inserted()[0]['body']['args'][0] === 'PSS_NCAA_S2_UGA_Touchdown', 'NCAA second team uses the correct helper');
pss_setPluginSetting('nflLastScoringPlayID', 'old');
$opponentPlays = array(array('id' => 'old'), array('id' => 'opponent', 'team' => array('id' => '1'), 'type' => array('text' => 'Touchdown')));
check(!pss_processFootballScoring('nfl', '23', $opponentPlays, 1, 0), 'opponent touchdown is ignored');
pss_drainCelebrations(); check(count(inserted()) === 1, 'opponent play has no dispatch');
pss_setPluginSetting('nflLastScoringPlayID', 'old');
$ourPlays = array(array('id' => 'old'), array('id' => 'ours', 'team' => array('id' => '23'), 'type' => array('text' => 'Field Goal Good')));
check(pss_processFootballScoring('nfl', '23', $ourPlays, 1, 3), 'fresh scoring play is accepted');
pss_drainCelebrations(); check(count(inserted()) === 2, 'fresh scoring play dispatch');

resetFixture();
pss_enqueueCelebration('nfl', 'TouchdownSequence', 'Touchdown', 1, 7);
pss_withCelebrationQueue(function (&$events) { foreach ($events as &$event) { $event['failedSince'] = time() - 121; } return true; });
pss_drainCelebrations(); check(count(inserted()) === 0, 'expired failure is not celebrated much later');
check(!pss_syncGeneratedPlaylistSetting('bogus', 'TD.fseq'), 'invalid sequence setting is rejected');
check(pss_syncGeneratedPlaylistSetting('nflTouchdownSequence', 'TD.fseq'), 'explicit sequence selection is saved and rebuilt');
check(pss_resolveOverlayFont('Helvetica') === 'DejaVu-Sans', 'old ticker font maps to a font actually advertised by FPP');
$requests = array();
check(pss_clearOverlayModel('Patio'), 'clear ticker succeeds');
check(array_column(array_column($requests, 'body'), 'command') === array('Overlay Model Effect', 'Overlay Model Clear', 'Overlay Model State'), 'clear stops drawing and disables overlay');
check(end($requests)['body']['args'] === array('Patio', 'Disabled'), 'clear restores sequence output');
check(pss_reloadFppSchedule(), 'schedule reload uses the shared public API');
resetFixture();
pss_enqueueCelebration('nfl', 'TouchdownSequence', 'Touchdown', 1, 7);
pss_enqueueCelebration('nfl', 'WinSequence', 'Win', 1, 'win');
$testHttp = function ($url) { return strpos($url, '/api/command') !== false ? response('unavailable', 503) : null; };
pss_drainCelebrations();
$testHttp = null;
pss_drainCelebrations();
check(count(inserted()) === 1, 'win cannot overtake touchdown waiting for retry on the same player');
forceRetry(); pss_drainCelebrations();
check(count(inserted()) === 2 && inserted()[1]['body']['args'][0] === 'PSS_NFL_PIT_Touchdown', 'touchdown retry precedes win');
pss_setPluginSetting('nflScoringEpoch', '1');
pss_enqueueCelebration('nfl', 'TouchdownSequence', 'Touchdown', 1, 7);
pss_drainCelebrations();
check(count(inserted()) === 3, 'score following an overturned score has a new deduplication epoch');
resetFixture();
check(pss_playConfiguredSequence('nfl', 'TouchdownSequence', 'test'), 'prepare a known helper');
pss_setPluginSettings(array('nflWledModel' => '["Patio"]', 'nflTouchdownSequence' => pss_wledSelectionToken('WLED - Unknown Effect')));
check(!pss_syncGeneratedPlaylistsForLeague('nfl'), 'unavailable effect metadata reports helper repair failure');
check(is_array(pss_readGeneratedPlaylistData('PSS_NFL_PIT_Touchdown')), 'metadata failure preserves selected helper');
echo 'PASS FPP ' . getFPPVersion() . ': ' . $assertions . " assertions\n";
