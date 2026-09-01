#!/usr/bin/env bash
#
# Build a deterministic upr-host-adapter release package.
#
# Two modes:
#   1. Immutable-ref mode (default, used by release.yml):
#        scripts/build-release-package.sh v0.1.1
#      Packages the tree at an exact annotated tag and asserts
#      tag == "v<header version>" == "v<UPR_HOST_ADAPTER_VERSION>".
#
#   2. Worktree mode (CI pull-request packaging validation, no tag yet):
#        scripts/build-release-package.sh --worktree
#      Packages the current working tree, derives the version from the
#      plugin header, and still asserts header == constant and the
#      structural package invariants. It does NOT assert a tag.
#
# Outputs under builds/ (gitignored, never committed):
#   upr-host-adapter-<version>.zip
#   upr-host-adapter-<version>.SHA256SUMS
#
# Safe to run repeatedly. Non-zero exit on any packaging error.
#
set -Eeuo pipefail

SLUG="upr-host-adapter"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${ROOT}/builds"
STAGE="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-pkg.XXXXXX")"
cleanup() { rm -rf "${STAGE}"; }
trap cleanup EXIT

cd "${ROOT}"

usage() { sed -n '2,32p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

MODE="ref"
REF="v0.1.1"
case "${1:-}" in
	-h|--help) usage 0 ;;
	--worktree) MODE="worktree" ;;
	"") : ;;
	*) REF="$1" ;;
esac

for tool in git tar sed head; do
	command -v "$tool" >/dev/null 2>&1 || { echo "ERROR: required tool not found: $tool" >&2; exit 1; }
