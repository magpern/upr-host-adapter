# UPR Host Adapter

Neutral **host-side** WordPress/WooCommerce adapter for [Universal Product Reviews](https://github.com/magpern/universal-product-reviews).

This plugin is **not** UPR core. It wires host delivery/support signals and a restrictive-only pilot invitation send policy into UPR’s public contracts.

## Boundaries

| In scope | Out of scope |
|----------|----------------|
| UPR version/tag/commit pin via packaged `release.meta.json` | UPR invitation/security core |
| Restrictive-only `upr_invitation_send_authorisation` pilot policy | Historical invitation backfill |
| MPCF → UPR delivery confirmation adapter | Production deploy configs, secrets, hostnames |
| Structured support signals (order ID + tags + optional ticket table) | Customer/order dumps, PII fixtures |
| DEV-only verification WP-CLI commands | Theme/storefront chrome unrelated to adapter contracts |

## Requirements

- WordPress 6.5+, PHP 8.1+, WooCommerce
- Universal Product Reviews **0.9.0-rc.2** @ commit `06e406f4ba944b8360209322b3953d0a27f080bc` (annotated tag `v0.9.0-rc.2`)
- Packaged UPR installs must include generic `release.meta.json` (`universal-product-reviews.package-meta/v1`)

## Version identity

Current release: **`0.1.1`** (annotated tag `v0.1.1`). First extraction release was `0.1.0`. Do not treat older embedded host package versions (e.g. legacy `0.1.5`) as this plugin.

## Release process

Canonical version source: the plugin header `Version:` line **and** the
`UPR_HOST_ADAPTER_VERSION` constant in `upr-host-adapter.php`, which must match.
`scripts/policy-check.sh` and `scripts/package-pin-check.sh` additionally pin
the literal version string and must be bumped in the same commit. The adapter
is **independently versioned** — its version is unrelated to the pinned
`universal-product-reviews` companion version (`REQUIRED_VERSION` in
`includes/class-upr-pin.php`, checked separately by `package-pin-check.sh`).

**Build locally**

```bash
git fetch --tags origin
bash scripts/build-release-package.sh v0.1.1        # immutable tag ref
bash scripts/build-release-package.sh --worktree    # current tree (CI PR mode)
```

Outputs under `builds/` (gitignored, **never committed**):
`upr-host-adapter-<version>.zip` + `upr-host-adapter-<version>.SHA256SUMS`.
Sole top-level directory is `upr-host-adapter/`.

**Validate locally**

```bash
( cd builds && sha256sum -c upr-host-adapter-<version>.SHA256SUMS )
unzip -l builds/upr-host-adapter-<version>.zip
```

**Cut a release**

1. Set the version in `upr-host-adapter.php` (header + constant) and in the
   grep pins in `scripts/policy-check.sh` / `scripts/package-pin-check.sh`;
   add a `CHANGELOG.md` entry. Merge to `main` (protected) and let CI go green.
2. Create and push an annotated tag `vX.Y.Z` on that `main` commit
   (`vX.Y.Z-rc.N` etc. → GitHub prerelease).
3. `.github/workflows/release.yml` re-runs the policy/pin/pilot gates, builds
   the package from the immutable tag ref, asserts the packaged version equals
   the tag, and publishes a GitHub Release with the ZIP + `.SHA256SUMS`
   attached.
4. Download both assets from the Release page and verify:
   `sha256sum -c upr-host-adapter-<version>.SHA256SUMS` before deployment.

**Recover from a failed release**: the job fails before `Create GitHub Release`
if any gate or the version check fails — fix the source on `main`, delete the
bad tag, and re-tag. If publish itself failed, delete the partial Release and
re-run the workflow (or re-push the tag). Generated ZIPs/checksums are CI
outputs only and must not be committed.

## Local / development setup (generic)

1. Check out this repository next to a WordPress + WooCommerce + UPR development stack.
2. Bind-mount or symlink the plugin directory as `wp-content/plugins/upr-host-adapter`.
3. Ensure UPR `0.3.0` is present with valid `release.meta.json` (or write it from a verified Git tag using UPR’s documented helper).
4. Activate **Universal Product Reviews first**, then **UPR Host Adapter** (`Requires Plugins: universal-product-reviews`).
5. Leave UPR invitation emails **disabled** unless your rehearsal explicitly requires otherwise.
6. Optional: map a support tickets table suffix via `upr_host_adapter_support_tickets_table` (empty = skip open-ticket checks).

### Focused checks (no site required for policy/pin scripts)

```bash
bash scripts/policy-check.sh
bash scripts/package-pin-check.sh
bash scripts/pilot-policy-check.sh
```

### DEV WP-CLI (development environment only)

```bash
wp upr-host-adapter verify-pilot-preflight
wp upr-host-adapter verify-dev-mail
```

Commands refuse when `wp_get_environment_type() !== 'development'`.

## Temporary public repository

This repository may be **public temporarily** so GitHub Actions can run. Do **not** add GitHub Actions secrets. Do **not** commit credentials, `.env` files, customer data, or private infrastructure identifiers.

## Production

This README intentionally contains **no** production deployment instructions or real configuration values. Production rollout, if any, is documented in private host operations plans outside this repository.

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
