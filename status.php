<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';
$pluginSettings = loadPluginSettings();

function s($key, $default = '') {
    global $pluginSettings;
    return isset($pluginSettings[$key]) ? urldecode((string)$pluginSettings[$key]) : $default;
}
function formatStart($value) {
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
function stateLabel($state) {
    if ($state === 'pre') return 'Pregame';
    if ($state === 'in') return 'Playing';
    if ($state === 'post') return 'Postgame';
    return 'Waiting for ESPN';
}
?>
<div class="container-fluid">
    <h2>Pro Sports Scoring Status</h2>
    <?php if (s('ENABLED', 'OFF') !== 'ON'): ?>
        <div class="alert alert-warning">The plugin is currently disabled.</div>
    <?php endif; ?>

    <div class="row">
    <?php foreach ($leagues as $league):
        $teamID = s($league . 'TeamID');
        if ($teamID === '') continue;
        $label = ($league === 'ncaa') ? 'NCAA Football' : strtoupper($league);
    ?>
        <div class="col-12 col-lg-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h4 class="card-title"><?=htmlspecialchars($label)?> — <?=htmlspecialchars(s($league . 'TeamName', s($league . 'TeamAbbreviation', 'Selected team')))?></h4>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Start</dt><dd class="col-sm-8"><?=htmlspecialchars(formatStart(s($league . 'Start')))?></dd>
                        <dt class="col-sm-4">Opponent</dt><dd class="col-sm-8"><?=htmlspecialchars(s($league . 'OppoName', 'Not loaded yet'))?></dd>
                        <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?=htmlspecialchars(stateLabel(s($league . 'GameStatus')))?></dd>
                        <dt class="col-sm-4"><?=htmlspecialchars(s($league . 'TeamAbbreviation', 'Team'))?> score</dt><dd class="col-sm-8"><?=htmlspecialchars(s($league . 'MyScore', '0'))?></dd>
                        <dt class="col-sm-4"><?=htmlspecialchars(s($league . 'OppoAbbreviation', 'Opponent'))?> score</dt><dd class="col-sm-8"><?=htmlspecialchars(s($league . 'OppoScore', '0'))?></dd>
                        <dt class="col-sm-4">ESPN event</dt><dd class="col-sm-8"><code><?=htmlspecialchars(s($league . 'TeamNextEventID', ''))?></code></dd>
                    </dl>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
