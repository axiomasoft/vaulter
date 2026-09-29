#!/usr/bin/env bash
 # Runs a command with PHP 8.4 as `php` on PATH, so Composer scripts and
 # `#!/usr/bin/env php` binaries use 8.4 even when the host default is older.
 # Usage: scripts/php84 --check | scripts/php84 <command> [args...]
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bin="${PHP84_BIN:-$(command -v php8.4 || true)}"

if [[ -z "$bin" || ! -x "$bin" ]]; then
    echo "php84: php8.4 binary not found (set PHP84_BIN)" >&2
    exit 1
fi

shim="$root/build/php84/bin"
mkdir -p "$shim"
ln -sf "$bin" "$shim/php"
export PATH="$shim:$PATH"

if [[ "${1:-}" == "--check" ]]; then
    version="$(php -r 'echo PHP_VERSION;')"
    if [[ "$version" != 8.4.* ]]; then
        echo "php84: expected PHP 8.4.x, got $version" >&2
        exit 1
    fi
    required=(ctype curl dom fileinfo gd gmp intl bcmath mbstring openssl pcntl pcov pdo_pgsql pdo_sqlite sqlite3 tokenizer xml zip)
    missing=()
    for ext in "${required[@]}"; do
        php -r "exit(extension_loaded('$ext') ? 0 : 1);" || missing+=("$ext")
    done
    if (( ${#missing[@]} )); then
        echo "php84: MISSING: ${missing[*]}" >&2
        # Several extensions ship in one apt package (dom → xml, pdo_sqlite → sqlite3).
        declare -A packages=()
        for ext in "${missing[@]}"; do
            case "$ext" in
                dom) packages[xml]=1 ;;
                pdo_pgsql) packages[pgsql]=1 ;;
                pdo_sqlite) packages[sqlite3]=1 ;;
                *) packages[$ext]=1 ;;
            esac
        done
        echo "php84: owner action: sudo apt install $(printf 'php8.4-%s ' $(printf '%s\n' "${!packages[@]}" | sort))" >&2
        exit 1
    fi
    echo "php84 OK: $version, extensions complete"
    exit 0
fi

exec "$@"
