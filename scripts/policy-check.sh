#!/usr/bin/env bash
# Static policy + name-leakage checks for upr-host-adapter (no site deploy).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail() { echo "POLICY FAILED: $*" >&2; exit 1; }

echo "==> Version / pin constants"
grep -q "Version: 0.1.1" "$ROOT/upr-host-adapter.php" || fail "plugin header version"
grep -q "License: GPL-2.0-or-later" "$ROOT/upr-host-adapter.php" || fail "plugin header license"
grep -q "SPDX-License-Identifier: GPL-2.0-or-later" "$ROOT/LICENSE" || fail "LICENSE SPDX"
if grep -qiE '^MIT License$' "$ROOT/LICENSE"; then fail "LICENSE must not be MIT"; fi
grep -q "UPR_HOST_ADAPTER_VERSION', '0.1.1'" "$ROOT/upr-host-adapter.php" || fail "version constant"
grep -q "REQUIRED_VERSION = '0.3.0'" "$ROOT/includes/class-upr-pin.php" || fail "UPR pin version"
grep -q "REQUIRED_COMMIT = 'b2abc2defc30fc023601593aa1720cbfdd0a4f3c'" "$ROOT/includes/class-upr-pin.php" || fail "UPR pin commit"
grep -q "REQUIRED_TAG = 'v0.3.0'" "$ROOT/includes/class-upr-pin.php" || fail "UPR pin tag"
grep -q 'InvitationAuthorisation' "$ROOT/includes/class-upr-pin.php" || fail "InvitationAuthorisation check"
grep -q "PACKAGE_META_BASENAME = 'release.meta.json'" "$ROOT/includes/class-upr-pin.php" || fail "package meta"
grep -q 'universal-product-reviews.package-meta/v1' "$ROOT/includes/class-upr-pin.php" || fail "package schema"

echo "==> No brand / private infrastructure leakage"
BRAND="$(printf '%s%s' bio pentra)"
BRAND_RE="${BRAND}|B${BRAND:1}|$(printf '%s' BIO)$(printf '%s' PENTRA)"
if grep -RIn --exclude-dir=.git -E "${BRAND_RE}" "$ROOT"; then
  fail "brand substring found"
fi
PRIV_RE="$(printf '%s' '173')\.$(printf '%s' '212')\.|"
PRIV_RE+="$(printf '%s' '169')\.$(printf '%s' '40')\.|"
PRIV_RE+="dev\\.${BRAND}\\.eu|www\\.${BRAND}\\.eu|"
PRIV_RE+="/opt/${BRAND}|/opt/host/$(printf '%s' proxy)"
if grep -RIn --exclude-dir=.git -E "${PRIV_RE}" "$ROOT"; then
  fail "private infrastructure identifier found"
fi
if grep -RIn --exclude-dir=.git -E '(API_TOKEN|IMAP_PASS|MYSQL_PASSWORD)\s*=\s*\S+' "$ROOT"; then
  fail "credential-like assignment found"
fi
if find "$ROOT" \( -name '.env' -o -name '.env.*' \) ! -name '.env.example' | grep -q .; then
  fail ".env file present"
fi

echo "==> No WooCommerce Internal APIs"
if grep -RIn --include='*.php' -E 'Internal\\OrderReviews|Automattic\\WooCommerce\\Internal\\' "$ROOT"; then
  fail "Internal WooCommerce API reference found"
fi

echo "==> No comments_open / host preprocess_comment gates"
if grep -RIn --include='*.php' -E "add_filter\s*\(\s*['\"]comments_open" "$ROOT/includes" "$ROOT/upr-host-adapter.php"; then
  fail "comments_open filter found"
fi
if grep -RIn --include='*.php' -E "add_filter\s*\(\s*['\"]preprocess_comment" "$ROOT/includes" "$ROOT/upr-host-adapter.php"; then
  fail "preprocess_comment registration found"
fi

echo "==> Restrictive pilot policy wired"
grep -q 'Upr_Host_Adapter_Invitation_Send_Policy::register' "$ROOT/includes/class-plugin.php" || fail "policy not registered"
grep -q "upr_invitation_send_authorisation" "$ROOT/includes/class-invitation-send-policy.php" || fail "missing UPR filter"
grep -q "OPTION_KEY = 'upr_host_adapter_settings'" "$ROOT/includes/class-options.php" || fail "option key"

echo "==> PHP syntax"
if command -v php >/dev/null 2>&1; then
  find "$ROOT" -name '*.php' -print0 | while IFS= read -r -d '' f; do php -l "$f" >/dev/null; done
else
  docker run --rm -v "$ROOT":/src -w /src php:8.4-cli bash -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
fi

echo "==> All policy checks passed"
