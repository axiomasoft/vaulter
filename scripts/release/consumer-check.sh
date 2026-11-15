#!/usr/bin/env bash
# I14 clean-consumer harness (PLAN3 P4.19, knowledge/p4-design#matrices): one Vaulter package, built the way its
# split repository is consumed, in an EMPTY directory and with nothing but the packages it declares, then its fast
# sqlite set runs there. A package that imports a type of a sibling it does not require, or whose tests reach
# `../core/tests`, fails here instead of passing on the monorepo's shared vendor/.
#
# Usage: consumer-check.sh <package> [--dry-run] [--root=DIR] [--work-dir=DIR]
#   <package>        core | media | drive | documents | webhooks | collaboration (directory under packages/ or the Composer name)
#   --dry-run        print the plan (repositories, requires, command) after the static checks; install and run nothing
#   --root=DIR       the monorepo to read (default: this checkout); packages/, release/packages.json, tests/ come from it
#   --work-dir=DIR   where the consumer is built (default: <root>/var/consumer/<package>, wiped first)
# Env: PHP84_BIN (the PHP binary, default php8.4 then php), CONSUMER_COMPOSER (composer command, default composer).
# Exit: 0 passed (or planned) | 1 the package is not self-contained, or its tests / install failed | 2 usage.
#
# How: the package (without vendor/ and composer.lock) is copied to <work>/; the siblings it declares in require and
# require-dev, and their runtime closure, are copied to <work>/.deps/. composer.json of the copy gets path
# repositories on .deps/* ONLY (options.versions pins them to 1.0.0, `self.version` becomes 1.0.0), so the repository
# vendor/ is never on any path. `core` keeps its Pest harness at the repository root (phpunit.xml, tests/Pest.php), so
# for it the root files are staged next to packages/core. Real runs need the network (Packagist); the zip archives of a
# release are qualified by PLAN4, not here.
set -euo pipefail

self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$self/../.." && pwd)"
package=""
dry_run=0
work=""
version="1.0.0"

die() {
    echo "consumer-check: $*" >&2
    exit "${2:-1}"
}

usage() {
    sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//' >&2
    exit 2
}

for arg in "$@"; do
    case "$arg" in
        --dry-run) dry_run=1 ;;
        --root=*) root="$(cd "${arg#--root=}" 2>/dev/null && pwd)" || die "no such root: ${arg#--root=}" 2 ;;
        --work-dir=*) work="${arg#--work-dir=}" ;;
        -h | --help) usage ;;
        -*) die "unknown option ${arg}" 2 ;;
        *) [[ -z "$package" ]] || die "one package at a time (got ${package} and ${arg})" 2
           package="$arg" ;;
    esac
done
[[ -n "$package" ]] || usage

command -v jq >/dev/null || die "jq is required" 2
manifest="${RELEASE_MANIFEST:-$root/release/packages.json}"
[[ -f "$manifest" ]] || die "release manifest not found: ${manifest}" 2
# v2 manifest: every php entry carries a known channel and a tag_prefix, otherwise nothing is checked.
[[ "$(jq -r '(.schema == "vaulter.release/v2") and ([.php[] | ((.channel == "public" or .channel == "private") and ((.tag_prefix // "") != ""))] | all)' "$manifest")" == "true" ]] \
    || die "${manifest} is not a vaulter.release/v2 manifest with channel and tag_prefix on every php entry" 2

# --- resolve the package in the manifest ------------------------------------------------------------------------

short="${package#axioma-studio/vaulter-}"
entry="$(jq -c --arg s "$short" --arg n "$package" \
    '.php[] | select((.path == "packages/" + $s) or (.name == $n)) | select(.metapackage // false | not)' "$manifest" | head -n1)"
[[ -n "$entry" ]] || die "'${package}' is not a releasable PHP package of ${manifest} (the metapackage has nothing to test)" 2

name="$(jq -r .name <<<"$entry")"
path="$(jq -r .path <<<"$entry")"
short="$(basename "$path")"
if [[ "$(jq -r '.excluded_unless_tagged // false' <<<"$entry")" == true ]]; then
    die "${name} waits for an upstream tag ($(jq -r '.requires_tag' <<<"$entry")): an external gate, not a consumer package" 2
fi
[[ -f "$root/$path/composer.json" ]] || die "${root}/${path}/composer.json is missing" 2

# sibling name -> path, from the manifest (the metapackage is not a dependency anyone installs for code)
declare -A sibling_path=()
while IFS=$'\t' read -r sibling_name sibling_dir; do
    sibling_path["$sibling_name"]="$sibling_dir"
