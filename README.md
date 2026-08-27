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
- Universal Product Reviews **0.3.0** @ commit `b2abc2defc30fc023601593aa1720cbfdd0a4f3c` (annotated tag `v0.3.0`)
- Packaged UPR installs must include generic `release.meta.json` (`universal-product-reviews.package-meta/v1`)

## Version identity

This repository’s first release is **`0.1.0`**. It supersedes the former embedded host candidate versioning under a different plugin identity. Do not treat older branded package versions as this plugin.

## Local / development setup (generic)

1. Check out this repository next to a WordPress + WooCommerce + UPR development stack.
2. Bind-mount or symlink the plugin directory as `wp-content/plugins/upr-host-adapter`.
3. Ensure UPR `0.3.0` is present with valid `release.meta.json` (or write it from a verified Git tag using UPR’s documented helper).
4. Activate **UPR Host Adapter**.
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
