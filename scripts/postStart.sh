#!/bin/bash
set -u
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"
PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
PID_FILE="${PLUGIN_DIR}/sports-scoring.pid"
PLUGIN_LOG="${LOGDIR}/plugin-${PLUGIN_NAME}.log"

if [ -f "$PID_FILE" ]; then
    PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        exit 0
    fi
    rm -f "$PID_FILE"
fi

nohup /usr/bin/php "${PLUGIN_DIR}/nfl.php" >> "$PLUGIN_LOG" 2>&1 &
echo $! > "$PID_FILE"
exit 0
