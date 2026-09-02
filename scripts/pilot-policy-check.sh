#!/usr/bin/env bash
# pilot send-policy static checks for upr-host-adapter (no DEV deploy).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail() { echo "PILOT POLICY FAILED: $*" >&2; exit 1; }

echo "==> Host version"
grep -q "Version: 0.1.3" "$ROOT/upr-host-adapter.php" || fail "plugin header version"
grep -q "UPR_HOST_ADAPTER_VERSION', '0.1.3'" "$ROOT/upr-host-adapter.php" || fail "host version constant"

echo "==> Pilot policy class wired"
test -f "$ROOT/includes/class-invitation-send-policy.php" || fail "missing invitation send policy"
grep -q 'class-invitation-send-policy.php' "$ROOT/upr-host-adapter.php" || fail "policy not required from bootstrap"
grep -q 'Upr_Host_Adapter_Invitation_Send_Policy::register' "$ROOT/includes/class-plugin.php" || fail "policy not registered"
grep -q "upr_invitation_send_authorisation" "$ROOT/includes/class-invitation-send-policy.php" || fail "missing UPR filter"

echo "==> Fail-closed defaults in options"
grep -q "pilot_invitation_sending_authorised' => false" "$ROOT/includes/class-options.php" \
  || grep -q 'pilot_invitation_sending_authorised'\'' => false' "$ROOT/includes/class-options.php" \
  || grep -q "pilot_invitation_sending_authorised" "$ROOT/includes/class-options.php" || fail "missing pilot authorised option"
grep -q "pilot_order_id_allowlist" "$ROOT/includes/class-options.php" || fail "missing allowlist option"
grep -q "=> array()," "$ROOT/includes/class-options.php" || fail "expected empty-array defaults present"

echo "==> Admin UI labels + dependency notice"
grep -q 'Pilot invitation sending authorised' "$ROOT/includes/class-admin-settings.php" || fail "missing authorised UI"
grep -q 'Pilot order-ID allowlist' "$ROOT/includes/class-admin-settings.php" || fail "missing allowlist UI"
grep -q 'InvitationAuthorisation' "$ROOT/includes/class-admin-settings.php" || fail "missing contract dependency notice"
grep -q 'Temporary limited-rollout' "$ROOT/includes/class-admin-settings.php" || fail "missing temporary-rollout copy"

echo "==> Must not duplicate UPR master controls"
if grep -RIn --include='*.php' -E "upr_invitation_emails_enabled|upr_invitation_emergency_pause" "$ROOT/includes" "$ROOT/upr-host-adapter.php"; then
  fail "host must not own UPR master enable/pause option keys"
fi

echo "==> Decision matrix (php)"
if command -v php >/dev/null 2>&1; then
  php "$ROOT/scripts/pilot-policy-check.php"
else
  echo "(php not on PATH — running via docker)"
  docker run --rm -v "$ROOT":/src -w /src php:8.4-cli php scripts/pilot-policy-check.php
fi

echo "==> PHP syntax"
if command -v php >/dev/null 2>&1; then
  find "$ROOT" -name '*.php' -print0 | while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null
  done
else
  docker run --rm -v "$ROOT":/src -w /src php:8.4-cli bash -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null' || fail "php -l failed"
fi

echo "==> All pilot host policy checks passed"
