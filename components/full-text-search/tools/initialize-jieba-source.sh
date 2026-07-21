#!/usr/bin/env bash
set -euo pipefail

component_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
repo_root="$(cd "${component_root}/../.." && pwd -P)"
manifest="${component_root}/resources/runtime/jieba/manifest.json"
source_path="components/full-text-search/resources/sources/jieba"

file_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{ print $1 }'
        return
    fi

    shasum -a 256 "$1" | awk '{ print $1 }'
}

file_bytes() {
    wc -c < "$1" | tr -d '[:space:]'
}

gitlink="$(jq -er '.upstream.commit' "${manifest}")"
url="$(jq -er '.upstream.repository' "${manifest}")"
dictionary_path="$(jq -er '.upstream.dictionary_path' "${manifest}")"
license_path="$(jq -er '.upstream.license_path' "${manifest}")"

read -r mode indexed_gitlink stage indexed_path <<< "$(git -C "${repo_root}" ls-files --stage -- "${source_path}")"
test "${mode}" = 160000
test "${indexed_gitlink}" = "${gitlink}"
test "${stage}" = 0
test "${indexed_path}" = "${source_path}"
test "$(git -C "${repo_root}" config -f .gitmodules --get "submodule.${source_path}.path")" = "${source_path}"
test "$(git -C "${repo_root}" config -f .gitmodules --get "submodule.${source_path}.url")" = "${url}"

git -C "${repo_root}" submodule sync -- "${source_path}"
git -C "${repo_root}" -c protocol.version=2 submodule update --init --depth 1 -- "${source_path}"
test "$(git -C "${repo_root}/${source_path}" rev-parse HEAD)" = "${gitlink}"
test -z "$(git -C "${repo_root}/${source_path}" status --porcelain --untracked-files=all)"

for name in dictionary license; do
    if [[ "${name}" == dictionary ]]; then
        source_file="${repo_root}/${source_path}/${dictionary_path}"
    else
        source_file="${repo_root}/${source_path}/${license_path}"
    fi
    test "$(file_sha256 "${source_file}")" = "$(jq -er ".artifacts.${name}.sha256" "${manifest}")"
    test "$(file_bytes "${source_file}")" = "$(jq -er ".artifacts.${name}.bytes" "${manifest}")"
done

lookup_file="${component_root}/resources/runtime/jieba/$(jq -er '.artifacts.lookup.runtime_path' "${manifest}")"
test "$(file_sha256 "${lookup_file}")" = "$(jq -er '.artifacts.lookup.sha256' "${manifest}")"
test "$(file_bytes "${lookup_file}")" = "$(jq -er '.artifacts.lookup.bytes' "${manifest}")"
