#!/bin/sh
set -u
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"
PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

# Start the worker immediately. The hook is idempotent, so this is also safe on reinstall/update.
"${PLUGIN_DIR}/scripts/postStart.sh" || true
exit 0
