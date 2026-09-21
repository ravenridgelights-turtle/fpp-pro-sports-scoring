# Maintainer Notes

## Plugin identity

The FPP plugin ID/repository name remains `fpp-nfl` for compatibility with existing installations and settings. The maintained source repository is `ravenridgelights-turtle/fpp-pro-sports-scoring`.

## ESPN

Team lists and game data use ESPN's Site API. ESPN's Site API is unofficial, so keep HTTP failures non-destructive: do not erase a known event or score merely because one poll fails.

The FPP device used during development received HTTP 403 responses from ESPN when PHP cURL used a browser-like User-Agent, while normal command-line curl succeeded. `pss_httpRequest()` therefore identifies as the installed curl version.

## Celebration sequence flow

User-facing settings are sequence selections:

- `TouchdownSequence`
- `FieldgoalSequence`
- `ScoreSequence`
- `WinSequence`

The plugin converts each selected `.fseq` into a one-item helper playlist. Generated names use:

`PSS_<LEAGUE>_<TEAM>_<TYPE>`

Examples: `PSS_NFL_KC_Touchdown`, `PSS_NHL_BOS_Score`.

Generated playlist JSON is version 4 and mirrors the structure written by FPP 10.1.2. The description is set to `Managed by Pro Sports Scoring plugin. Do not edit manually.` so cleanup only removes files owned by this plugin. Sequence duration is read from the FSEQ header (frame count x step time), using the documented FSEQ v1/v2 header fields.

`pss_syncAllGeneratedPlaylists()` runs in the root-owned daemon even while the plugin is disabled, so helper playlists are created or repaired shortly after settings change. The setup page also requests an immediate sync after a sequence selection changes.

Playback uses `POST /api/command` with `Insert Playlist Immediate`. FPP may return `text/plain` on success, so command success is determined from the HTTP 2xx status rather than JSON parsing.

## Worker lifecycle

The scoring daemon is started by the plugin lifecycle scripts and runs as root under FPP. Upgrade/install must replace stale daemon processes so newly installed PHP code actually takes effect.

## Logging

Use only FPP's plugin log:

`plugin-fpp-nfl.log`

Info logs scoring actions and notable failures. Debug additionally logs ESPN polling.

## Test checklist

1. Plugin install/upgrade finishes with rc=0.
2. Setup and status pages render without PHP fatal errors.
3. NFL returns 33 entries including `No team`.
4. NCAA Football, NHL, and MLB lists populate.
5. Selecting a team populates opponent, event ID, status, and score.
6. A selected sequence creates the matching `PSS_*` playlist in FPP.
7. With a normal show running, a manual celebration inserts the helper playlist and FPP returns to the show afterward.
8. Live polling uses a fresh daemon PID after upgrade.
9. Football scoring is based on ESPN scoring plays rather than score-delta guesses.
10. NHL/MLB score changes and win handling do not replay after daemon restart.
