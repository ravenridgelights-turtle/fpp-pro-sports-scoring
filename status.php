<?php
$pssKioskMode = isset($_GET['kiosk']) && (string)$_GET['kiosk'] === '1';
$pssDataMode = isset($_GET['data']) && (string)$_GET['data'] === '1';
if ($pssDataMode) {
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
    );
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
    padding: 14px;
    box-sizing: border-box;
}
.pss-kiosk-page .pss-status-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
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

    window.setInterval(refreshScoreboard, 10000);
})();
</script>
<?php else: ?>
</div>
<?php endif; ?>
