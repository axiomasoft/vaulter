#!/usr/bin/env bash
# Provide the two workspace repositories used by Composer in CI, including isolated consumers.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
workspace=$(dirname "$root")
packman_ref=b2fea80d85b1787f0a74d22c0f2563fd411ad132
azguard_ref=1718b337ac237a260c2876ecd5fafa5867c9a9b3

fetch_dependency() {
    local directory=$1 url=$2 ref=$3
    mkdir "$workspace/$directory"
    git -C "$workspace/$directory" init -q
    git -C "$workspace/$directory" fetch --quiet --depth=1 "$url" "$ref"
    git -C "$workspace/$directory" checkout -q -b main FETCH_HEAD
    local resolved
    resolved=$(git -C "$workspace/$directory" rev-parse --verify 'HEAD^{commit}')
    if [[ "$resolved" != "$ref" ]]; then
        echo "checkout-ecosystem: expected $directory commit $ref, got $resolved" >&2
        exit 1
    fi
}

fetch_dependency packman https://github.com/axiomasoft/packman.git "$packman_ref"
fetch_dependency azguard https://github.com/axiomasoft/azguard.git "$azguard_ref"

# Composer ignores repositories declared by dependencies. Make these same pinned
# packages available when a split package or its isolated fixture is the root.
for package in packman azguard; do
    definition=$(jq -ce --arg p "$package" --arg base "$workspace" '
        .repositories[] | select(.url == ("../" + $p + "/packages/*"))
        | .url = ($base + "/" + $p + "/packages/*")
    ' "$root/composer.json")
    composer config --global "repositories.$package" "$definition"
done
