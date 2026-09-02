# Changelog

## [0.1.3] — 2026-09-02

### Added

- Self-updates from a private update server via the bundled Plugin Update Checker v5 library (`lib/plugin-update-checker/`); active only when `PRIVATE_UPDATE_SERVER` is defined in `wp-config.php`. Bespoke updater not previously present.

## [0.1.2] — 2026-09-02

### Changed

- Companion pin (`Upr_Host_Adapter_Upr_Pin`) now requires Universal Product
  Reviews **`0.9.0-rc.2`** (`06e406f4ba944b8360209322b3953d0a27f080bc`,
  tag `v0.9.0-rc.2`), was `0.3.0`. Aligns the DEV bind-mount validation with
  the current UPR release candidate. The adapter is independently versioned;
  this pin change is the reason for the `0.1.2` bump.
- `scripts/policy-check.sh`, `scripts/pilot-policy-check.sh`,
  `scripts/package-pin-check.sh` version pins bumped to `0.1.2`.

### Added

- Tag-triggered GitHub Release workflow (`.github/workflows/release.yml`) and a
  CI packaging-validation job; `scripts/build-release-package.sh` gains a
  `--worktree` mode. Generated ZIP/checksum are CI outputs, never committed.

## [0.1.1] — 2026-08-27

### Fixed

- Align repository `LICENSE` with the plugin header: **GPL-2.0-or-later** (was MIT contradiction at `v0.1.0`).

### Notes

- Corrective release. Annotated tag `v0.1.0` is retained and not moved.

## [0.1.0] — 2026-08-27

### Added

- Initial public extraction of the UPR host adapter as plugin slug `upr-host-adapter`.
- UPR packaged-metadata pin for `v0.3.0` / `b2abc2defc30fc023601593aa1720cbfdd0a4f3c`.
- Restrictive-only pilot invitation send policy, delivery adapter, support adapter, availability UX, DEV verification CLIs.
- Policy / name-leakage checks and offline package-pin + pilot decision tests.

### Notes

- New repository identity. Does not continue branded embedded-host version numbering.
- No branded legacy-option migration (fresh option key `upr_host_adapter_settings`).
