# FPP 8–10 compatibility audit

Release: **2026.10.19.1**. FPP 8 is the minimum supported major version. FPP 7 and unverified future major versions are excluded from the install manifest.

This audit checked the official source at **8.0, 8.5.1, 9.0, 9.4, 10.0, 10.1.2 and 10.2**, then exercised the plugin with simulated API responses. It does **not** claim physical playback testing on those releases. Machine-readable commits and source links are in [fpp-source-contracts.json](fpp-source-contracts.json).

## Version and API matrix

| Feature / contract | FPP 8 / 8.5.1 | FPP 9 / 9.4 | FPP 10.0 | FPP 10.1.2 / 10.2 |
| --- | --- | --- | --- | --- |
| `POST /api/command`, JSON `{command,args}` | Present | Present | Present | Present |
| `Insert Playlist Immediate` arguments: playlist, start item, end item, if-not-running | Same four arguments | Same | Same | Same |
| FSEQ and sequence-plus-media playlist entries | Present | Present | Present | Present |
| `GET/POST /api/playlist/:name` | Present | Present | Present | Present |
| `GET /api/fppd/status` | Present | Present | Present | Present |
| Overlay State / Clear / Effect commands | Present | Present | Present | Present |
| Text effect: Color, Font, FontSize, AntiAlias, Position, Speed, Duration, Text | Same order | Same | Same | Same |
| `GET /api/models`, `/api/overlays/effects/`, `/api/overlays/fonts` | Present | Present | Present | Present |
| WLED effect-specific controls | Discover from the running player | Discover | Discover | Discover |
| `POST /api/schedule/reload` | Present | Present | Present | Present |
| Video input source configuration API | Absent | Absent | Present | Present |
| Browser live-video preview endpoint | Absent | Absent | Absent | Present |

The core APIs used by scoring did not require separate FPP 8/9/10 playback implementations. Optional video preview is enabled only on FPP 10.1+ when its API responds. Checking the firmware version does not replace checking the actual command response, configured models, available fonts or files.

Primary source examples from FPP 9.4:

