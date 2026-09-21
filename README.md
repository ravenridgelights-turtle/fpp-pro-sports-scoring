# Pro Sports Scoring Plugin for FPP

Maintained fork of Ben Kools' original `fpp-nfl` plugin. It supports NFL, NCAA Football, NHL, and MLB and plays local FPP sequences when a selected team scores or wins.

## What changed in this maintained version

- Uses HTTPS ESPN Site API endpoints and the current game-summary endpoint.
- Uses ESPN `scoringPlays` for NFL/NCAA touchdown and field-goal detection instead of guessing from `+6`/`+3` score changes.
- Protects saved state when ESPN is unavailable or returns malformed data.
- Prevents old scoring plays and win sequences from replaying after a restart.
- Uses FPP's documented `POST /api/command` interface to trigger sequences.
- Uses one FPP-managed log: `plugin-fpp-nfl.log`.
- Runs one background worker that stays alive while FPP is running; enabling/disabling the plugin no longer launches PHP from a web request.
- Removes external Bootstrap/CDN dependencies and uses FPP's own UI styling.
- Installer/uninstaller are idempotent and do not reboot or restart FPP.

## ESPN note

The ESPN Site API used here is public and does not require credentials, but it is unofficial and can change. The most likely maintenance point is the JSON parsing in `getTeams()`, `getTeamInfo()`, and `getGameStatus()` in `functions.inc.php`.

## FPP compatibility

The manifest declares FPP 7, 8, 9, and 10 compatibility. Test each major before publishing a release. For FPP Plugin Manager submission, also test the latest released FPP and the current nightly build.

## License and attribution

GPL-3.0. Original plugin by Ben Kools (koolsb). Maintained fork by ravenridgelights-turtle.
