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
