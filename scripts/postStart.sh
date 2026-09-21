#!/bin/bash
# nounset intentionally disabled for FPP 7-9 compatibility
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
PID_FILE="${PLUGIN_DIR}/sports-scoring.pid"
PLUGIN_LOG="${LOGDIR}/plugin-${PLUGIN_NAME}.log"

# If a worker is already running, repair the PID file and leave it alone.
EXISTING_PID="$(pgrep -f "^/usr/bin/php ${PLUGIN_DIR}/nfl\\.php$" 2>/dev/null | head -n 1 || true)"
if [ -n "$EXISTING_PID" ]; then
    echo "$EXISTING_PID" > "$PID_FILE"
    exit 0
fi

rm -f "$PID_FILE"
touch "$PLUGIN_LOG"

nohup /usr/bin/php "${PLUGIN_DIR}/nfl.php" >> "$PLUGIN_LOG" 2>&1 &
PID=$!
echo "$PID" > "$PID_FILE"

sleep 1
if ! kill -0 "$PID" 2>/dev/null; then
    rm -f "$PID_FILE"
    exit 1
fi

exit 0
