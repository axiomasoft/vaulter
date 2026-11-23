#!/usr/bin/env bash
# Read-only check that every split repository of the release manifest carries <tag>,
# and that the tag points at the commit the split job just pushed.
#
# Usage: verify-split-tags.sh <tag> [expected-splits.json]
#        verify-split-tags.sh --excluded     # split repositories left out of this release
#   expected-splits.json: {"axiomasoft/vaulter-core": "<commit sha>", ...}
#   The manifest is schema vaulter.release/v2: every php entry needs a public|private channel and a tag_prefix, otherwise
#   this refuses (exit 2). The tag must match the declared prefix and a strict SemVer. Public tags such as
#   `v1.2.3` select the public PHP splits. An unknown prefix is refused.
# Env:   SPLIT_REMOTE_BASE (default https://github.com), RELEASE_MANIFEST (default release/packages.json)
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
manifest="${RELEASE_MANIFEST:-$root/release/packages.json}"
base="${SPLIT_REMOTE_BASE:-https://github.com}"

# Refuse a manifest that is not v2 or has a php entry without channel/tag_prefix, before any filtering.
manifest_ok="$(jq -r '(.schema == "vaulter.release/v2") and ([.php[] | ((.channel == "public" or .channel == "private") and (.tag_prefix | type == "string") and (.tag_prefix != ""))] | all)' "$manifest")"
if [[ "$manifest_ok" != "true" ]]; then
    echo "verify-split-tags: ${manifest} is not a valid vaulter.release/v2 manifest (channel/tag_prefix)" >&2
    exit 2
fi

# A package flagged excluded_unless_tagged ships only once the upstream it requires has a tag,
# i.e. while its composer.json still pins that upstream to a dev constraint it is left out.
excluded_splits() {
    local entries split path upstream constraint
    # Plain assignments, so an unreadable manifest or composer.json aborts instead of reading as "ship it".
    entries="$(jq -r '.php[] | select(.excluded_unless_tagged // false) | [.split, .path, .requires_tag] | @tsv' "$manifest")"
    while IFS=$'\t' read -r split path upstream; do
        [[ -n "$split" ]] || continue
        constraint="$(jq -r --arg u "$upstream" '.require[$u] // ""' "$root/$path/composer.json")"
        if [[ "$constraint" =~ ^dev-|@dev|-dev$ ]]; then
            echo "$split"
        fi
    done <<<"$entries"
}

if [[ "${1:-}" == "--excluded" ]]; then
    excluded_splits
    exit 0
fi

tag="${1:?usage: verify-split-tags.sh <tag> [expected-splits.json]}"
expected_file="${2:-}"
excluded="$(excluded_splits)"

# Splits of the release line this tag belongs to (strict SemVer after the entry's own prefix).
routed_splits="$(jq -r --arg t "$tag" '.php[] | select(.tag_prefix as $p | ($t | startswith($p)) and ($t[($p | length):] | test("^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)$"))) | .split' "$manifest")"
if [[ -z "$routed_splits" ]]; then
    echo "verify-split-tags: tag ${tag} fits no php tag_prefix of ${manifest}" >&2
    exit 2
fi

failures=()
checked=0

while IFS= read -r split; do
    if grep -qxF "$split" <<<"$excluded"; then
        echo "skip ${split}: excluded from this release"
        continue
    fi

    checked=$((checked + 1))
    refs="$(git ls-remote "${base}/${split}.git" "refs/tags/${tag}" "refs/tags/${tag}^{}")" || {
        failures+=("${split}: git ls-remote failed")
        continue
    }

    # An annotated tag lists the tag object and, peeled, the commit; a lightweight tag lists the commit.
    actual="$(awk -v p="refs/tags/${tag}^{}" '$2 == p { print $1 }' <<<"$refs")"
    [[ -n "$actual" ]] || actual="$(awk -v p="refs/tags/${tag}" '$2 == p { print $1 }' <<<"$refs")"

    if [[ -z "$actual" ]]; then
        failures+=("${split}: tag ${tag} is missing")
        continue
    fi

    if [[ -n "$expected_file" ]]; then
        expected="$(jq -r --arg s "$split" '.[$s] // empty' "$expected_file")"
        if [[ -z "$expected" ]]; then
            failures+=("${split}: no expected commit recorded by the split job")
        elif [[ "$expected" != "$actual" ]]; then
            failures+=("${split}: tag ${tag} points at ${actual}, expected ${expected}")
        fi
    fi
done <<<"$routed_splits"

if ((${#failures[@]})); then
    echo "verify-split-tags: ${#failures[@]} problem(s) for ${tag}:" >&2
    printf ' - %s\n' "${failures[@]}" >&2
    exit 1
fi

echo "verify-split-tags: ${checked} split repositories carry ${tag}"
