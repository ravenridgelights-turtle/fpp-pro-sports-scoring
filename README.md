# Pro Sports Scoring Plugin for FPP

Maintained fork of Ben Kools' original `fpp-nfl` plugin. It supports NFL, NCAA Football, NHL, and MLB and plays local FPP celebration sequences when a selected team scores or wins.

## How celebration playback works

The setup page lets the user select ordinary `.fseq` files. The plugin automatically creates small one-item FPP playlists with names such as:

- `PSS_NFL_KC_Touchdown`
- `PSS_NFL_KC_FieldGoal`
- `PSS_NFL_KC_Win`
- `PSS_NCAA_UGA_Touchdown`
- `PSS_NHL_BOS_Score`
- `PSS_MLB_ATL_Score`

Those generated playlists are marked as plugin-managed and are updated when the selected team or sequence changes. When a scoring event occurs, the plugin uses FPP's `Insert Playlist Immediate` command. This lets the celebration interrupt the active show and lets FPP return to the show after the inserted playlist finishes.

## Sports data

Sports data is retrieved from ESPN's public Site API. The ESPN Site API is unofficial and can change without notice, so the plugin includes request validation and failure-safe behavior intended to preserve the last known game state when ESPN is unavailable.

## Development notes

See `MAINTAINER_NOTES.md` for the current architecture, generated-playlist rules, testing checklist, and maintenance notes.

## License and attribution

This maintained version is derived from the original work by Ben Kools (koolsb) and remains licensed under GPL-3.0.

## Supported FPP versions

The shared release **2026.10.19.1** supports **FPP 8, 9 and 10**. Scoring, manual celebration buttons, FSEQ/media helpers, WLED and ticker use the common command contract; optional live video preview requires FPP 10.1+ and an available video API. New installs use `modernize-fpp-espn`; existing `legacy-fpp7-9` installations retain their branch for updates. FPP 7 is no longer supported.

See the [compatibility audit, update instructions and hardware checklist](docs/FPP_COMPATIBILITY.md). For a two-player setup, use one automatic scoring controller for the same outputs and configure its Remote/Both target instead of leaving two independent scorers enabled.

**FPP 10.3 recheck (October 21, 2026):** no breaking change was found in the maintained plugin's API contracts. The existing runtime release remains **2026.10.19.1**; this audit adds tests and documentation, with no playback or settings changes. See the [10.3 findings](docs/FPP_COMPATIBILITY.md#fpp-103-recheck--october-21-2026). Source checks and simulated API tests do not replace an on-device celebration/interrupt/resume test after updating FPP.

## Regression checks

```bash
for version in 8.0 8.5.1 9.0 9.4 10.0 10.1.2 10.2 10.3; do
  PSS_TEST_FPP_VERSION="$version" php tests/run.php || exit 1
done
php tests/fpp-10.3.php
node tests/status-buttons.mjs
python3 tests/check_fpp_contracts.py /path/to/FalconChristmas/fpp
bash tests/lifecycle.sh /path/to/FalconChristmas/fpp
```

The FPP checkout must contain the release tags above. Profile tests use simulated responses; source checks read official release code. Physical playback and interrupt/resume still require the on-device checklist.
