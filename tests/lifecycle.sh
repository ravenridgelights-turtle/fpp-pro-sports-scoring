#!/bin/bash
set -e
FPP_CHECKOUT="$(cd "$1" && pwd)"
PLUGIN_CHECKOUT="$(cd "$(dirname "$0")/.." && pwd)"
TEST_DIR=$(mktemp -d)
trap 'rm -rf "$TEST_DIR"' EXIT
mkdir -p "$TEST_DIR/scripts"
git -C "$FPP_CHECKOUT" show 8.5.1:scripts/common > "$TEST_DIR/scripts/common"
# Reproduce the old failure against the actual 8.5.1 common file.
if env -u LD_LIBRARY_PATH FPPDIR="$TEST_DIR" bash -c 'set -u; . "$FPPDIR/scripts/common"' > "$TEST_DIR/old-output" 2>&1; then
    echo 'Expected original nounset failure' >&2; exit 1
fi
rg -q 'LD_LIBRARY_PATH.*unbound variable' "$TEST_DIR/old-output"
# The updated lifecycle must leave nounset disabled while sourcing old common.
env -u LD_LIBRARY_PATH FPPDIR="$TEST_DIR" bash -c '. "$FPPDIR/scripts/common"'
for script in "$PLUGIN_CHECKOUT"/scripts/*.sh; do bash -n "$script"; done
if rg -q '^set -u' "$PLUGIN_CHECKOUT/scripts/postStart.sh" "$PLUGIN_CHECKOUT/scripts/fpp_install.sh"; then
    echo 'nounset reintroduced before FPP common' >&2; exit 1
fi
# A stale recorded PID must not terminate an unrelated process.
mkdir -p "$TEST_DIR/plugin/scripts"
cp "$PLUGIN_CHECKOUT/scripts/preStop.sh" "$TEST_DIR/plugin/scripts/"
sleep 30 &
UNRELATED_PID=$!
echo "$UNRELATED_PID" > "$TEST_DIR/plugin/sports-scoring.pid"
bash "$TEST_DIR/plugin/scripts/preStop.sh"
kill -0 "$UNRELATED_PID"
kill "$UNRELATED_PID"
echo 'PASS lifecycle: actual FPP 8.5.1 startup regression, shell syntax, stale PID protection'
