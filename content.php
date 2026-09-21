<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/functions.inc.php';
$pluginName = basename(dirname(__FILE__));
$pluginSettings = pss_loadPluginSettings();
$pssSequenceOptions = pss_getSequences();
$pssOverlayModels = pss_getOverlayCommandModels();
$pssOverlayModelOptions = array('No WLED celebration model' => '');
foreach ($pssOverlayModels as $pssOverlayModelName) {
    $pssOverlayModelOptions[$pssOverlayModelName] = $pssOverlayModelName;
}
$pssOverlayGeometry = pss_getOverlayModels();
$pssOverlayFonts = pss_getOverlayFonts();
$pssTeamPalettes = pss_syncTeamPalettes(true);

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
    display: grid;
    grid-template-columns: minmax(0, 1fr) 42px;
    gap: 8px;
    align-items: center;
    min-width: 0;
}
.pss-ticker-team-check {
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
    margin: 0;
}
.pss-ticker-team-color {
    width: 40px !important;
    min-width: 40px;
    height: 34px;
    padding: 2px;
}
.pss-ticker-preview-items {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
}
.pss-ticker-preview-separator {
    opacity: .65;
}
.pss-overlay-geometry-panel {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    align-items: center;
    padding: 10px 12px;
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 4px;
    background: rgba(255,255,255,.025);
}
.pss-overlay-geometry-item {
    white-space: nowrap;
}
.pss-overlay-geometry-actions {
    margin-left: auto;
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
.pss-team-palette-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 10px;
}
.pss-team-palette-card {
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 6px;
    padding: 10px 12px;
    background: rgba(255,255,255,.025);
}
.pss-team-palette-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
}
.pss-team-palette-name {
    font-weight: 700;
}
.pss-team-palette-league {
    opacity: .65;
    font-size: .82em;
    white-space: nowrap;
}
.pss-team-palette-swatches {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
}
.pss-team-palette-swatch {
    min-height: 34px;
    border-radius: 4px;
    border: 1px solid rgba(255,255,255,.22);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding: 3px;
    font-size: .72em;
    font-family: monospace;
    text-shadow: 0 1px 2px #000, 0 0 2px #000;
    color: #fff;
}
.pss-team-palette-empty {
    color: rgba(255,255,255,.55);
    padding: 8px 0;
}
.pss-team-effect-card .form-control,
.pss-team-effect-card select,
.pss-team-effect-card input[type="number"] {
    width: 100%;
    max-width: 100%;
}
.pss-team-effect-colors {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.pss-team-effect-color {
    min-width: 105px;
    padding: 6px 9px;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 4px;
    font-family: monospace;
    text-align: center;
    color: #fff;
    text-shadow: 0 1px 2px #000, 0 0 2px #000;
}
.pss-team-effect-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.pss-team-effect-message {
    min-height: 1.4em;
    margin-top: 8px;
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

    <div class="card mb-3">
        <div class="card-body">
            <h4 class="card-title">Sports Team Effect Palettes</h4>
            <p class="text-muted small mb-2">Automatically managed from the teams selected below. ESPN supplies the first two team colors; the plugin adds a contrasting third accent so FPP/WLED effects can use up to three colors. If an effect only uses two colors, Color 3 is simply ignored.</p>
            <p class="text-muted small">These are plugin-managed named palettes stored as FPP/WLED-compatible <strong>* Colors Only</strong> + Color 1/2/3 values. Only currently selected teams are kept; unselecting a team removes its palette automatically unless that same team is still selected in another slot. FPP's built-in WLED palette list is left untouched so FPP updates cannot overwrite or break this plugin data.</p>
            <div id="pss-team-palette-grid" class="pss-team-palette-grid"></div>
        </div>
    </div>

    <div class="card mb-3 pss-team-effect-card">
        <div class="card-body">
            <h4 class="card-title">Team Palette Effect Trigger</h4>
            <p class="text-muted small mb-2">This is the first live use of the managed team palettes. Pick a model and one of the currently selected teams, then run an FPP/WLED overlay effect using <strong>* Colors Only</strong>. The plugin supplies that team's colors automatically.</p>
            <p class="text-muted small">The first two presets intentionally mirror FPP's own command fields: <strong>WLED - Colortwinkles</strong> uses Color 1/2/3; <strong>WLED - Android</strong> uses only Color 1/2. The team registry still keeps three colors for every team.</p>

            <?php
                $teamEffectModel = pss_currentValue('TeamEffectModel', pss_currentValue('TickerOverlayModel', ''));
                $teamEffectPaletteID = pss_currentValue('TeamEffectPaletteID', '');
                $teamEffectPreset = pss_currentValue('TeamEffectPreset', 'colortwinkles');
                $teamEffectMapping = pss_currentValue('TeamEffectMapping', 'Horizontal');
                $teamEffectAutoEnable = pss_currentValue('TeamEffectAutoEnable', 'Enabled');
            ?>
            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong>Models</strong><div class="text-muted small">Same Pixel Overlay model list FPP uses for Overlay Model Effect.</div></div>
                <div class="col-md-8"><select class="form-control" id="pss-team-effect-model">
                    <option value="">-- Select model --</option>
                    <?php foreach ($pssOverlayModels as $overlayModel): ?>
                    <option value="<?=htmlspecialchars($overlayModel, ENT_QUOTES)?>" <?=$teamEffectModel === $overlayModel ? 'selected' : ''?>><?=htmlspecialchars($overlayModel)?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong>Team Palette</strong><div class="text-muted small">Only teams currently selected in this plugin appear here.</div></div>
                <div class="col-md-8"><select class="form-control" id="pss-team-effect-palette">
                    <option value="">-- Select team palette --</option>
                    <?php foreach ($pssTeamPalettes as $paletteID => $palette): ?>
                    <option value="<?=htmlspecialchars($paletteID, ENT_QUOTES)?>" <?=$teamEffectPaletteID === $paletteID ? 'selected' : ''?>><?=htmlspecialchars($palette['name'])?> — <?=htmlspecialchars($palette['league'])?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong>Auto Enable/Disable</strong></div>
                <div class="col-md-8"><select class="form-control" id="pss-team-effect-autoenable">
                    <?php foreach (array('False','Enabled','Transparent','Transparent RGB') as $v): ?>
                    <option value="<?=htmlspecialchars($v, ENT_QUOTES)?>" <?=$teamEffectAutoEnable === $v ? 'selected' : ''?>><?=htmlspecialchars($v)?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong>Effect</strong></div>
                <div class="col-md-8"><select class="form-control" id="pss-team-effect-preset" onchange="pssTeamEffectPresetChanged();">
                    <option value="colortwinkles" <?=$teamEffectPreset === 'colortwinkles' ? 'selected' : ''?>>WLED - Colortwinkles — 3 team colors</option>
                    <option value="android" <?=$teamEffectPreset === 'android' ? 'selected' : ''?>>WLED - Android — 2 team colors</option>
                </select></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong>Buffer Mapping</strong></div>
                <div class="col-md-8"><select class="form-control" id="pss-team-effect-mapping">
                    <option value="Horizontal" <?=$teamEffectMapping === 'Horizontal' ? 'selected' : ''?>>Horizontal</option>
                    <option value="Vertical" <?=$teamEffectMapping === 'Vertical' ? 'selected' : ''?>>Vertical</option>
                </select></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong>Brightness</strong></div>
                <div class="col-md-8"><input class="form-control" id="pss-team-effect-brightness" type="number" min="0" max="255" value="<?=htmlspecialchars(pss_currentValue('TeamEffectBrightness', '128'))?>"></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong id="pss-team-effect-control1-label">Fade Speed</strong></div>
                <div class="col-md-8"><input class="form-control" id="pss-team-effect-control1" type="number" min="0" max="255" value="<?=htmlspecialchars(pss_currentValue('TeamEffectControl1', '128'))?>"></div>
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-md-4"><strong id="pss-team-effect-control2-label">Spawn Speed</strong></div>
                <div class="col-md-8"><input class="form-control" id="pss-team-effect-control2" type="number" min="0" max="255" value="<?=htmlspecialchars(pss_currentValue('TeamEffectControl2', '128'))?>"></div>
            </div>

            <div class="row mb-3 align-items-start">
                <div class="col-md-4"><strong>Palette sent to FPP</strong></div>
                <div class="col-md-8">
                    <div><strong>* Colors Only</strong></div>
                    <div id="pss-team-effect-colors" class="pss-team-effect-colors mt-2"></div>
                    <div id="pss-team-effect-color-note" class="text-muted small mt-1"></div>
                </div>
            </div>

            <div class="pss-team-effect-actions">
                <button type="button" class="btn btn-primary" onclick="pssRunTeamEffect();">Run Team Effect</button>
                <button type="button" class="btn btn-secondary" onclick="pssStopTeamEffect();">Stop Effect</button>
            </div>
            <div id="pss-team-effect-message" class="pss-team-effect-message text-muted small"></div>
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
                            <?php $tickerColorKey = pss_tickerColorSetting($tickerLeague, $tickerSlot); ?>
                            <div class="pss-ticker-team-option">
                                <label class="pss-ticker-team-check">
                                    <input type="checkbox" name="<?=htmlspecialchars($tickerKey)?>" value="ON" <?=pss_currentValue($tickerKey, 'ON') === 'ON' ? 'checked' : ''?>>
                                    <span><?=htmlspecialchars($tickerOptionLabel)?></span>
                                </label>
                                <input class="form-control pss-ticker-team-color" type="color" name="<?=htmlspecialchars($tickerColorKey)?>" value="<?=htmlspecialchars(pss_currentValue($tickerColorKey, '#FFFFFF'))?>" title="Ticker color for <?=htmlspecialchars($tickerOptionLabel, ENT_QUOTES)?>">
                            </div>
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

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Kiosk ticker text size</strong><div class="text-muted small">Controls the scrolling ticker text size on the web/kiosk display, including mobile screens.</div></div>
                    <div class="col-md-2"><input class="form-control" type="number" min="12" max="48" name="TickerWebFontSize" value="<?=htmlspecialchars(pss_currentValue('TickerWebFontSize', '18'))?>"></div>
                    <div class="col-md-6 text-muted small">Pixels. 18 = normal, 24–30 works well for phones/tablets. Pixel Overlay font size is configured separately below.</div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Item spacing</strong><div class="text-muted small">Adds more breathing room between each team's ticker item. Also adds spacing to Pixel Overlay text.</div></div>
                    <div class="col-md-2"><input class="form-control" type="number" min="1" max="12" name="TickerSpacing" value="<?=htmlspecialchars(pss_currentValue('TickerSpacing', '4'))?>"></div>
                    <div class="col-md-6 text-muted small">1 = tight, 4 = comfortable, 12 = extra wide. Team colors below apply to the web/kiosk ticker; Pixel Overlay output still uses its single Text color setting.</div>
                </div>

                <hr>
                <h5>Pixel Overlay Output</h5>
                <p class="text-muted small">This section mirrors FPP's <strong>Run FPP Command → Overlay Model Effect → Text</strong> command. Only the fields shown below are sent to FPP. Model width/height are not sent; FPP/xLights owns the selected model's geometry.</p>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Enable Pixel Overlay ticker</strong><div class="text-muted small">Plugin control only; this checkbox is not an Overlay Model Effect argument.</div></div>
                    <div class="col-md-8"><label><input type="checkbox" name="TickerOverlayEnabled" value="ON" <?=pss_currentValue('TickerOverlayEnabled', 'OFF') === 'ON' ? 'checked' : ''?>> Send ticker to a Pixel Overlay Model</label></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Command</strong></div>
                    <div class="col-md-8"><input class="form-control" type="text" value="Overlay Model Effect" readonly></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Models</strong><div class="text-muted small">Loaded from FPP's command model list. No dimensions or other model metadata are sent.</div></div>
                    <div class="col-md-8">
                        <?php $tickerModel = pss_currentValue('TickerOverlayModel', ''); ?>
                        <select class="form-control" id="pss-ticker-model" name="TickerOverlayModel">
                            <option value="">-- Select model --</option>
                            <?php if ($tickerModel !== '' && !in_array($tickerModel, $pssOverlayModels, true)): ?>
                            <option value="<?=htmlspecialchars($tickerModel, ENT_QUOTES)?>" selected><?=htmlspecialchars($tickerModel)?> — saved model not currently returned by FPP</option>
                            <?php endif; ?>
                            <?php foreach ($pssOverlayModels as $overlayModel): ?>
                            <option value="<?=htmlspecialchars($overlayModel, ENT_QUOTES)?>" <?=$tickerModel === $overlayModel ? 'selected' : ''?>><?=htmlspecialchars($overlayModel)?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($pssOverlayModels)): ?><div class="text-warning small mt-1">FPP did not return any Pixel Overlay Models from its command model API.</div><?php endif; ?>
                    </div>
                </div>

                <div class="row mb-3 align-items-start">
                    <div class="col-md-4"><strong>FPP Model Geometry</strong><div class="text-muted small">Read-only. FPP/xLights controls how rendered text maps onto the physical pixels.</div></div>
                    <div class="col-md-8">
                        <div class="pss-overlay-geometry-panel" id="pss-overlay-geometry-panel">
                            <span class="pss-overlay-geometry-item">Orientation: <strong id="pss-overlay-geometry-orientation">—</strong></span>
                            <span class="pss-overlay-geometry-item">Start Corner: <strong id="pss-overlay-geometry-corner">—</strong></span>
                            <span class="pss-overlay-geometry-item">Size: <strong id="pss-overlay-geometry-size">—</strong></span>
                            <span class="pss-overlay-geometry-item">Source: <strong id="pss-overlay-geometry-source">—</strong></span>
                            <span class="pss-overlay-geometry-actions"><a class="btn btn-sm btn-secondary" href="pixeloverlaymodels.php" target="_blank" rel="noopener">Open Pixel Overlay Models</a></span>
                        </div>
                        <div id="pss-overlay-geometry-note" class="text-muted small mt-1">If text appears upside down or mirrored, change the selected model's Orientation/Start Corner in FPP. The sports plugin does not alter these values.</div>
                    </div>
                </div>

                <?php $tickerAutoEnable = pss_currentValue('TickerOverlayAutoEnable', 'Enabled'); ?>
                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Auto Enable/Disable</strong></div>
                    <div class="col-md-8"><select class="form-control" name="TickerOverlayAutoEnable">
                        <?php foreach (array('False','Enabled','Transparent','Transparent RGB') as $v): ?>
                        <option value="<?=htmlspecialchars($v, ENT_QUOTES)?>" <?=$tickerAutoEnable === $v ? 'selected' : ''?>><?=htmlspecialchars($v)?></option>
                        <?php endforeach; ?>
                    </select></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Effect</strong></div>
                    <div class="col-md-8"><input class="form-control" type="text" value="Text" readonly></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Color</strong></div>
                    <div class="col-md-8"><input class="form-control" style="max-width:110px" type="color" name="TickerTextColor" value="<?=htmlspecialchars(pss_currentValue('TickerTextColor', '#FFFFFF'))?>"></div>
                </div>

                <?php $tickerFont = pss_currentValue('TickerFont', 'C059-Bdlta'); ?>
                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Font</strong><div class="text-muted small">Loaded from FPP's overlay font API.</div></div>
                    <div class="col-md-8"><select class="form-control" name="TickerFont">
                        <?php if ($tickerFont !== '' && !in_array($tickerFont, $pssOverlayFonts, true)): ?>
                        <option value="<?=htmlspecialchars($tickerFont, ENT_QUOTES)?>" selected><?=htmlspecialchars($tickerFont)?> — saved font</option>
                        <?php endif; ?>
                        <?php foreach ($pssOverlayFonts as $fontName): ?>
                        <option value="<?=htmlspecialchars($fontName, ENT_QUOTES)?>" <?=$tickerFont === $fontName ? 'selected' : ''?>><?=htmlspecialchars($fontName)?></option>
                        <?php endforeach; ?>
                    </select></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>FontSize</strong></div>
                    <div class="col-md-8"><input class="form-control" type="number" min="4" max="100" name="TickerFontSize" value="<?=htmlspecialchars(pss_currentValue('TickerFontSize', '20'))?>"></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Anti-Aliased</strong></div>
                    <div class="col-md-8"><label><input type="checkbox" name="TickerFontAntiAlias" value="ON" <?=pss_currentValue('TickerFontAntiAlias', 'OFF') === 'ON' ? 'checked' : ''?>> Enable anti-aliasing</label></div>
                </div>

                <?php $tickerDirection = pss_currentValue('TickerDirection', 'Right to Left'); ?>
                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Position</strong></div>
                    <div class="col-md-8"><select class="form-control" name="TickerDirection">
                        <?php foreach (array('Center','Right to Left','Left to Right','Bottom to Top','Top to Bottom') as $v): ?>
                        <option value="<?=htmlspecialchars($v, ENT_QUOTES)?>" <?=$tickerDirection === $v ? 'selected' : ''?>><?=htmlspecialchars($v)?></option>
                        <?php endforeach; ?>
                    </select></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Scroll Speed</strong></div>
                    <div class="col-md-8"><input class="form-control" type="number" min="0" max="200" name="TickerScrollSpeed" value="<?=htmlspecialchars(pss_currentValue('TickerScrollSpeed', '10'))?>"></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Duration</strong></div>
                    <div class="col-md-8"><input class="form-control" type="number" min="-1" max="2000" name="TickerDuration" value="<?=htmlspecialchars(pss_currentValue('TickerDuration', '0'))?>"></div>
                </div>

                <div class="row mb-3 align-items-center">
                    <div class="col-md-4"><strong>Text</strong><div class="text-muted small">Automatically generated from the current sports ticker preview. This exact string is sent as FPP's Text argument.</div></div>
                    <div class="col-md-8"><input id="pss-overlay-command-text" class="form-control" type="text" name="TickerCommandText" value="<?=htmlspecialchars(pss_buildTickerText(true), ENT_QUOTES)?>" readonly></div>
                </div>

                <div class="pss-ticker-actions">
                    <button type="submit" class="btn btn-primary">Save Ticker Settings</button>
                    <button type="button" class="btn btn-secondary" onclick="pssTestTicker()">Test Pixel Ticker</button>
                    <button type="button" class="btn btn-secondary" onclick="pssClearTicker()">Clear Pixel Ticker</button>
                </div>
                <div id="pss-ticker-message" class="pss-ticker-message text-muted" role="status" aria-live="polite"></div>
                <?php
                    $pssPreviewItems = pss_buildTickerItems(false);
                    $pssPreviewSpacing = pss_tickerSpacing();
                ?>
                <div class="pss-ticker-preview">
                    <strong>Current ticker preview:</strong>
                    <span id="pss-ticker-preview-text" class="pss-ticker-preview-items" data-spacing="<?=intval($pssPreviewSpacing)?>" style="font-size:<?=intval(max(12, min(48, (int)pss_currentValue('TickerWebFontSize', '18'))))?>px;">
                    <?php if (empty($pssPreviewItems)): ?>
                        <span>PRO SPORTS SCORING • NO SELECTED TEAMS</span>
                    <?php else: foreach ($pssPreviewItems as $pssPreviewIndex => $pssPreviewItem): ?>
                        <?php if ($pssPreviewIndex > 0): ?><span class="pss-ticker-preview-separator" style="margin:0 <?=htmlspecialchars(number_format($pssPreviewSpacing * 0.35, 2, '.', ''))?>em;">•</span><?php endif; ?>
                        <span style="color:<?=htmlspecialchars($pssPreviewItem['color'], ENT_QUOTES)?>"><?=htmlspecialchars($pssPreviewItem['text'])?></span>
                    <?php endforeach; endif; ?>
                    </span>
                </div>
            </form>
        </div>
    </div>

    <?php foreach ($leagues as $league):
        $meta = pss_leagueInfo($league);
        $label = ($league === 'ncaa') ? 'NCAA Football' : strtoupper($league);
        $teamOptions = pss_getTeams($meta['sport'], $league);
        $prefix1 = pss_teamPrefix($league, 1);
        $prefix2 = pss_teamPrefix($league, 2);
        // Team changes are handled below with the selected ID sent explicitly.
        // Leaving the FPP callback empty avoids a race where the plugin reads
        // the previous TeamID before FPP's own AJAX setting save completes.
        $callback1 = '';
        $callback2 = '';
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

            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Celebration delay</strong>
                    <div class="text-muted small pss-config-note">Optional pause added to the beginning of every helper playlist for this team. Useful for delayed TV/streaming feeds. 0 = play immediately.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1">
                    <div class="input-group">
                        <input class="form-control" type="number" min="0" max="300" step="1" value="<?=htmlspecialchars(pss_currentValue($prefix1 . 'CelebrationDelay', '0'))?>" onchange="pssCelebrationDelayChanged('<?=htmlspecialchars($prefix1 . 'CelebrationDelay', ENT_QUOTES)?>', this)">
                        <div class="input-group-append"><span class="input-group-text">sec</span></div>
                    </div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team2">
                    <div class="input-group">
                        <input class="form-control" type="number" min="0" max="300" step="1" value="<?=htmlspecialchars(pss_currentValue($prefix2 . 'CelebrationDelay', '0'))?>" onchange="pssCelebrationDelayChanged('<?=htmlspecialchars($prefix2 . 'CelebrationDelay', ENT_QUOTES)?>', this)">
                        <div class="input-group-append"><span class="input-group-text">sec</span></div>
                    </div>
                </div>
            </div>

            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>WLED celebration model</strong>
                    <div class="text-muted small pss-config-note">Only used when a celebration dropdown is set to <strong>Run WLED Effect</strong>. The effect runs on this Pixel Overlay Model; normal .fseq selections ignore this setting.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'WledModel', $prefix1 . 'WledModel', 0, 0, '', $pssOverlayModelOptions, $pluginName, 'pssWledModelChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'WledModel', $prefix2 . 'WledModel', 0, 0, '', $pssOverlayModelOptions, $pluginName, 'pssWledModelChanged', ''); ?></div>
            </div>

            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>WLED effect run time</strong>
                    <div class="text-muted small pss-config-note">How long a selected WLED celebration runs before the helper playlist sends <strong>Stop Effects</strong>. 1–600 seconds.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1">
                    <div class="input-group">
                        <input class="form-control" type="number" min="1" max="600" step="1" value="<?=htmlspecialchars(pss_currentValue($prefix1 . 'WledDuration', '5'))?>" onchange="pssWledDurationChanged('<?=htmlspecialchars($prefix1 . 'WledDuration', ENT_QUOTES)?>', this)">
                        <div class="input-group-append"><span class="input-group-text">sec</span></div>
                    </div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team2">
                    <div class="input-group">
                        <input class="form-control" type="number" min="1" max="600" step="1" value="<?=htmlspecialchars(pss_currentValue($prefix2 . 'WledDuration', '5'))?>" onchange="pssWledDurationChanged('<?=htmlspecialchars($prefix2 . 'WledDuration', ENT_QUOTES)?>', this)">
                        <div class="input-group-append"><span class="input-group-text">sec</span></div>
                    </div>
                </div>
            </div>

            <?php if ($meta['sport'] === 'football'): ?>
            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Touchdown sequence / effect</strong>
                    <div class="text-muted small pss-config-note">Choose an existing .fseq or a Run WLED Effect option. Team colors are supplied automatically for WLED effects.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'TouchdownSequence', $prefix1 . 'TouchdownSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'TouchdownSequence', $prefix2 . 'TouchdownSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Field goal sequence / effect</strong>
                    <div class="text-muted small pss-config-note">Existing sequences work exactly as before. WLED choices generate start → pause → stop helper playlists automatically.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'FieldgoalSequence', $prefix1 . 'FieldgoalSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'FieldgoalSequence', $prefix2 . 'FieldgoalSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <?php else: ?>
            <div class="row mb-3 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Score sequence / effect</strong>
                    <div class="text-muted small pss-config-note">Choose an existing .fseq or a Run WLED Effect option for that team score.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'ScoreSequence', $prefix1 . 'ScoreSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'ScoreSequence', $prefix2 . 'ScoreSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
            <?php endif; ?>

            <div class="row mb-1 align-items-start">
                <div class="col-md-4 pss-config-label">
                    <strong>Win sequence / effect</strong>
                    <div class="text-muted small pss-config-note">Choose an existing .fseq or a Run WLED Effect option when that team wins.</div>
                </div>
                <div class="col-md-4 pss-config-select pss-config-select-team1"><?php PrintSettingSelect($prefix1 . 'WinSequence', $prefix1 . 'WinSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
                <div class="col-md-4 pss-config-select pss-config-select-team2"><?php PrintSettingSelect($prefix2 . 'WinSequence', $prefix2 . 'WinSequence', 0, 0, '', $pssSequenceOptions, $pluginName, 'pssSequenceChanged', ''); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
var pssTeamPalettes = <?=json_encode(array_values($pssTeamPalettes), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

function pssRenderTeamPalettes(palettes) {
    var root = document.getElementById('pss-team-palette-grid');
    if (!root) return;
    while (root.firstChild) root.removeChild(root.firstChild);

    palettes = Array.isArray(palettes) ? palettes : [];
    if (!palettes.length) {
        var empty = document.createElement('div');
        empty.className = 'pss-team-palette-empty';
        empty.textContent = 'No team palettes yet. Select a team below and its colors will be created automatically.';
        root.appendChild(empty);
        return;
    }

    palettes.forEach(function(palette) {
        var card = document.createElement('div');
        card.className = 'pss-team-palette-card';

        var head = document.createElement('div');
        head.className = 'pss-team-palette-head';
        var name = document.createElement('span');
        name.className = 'pss-team-palette-name';
        name.textContent = String(palette.name || 'Team');
        var league = document.createElement('span');
        league.className = 'pss-team-palette-league';
        league.textContent = String(palette.league || '');
        head.appendChild(name);
        head.appendChild(league);
        card.appendChild(head);

        var swatches = document.createElement('div');
        swatches.className = 'pss-team-palette-swatches';
        var colors = Array.isArray(palette.colors) ? palette.colors.slice(0, 3) : [];
        while (colors.length < 3) colors.push('#000000');
        colors.forEach(function(color, index) {
            var swatch = document.createElement('div');
            swatch.className = 'pss-team-palette-swatch';
            var safeColor = /^#[0-9A-Fa-f]{6}$/.test(String(color || '')) ? String(color) : '#000000';
            swatch.style.backgroundColor = safeColor;
            swatch.title = 'Color ' + (index + 1) + ': ' + safeColor.toUpperCase();
            swatch.textContent = safeColor.toUpperCase();
            swatches.appendChild(swatch);
        });
        card.appendChild(swatches);
        root.appendChild(card);
    });
}


function pssTeamEffectSelectedPalette() {
    var select = document.getElementById('pss-team-effect-palette');
    if (!select || !select.value) return null;
    for (var i = 0; i < pssTeamPalettes.length; i++) {
        if (String(pssTeamPalettes[i].id || '') === String(select.value)) return pssTeamPalettes[i];
    }
    return null;
}

function pssRenderTeamEffectColors() {
    var root = document.getElementById('pss-team-effect-colors');
    var note = document.getElementById('pss-team-effect-color-note');
    if (!root) return;
    while (root.firstChild) root.removeChild(root.firstChild);

    var palette = pssTeamEffectSelectedPalette();
    if (!palette) {
        if (note) note.textContent = 'Select a team palette to preview the colors that will be sent to FPP.';
        return;
    }

    var preset = document.getElementById('pss-team-effect-preset');
    var colorCount = preset && preset.value === 'android' ? 2 : 3;
    var colors = Array.isArray(palette.colors) ? palette.colors.slice(0, 3) : [];
    while (colors.length < 3) colors.push('#000000');
    colors.forEach(function(color, index) {
        var chip = document.createElement('div');
        chip.className = 'pss-team-effect-color';
        chip.style.backgroundColor = /^#[0-9A-Fa-f]{6}$/.test(String(color || '')) ? color : '#000000';
        chip.textContent = 'Color ' + (index + 1) + '  ' + String(color || '#000000').toUpperCase();
        if (index >= colorCount) {
            chip.style.opacity = '.38';
            chip.title = 'Stored for the team but not sent by this effect preset';
        }
        root.appendChild(chip);
    });
    if (note) {
        note.textContent = colorCount === 3
            ? 'This effect receives Color 1, Color 2, and Color 3.'
            : 'This effect receives Color 1 and Color 2. Color 3 stays stored for effects that support it.';
    }
}

function pssTeamEffectPresetChanged() {
    var preset = document.getElementById('pss-team-effect-preset');
    var c1 = document.getElementById('pss-team-effect-control1-label');
    var c2 = document.getElementById('pss-team-effect-control2-label');
    var android = preset && preset.value === 'android';
    if (c1) c1.textContent = android ? 'Speed' : 'Fade Speed';
    if (c2) c2.textContent = android ? 'Width' : 'Spawn Speed';
    pssRenderTeamEffectColors();
}

function pssRunTeamEffect() {
    var message = document.getElementById('pss-team-effect-message');
    if (message) {
        message.className = 'pss-team-effect-message text-muted small';
        message.textContent = 'Starting team effect...';
    }
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        type: 'post',
        dataType: 'json',
        data: {
            action: 'runTeamEffect',
            model: $('#pss-team-effect-model').val() || '',
            paletteID: $('#pss-team-effect-palette').val() || '',
            preset: $('#pss-team-effect-preset').val() || 'colortwinkles',
            mapping: $('#pss-team-effect-mapping').val() || 'Horizontal',
            autoEnable: $('#pss-team-effect-autoenable').val() || 'Enabled',
            brightness: $('#pss-team-effect-brightness').val() || '128',
            control1: $('#pss-team-effect-control1').val() || '128',
            control2: $('#pss-team-effect-control2').val() || '128'
        }
    }).done(function(response) {
        if (message) {
            message.className = 'pss-team-effect-message ' + (response && response.ok ? 'text-success' : 'text-danger') + ' small';
            message.textContent = response && response.message ? response.message : 'Team effect request finished.';
        }
    }).fail(function(xhr) {
        if (message) {
            message.className = 'pss-team-effect-message text-danger small';
            message.textContent = 'Team effect request failed. Check the plugin log.';
        }
    });
}

function pssStopTeamEffect() {
    var message = document.getElementById('pss-team-effect-message');
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        type: 'post',
        dataType: 'json',
        data: {
            action: 'stopTeamEffect',
            model: $('#pss-team-effect-model').val() || ''
        }
    }).done(function(response) {
        if (message) {
            message.className = 'pss-team-effect-message ' + (response && response.ok ? 'text-success' : 'text-danger') + ' small';
            message.textContent = response && response.message ? response.message : 'Stop request finished.';
        }
    }).fail(function() {
        if (message) {
            message.className = 'pss-team-effect-message text-danger small';
            message.textContent = 'Stop request failed. Check the plugin log.';
        }
    });
}

