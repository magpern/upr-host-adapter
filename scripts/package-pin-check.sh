#!/usr/bin/env bash
# M3 packaged UPR pin checks for upr-host-adapter 0.1.1+ (no site deploy).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail() { echo "PACKAGE PIN FAILED: $*" >&2; exit 1; }

echo "==> Host 0.1.1 + package meta pin"
grep -q "Version: 0.1.1" "$ROOT/upr-host-adapter.php" || fail "plugin header version"
grep -q "PACKAGE_META_BASENAME = 'release.meta.json'" "$ROOT/includes/class-upr-pin.php" || fail "meta basename"
grep -q 'universal-product-reviews.package-meta/v1' "$ROOT/includes/class-upr-pin.php" || fail "meta schema"
if grep -nE "['\"]/\.git|/\.git'|/\.git\"|\.git/HEAD" "$ROOT/includes/class-upr-pin.php"; then
  fail "pin must not resolve commit via .git"
fi

echo "==> Package pin decision matrix (php)"
if command -v php >/dev/null 2>&1; then
  php "$ROOT/scripts/package-pin-check.php"
else
  docker run --rm -v "$ROOT":/src -w /src php:8.4-cli php scripts/package-pin-check.php
fi

echo "==> All package-pin checks passed"
