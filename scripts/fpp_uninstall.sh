#!/bin/bash
set -u
: "${FPPDIR:=/opt/fpp}"
PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
"${PLUGIN_DIR}/scripts/preStop.sh" 2>/dev/null || true
exit 0
