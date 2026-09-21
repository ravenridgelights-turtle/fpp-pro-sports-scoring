#!/bin/bash
# nounset intentionally disabled for FPP 7-9 compatibility
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

# An upgrade can replace PHP while the existing worker still has the old code
# loaded in memory. Always restart the worker after install/update.
"${PLUGIN_DIR}/scripts/preStop.sh" || true
"${PLUGIN_DIR}/scripts/postStart.sh" || true

exit 0
