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
    <p class="text-muted">Insert FPP celebration playlists when your NFL, NCAA Football, NHL, or MLB team scores or wins. FPP returns to the active show after the inserted playlist finishes.</p>

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
                <div class="col-md-5"><strong>Touchdown playlist</strong><div class="text-muted small">Saved FPP playlist inserted immediately when your team scores a touchdown.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'TouchdownPlaylist', $league . 'TouchdownPlaylist', 0, 0, '', pss_getPlaylists(), $pluginName, '', ''); ?></div>
            </div>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Field goal playlist</strong><div class="text-muted small">Saved FPP playlist inserted immediately for a made field goal.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'FieldgoalPlaylist', $league . 'FieldgoalPlaylist', 0, 0, '', pss_getPlaylists(), $pluginName, '', ''); ?></div>
            </div>
            <?php else: ?>
            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Score playlist</strong><div class="text-muted small">Saved FPP playlist inserted immediately when your team scores.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'ScorePlaylist', $league . 'ScorePlaylist', 0, 0, '', pss_getPlaylists(), $pluginName, '', ''); ?></div>
            </div>
            <?php endif; ?>

            <div class="row mb-3 align-items-center">
                <div class="col-md-5"><strong>Win playlist</strong><div class="text-muted small">Saved FPP playlist inserted when a completed game is detected as a win.</div></div>
                <div class="col-md-7"><?php PrintSettingSelect($league . 'WinPlaylist', $league . 'WinPlaylist', 0, 0, '', pss_getPlaylists(), $pluginName, '', ''); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
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
