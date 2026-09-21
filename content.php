<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';
$pluginName = basename(dirname(__FILE__));
$pluginSettings = pss_loadPluginSettings();

function pss_currentValue($key, $default = '') {
    global $pluginSettings;
    return isset($pluginSettings[$key]) ? urldecode((string)$pluginSettings[$key]) : $default;
}
?>
<div class="container-fluid">
    <h2>Pro Sports Scoring Plugin</h2>
    <p class="text-muted">Choose FPP sequences for NFL, NCAA Football, NHL, or MLB scoring events. The plugin automatically maintains helper playlists so FPP can interrupt the active show and return to it afterward.</p>

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
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <h4 class="card-title"><?=htmlspecialchars($label)?></h4>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Team</strong><div class="text-muted small">Selecting a team refreshes its current or next game.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'TeamID', $league . 'TeamID', 0, 0, '', pss_getTeams($meta['sport'], $league), $pluginName, 'update' . strtoupper($league) . 'Team', ''); ?></div>
            </div>

            <?php if ($meta['sport'] === 'football'): ?>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Touchdown sequence</strong><div class="text-muted small">The plugin creates a PSS_NFL/NCAA helper playlist automatically and inserts it when your team scores a touchdown.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'TouchdownSequence', $league . 'TouchdownSequence', 0, 0, '', pss_getSequences(), $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Field goal sequence</strong><div class="text-muted small">The selected sequence is wrapped in an automatically managed PSS helper playlist.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'FieldgoalSequence', $league . 'FieldgoalSequence', 0, 0, '', pss_getSequences(), $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <?php else: ?>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Score sequence</strong><div class="text-muted small">The selected sequence is wrapped in an automatically managed PSS helper playlist.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'ScoreSequence', $league . 'ScoreSequence', 0, 0, '', pss_getSequences(), $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <?php endif; ?>

            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Win sequence</strong><div class="text-muted small">The selected sequence is wrapped in an automatically managed PSS helper playlist and inserted after a win.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'WinSequence', $league . 'WinSequence', 0, 0, '', pss_getSequences(), $pluginName, 'pssSequenceChanged', ''); ?></div>
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
<?php endforeach; ?>
</script>
