#!/usr/bin/env python3
"""Read-only verification against the official FPP release tags, not live hardware."""
import argparse
import json
import re
import subprocess
from pathlib import Path

TAGS = ['8.0', '8.5.1', '9.0', '9.4', '10.0', '10.1.2', '10.2']
p = argparse.ArgumentParser()
p.add_argument('fpp_checkout', type=Path)
p.add_argument('--report', type=Path)
args = p.parse_args()

def git(*cmd):
    return subprocess.check_output(['git', '-C', str(args.fpp_checkout), *cmd], text=True)

def read(tag, path):
    return git('show', f'{tag}:{path}')

report = []
for tag in TAGS:
    commands = read(tag, 'src/commands/Commands.cpp')
    playlists = read(tag, 'src/commands/PlaylistCommands.cpp')
    overlays = read(tag, 'src/overlays/PixelOverlay.cpp')
    effects = read(tag, 'src/overlays/PixelOverlayEffects.cpp')
    routes = read(tag, 'www/api/index.php')
    playlist_api = read(tag, 'www/api/controllers/playlist.php')
    status = read(tag, 'src/playlist/Playlist.cpp')
    start = playlists.index('InsertPlaylistImmediate::InsertPlaylistImmediate')
    end = playlists.index('InsertRandomItemFromPlaylistCommand::', start)
    insert = playlists[start:end]
    assert re.findall(r'CommandArg\("([^\"]+)"', insert) == ['name', 'startItem', 'endItem', 'ifNotRunning'], tag
    assert 'render_POST' in commands and 'cmd["args"]' in commands and 'cmd["command"]' in commands, tag
    assert '"text/plain"' in commands, tag
    for command in ['Overlay Model State', 'Overlay Model Clear', 'Overlay Model Effect']:
        assert f'"{command}"' in overlays, (tag, command)
    text_effect = effects[effects.index('class TextEffect'):effects.index('class TextEffect') + 2400]
    assert re.findall(r'CommandArg\("([^\"]+)"', text_effect)[:8] == ['Color', 'Font', 'FontSize', 'FontAntiAlias', 'Position', 'Speed', 'Duration', 'Text'], tag
    assert 'Stop Effects' in effects, tag
    for route in ["dispatch_post('/playlist/:PlaylistName'", "dispatch_get('/media/:MediaName/duration'", "dispatch_get('/files/:DirName'", "dispatch_post('/schedule/reload'"]:
        assert route in routes, (tag, route)
    assert 'function playlist_update' in playlist_api and "['Status'] = 'Error'" in playlist_api, tag
    assert 'current_playlist' in status and '"playlist"' in status, tag
    common = read(tag, 'scripts/common')
    if tag.startswith('8.'):
        assert '$LD_LIBRARY_PATH' in common, tag
    version = tuple(int(n) for n in tag.split('.'))
    has_sources = "dispatch_get('/pipewire/video/input-sources'" in routes
    has_preview = "dispatch_get('/pipewire/video/input-sources/:id/preview'" in routes
    assert has_sources == (version >= (10, 0)), tag
    assert has_preview == (version >= (10, 1)), tag
    row = {'tag': tag, 'commit': git('rev-parse', f'{tag}^{{commit}}').strip(),
           'insertPlaylistArguments': ['name', 'startItem', 'endItem', 'ifNotRunning'],
           'jsonCommandPost': True, 'overlayTextArguments': 8, 'playlistApi': True,
           'videoInputSources': has_sources, 'videoPreview': has_preview,
           'source': f'https://github.com/FalconChristmas/fpp/tree/{tag}'}
    report.append(row)
    print(f'PASS FPP {tag}: command, playlist, overlay, status and optional-video source contracts')
if args.report:
    args.report.write_text(json.dumps({'scope': 'Tagged source contracts; no hardware execution', 'releases': report}, indent=2) + '\n')
