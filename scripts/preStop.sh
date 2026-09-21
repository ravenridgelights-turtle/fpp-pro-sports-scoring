#!/bin/bash
# nounset intentionally disabled for FPP 7-9 compatibility

PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PID_FILE="${PLUGIN_DIR}/sports-scoring.pid"

stop_pid() {
    local pid="$1"
    [ -n "$pid" ] || return 0

    if kill -0 "$pid" 2>/dev/null; then
        kill "$pid" 2>/dev/null || true
        local i=0
        while kill -0 "$pid" 2>/dev/null && [ "$i" -lt 10 ]; do
            sleep 1
            i=$((i + 1))
        done
        if kill -0 "$pid" 2>/dev/null; then
            kill -9 "$pid" 2>/dev/null || true
        fi
    fi
}

# Stop the PID we recorded, if it is still valid.
if [ -f "$PID_FILE" ]; then
    PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    stop_pid "$PID"
fi

# Also stop orphaned workers left behind by a stale/missing PID file.
while IFS= read -r PID; do
    stop_pid "$PID"
done < <(pgrep -f "^/usr/bin/php ${PLUGIN_DIR}/nfl\\.php$" 2>/dev/null || true)

rm -f "$PID_FILE"
exit 0
