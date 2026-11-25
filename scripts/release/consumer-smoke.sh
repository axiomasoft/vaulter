#!/usr/bin/env bash
# Installs the built artifacts into a fresh Laravel application, with no path repositories,
# and boots Vaulter the way a consumer would. Needs network (Packagist) and takes minutes.
#
# Usage: consumer-smoke.sh <artifacts-dir> <version>
set -euo pipefail

artifacts="$(cd "${1:?usage: consumer-smoke.sh <artifacts-dir> <version>}" && pwd)"
version="${2:?usage: consumer-smoke.sh <artifacts-dir> <version>}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

has_artifact() { compgen -G "$artifacts/axiomasoft-$1-$version.zip" >/dev/null; }

core_media_drive="axiomasoft/vaulter-core axiomasoft/vaulter-media axiomasoft/vaulter-drive"

# Scenario name -> packages required in the consumer.
names=("metapackage" "core+media+drive" "+documents" "+filament")
sets=(
    "axiomasoft/vaulter"
    "$core_media_drive"
    "$core_media_drive axiomasoft/vaulter-documents"
    "$core_media_drive axiomasoft/vaulter-documents axiomasoft/vaulter-filament"
)
# The bridge ships only when its artifact was built, i.e. once its upstream is tagged.
if has_artifact vaulter-azguard; then
    names+=("+azguard")
    sets+=("$core_media_drive axiomasoft/vaulter-documents axiomasoft/vaulter-azguard")
fi

app="$work/app"
composer create-project laravel/laravel "$app" "^13.0" --prefer-dist --no-interaction --no-scripts
cp "$app/composer.json" "$work/composer.base.json"

failed=()
for i in "${!sets[@]}"; do
    scenario="${names[$i]}"
    echo "=== scenario: $scenario"
    (
        cd "$app"
        # Every scenario starts from the pristine skeleton so a package from the previous one cannot leak in.
        cp "$work/composer.base.json" composer.json
        rm -rf vendor composer.lock bootstrap/cache/*.php database/database.sqlite
        touch database/database.sqlite

        composer config repositories.vaulter-artifacts "{\"type\":\"artifact\",\"url\":\"$artifacts\"}"
        composer config minimum-stability dev
        composer config prefer-stable true
        read -r -a packages <<<"${sets[$i]}"
        composer require "${packages[@]/%/:$version}" --no-update --no-interaction
        composer update --prefer-dist --no-interaction
        composer check-platform-reqs
        php artisan package:discover --ansi

        export DB_CONNECTION=sqlite DB_DATABASE="$app/database/database.sqlite"
        php artisan vaulter:install --no-interaction --migrate
        php artisan config:cache
        php artisan route:cache
        # Exit 0 or warnings only: SQLite is acceptable outside production.
        php artisan vaulter:doctor --json
        php artisan config:clear
        php artisan route:clear
    ) || failed+=("$scenario")
done

if ((${#failed[@]})); then
    echo "consumer-smoke: failed scenarios: ${failed[*]}" >&2
    exit 1
fi
echo "consumer-smoke: ${#sets[@]} scenario(s) passed for $version"
