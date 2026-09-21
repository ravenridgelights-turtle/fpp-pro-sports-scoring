<?php
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
?>
<style>
.pss-status-wrap {
    max-width: 1500px;
    margin: 0 auto;
}
.pss-scoreboard {
    border: 1px solid rgba(127, 127, 127, 0.28);
    border-radius: 14px;
    overflow: hidden;
    background: rgba(127, 127, 127, 0.07);
}
.pss-scoreboard + .pss-scoreboard {
    margin-top: 18px;
}
.pss-scoreboard-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid rgba(127, 127, 127, 0.22);
    background: rgba(127, 127, 127, 0.08);
}
.pss-league {
    font-size: 0.82rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
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
    padding: 24px 22px 18px;
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
    width: 78px;
    height: 78px;
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
    font-size: 1.08rem;
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
    font-size: clamp(2.4rem, 5vw, 4.1rem);
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
    margin-top: 12px;
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
    margin-top: 8px;
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
    padding: 12px 16px;
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
@media (max-width: 720px) {
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
</style>

<div class="container-fluid pss-status-wrap">
    <h2>Pro Sports Scoring Status</h2>

    <?php if (pss_statusValue('ENABLED', 'OFF') !== 'ON'): ?>
        <div class="alert alert-warning">The plugin is currently disabled.</div>
    <?php endif; ?>

    <?php
    $rendered = 0;
    foreach ($leagues as $league):
        $teamID = pss_statusValue($league . 'TeamID');
        if ($teamID === '') continue;
        $rendered++;

        $label = ($league === 'ncaa') ? 'NCAA Football' : strtoupper($league);
        $state = pss_statusValue($league . 'GameStatus');
        $stateClass = ($state === 'in') ? 'pss-state-in' : (($state === 'pre') ? 'pss-state-pre' : (($state === 'post') ? 'pss-state-post' : 'pss-state-wait'));
        $detail = pss_statusValue($league . 'GameDetail');

        $myName = pss_statusValue($league . 'TeamName', 'Selected team');
        $myAbbr = pss_statusValue($league . 'TeamAbbreviation', 'TEAM');
        $myLogo = pss_statusValue($league . 'TeamLogo');
        $myScore = pss_statusValue($league . 'MyScore', '0');

        $oppoName = pss_statusValue($league . 'OppoName', 'Opponent');
        $oppoAbbr = pss_statusValue($league . 'OppoAbbreviation', 'OPP');
        $oppoLogo = pss_statusValue($league . 'OppoLogo');
        $oppoScore = pss_statusValue($league . 'OppoScore', '0');

        $eventID = pss_statusValue($league . 'TeamNextEventID');
    ?>
        <section class="pss-scoreboard" aria-label="<?=htmlspecialchars($label)?> game status">
            <div class="pss-scoreboard-head">
                <span class="pss-league"><?=htmlspecialchars($label)?></span>
                <?php if ($eventID !== ''): ?>
                    <span class="pss-event-id">ESPN event <?=htmlspecialchars($eventID)?></span>
                <?php endif; ?>
            </div>

            <div class="pss-matchup">
                <div class="pss-team">
                    <?=pss_teamLogoMarkup($oppoLogo, $oppoAbbr, $oppoName)?>
                    <div class="pss-team-name"><?=htmlspecialchars($oppoName)?></div>
                    <div class="pss-team-abbr"><?=htmlspecialchars($oppoAbbr)?></div>
                </div>

                <div class="pss-score-center">
                    <div class="pss-score" aria-label="<?=htmlspecialchars($oppoName . ' ' . $oppoScore . ', ' . $myName . ' ' . $myScore)?>">
                        <span><?=htmlspecialchars($oppoScore)?></span>
                        <span class="pss-score-separator">–</span>
                        <span><?=htmlspecialchars($myScore)?></span>
                    </div>
                    <div class="pss-state-pill <?=$stateClass?>"><?=htmlspecialchars(pss_stateLabel($state))?></div>
                    <div class="pss-game-detail"><?=htmlspecialchars($detail !== '' ? $detail : pss_stateLabel($state))?></div>
                </div>

                <div class="pss-team pss-team-selected">
                    <div class="pss-selected-tag">Selected team</div>
                    <?=pss_teamLogoMarkup($myLogo, $myAbbr, $myName)?>
                    <div class="pss-team-name"><?=htmlspecialchars($myName)?></div>
                    <div class="pss-team-abbr"><?=htmlspecialchars($myAbbr)?></div>
                </div>
            </div>

            <div class="pss-meta">
                <div class="pss-meta-item">
                    <span class="pss-meta-label">Start</span>
                    <span class="pss-meta-value"><?=htmlspecialchars(pss_formatStart(pss_statusValue($league . 'Start')))?></span>
                </div>
                <div class="pss-meta-item">
                    <span class="pss-meta-label">Matchup</span>
                    <span class="pss-meta-value"><?=htmlspecialchars($oppoAbbr . ' vs ' . $myAbbr)?></span>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($rendered === 0): ?>
        <div class="alert alert-info">Select a team on the Pro Sports Scoring setup page to display game status here.</div>
    <?php endif; ?>
</div>
