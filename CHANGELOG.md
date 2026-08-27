# Changelog

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