done < <(jq -r '.php[] | select(.metapackage // false | not) | [.name, .path] | @tsv' "$manifest")

# v2: the channel and release line of every package. They never relax the isolation rules below; they add one: a public
# package must not name anything of a private one.
declare -A channel_of=()
while IFS=$'\t' read -r sibling_name sibling_channel; do
    channel_of["$sibling_name"]="$sibling_channel"
done < <(jq -r '.php[] | [.name, .channel] | @tsv' "$manifest")
channel="${channel_of[$(jq -r .name <<<"$entry")]}"
tag_prefix="$(jq -r .tag_prefix <<<"$entry")"

declared_names() { # <composer.json> <sections...>: sibling packages named in those sections
    local file="$1" section dep
    shift
    for section in "$@"; do
        while IFS= read -r dep; do
            [[ -n "${sibling_path[$dep]:-}" ]] && echo "$dep"
        done < <(jq -r --arg s "$section" '(.[$s] // {}) | keys[]' "$file")
    done
}

# What the package itself declares (require + require-dev) and the runtime closure of it (only `require` is
# transitive: the dev section of a dependency is never installed).
mapfile -t declared < <(declared_names "$root/$path/composer.json" require require-dev | sort -u)
declare -A staged=()
queue=("${declared[@]}")
while ((${#queue[@]})); do
    current="${queue[0]}"
    queue=("${queue[@]:1}")
    [[ "$current" != "$name" ]] || continue
    [[ -z "${staged[$current]:-}" ]] || continue
    staged["$current"]=1
    [[ -f "$root/${sibling_path[$current]}/composer.json" ]] || die "${current}: ${sibling_path[$current]}/composer.json is missing"
    while IFS= read -r dep; do
        queue+=("$dep")
    done < <(declared_names "$root/${sibling_path[$current]}/composer.json" require)
done
mapfile -t closure < <(printf '%s\n' "${!staged[@]}" | sort)
[[ ${#closure[@]} -gt 0 && -n "${closure[0]}" ]] || closure=()

# --- static checks, before anything is installed ------------------------------------------------------------------

# Namespace ownership: Vaulter\<Segment>\ -> package (first PSR-4 segment of the package's own `autoload`); every other
# Vaulter\ name (Vaulter\Operations, Vaulter\Testing, ...) is the kernel's. Core is always required by the package.
declare -A segment_owner=()
for sibling_name in "${!sibling_path[@]}"; do
    while IFS= read -r prefix; do
        if [[ "$prefix" =~ ^Vaulter\\([A-Za-z0-9_]+)\\ ]]; then
            segment_owner["${BASH_REMATCH[1]}"]="$sibling_name"
        fi
    done < <(jq -r '(.autoload["psr-4"] // {}) | keys[]' "$root/${sibling_path[$sibling_name]}/composer.json" 2>/dev/null || true)
done
core_name="$(jq -r '.php[] | select(.path == "packages/core") | .name' "$manifest")"

declare -A allowed=()
allowed["$name"]=1
# Only what the package names itself: a class that arrives through a dependency's dependency installs today and breaks the
# day that dependency drops it, so a transitive sibling must be declared too.
for dep in "${declared[@]}"; do allowed["$dep"]=1; done

violations=()
check_reference() { # <file> <line> <match>
    local file="$1" line="$2" match="$3" segment owner relative
    segment="$(sed -E 's/^\\?Vaulter\\+//' <<<"$match" | sed -E 's/\\.*$//')"
    owner="${segment_owner[$segment]:-$core_name}"
    if [[ "$channel" == public && "${channel_of[$owner]:-}" == private ]]; then
        violations+=("${file#"$root/$path/"}:${line}: public package names ${match} of the private ${owner}")
    fi
    relative="${file#"$root/$path/"}"
    if [[ "$segment" == "Integration" || ( "$segment" == "Tests" && "$name" != "$core_name" ) ]]; then
        violations+=("${relative}:${line}: ${match} is the kernel's test namespace; a split package has no packages/core/tests (D14: own TestCase)")
    elif [[ -z "${allowed[$owner]:-}" ]]; then
        violations+=("${relative}:${line}: ${match} belongs to ${owner}, which ${name} does not declare in composer.json")
    fi
}

# Names are read from the PHP token stream (consumer-scan.php). src/: also a string that is a class name (a config
# default couples as hard as an import). tests/: code only - an architecture test names the namespaces it FORBIDS in
# strings and heredoc fixtures, which are not references.
php_bin="${PHP84_BIN:-$(command -v php8.4 || command -v php || true)}"
[[ -n "$php_bin" && -x "$php_bin" ]] || die "no PHP binary (set PHP84_BIN)" 2
scan() { # <flags/dirs...>
    "$php_bin" "$self/consumer-scan.php" "$@"
}
while IFS=: read -r file line match; do
    [[ -n "$match" ]] && check_reference "$file" "$line" "$match"
done < <(scan --strings "$root/$path/src"; scan "$root/$path/tests")

# A package must not read a sibling's tests or the root harness: `../core/tests`, `../../packages/x/tests`, ...
siblings_re=""
for sibling_name in "${!sibling_path[@]}"; do
    siblings_re+="${siblings_re:+|}$(basename "${sibling_path[$sibling_name]}")"
done
reach="(\\.\\./)+(packages/)?(${siblings_re})/tests"
for target in composer.json phpunit.xml tests; do
    [[ -e "$root/$path/$target" ]] || continue
    while IFS= read -r hit; do
        violations+=("${hit#"$root/$path/"}: reaches another package's tests (../<package>/tests)")
    done < <(grep -rnE "$reach" "$root/$path/$target" 2>/dev/null | cut -d: -f1,2 | sort -u || true)
done

# --- layout -----------------------------------------------------------------------------------------------------

# `repo` layout (tests/suites.json path_base): the Pest harness of the package lives at the repository root.
layout=package
if [[ -f "$root/tests/suites.json" ]] \
    && [[ "$(jq -r --arg s "$short" '.suites[$s].path_base // "package"' "$root/tests/suites.json")" == repo ]]; then
    layout=repo
fi
# openapi/ is the contract the HTTP tests validate every v1 response against: the testing kit of core reads it
# next to the directory that holds the core package (the repository root, or the consumer root over .deps/core).
harness_files=(openapi)
pest_args=(--ci --exclude-group=nofast)
if [[ "$layout" == repo ]]; then
    # phpunit.xml and tests/Pest.php of the core suite are generated below from the root ones, core bindings only.
    pest_args+=("--testsuite=${short}")
fi

[[ -n "$work" ]] || work="$root/var/consumer/${short}"

# --- plan -----------------------------------------------------------------------------------------------------------

echo "consumer-check: ${name} (${path}), layout=${layout}, channel=${channel}, tag_prefix=${tag_prefix}"
echo "  work dir:     ${work}"
echo "  declared:     ${declared[*]:-(none)}"
echo "  repositories: ${closure[*]:-(none)}"
for dep in "${closure[@]}"; do
    echo "    path  .deps/$(basename "${sibling_path[$dep]}")  ->  ${sibling_path[$dep]}  (${dep} @ ${version})"
done
echo "  on no path: the repository vendor/, packages/core/tests (core itself aside) and every sibling not listed above"
echo "  run: composer install --no-interaction --prefer-dist, then pest ${pest_args[*]}"

if ((${#violations[@]})); then
    {
        echo "consumer-check: ${name} is not self-contained (${#violations[@]} violation(s)):"
        printf ' - %s\n' "${violations[@]}"
    } >&2
    exit 1
fi

if ((dry_run)); then
    echo "consumer-check: dry run OK - ${name} references only what it declares"
    exit 0
fi

# --- build the consumer --------------------------------------------------------------------------------------------

composer_cmd="${CONSUMER_COMPOSER:-composer}"

case "$work" in
    "" | "/" | "$root" | "$root/") die "refusing to use ${work:-an empty path} as the work dir" 2 ;;
esac
rm -rf "$work"
mkdir -p "$work/.deps"

copy_tree() { # <source dir> <destination dir> [extra tar exclude]: the package without installed or generated state
    mkdir -p "$2"
    (cd "$1" && tar --exclude=./vendor --exclude=./composer.lock --exclude=./node_modules --exclude=./var --exclude=./.git ${3:+"$3"} -cf - .) \
        | (cd "$2" && tar -xf -)
}

if [[ "$layout" == repo ]]; then
    project="$work"
    copy_tree "$root/$path" "$work/$path"
    mkdir -p "$work/tests"
    "$php_bin" /dev/stdin "$root" "$work" <<'PHP'
<?php
[$unused, $root, $work] = $argv;
$xml = new DOMDocument;
$xml->load($root.'/phpunit.xml');
foreach (iterator_to_array($xml->getElementsByTagName('testsuite')) as $suite) {
    if ($suite->getAttribute('name') !== 'core') { $suite->parentNode->removeChild($suite); }
}
$xml->save($work.'/phpunit.xml');
$source = file_get_contents($root.'/tests/Pest.php');
$imports = [];
preg_match_all('/^use ([^;]+);$/m', $source, $matches);
foreach ($matches[1] as $name) {
    if (str_starts_with($name, 'Vaulter\\Load\\')) { continue; }
    if (str_starts_with($name, 'Vaulter\\Tests\\') && !is_file($root.'/packages/core/tests/'.str_replace('\\', '/', substr($name, strlen('Vaulter\\Tests\\'))).'.php')) { continue; }
    $imports[] = 'use '.$name.';';
}
preg_match_all('/^uses\(.*?;[ \t]*$/ms', $source, $matches);
$bindings = array_filter($matches[0], static fn ($line) => str_contains($line, '/packages/core/tests/') && !str_contains($line, '/tests/Integration/'));
file_put_contents($work.'/tests/Pest.php', "<?php\n\ndeclare(strict_types=1);\n\n".implode("\n", $imports)."\nTestGroups::bind(dirname(__DIR__).'/packages/core/tests', 'core');\n".implode("\n", $bindings)."\n");
PHP
    composer_source="$root/$path/composer.json"
else
    project="$work"
    copy_tree "$root/$path" "$work"
    composer_source="$root/$path/composer.json"
fi

for item in "${harness_files[@]}"; do
    [[ ! -e "$root/$item" ]] || cp -R "$root/$item" "$work/$item"
done

repositories='[]'
for dep in "${closure[@]}"; do
    dir="$(basename "${sibling_path[$dep]}")"
    # a dependency brings its code, never its tests: only the package under check has tests in the consumer
    copy_tree "$root/${sibling_path[$dep]}" "$work/.deps/$dir" --exclude=./tests
    repositories="$(jq -c --arg url ".deps/$dir" --arg n "$dep" --arg v "$version" \
        '. + [{type: "path", url: $url, options: {symlink: true, versions: {($n): $v}}}]' <<<"$repositories")"
done

# In the repo layout composer.json sits at the root while src/ and tests/ stay under the package path.
prefix=""
[[ "$layout" != repo ]] || prefix="${path}/"
jq --argjson repos "$repositories" --arg v "$version" --arg prefix "$prefix" '
    def pin: with_entries(if .value == "self.version" then .value = $v else . end);
    def under: with_entries(.value |= (if type == "array" then map($prefix + .) else $prefix + . end));
    .repositories = $repos
    | .require = ((.require // {}) | pin)
    | .["require-dev"] = ((.["require-dev"] // {}) | pin)
    | (if has("autoload") and (.autoload | has("psr-4")) then .autoload["psr-4"] |= under else . end)
    | (if has("autoload-dev") and (.["autoload-dev"] | has("psr-4")) then .["autoload-dev"]["psr-4"] |= under else . end)
    | .config = ((.config // {}) + {"lock": false})
' "$composer_source" >"$project/composer.json"

echo "consumer-check: composer install in ${project}"
(cd "$project" && COMPOSER_ROOT_VERSION="$version" "$php_bin" "$(command -v "$composer_cmd")" install --no-interaction --prefer-dist --no-progress) \
    || die "composer install failed for ${name}: a declared dependency does not resolve from the sibling set"

# The install must contain exactly the declared siblings: anything else got in through a path nobody declared.
vaulter_names="$(jq -c '[.php[].name]' "$manifest")"
mapfile -t installed < <(jq -r --argjson names "$vaulter_names" '.packages[].name | select(. as $n | $names | index($n))' "$project/vendor/composer/installed.json" | sort)
expected="$(printf '%s\n' "${closure[@]}" | sort)"
unexpected="$(comm -13 <(printf '%s\n' "$expected") <(printf '%s\n' "${installed[@]}") | grep -vxF "$name" || true)"
[[ -z "$unexpected" ]] || die "undeclared Vaulter packages were installed: ${unexpected//$'\n'/, }"

echo "consumer-check: pest ${pest_args[*]}"
(
    cd "$project"
    env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_DB_PER_PROCESS=0 \
        "$php_bin" -d memory_limit=1G -d variables_order=EGPCS vendor/bin/pest "${pest_args[@]}"
) || die "the fast set of ${name} failed in a clean consumer"

echo "consumer-check: ${name} passed in a clean consumer"
