#!/bin/bash
# FPP 8/9 common reads optional variables; do not enable nounset before sourcing it.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

/usr/bin/php "${PLUGIN_DIR}/scripts/check_compatibility.php" || exit 1

# An upgrade can replace PHP while the existing worker still has the old code
# loaded in memory. Always restart the worker after install/update.
bash "${PLUGIN_DIR}/scripts/preStop.sh" || exit 1
bash "${PLUGIN_DIR}/scripts/postStart.sh" || exit 1

exit 0
