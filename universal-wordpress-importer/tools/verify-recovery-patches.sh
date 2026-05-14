#!/usr/bin/env bash
set -euo pipefail
umask 0022

usage() {
	cat <<'USAGE'
Usage: bash tools/verify-recovery-patches.sh [--recovery-dir=.autonomous-loop/tmp/recovery]

Verifies the read-only Git recovery patch artifacts by applying them to a
temporary clean HEAD export and comparing recovered file hashes against the
current dirty worktree.
USAGE
}

recovery_dir=".autonomous-loop/tmp/recovery"

while [ "$#" -gt 0 ]; do
	case "$1" in
		--recovery-dir=*)
			recovery_dir="${1#*=}"
			shift
			;;
		--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown option: $1" >&2
			usage >&2
			exit 2
			;;
	esac
done

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if git -C "$repo_root" diff --quiet HEAD -- && [ -z "$(git -C "$repo_root" ls-files --others --exclude-standard AGENTS.md tools/verify-recovery-patches.sh tools/verify-release-zip.php)" ]; then
	echo "Worktree is clean; recovery patches have been superseded by committed files."
	exit 0
fi

tracked_patch="$repo_root/$recovery_dir/2026-05-14-tracked-worktree.patch"
agents_patch="$repo_root/$recovery_dir/2026-05-14-untracked-agents.patch"
verifier_patch="$repo_root/$recovery_dir/2026-05-14-untracked-recovery-verifier.patch"
release_verifier_patch="$repo_root/$recovery_dir/2026-05-14-untracked-release-zip-verifier.patch"

if [ ! -f "$tracked_patch" ]; then
	echo "Tracked recovery patch is missing: $tracked_patch" >&2
	exit 1
fi

if [ ! -f "$agents_patch" ]; then
	echo "AGENTS.md recovery patch is missing: $agents_patch" >&2
	exit 1
fi

if [ ! -f "$verifier_patch" ]; then
	echo "Recovery verifier patch is missing: $verifier_patch" >&2
	exit 1
fi

if [ ! -f "$release_verifier_patch" ]; then
	echo "Release zip verifier patch is missing: $release_verifier_patch" >&2
	exit 1
fi

tmpdir="$(mktemp -d /tmp/importer-recovery-verify-XXXXXX)"
cleanup() {
	rm -rf "$tmpdir"
}
trap cleanup EXIT

git -C "$repo_root" archive HEAD | tar -x -C "$tmpdir"
cp "$tracked_patch" "$tmpdir/tracked.patch"
cp "$agents_patch" "$tmpdir/agents.patch"
cp "$verifier_patch" "$tmpdir/verifier.patch"
cp "$release_verifier_patch" "$tmpdir/release-verifier.patch"

git -C "$tmpdir" apply --check tracked.patch
git -C "$tmpdir" apply tracked.patch
git -C "$tmpdir" apply --check agents.patch
git -C "$tmpdir" apply agents.patch
git -C "$tmpdir" apply --check verifier.patch
git -C "$tmpdir" apply verifier.patch
git -C "$tmpdir" apply --check release-verifier.patch
git -C "$tmpdir" apply release-verifier.patch

mapfile -t changed_files < <(git -C "$repo_root" diff --name-only HEAD --)
changed_files+=("AGENTS.md")
changed_files+=("tools/verify-recovery-patches.sh")
changed_files+=("tools/verify-release-zip.php")

for file in "${changed_files[@]}"; do
	if [ ! -f "$repo_root/$file" ]; then
		echo "Current worktree file is missing after patch list selection: $file" >&2
		exit 1
	fi

	if [ ! -f "$tmpdir/$file" ]; then
		echo "Recovered file is missing: $file" >&2
		exit 1
	fi

	current_hash="$(sha256sum "$repo_root/$file" | awk '{print $1}')"
	recovered_hash="$(sha256sum "$tmpdir/$file" | awk '{print $1}')"

	if [ "$current_hash" != "$recovered_hash" ]; then
		echo "Recovered file hash mismatch: $file" >&2
		echo "current:   $current_hash" >&2
		echo "recovered: $recovered_hash" >&2
		exit 1
	fi
done

echo "Recovery patches apply cleanly and reproduce ${#changed_files[@]} file(s)."
sha256sum "$tracked_patch" "$agents_patch" "$verifier_patch" "$release_verifier_patch"
