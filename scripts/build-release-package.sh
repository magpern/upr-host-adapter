#!/usr/bin/env bash
#
# Build a deterministic upr-host-adapter release package from an immutable Git ref.
# Default ref: annotated tag v0.1.1.
#
# Usage:
#   scripts/build-release-package.sh [ref]
#
# Outputs under builds/ (gitignored):
#   upr-host-adapter-<version>.zip
#   upr-host-adapter-<version>.SHA256SUMS
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REF="${1:-v0.1.1}"
OUT_DIR="${ROOT}/builds"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/upr-host-adapter-pkg.XXXXXX")"
cleanup() { rm -rf "${STAGE}"; }
trap cleanup EXIT

cd "${ROOT}"

if ! git rev-parse --verify "${REF}^{commit}" >/dev/null 2>&1; then
	echo "ERROR: unknown ref ${REF}" >&2
	exit 1
fi

COMMIT="$(git rev-parse "${REF}^{commit}")"
if git rev-parse -q --verify "refs/tags/${REF}" >/dev/null 2>&1; then
	TAG="${REF}"
else
	TAG="$(git describe --tags --exact-match "${COMMIT}" 2>/dev/null || true)"
	if [[ -z "${TAG}" ]]; then
		echo "ERROR: ref ${REF} (${COMMIT}) is not an exact tag; refuse ambiguous package" >&2
		exit 1
	fi
fi

HEADER_VERSION="$(
	git show "${COMMIT}:upr-host-adapter.php" \
		| sed -nE 's/^ \* Version:[[:space:]]*([0-9A-Za-z.-]+).*/\1/p' \
		| head -n1
)"
CONST_VERSION="$(
	git show "${COMMIT}:upr-host-adapter.php" \
		| sed -nE "s/.*UPR_HOST_ADAPTER_VERSION', '([^']+)'.*/\1/p" \
		| head -n1
)"
if [[ -z "${HEADER_VERSION}" || "${HEADER_VERSION}" != "${CONST_VERSION}" ]]; then
	echo "ERROR: version mismatch header=${HEADER_VERSION} const=${CONST_VERSION}" >&2
	exit 1
fi

EXPECTED_TAG="v${HEADER_VERSION}"
if [[ "${TAG}" != "${EXPECTED_TAG}" ]]; then
	echo "ERROR: tag ${TAG} does not match header version ${HEADER_VERSION} (expected ${EXPECTED_TAG})" >&2
	exit 1
fi

# Refuse superseded identities if someone points this script at the wrong tree.
if [[ "${HEADER_VERSION}" == "0.1.5" ]]; then
	echo "ERROR: version 0.1.5 is the superseded embedded host identity — refuse" >&2
	exit 1
fi

SLUG="upr-host-adapter"
ZIP_NAME="${SLUG}-${HEADER_VERSION}.zip"
ZIP_PATH="${OUT_DIR}/${ZIP_NAME}"
SUMS_PATH="${OUT_DIR}/${SLUG}-${HEADER_VERSION}.SHA256SUMS"
PKG_DIR="${STAGE}/${SLUG}"

echo "==> Building upr-host-adapter package"
echo "    ref:     ${REF}"
echo "    tag:     ${TAG}"
echo "    commit:  ${COMMIT}"
echo "    version: ${HEADER_VERSION}"
echo "    output:  ${ZIP_PATH}"

mkdir -p "${OUT_DIR}" "${PKG_DIR}"

echo "==> Exporting immutable tree (no .git)"
git archive --format=tar "${COMMIT}" | tar -C "${PKG_DIR}" -xf -

# Strip VCS / CI / tests from production package.
rm -rf "${PKG_DIR}/.git" "${PKG_DIR}/.github" "${PKG_DIR}/tests" \
	"${PKG_DIR}/phpunit.xml.dist" "${PKG_DIR}/.phpunit.result.cache"

echo "==> Creating zip"
rm -f "${ZIP_PATH}"
if command -v zip >/dev/null 2>&1; then
	(
		cd "${STAGE}"
		zip -qr "${ZIP_PATH}" "${SLUG}"
	)
else
	python3 - "${STAGE}" "${ZIP_PATH}" "${SLUG}" <<'PY'
import sys, zipfile
from pathlib import Path
stage, zip_path, slug = sys.argv[1:4]
root = Path(stage) / slug
with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
	for path in sorted(root.rglob("*")):
		if path.is_file():
			zf.write(path, path.relative_to(Path(stage)).as_posix())
print("wrote", zip_path)
PY
fi

echo "==> SHA-256 manifest"
(
	cd "${OUT_DIR}"
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "${ZIP_NAME}" > "${SUMS_PATH}"
	else
		shasum -a 256 "${ZIP_NAME}" > "${SUMS_PATH}"
	fi
	cat "${SUMS_PATH}"
)

python3 - "${ZIP_PATH}" "${SLUG}" <<'PY'
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
slug = sys.argv[2]
names = z.namelist()
gitty = [n for n in names if n == ".git" or n.startswith(".git/") or "/.git/" in n or n.endswith("/.git")]
if gitty:
	raise SystemExit("ERROR: package contains .git paths: " + ", ".join(gitty[:5]))
tops = {n.split("/", 1)[0] for n in names if n and not n.endswith("/")}
if tops != {slug}:
	raise SystemExit(f"ERROR: expected sole top-level dir {slug!r}, got {sorted(tops)}")
boot = f"{slug}/{slug}.php"
if boot not in names:
	raise SystemExit(f"ERROR: missing bootstrap {boot}")
print("OK: no .git; top-level", slug, "; entries", len(names))
PY

echo "==> Done: ${ZIP_PATH}"
