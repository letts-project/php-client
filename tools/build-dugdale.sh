#!/usr/bin/env sh
# Rebuild tools/dugdale from the letts Go source so integration tests always
# run against the CURRENT daemon wire-contract (never a stale committed binary).
#
# Source location resolution (first wins):
#   1. $LETTS_SRC                     (explicit override)
#   2. ../letts relative to this repo (default sibling checkout)
#
# If the Go toolchain or the letts source is unavailable, this is a no-op (exit
# 0) — the integration fixture will then skip if tools/dugdale is missing. A
# compile failure in letts is surfaced loudly (non-zero exit).
set -e

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$REPO_ROOT/tools/dugdale"
SRC="${LETTS_SRC:-$(cd "$REPO_ROOT/.." && pwd)/letts}"

if ! command -v go >/dev/null 2>&1; then
    echo "[build-dugdale] go toolchain not found; using existing tools/dugdale (or tests skip)." >&2
    exit 0
fi
if [ ! -d "$SRC/cmd/dugdale" ]; then
    echo "[build-dugdale] letts source not found at '$SRC' (set LETTS_SRC); using existing tools/dugdale." >&2
    exit 0
fi

echo "[build-dugdale] building dugdale from $SRC -> $OUT" >&2
( cd "$SRC" && go build -o "$OUT" ./cmd/dugdale )
echo "[build-dugdale] ok" >&2
