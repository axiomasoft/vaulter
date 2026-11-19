#!/usr/bin/env bash
# Provide the two workspace repositories used by Composer in CI, including isolated consumers.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
workspace=$(dirname "$root")
packman_version=$(jq -er '.repositories[] | select(.url == "../packman/packages/*") | .options.versions["axiomasoft/packman"]' "$root/composer.json")
azguard_ref=74886a7f2c5fac7a17c9f9adf871ec2ace96830e

fetch_dependency() {
    local directory=$1 url=$2 ref=$3
    mkdir "$workspace/$directory"
    git -C "$workspace/$directory" init -q
    git -C "$workspace/$directory" fetch --quiet --depth=1 "$url" "$ref"
    git -C "$workspace/$directory" checkout -q -b main FETCH_HEAD
}

fetch_dependency packman https://github.com/axiomasoft/packman.git "refs/tags/v$packman_version"
fetch_dependency azguard https://github.com/axiomasoft/azguard-private.git "$azguard_ref"

# Composer ignores repositories declared by dependencies. Make these same pinned
# packages available when a split package or its isolated fixture is the root.
for package in packman azguard; do
    definition=$(jq -ce --arg p "$package" --arg base "$workspace" '
        .repositories[] | select(.url == ("../" + $p + "/packages/*"))
        | .url = ($base + "/" + $p + "/packages/*")
    ' "$root/composer.json")
    composer config --global "repositories.$package" "$definition"
done
