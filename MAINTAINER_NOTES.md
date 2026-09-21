# Maintainer Notes

## Normal maintenance workflow

1. Create a branch: `git checkout -b fix-description`.
2. Make the change.
3. Run `php -l` on every changed PHP file and `git diff --check`.
4. Test on an actual FPP player with the plugin disabled first, then one league at a time.
5. Commit and push the branch.
6. Merge to `main` only after the FPP test passes.

## Fast checks on an FPP player

- Plugin log: `/home/fpp/media/logs/plugin-fpp-nfl.log`
- Worker PID file: `/home/fpp/media/plugins/fpp-nfl/sports-scoring.pid`
- ESPN team endpoint: `https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams`
- ESPN game summary: `https://site.api.espn.com/apis/site/v2/sports/football/nfl/summary?event=EVENT_ID`
- FPP playlists are read from FPP's configured playlist directory (`$settings['playlistDirectory']`).
- FPP command API: `POST http://127.0.0.1/api/command`

## Important design rules

- Keep `repoName` as `fpp-nfl` unless you intentionally plan a configuration migration. Existing settings are stored under `config/plugin.fpp-nfl`.
- Do not add direct `reboot`, `systemctl restart fppd`, or `killall` calls.
- Keep runtime logs in the single `plugin-fpp-nfl.log` file.
- Do not guess football scoring from score deltas. Use ESPN scoring-play IDs so a 7-point touchdown is still recognized as a touchdown and old plays are not repeated.
- If ESPN changes its schema, fail closed: keep the last known game state and try again later rather than clearing scores or replaying celebrations.

## Celebration playback

Configured celebration values are FPP playlist names (`TouchdownPlaylist`, `FieldgoalPlaylist`, `ScorePlaylist`, `WinPlaylist`). Playback uses `POST /api/command` with `Insert Playlist Immediate`. FPP may return `text/plain` for a successful command, so command success is based on the HTTP 2xx status rather than JSON parsing.
