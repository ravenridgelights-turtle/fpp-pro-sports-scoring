<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';
$pluginName = basename(dirname(__FILE__));
$pluginSettings = pss_loadPluginSettings();
$pssSequenceOptions = pss_getSequences();
$pssOverlayModels = pss_getOverlayModels();

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
.pss-ticker-card .form-control,
.pss-ticker-card .custom-select,
.pss-ticker-card select,
.pss-ticker-card input[type="text"],
.pss-ticker-card input[type="number"] {
    width: 100%;
    max-width: 100%;
}
.pss-ticker-team-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    gap: 8px 14px;
}
.pss-ticker-team-option {
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
}
.pss-ticker-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.pss-ticker-message {
    min-height: 1.4em;
    margin-top: 8px;
}
.pss-ticker-preview {
    margin-top: 8px;
    padding: 8px 10px;
    border: 1px solid rgba(127,127,127,.25);
    border-radius: 6px;
    overflow-wrap: anywhere;
}
@media (max-width: 900px) {
    .pss-ticker-team-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
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

    <div class="card mb-3 pss-ticker-card">
        <div class="card-body">
            <h4 class="card-title">Score Ticker</h4>
            <p class="text-muted small">Builds one ESPN-style scrolling line from your selected teams. The web ticker can appear in Kiosk Display, and the same scores can be sent directly to an FPP Pixel Overlay Model without requiring MatrixTools.</p>

            <form id="pss-ticker-form" onsubmit="return pssSaveTickerSettings(event);">
                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Enable score ticker</strong><div class="text-muted small">Master switch for both kiosk and Pixel Overlay ticker output.</div></div>
                    <div class="col-md-8"><label><input type="checkbox" name="TickerEnabled" value="ON" <?=pss_currentValue('TickerEnabled', 'OFF') === 'ON' ? 'checked' : ''?>> Enable ticker</label></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Show in Kiosk Display</strong><div class="text-muted small">Keeps a sports ticker pinned along the bottom of kiosk mode.</div></div>
                    <div class="col-md-8"><label><input type="checkbox" name="TickerKioskEnabled" value="ON" <?=pss_currentValue('TickerKioskEnabled', 'ON') === 'ON' ? 'checked' : ''?>> Show web ticker</label></div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4"><strong>Teams included</strong><div class="text-muted small">Only selected team slots that are checked here are added to the ticker.</div></div>
                    <div class="col-md-8">
                        <div class="pss-ticker-team-grid">
                        <?php foreach ($leagues as $tickerLeague): foreach (array(1,2) as $tickerSlot):
                            $tickerPrefix = pss_teamPrefix($tickerLeague, $tickerSlot);
                            $tickerKey = pss_tickerIncludeSetting($tickerLeague, $tickerSlot);
                            $tickerLeagueLabel = ($tickerLeague === 'ncaa') ? 'NCAA' : strtoupper($tickerLeague);
                            $tickerTeamName = pss_currentValue($tickerPrefix . 'TeamName', '');
                            $tickerOptionLabel = $tickerLeagueLabel . ' Team ' . $tickerSlot . ($tickerTeamName !== '' ? ' — ' . $tickerTeamName : '');
                        ?>
                            <label class="pss-ticker-team-option"><input type="checkbox" name="<?=htmlspecialchars($tickerKey)?>" value="ON" <?=pss_currentValue($tickerKey, 'ON') === 'ON' ? 'checked' : ''?>> <span><?=htmlspecialchars($tickerOptionLabel)?></span></label>
                        <?php endforeach; endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4"><strong>Ticker text style</strong></div>
                    <div class="col-md-4">
                        <select class="form-control" name="TickerStyle">
                            <?php $tickerStyle = pss_currentValue('TickerStyle', 'normal'); ?>
                            <option value="compact" <?=$tickerStyle === 'compact' ? 'selected' : ''?>>Compact — PIT 3 - NE 20 FINAL</option>
                            <option value="normal" <?=$tickerStyle === 'normal' ? 'selected' : ''?>>Normal — NFL • PIT 3 - NE 20 • FINAL</option>
                            <option value="detailed" <?=$tickerStyle === 'detailed' ? 'selected' : ''?>>Detailed — NFL • Pittsburgh Steelers 3 - New England Patriots 20 • FINAL</option>
                        </select>
                    </div>
                    <div class="col-md-2"><strong>Web speed</strong><div class="text-muted small">pixels/sec</div></div>
                    <div class="col-md-2"><input class="form-control" type="number" min="20" max="300" name="TickerWebSpeed" value="<?=htmlspecialchars(pss_currentValue('TickerWebSpeed', '90'))?>"></div>
                </div>

                <hr>
                <h5>Pixel Overlay Output</h5>
                <p class="text-muted small">Uses FPP's built-in Overlay Model Text command. The actual output area is the Pixel Overlay Model you select; width and height below are for preview/validation and font-size planning.</p>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Enable Pixel Overlay ticker</strong></div>
                    <div class="col-md-8"><label><input type="checkbox" name="TickerOverlayEnabled" value="ON" <?=pss_currentValue('TickerOverlayEnabled', 'OFF') === 'ON' ? 'checked' : ''?>> Send ticker to a Pixel Overlay Model</label></div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4"><strong>Pixel Overlay Model</strong><div class="text-muted small">Create/upload the model in FPP first. If dimensions are known, selecting the model fills them below.</div></div>
                    <div class="col-md-8">
                        <?php $tickerModel = pss_currentValue('TickerOverlayModel', ''); ?>
                        <select class="form-control" id="pss-ticker-model" name="TickerOverlayModel" onchange="pssTickerModelChanged()">
                            <option value="">-- Select model --</option>
                            <?php if ($tickerModel !== '' && !isset($pssOverlayModels[$tickerModel])): ?>
                            <option value="<?=htmlspecialchars($tickerModel, ENT_QUOTES)?>" selected><?=htmlspecialchars($tickerModel)?> — saved model not currently found</option>
                            <?php endif; ?>
                            <?php foreach ($pssOverlayModels as $overlayModel):
                                $dims = ($overlayModel['width'] > 0 && $overlayModel['height'] > 0) ? ' — ' . $overlayModel['width'] . '×' . $overlayModel['height'] : '';
                            ?>
                            <option value="<?=htmlspecialchars($overlayModel['name'], ENT_QUOTES)?>" data-width="<?=intval($overlayModel['width'])?>" data-height="<?=intval($overlayModel['height'])?>" <?=$tickerModel === $overlayModel['name'] ? 'selected' : ''?>><?=htmlspecialchars($overlayModel['name'] . $dims)?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($pssOverlayModels)): ?><div class="text-warning small mt-1">No Pixel Overlay Models were found in FPP's model-overlays.json.</div><?php endif; ?>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4"><strong>Ticker area</strong><div class="text-muted small">Informational dimensions; the selected FPP model controls the real output geometry.</div></div>
                    <div class="col-md-2"><label>Width (px)<input id="pss-ticker-width" class="form-control" type="number" min="1" max="4096" name="TickerWidth" value="<?=htmlspecialchars(pss_currentValue('TickerWidth', '128'))?>"></label></div>
                    <div class="col-md-2"><label>Height (px)<input id="pss-ticker-height" class="form-control" type="number" min="1" max="4096" name="TickerHeight" value="<?=htmlspecialchars(pss_currentValue('TickerHeight', '32'))?>"></label></div>
                    <div class="col-md-2"><label>Font size<input class="form-control" type="number" min="6" max="128" name="TickerFontSize" value="<?=htmlspecialchars(pss_currentValue('TickerFontSize', '16'))?>"></label></div>
                    <div class="col-md-2"><label>Text color<input class="form-control" type="color" name="TickerTextColor" value="<?=htmlspecialchars(pss_currentValue('TickerTextColor', '#FFFFFF'))?>"></label></div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4"><strong>Scroll settings</strong></div>
                    <div class="col-md-3"><label>Font<input class="form-control" type="text" name="TickerFont" value="<?=htmlspecialchars(pss_currentValue('TickerFont', 'Helvetica'))?>"></label></div>
                    <div class="col-md-3"><label>Direction<select class="form-control" name="TickerDirection"><?php $tickerDirection = pss_currentValue('TickerDirection', 'Right to Left'); ?><option value="Right to Left" <?=$tickerDirection === 'Right to Left' ? 'selected' : ''?>>Right to Left</option><option value="Left to Right" <?=$tickerDirection === 'Left to Right' ? 'selected' : ''?>>Left to Right</option></select></label></div>
                    <div class="col-md-2"><label>Speed<input class="form-control" type="number" min="1" max="100" name="TickerScrollSpeed" value="<?=htmlspecialchars(pss_currentValue('TickerScrollSpeed', '10'))?>"></label></div>
                </div>

                <div class="pss-ticker-actions">
                    <button type="submit" class="btn btn-primary">Save Ticker Settings</button>
                    <button type="button" class="btn btn-secondary" onclick="pssTestTicker()">Test Pixel Ticker</button>
                    <button type="button" class="btn btn-secondary" onclick="pssClearTicker()">Clear Pixel Ticker</button>
                </div>
                <div id="pss-ticker-message" class="pss-ticker-message text-muted" role="status" aria-live="polite"></div>
                <div class="pss-ticker-preview"><strong>Current ticker preview:</strong> <span id="pss-ticker-preview-text"><?=htmlspecialchars(pss_buildTickerText(false))?></span></div>
            </form>
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