done
command -v zip >/dev/null 2>&1 || command -v python3 >/dev/null 2>&1 || {
	echo "ERROR: need either 'zip' or 'python3' to build the archive" >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || command -v shasum >/dev/null 2>&1 || {
	echo "ERROR: need either 'sha256sum' or 'shasum' for the checksum" >&2; exit 1; }

[ -f "${ROOT}/${SLUG}.php" ] || { echo "ERROR: main plugin file ${SLUG}.php missing" >&2; exit 1; }

read_version() { # <php-source-on-stdin>
	local src; src="$(cat)"
	HEADER_VERSION="$(printf '%s\n' "$src" | sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([0-9A-Za-z.-]+).*/\1/p' | head -n1)"
	CONST_VERSION="$(printf '%s\n' "$src" | sed -nE "s/.*UPR_HOST_ADAPTER_VERSION',[[:space:]]*'([^']+)'.*/\1/p" | head -n1)"
}

if [[ "${MODE}" == "worktree" ]]; then
	COMMIT="$(git rev-parse --verify HEAD 2>/dev/null || echo 'WORKTREE')"
	TAG="(none — worktree mode)"
	read_version < "${ROOT}/${SLUG}.php"
	SOURCE_DESC="working tree"
else
	if ! git rev-parse --verify "${REF}^{commit}" >/dev/null 2>&1; then
		echo "ERROR: unknown ref ${REF}" >&2; exit 1
	fi
	COMMIT="$(git rev-parse "${REF}^{commit}")"
	if git rev-parse -q --verify "refs/tags/${REF}" >/dev/null 2>&1; then
		TAG="${REF}"
	else
		TAG="$(git describe --tags --exact-match "${COMMIT}" 2>/dev/null || true)"
		[[ -n "${TAG}" ]] || { echo "ERROR: ref ${REF} (${COMMIT}) is not an exact tag; refuse ambiguous package" >&2; exit 1; }
	fi
	read_version < <(git show "${COMMIT}:${SLUG}.php")
	SOURCE_DESC="immutable ref ${REF}"
fi

if [[ -z "${HEADER_VERSION}" || "${HEADER_VERSION}" != "${CONST_VERSION}" ]]; then
	echo "ERROR: version mismatch header=${HEADER_VERSION:-<empty>} const=${CONST_VERSION:-<empty>}" >&2
	exit 1
fi

# Refuse superseded embedded host identities if pointed at the wrong tree.
if [[ "${HEADER_VERSION}" == "0.1.5" ]]; then
	echo "ERROR: version 0.1.5 is the superseded embedded host identity — refuse" >&2
	exit 1
fi

if [[ "${MODE}" == "ref" ]]; then
	EXPECTED_TAG="v${HEADER_VERSION}"
	[[ "${TAG}" == "${EXPECTED_TAG}" ]] || {
		echo "ERROR: tag ${TAG} does not match header version ${HEADER_VERSION} (expected ${EXPECTED_TAG})" >&2; exit 1; }
fi

ZIP_NAME="${SLUG}-${HEADER_VERSION}.zip"
ZIP_PATH="${OUT_DIR}/${ZIP_NAME}"
SUMS_PATH="${OUT_DIR}/${SLUG}-${HEADER_VERSION}.SHA256SUMS"
PKG_DIR="${STAGE}/${SLUG}"

echo "==> Building ${SLUG} package"
echo "    source:  ${SOURCE_DESC}"
echo "    tag:     ${TAG}"
echo "    commit:  ${COMMIT}"
echo "    version: ${HEADER_VERSION}"
echo "    output:  ${ZIP_PATH}"

mkdir -p "${OUT_DIR}" "${PKG_DIR}"

echo "==> Exporting tree (no .git)"
if [[ "${MODE}" == "worktree" ]]; then
	# Only Git-tracked files, honouring .gitignore, from the working tree.
	git -C "${ROOT}" ls-files -z | tar --null -T - -cf - | tar -C "${PKG_DIR}" -xf -
else
	git archive --format=tar "${COMMIT}" | tar -C "${PKG_DIR}" -xf -
fi

# Strip VCS / CI / dev-only tooling from the production package.
rm -rf \
	"${PKG_DIR}/.git" "${PKG_DIR}/.github" "${PKG_DIR}/scripts" \
	"${PKG_DIR}/tests" "${PKG_DIR}/builds" \
	"${PKG_DIR}/.gitignore" "${PKG_DIR}/.gitattributes" "${PKG_DIR}/.editorconfig" \
	"${PKG_DIR}/phpunit.xml.dist" "${PKG_DIR}/.phpunit.result.cache"

echo "==> Creating zip"
rm -f "${ZIP_PATH}"
if command -v zip >/dev/null 2>&1; then
	( cd "${STAGE}" && zip -qr "${ZIP_PATH}" "${SLUG}" )
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

python3 - "${ZIP_PATH}" "${SLUG}" "${HEADER_VERSION}" <<'PY'
import sys, zipfile
zip_path, slug, version = sys.argv[1:4]
z = zipfile.ZipFile(zip_path)
names = z.namelist()
gitty = [n for n in names if n == ".git" or n.startswith(".git/") or "/.git/" in n or n.endswith("/.git")]
if gitty:
	raise SystemExit("ERROR: package contains .git paths: " + ", ".join(gitty[:5]))
forbidden = [n for n in names if any(
	seg in ("tests", "scripts", ".github") for seg in n.split("/"))]
if forbidden:
	raise SystemExit("ERROR: package contains forbidden dev paths: " + ", ".join(forbidden[:5]))
tops = {n.split("/", 1)[0] for n in names if n and not n.endswith("/")}
if tops != {slug}:
	raise SystemExit(f"ERROR: expected sole top-level dir {slug!r}, got {sorted(tops)}")
boot = f"{slug}/{slug}.php"
if boot not in names:
	raise SystemExit(f"ERROR: missing bootstrap {boot}")
src = z.read(boot).decode("utf-8", "replace")
if f"Version: {version}" not in src and f"Version:{version}" not in src:
	raise SystemExit(f"ERROR: packaged {boot} does not declare Version {version}")
print(f"OK: sole top-level {slug!r}; bootstrap present; Version {version}; {len(names)} entries")
PY

echo "==> Done: ${ZIP_PATH}"
echo "    ${SUMS_PATH}"
