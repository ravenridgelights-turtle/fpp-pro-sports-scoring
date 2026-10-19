# Pro Sports Scoring for FPP 8–10

Maintained fork of Ben Kools' original GPL-3.0 `fpp-nfl` plugin. The plugin inserts celebration sequences/effects for selected NFL, NCAA Football, NHL and MLB teams.

New installs use `modernize-fpp-espn`, selected by this branch's `pluginInfo.json`. FPP 8, 9 and 10 use the same maintained implementation; optional live video preview requires FPP 10.1+. FPP 7 is no longer supported. The plugin ID remains `fpp-nfl` so existing settings keep their identity.

The application source is in [modernize-fpp-espn](https://github.com/ravenridgelights-turtle/fpp-pro-sports-scoring/tree/modernize-fpp-espn). Existing `legacy-fpp7-9` installations retain that branch for updates. Apply the matching compatibility fixes to both active branches before publishing this install routing update.

See the [compatibility audit and update instructions](https://github.com/ravenridgelights-turtle/fpp-pro-sports-scoring/blob/modernize-fpp-espn/docs/FPP_COMPATIBILITY.md). The audit distinguishes verified source/API contracts and simulated regressions from the required on-device playback checks.

For a multi-player setup using the same celebration outputs, run one automatic scoring controller and choose its Local, Remote or Both destination. Remote sequence/audio files and overlay models must already be present on the destination player.

Derived from the original work by Ben Kools (koolsb), licensed under GPL-3.0. Maintained by ravenridgelights-turtle.