- [API endpoint help definitions](https://github.com/FalconChristmas/fpp/blob/9.4/www/api/endpoints.json)
- [Command help UI](https://github.com/FalconChristmas/fpp/blob/9.4/www/api/commandhelp.php)
- [Command POST handling](https://github.com/FalconChristmas/fpp/blob/9.4/src/commands/Commands.cpp)
- [Insert Playlist Immediate](https://github.com/FalconChristmas/fpp/blob/9.4/src/commands/PlaylistCommands.cpp)
- [Overlay commands](https://github.com/FalconChristmas/fpp/blob/9.4/src/overlays/PixelOverlay.cpp)
- [Text effect arguments](https://github.com/FalconChristmas/fpp/blob/9.4/src/overlays/PixelOverlayEffects.cpp)
- [PHP API routes](https://github.com/FalconChristmas/fpp/blob/9.4/www/api/index.php)
- [9.4 release notes](https://github.com/FalconChristmas/fpp/releases/tag/9.4)

The 9.4 notes also identify upstream playlist/API race and playlist-command crash fixes. The plugin cannot backport firmware fixes into FPP 8; physical interrupt/resume remains an on-device acceptance check.

## Verified problems and fixes

1. **Startup on FPP 8:** the modern lifecycle enabled `set -u` before sourcing FPP common. FPP 8.5.1 reads optional variables such as `LD_LIBRARY_PATH` without a default, causing startup to abort. The installer also ignored worker-start failures. Lifecycle now permits those optional variables and propagates failed startup. A stale PID file cannot terminate an unrelated process; a worker lock prevents duplicate daemons on one player.
2. **Saved model lists:** PHP's INI parser strips embedded quotes from JSON model lists. Locked, string-preserving reads and a compatible writer retain these lists, encode unsafe values for FPP 8's own parser, recover simple previously stripped lists, and avoid rewriting unchanged settings. A game snapshot is saved in one settings transaction. Complex names already damaged by an older writer may still need reselecting.
3. **Lost celebrations:** the previous code advanced scoring/completion markers even if FPP rejected playback. Automatic events now enter a persistent outbox before acknowledgment. Each successful destination is retained independently; an offline remote does not replay a successful local celebration. Explicit failures retry about every five seconds for up to two minutes. Waiting for an existing celebration does not consume that failure window; queued events have a 15-minute age limit. Disabled, changed-team, changed-event and overturned-score events are cancelled.
4. **Overlapping celebrations:** FPP's Insert Playlist Immediate has one pending insert slot. Dispatch serializes inserts, checks the active celebration, and keeps destination order so a win cannot overtake its touchdown while waiting for retry.
5. **Final scores and duplicates:** game-ending scores now run before win handling. A delayed touchdown record arriving with the PAT does not replay the touchdown. Score corrections reset the score high-water mark with a new deduplication epoch. Opponent scores, repeated polls, historical baselines and completed games remain excluded.
6. **Manual buttons:** status controls call the shared action endpoint, return FPP/file failures to the user, and recover after failed requests. Sequence-change requests carry the selected value and report helper-generation failures. Remote helpers verify referenced sequence/media filenames before inserting; HTTP 200 with `Status: Error` is rejected.
7. **Ticker cleanup and fonts:** clearing pixels alone does not stop a running Text/WLED effect. Clear now stops the effect, clears it and disables the model so sequence output can show again. Old default fonts resolve against the player's advertised fonts.
8. **Branch drift:** legacy contained remote target and sequence-plus-media support missing from modern. The shared implementation retains both along with palettes, WLED, schedules, ticker and browser highlights. `team-palettes.php` remains available. Existing branch names are retained for upgrades; both use the same implementation after their patches are merged.
9. **Install discovery:** the old `main` manifest pointed at the original author's repository. The separate manifest fix routes fresh FPP 8–10 installs to this maintained modern branch. Existing installs update their current branch; reading a new manifest is not a substitute for merging/updating that branch.

## Runtime cost

Settings are no longer rewritten for every unchanged field in every poll. Helper repair runs when relevant configuration changes and once a minute, including while scoring is disabled. Failed repairs preserve selected helpers rather than deleting them. Stable FPP effect/font/model metadata is cached for 60 seconds, but scores and command responses are not cached. The worker checks for configuration changes every three seconds even between games, rather than sleeping for ten minutes through a user change. Its small heartbeat is stored under `/tmp`; the celebration outbox remains in the persistent config directory. Unchanged remote helpers are not re-uploaded, avoiding repeated FPP backup generation.

The status page distinguishes a responding scoring worker from a browser that merely refreshed its cached scores. It shows the plugin version, branch/commit, FPP version, and ESPN polling errors.

## Updating an existing installation

After the fixes are merged into the installed branch, use FPP's Plugin Manager **Update**, then restart the plugin if the new worker/version does not appear. The installer restarts the worker itself on a successful update.

For an SSH update, first inspect local edits. Do not discard local modifications to force the update:

```bash
cd /home/fpp/media/plugins/fpp-nfl
git status --short
git branch --show-current
```

For a clean modern installation:

```bash
git pull --ff-only origin modernize-fpp-espn
sudo bash scripts/fpp_install.sh
```

For a clean legacy installation, use `legacy-fpp7-9` in the pull command. The legacy branch name is retained for existing installations; this release supports FPP 8–10. An update is successful only if the installer exits successfully and the Status page shows **2026.10.19.1**, the expected branch/commit and a responding worker.

## Two-player installations

Use **one enabled scoring controller** for the same celebration outputs. On that player choose Local, Remote, or Both. A remote destination must already contain the selected sequence/audio files and matching overlay models; the plugin transfers helper playlist JSON, not the media or layout. Keep automatic scoring disabled on a second player receiving those remote triggers. Independent controllers do not share deduplication records and can both celebrate the same score.

Do not assume that enabling both plugins proves that both work. Use the Status page buttons and logs on each independently, then leave the intended scoring controller enabled.

## On-device acceptance checklist

1. Confirm the plugin version/branch, firmware version and responding worker on each player separately.
2. Select an actual local FSEQ. From idle, test Touchdown, Field Goal and Win (and Score for NHL/MLB). Disabled buttons mean no selection is configured for that action.
3. Repeat while a normal patio playlist runs. Verify the celebration plays and FPP resumes the original show at the expected point. Check audio timing if sequence-plus-media is selected.
4. Test each configured WLED model, Test Pixel Ticker, and Clear Ticker. Check colors, orientation, font and restoration of the normal output.
5. If using a remote destination, test that destination explicitly with its files/models present, then with its player unavailable. Review the error and retry logs.
6. Leave only the intended controller enabled. During the next live game, compare one selected-team score, an opponent score and the final result to the log. ESPN feed latency can delay detection.

Automated checks cover seven firmware profiles, PHP syntax, simulated scoring/failures, browser button behavior, concurrent settings writes, official tagged API contracts and the real FPP 8.5.1 shell-startup regression. They do not run an FPP daemon, drive lights, test audio hardware, or validate every possible WLED model/effect combination. Browser highlights and USB/network video also need testing with the actual browser and source. Football score-delta inference remains a fallback when ESPN's scoring-play data is missing; long feed gaps can make the exact play type ambiguous.