function pssTickerFormData(action) {
    var form = document.getElementById('pss-ticker-form');
    var data = $(form).serializeArray();
    data.push({ name: 'action', value: action });
    return $.param(data);
}

function pssTickerMessage(text, isError) {
    var el = document.getElementById('pss-ticker-message');
    if (!el) return;
    el.textContent = text || '';
    el.className = 'pss-ticker-message ' + (isError ? 'text-danger' : 'text-success');
}

function pssSaveTickerSettings(event) {
    if (event) event.preventDefault();
    pssTickerMessage('Saving ticker settings…', false);
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: pssTickerFormData('saveTickerSettings'),
        type: 'post',
        dataType: 'json'
    }).done(function (response) {
        pssTickerMessage(response && response.message ? response.message : 'Ticker settings saved.', !(response && response.ok));
        if (response && response.tickerText) {
            $('#pss-ticker-preview-text').text(response.tickerText);
        }
    }).fail(function () {
        pssTickerMessage('Unable to save ticker settings. Check the plugin log.', true);
    });
    return false;
}

function pssTestTicker() {
    pssTickerMessage('Sending test ticker…', false);
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'testTicker' },
        type: 'post',
        dataType: 'json'
    }).done(function (response) {
        pssTickerMessage(response && response.message ? response.message : 'Test complete.', !(response && response.ok));
    }).fail(function () {
        pssTickerMessage('Unable to send test ticker. Check the plugin log.', true);
    });
}

function pssClearTicker() {
    pssTickerMessage('Clearing Pixel Overlay ticker…', false);
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'clearTicker' },
        type: 'post',
        dataType: 'json'
    }).done(function (response) {
        pssTickerMessage(response && response.message ? response.message : 'Ticker cleared.', !(response && response.ok));
    }).fail(function () {
        pssTickerMessage('Unable to clear ticker. Check the plugin log.', true);
    });
}

function pssTickerModelChanged() {
    var select = document.getElementById('pss-ticker-model');
    if (!select || select.selectedIndex < 0) return;
    var option = select.options[select.selectedIndex];
    var width = parseInt(option.getAttribute('data-width') || '0', 10);
    var height = parseInt(option.getAttribute('data-height') || '0', 10);
    if (width > 0) document.getElementById('pss-ticker-width').value = width;
    if (height > 0) document.getElementById('pss-ticker-height').value = height;
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
