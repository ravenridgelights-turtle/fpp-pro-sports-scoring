<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';
$pluginName = basename(dirname(__FILE__));
$pluginSettings = pss_loadPluginSettings();
$pssSequenceOptions = pss_getSequences();

function pss_currentValue($key, $default = '') {
    global $pluginSettings;
    return isset($pluginSettings[$key]) ? urldecode((string)$pluginSettings[$key]) : $default;
}
?>
<style>
.pss-config-grid-head {
    margin-bottom: 8px;
}
.pss-config-team-title {
    font-weight: 700;
    text-align: left;
}
.pss-config-label {
    padding-top: 7px;
}
.pss-config-note {
    margin-top: 3px;
}
@media (max-width: 767px) {
    .pss-config-grid-head {
        display: none;
    }
    .pss-config-select {
        margin-bottom: 10px;
    }
    .pss-config-select::before {
        display: block;
        margin-bottom: 4px;
        font-weight: 700;
    }
    .pss-config-select-team1::before { content: "Team 1"; }
    .pss-config-select-team2::before { content: "Team 2"; }
}
</style>

<div class="container-fluid">
    <h2>Pro Sports Scoring Plugin</h2>
    <p class="text-muted">Choose up to two teams in each sport. Each team can use its own scoring and win sequences.</p>

    <div class="card mb-3">
        <div class="card-body">
            <h4 class="card-title">General</h4>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Enable plugin</strong><div class="text-muted small">The background worker keeps running, so this switch takes effect without restarting FPP.</div></div>
                <div class="col-md-7"><?php PrintSettingCheckbox('NFLPlugin', 'ENABLED', 0, 0, 'ON', 'OFF', $pluginName, '', ''); ?></div>
            </div>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Log level</strong><div class="text-muted small">Info logs scoring actions. Debug also logs ESPN polling.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect('logLevel', 'logLevel', 0, 0, '4', array('Info' => '4', 'Debug' => '5'), $pluginName, '', ''); ?></div>
            </div>
        </div>
    </div>

    <?php foreach ($leagues as $league):
        $meta = pss_leagueInfo($league);
        $label = ($league === 'ncaa') ? 'NCAA Football' : strtoupper($league);
        $teamOptions = pss_getTeams($meta['sport'], $league);
        $prefix1 = pss_teamPrefix($league, 1);
        $prefix2 = pss_teamPrefix($league, 2);
        $callback1 = 'update' . strtoupper($league) . 'Team';
        $callback2 = 'update' . strtoupper($league) . 'Team2';
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <h4 class="card-title"><?=htmlspecialchars($label)?></h4>

            <div class="row pss-config-grid-head">
                <div class="col-md-4"></div>
                <div class="col-md-4 pss-config-team-title">Team 1</div>
                <div class="col-md-4 pss-config-team-title">Team 2</div>
            </div>

            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Team</strong>
                    <div class="text-muted small pss-config-note">Selecting a team refreshes its current or next game.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'TeamID', $prefix1 . 'TeamID', 0, 0, '', $teamOptions, $pluginName, $callback1, ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'TeamID', $prefix2 . 'TeamID', 0, 0, '', $teamOptions, $pluginName, $callback2, ''); ?></div>
            </div>

            <?php if ($meta['sport'] === 'football'): ?>
            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Touchdown sequence</strong>
                    <div class="text-muted small pss-config-note">Runs only when that selected team scores a touchdown.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'TouchdownSequence', $prefix1 . 'TouchdownSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'TouchdownSequence', $prefix2 . 'TouchdownSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Field goal sequence</strong>
                    <div class="text-muted small pss-config-note">Each team gets its own automatically managed helper playlist.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'FieldgoalSequence', $prefix1 . 'FieldgoalSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'FieldgoalSequence', $prefix2 . 'FieldgoalSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <?php else: ?>
            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Score sequence</strong>
                    <div class="text-muted small pss-config-note">Runs only when that selected NHL or MLB team scores.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'ScoreSequence', $prefix1 . 'ScoreSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'ScoreSequence', $prefix2 . 'ScoreSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <?php endif; ?>

            <div class="row mb-1 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Win sequence</strong>
                    <div class="text-muted small pss-config-note">Runs once when that selected team finishes a game with a win.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'WinSequence', $prefix1 . 'WinSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'WinSequence', $prefix2 . 'WinSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
function pssSequenceChanged(setting) {
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'syncSequencePlaylist', setting: setting },
        type: 'post'
    });
}

<?php foreach ($leagues as $league): ?>
function update<?=strtoupper($league)?>Team() {
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'update<?=strtoupper($league)?>Team' },
        type: 'post'
    });
}
function update<?=strtoupper($league)?>Team2() {
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'update<?=strtoupper($league)?>Team2' },
        type: 'post'
    });
}
<?php endforeach; ?>
</script>
