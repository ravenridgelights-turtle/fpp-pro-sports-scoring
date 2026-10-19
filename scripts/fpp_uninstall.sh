#!/bin/bash
# nounset intentionally disabled for FPP 8-10 compatibility
: "${FPPDIR:=/opt/fpp}"
PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
"${PLUGIN_DIR}/scripts/preStop.sh" 2>/dev/null || true
exit 0
