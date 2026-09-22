<?php
// Pro Sports Scoring scoreboard live-video enhancement.
// FPP includes plugin theme.inc.php files globally, so guard tightly to the
// plugin's status page and never emit markup for API/data responses.
if (!isset($_GET['plugin']) || (string)$_GET['plugin'] !== 'fpp-nfl') return;
if (!isset($_GET['page']) || basename((string)$_GET['page']) !== 'status.php') return;
if (isset($_GET['data']) || isset($_GET['highlights']) || isset($_GET['highlightmedia'])) return;

$pssThemeConfig = isset($settings['configDirectory'])
    ? rtrim((string)$settings['configDirectory'], '/') . '/plugin.fpp-nfl'
    : '/home/fpp/media/config/plugin.fpp-nfl';
$pssThemeSettings = is_file($pssThemeConfig) ? @parse_ini_file($pssThemeConfig) : array();
if (!is_array($pssThemeSettings)) $pssThemeSettings = array();

function pss_theme_setting($key, $default = '') {
    global $pssThemeSettings;
    return isset($pssThemeSettings[$key]) ? urldecode((string)$pssThemeSettings[$key]) : $default;
}

$pssVideoMediaMode = strtolower(trim(pss_theme_setting('ScoreboardMediaMode', 'auto')));
if (!in_array($pssVideoMediaMode, array('auto', 'video', 'highlights', 'none'), true)) {
    $pssVideoMediaMode = 'auto';
}
$pssVideoHideHighlights = pss_theme_setting('ScoreboardHideHighlightsWithVideo', 'ON') === 'ON';
$pssVideoPreviewFps = (int)pss_theme_setting('ScoreboardVideoPreviewFPS', '5');
$pssVideoPreviewFps = max(1, min(10, $pssVideoPreviewFps));
$pssVideoTeams = array();
foreach (array('nfl', 'ncaa', 'nhl', 'mlb') as $league) {
    foreach (array(1, 2) as $slot) {
        $prefix = $league . ($slot === 2 ? '2' : '');
        $source = trim(pss_theme_setting($prefix . 'VideoSource', ''));
        $url = trim(pss_theme_setting($prefix . 'VideoStreamUrl', ''));
        $valid = (strpos($source, 'usb:/dev/video') === 0) || ($source === 'url' && $url !== '');
        if (!$valid) continue;
        $label = trim(pss_theme_setting($prefix . 'VideoSourceLabel', ''));
        if ($label === '') {
            $label = ($source === 'url') ? 'Network / IP stream' : substr($source, 4);
        }
        $pssVideoTeams[$prefix] = array(
            'league' => $league,
            'slot' => $slot,
            'label' => $label,
            'kind' => ($source === 'url') ? 'url' : 'usb'
        );
    }
}
?>
<style id="pss-live-video-style">
.pss-live-video {
    margin-top: 14px;
    border: 1px solid rgba(127,127,127,.28);
    border-radius: 8px;
    overflow: hidden;
    background: rgba(0,0,0,.08);
}
.pss-live-video-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 12px;
    padding: 9px 11px;
    border-bottom: 1px solid rgba(127,127,127,.22);
}
.pss-live-video-title { font-weight: 700; }
.pss-live-video-source { opacity: .7; font-size: .85em; overflow-wrap: anywhere; }
.pss-live-video-actions { display: flex; gap: 7px; align-items: center; }
.pss-live-video-button {
    appearance: none;
    border: 1px solid rgba(127,127,127,.45);
    border-radius: 5px;
    padding: 5px 10px;
    font: inherit;
    cursor: pointer;
    background: rgba(127,127,127,.12);
    color: inherit;
}
.pss-live-video-button:hover { background: rgba(127,127,127,.22); }
.pss-live-video-button:disabled { opacity: .5; cursor: default; }
.pss-live-video-frame {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    min-height: 150px;
    display: grid;
    place-items: center;
    background: #050505;
}
.pss-live-video-frame img {
    display: none;
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
}
.pss-live-video.is-playing .pss-live-video-frame img { display: block; }
.pss-live-video-placeholder {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    padding: 18px;
    text-align: center;
    color: #aaa;
}
.pss-live-video.is-playing .pss-live-video-placeholder { display: none; }
.pss-live-video-status {
    padding: 7px 11px;
    min-height: 2.2em;
    border-top: 1px solid rgba(127,127,127,.22);
    opacity: .75;
    font-size: .85em;
}
.pss-live-video-error { color: #dc3545; opacity: 1; }
.pss-live-video-badge {
    display: none;
    font-size: .72em;
    border-radius: 999px;
    padding: 2px 7px;
    background: #198754;
    color: #fff;
    margin-left: 6px;
}
.pss-live-video.is-playing .pss-live-video-badge { display: inline-block; }
@media (max-width: 600px) {
    .pss-live-video-head { align-items: flex-start; }
    .pss-live-video-actions { width: 100%; }
    .pss-live-video-button { flex: 1 1 0; }
}
</style>
<script id="pss-live-video-script">
(function () {
    'use strict';

    var teams = <?=json_encode($pssVideoTeams, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
    var mediaMode = <?=json_encode($pssVideoMediaMode)?>;
    var hideHighlightsWhilePlaying = <?=$pssVideoHideHighlights ? 'true' : 'false'?>;
    var defaultFps = <?=intval($pssVideoPreviewFps)?>;
    var endpoint = 'plugin.php?_menu=content&plugin=fpp-nfl&nopage=1&page=functions.inc.php';
    var active = null;

    function postAction(fields) {
        var body = new URLSearchParams();
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key]);
        });
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) {}
                if (!data) throw new Error('FPP returned an invalid video response.');
                return data;
            });
        });
    }

    function highlightPanel(card) {
        return card ? card.querySelector('[data-pss-highlights="1"]') : null;
    }

    function applyHighlightVisibility(state, playing) {
        var panel = highlightPanel(state.card);
        if (!panel) return;
        if (mediaMode === 'none' || mediaMode === 'video') {
            panel.style.display = 'none';
            return;
        }
        if (mediaMode === 'highlights') {
            panel.style.display = '';
            return;
        }
        panel.style.display = (playing && hideHighlightsWhilePlaying) ? 'none' : '';
    }

    function setStatus(state, text, isError) {
        state.status.textContent = text || '';
        state.status.classList.toggle('pss-live-video-error', !!isError);
    }

    function stopPolling(state) {
        state.generation++;
        if (state.timer) {
            clearTimeout(state.timer);
            state.timer = null;
        }
        state.img.onload = null;
        state.img.onerror = null;
        state.img.removeAttribute('src');
        state.root.classList.remove('is-playing');
        state.play.disabled = false;
        state.stop.disabled = true;
        applyHighlightVisibility(state, false);
    }

    function scheduleFrame(state, delay) {
        if (active !== state || !state.previewUrl) return;
        if (state.timer) clearTimeout(state.timer);
        state.timer = setTimeout(function () { loadFrame(state); }, Math.max(0, delay || 0));
    }

    function loadFrame(state) {
        if (active !== state || !state.previewUrl) return;
        if (document.visibilityState === 'hidden') {
            scheduleFrame(state, 1000);
            return;
        }

        var generation = state.generation;
        var started = Date.now();
        state.img.onload = function () {
            if (active !== state || generation !== state.generation) return;
            state.root.classList.add('is-playing');
            setStatus(state, 'Live preview · ' + state.fps + ' fps target');
            var elapsed = Date.now() - started;
            scheduleFrame(state, Math.max(0, Math.round(1000 / state.fps) - elapsed));
        };
        state.img.onerror = function () {
            if (active !== state || generation !== state.generation) return;
            setStatus(state, 'Waiting for frames from FPP…', false);
            scheduleFrame(state, 800);
        };
        var separator = state.previewUrl.indexOf('?') >= 0 ? '&' : '?';
        state.img.src = state.previewUrl + separator + '_pss=' + Date.now();
    }

    function deactivateUi(state, message) {
        if (!state) return;
        stopPolling(state);
        state.previewUrl = '';
        if (message) setStatus(state, message, false);
    }

    function play(state) {
        if (mediaMode === 'highlights' || mediaMode === 'none') return;
        if (active && active !== state) {
            deactivateUi(active, 'Stopped because another team was started.');
        }
        active = state;
        state.play.disabled = true;
        state.stop.disabled = false;
        setStatus(state, 'Starting FPP video input…');

        postAction({ action: 'startTeamVideoPreview', league: state.team.league, slot: state.team.slot })
            .then(function (data) {
                if (active !== state) return;
                if (!data.ok) throw new Error(data.message || 'Unable to start live video.');
                state.previewUrl = data.previewUrl || '';
                state.fps = Math.max(1, Math.min(10, parseInt(data.fps || defaultFps, 10) || defaultFps));
                state.generation++;
                applyHighlightVisibility(state, true);
                if (!state.previewUrl) throw new Error('FPP did not return a preview endpoint.');
                setStatus(state, data.message || 'Live video started.');
                loadFrame(state);
            })
            .catch(function (err) {
                if (active === state) active = null;
                deactivateUi(state, 'Unable to start video: ' + (err && err.message ? err.message : err), true);
                setStatus(state, 'Unable to start video: ' + (err && err.message ? err.message : err), true);
            });
    }

    function stop(state) {
        state.stop.disabled = true;
        setStatus(state, 'Stopping live video…');
        postAction({ action: 'stopTeamVideoPreview' })
            .then(function (data) {
                if (active === state) active = null;
                deactivateUi(state, (data && data.message) ? data.message : 'Live video stopped.');
            })
            .catch(function (err) {
                if (active === state) active = null;
                deactivateUi(state, 'Preview stopped in this browser. FPP stop request failed.');
                setStatus(state, 'FPP stop request failed: ' + (err && err.message ? err.message : err), true);
            });
    }

    function buildPanel(card, key, team) {
        var root = document.createElement('div');
        root.className = 'pss-live-video';
        root.setAttribute('data-pss-live-video', '1');

        var head = document.createElement('div');
        head.className = 'pss-live-video-head';
        var copy = document.createElement('div');
        var title = document.createElement('div');
        title.className = 'pss-live-video-title';
        title.appendChild(document.createTextNode('Live Game Video'));
        var badge = document.createElement('span');
        badge.className = 'pss-live-video-badge';
        badge.textContent = 'LIVE';
        title.appendChild(badge);
        var source = document.createElement('div');
        source.className = 'pss-live-video-source';
        source.textContent = team.label || (team.kind === 'url' ? 'Network / IP stream' : 'USB capture device');
        copy.appendChild(title);
        copy.appendChild(source);

        var actions = document.createElement('div');
        actions.className = 'pss-live-video-actions';
        var playButton = document.createElement('button');
        playButton.type = 'button';
        playButton.className = 'pss-live-video-button';
        playButton.textContent = '▶ Play';
        var stopButton = document.createElement('button');
        stopButton.type = 'button';
        stopButton.className = 'pss-live-video-button';
        stopButton.textContent = '■ Stop';
        stopButton.disabled = true;
        actions.appendChild(playButton);
        actions.appendChild(stopButton);
        head.appendChild(copy);
        head.appendChild(actions);

        var frame = document.createElement('div');
        frame.className = 'pss-live-video-frame';
        var img = document.createElement('img');
        img.alt = 'Live video preview for ' + (card.getAttribute('data-team-name') || 'selected team');
        var placeholder = document.createElement('div');
        placeholder.className = 'pss-live-video-placeholder';
        placeholder.textContent = 'Press Play to start this team’s configured live video source. Only one sports preview runs at a time.';
        frame.appendChild(img);
        frame.appendChild(placeholder);

        var status = document.createElement('div');
        status.className = 'pss-live-video-status';
        status.textContent = 'Ready · ' + (team.kind === 'url' ? 'network stream' : 'USB capture');

        root.appendChild(head);
        root.appendChild(frame);
        root.appendChild(status);

        var state = {
            key: key,
            team: team,
            card: card,
            root: root,
            img: img,
            status: status,
            play: playButton,
            stop: stopButton,
            previewUrl: '',
            fps: defaultFps,
            generation: 0,
            timer: null
        };
        playButton.addEventListener('click', function () { play(state); });
        stopButton.addEventListener('click', function () { stop(state); });
        return state;
    }

    function initialize() {
        var cards = document.querySelectorAll('.pss-scoreboard[data-pss-key]');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var key = card.getAttribute('data-pss-key') || '';
            var highlights = highlightPanel(card);

            if (mediaMode === 'none') {
                if (highlights) highlights.style.display = 'none';
                continue;
            }
            if (mediaMode === 'highlights') continue;

            var team = teams[key];
            if (!team) {
                if (mediaMode === 'video' && highlights) highlights.style.display = 'none';
                continue;
            }

            if (mediaMode === 'video' && highlights) highlights.style.display = 'none';
            var state = buildPanel(card, key, team);
            var meta = card.querySelector('.pss-meta');
            if (meta && meta.parentNode) {
                if (meta.nextSibling) meta.parentNode.insertBefore(state.root, meta.nextSibling);
                else meta.parentNode.appendChild(state.root);
            } else {
                card.appendChild(state.root);
            }
            applyHighlightVisibility(state, false);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
</script>