function pssTeamSelectionChanged(league, slot, selectElement) {
    var teamID = selectElement ? selectElement.value : '';
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: {
            action: 'updateTeamSelection',
            league: league,
            slot: slot,
            teamID: teamID
        },
        type: 'post',
        dataType: 'json'
    }).done(function(response) {
        if (response && response.teamPalettes) {
            pssTeamPalettes = response.teamPalettes;
            pssRenderTeamPalettes(pssTeamPalettes);
            var teamSelect = document.getElementById('pss-team-effect-palette');
            if (teamSelect) {
                var previous = teamSelect.value;
                while (teamSelect.options.length > 1) teamSelect.remove(1);
                pssTeamPalettes.forEach(function(palette) {
                    var option = document.createElement('option');
                    option.value = String(palette.id || '');
                    option.textContent = String(palette.name || 'Team') + ' — ' + String(palette.league || '');
                    teamSelect.appendChild(option);
                });
                if (Array.prototype.some.call(teamSelect.options, function(opt) { return opt.value === previous; })) {
                    teamSelect.value = previous;
                }
                pssRenderTeamEffectColors();
            }
        }
    });
}

$(function() {
    var teamSelects = [
        ['nfl', 1, 'nflTeamID'], ['nfl', 2, 'nfl2TeamID'],
        ['ncaa', 1, 'ncaaTeamID'], ['ncaa', 2, 'ncaa2TeamID'],
        ['nhl', 1, 'nhlTeamID'], ['nhl', 2, 'nhl2TeamID'],
        ['mlb', 1, 'mlbTeamID'], ['mlb', 2, 'mlb2TeamID']
    ];

    teamSelects.forEach(function(config) {
        var select = document.getElementById(config[2]);
        if (!select) return;
        $(select).off('change.pssTeamSelection').on('change.pssTeamSelection', function() {
            pssTeamSelectionChanged(config[0], config[1], this);
        });
    });

    $('#pss-team-effect-palette').off('change.pssTeamEffect').on('change.pssTeamEffect', pssRenderTeamEffectColors);
    pssTeamEffectPresetChanged();
    pssRenderTeamEffectColors();
});

function pssSequenceChanged(setting) {
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'syncSequencePlaylist', setting: setting },
        type: 'post'
    });
}

function pssCelebrationDelayChanged(setting, input) {
    var value = parseInt(input.value, 10);
    if (isNaN(value)) value = 0;
    value = Math.max(0, Math.min(300, value));
    input.value = value;

    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'saveCelebrationDelay', setting: setting, value: value },
        type: 'post',
        dataType: 'json',
        success: function(response) {
            if (response && response.ok && typeof response.value !== 'undefined') {
                input.value = response.value;
            }
        }
    });
}

function pssWledModelChanged(setting) {
    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'syncWledCelebrationSetting', setting: setting },
        type: 'post'
    });
}

function pssWledDurationChanged(setting, input) {
    var value = parseInt(input.value, 10);
    if (isNaN(value)) value = 5;
    value = Math.max(1, Math.min(600, value));
    input.value = value;

    $.ajax({
        url: 'plugin.php?_menu=content&plugin=<?=rawurlencode($pluginName)?>&nopage=1&page=functions.inc.php',
        data: { action: 'saveWledCelebrationDuration', setting: setting, value: value },
        type: 'post',
        dataType: 'json',
        success: function(response) {
            if (response && response.ok && typeof response.value !== 'undefined') {
                input.value = response.value;
            }
        }
    });
}

var pssOverlayGeometry = <?=json_encode($pssOverlayGeometry, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

function pssOverlayCornerLabel(value) {
    var labels = { TL: 'Top Left', TR: 'Top Right', BL: 'Bottom Left', BR: 'Bottom Right' };
    var key = String(value || '').trim();
    return labels[key.toUpperCase()] || key || 'Unknown';
}

function pssOverlayOrientationLabel(value) {
    var text = String(value || '').trim();
    if (!text) return 'Unknown';
    return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

function pssUpdateOverlayGeometry() {
    var select = document.getElementById('pss-ticker-model');
    var model = select ? select.value : '';
    var info = model && pssOverlayGeometry ? pssOverlayGeometry[model] : null;
    var orientation = document.getElementById('pss-overlay-geometry-orientation');
    var corner = document.getElementById('pss-overlay-geometry-corner');
    var size = document.getElementById('pss-overlay-geometry-size');
    var source = document.getElementById('pss-overlay-geometry-source');
    var note = document.getElementById('pss-overlay-geometry-note');

    if (!info) {
        if (orientation) orientation.textContent = 'Unknown';
        if (corner) corner.textContent = 'Unknown';
        if (size) size.textContent = 'Unknown';
        if (source) source.textContent = 'FPP runtime model';
        if (note) note.textContent = model
            ? 'This model is returned by FPP but its saved geometry was not found in model-overlays.json. Open Pixel Overlay Models to inspect it.'
            : 'Select a model to display its FPP geometry.';
        return;
    }

    if (orientation) orientation.textContent = pssOverlayOrientationLabel(info.orientation);
    if (corner) corner.textContent = pssOverlayCornerLabel(info.startCorner);
    if (size) {
        var w = parseInt(info.width || 0, 10);
        var h = parseInt(info.height || 0, 10);
        size.textContent = (w > 0 && h > 0) ? (w + ' × ' + h) : 'Unknown';
    }
    if (source) source.textContent = info.xlights ? 'xLights' : 'FPP';
    if (note) note.textContent = 'Text orientation comes from this FPP model. If text is upside down or mirrored, adjust Orientation/Start Corner in Pixel Overlay Models; the sports plugin leaves geometry unchanged.';
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

function pssRenderTickerPreview(items, fallbackText, spacing, fontSize) {
    var root = document.getElementById('pss-ticker-preview-text');
    if (!root) return;
    while (root.firstChild) root.removeChild(root.firstChild);

    var safeSpacing = Math.max(1, Math.min(12, parseInt(spacing || 4, 10)));
    var safeFontSize = Math.max(12, Math.min(48, parseInt(fontSize || 18, 10)));
    root.style.fontSize = safeFontSize + 'px';
    if (!items || !items.length) {
        var empty = document.createElement('span');
        empty.textContent = fallbackText || 'PRO SPORTS SCORING • NO SELECTED TEAMS';
        root.appendChild(empty);
        return;
    }

    for (var i = 0; i < items.length; i++) {
        if (i > 0) {
            var separator = document.createElement('span');
            separator.className = 'pss-ticker-preview-separator';
            separator.textContent = '•';
            separator.style.margin = '0 ' + (safeSpacing * 0.35).toFixed(2) + 'em';
            root.appendChild(separator);
        }

        var segment = document.createElement('span');
        segment.textContent = String(items[i].text || '');
        if (/^#[0-9A-Fa-f]{6}$/.test(String(items[i].color || ''))) {
            segment.style.color = items[i].color;
        }
        root.appendChild(segment);
    }
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
            pssRenderTickerPreview(response.tickerItems || [], response.tickerText, response.tickerSpacing || 4, response.tickerWebFontSize || 18);
        }
        if (response && response.overlayTickerText) {
            var commandText = document.getElementById('pss-overlay-command-text');
            if (commandText) commandText.value = response.overlayTickerText;
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
        data: pssTickerFormData('testTicker'),
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

document.addEventListener('DOMContentLoaded', function () {
    var modelSelect = document.getElementById('pss-ticker-model');
    if (modelSelect) modelSelect.addEventListener('change', pssUpdateOverlayGeometry);
    pssUpdateOverlayGeometry();
    pssRenderTeamPalettes(pssTeamPalettes);
});

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
