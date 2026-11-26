#!/usr/bin/env bash
# Clean documents-basic consumer: public ZIP/tgz into a fresh Laravel host, then the Drive → Tiptap journey.
# Requires Bash, Git, Python 3, curl, PHP 8.4 with zip and sqlite (or pdo_pgsql for PostgreSQL), Composer 2, Node >= 26.13.1 and npm.
# Set E2E_DB_CONNECTION=pgsql (or DB_CONNECTION=pgsql) with DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD for PostgreSQL 18.7+.
# PACKMAN_DIR defaults to the sibling checkout. BROWSER_BIN skips the Playwright browser download.
# VAULTER_REF defaults to HEAD so the candidate is this checkout, not the historical FlexCms pin.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
src=$(git -C "$here" rev-parse --show-toplevel)
e2e_db_connection="${E2E_DB_CONNECTION:-${DB_CONNECTION:-sqlite}}"
e2e_db_host="${DB_HOST:-127.0.0.1}"
e2e_db_port="${DB_PORT:-5432}"
e2e_db_database="${DB_DATABASE:-}"
e2e_db_username="${DB_USERNAME:-vaulter}"
e2e_db_password="${DB_PASSWORD:-secret}"
case "$e2e_db_connection" in
    sqlite|pgsql) ;;
    *)
        echo "Unsupported E2E DB connection: $e2e_db_connection (expected sqlite or pgsql)" >&2
        exit 2
        ;;
esac
if [[ "$e2e_db_connection" == pgsql ]]; then
    php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || {
        echo "PHP extension pdo_pgsql is required when DB_CONNECTION=pgsql" >&2
        exit 1
    }
    if [[ -z "$e2e_db_database" ]]; then
        echo "DB_DATABASE is required when DB_CONNECTION=pgsql" >&2
        exit 1
    fi
fi
# Chromium aborts when its singleton socket exceeds sockaddr_un.sun_path (108 bytes).
# A deep TMPDIR produces that path, so this run keeps its own short temp root.
export TMPDIR=/tmp
node -e 'const p=process.versions.node.split(".").map(Number); if (p[0]<26 || (p[0]===26 && (p[1]<13 || (p[1]===13 && p[2]<1)))) { console.error("Node >= 26.13.1 required, got "+process.versions.node); process.exit(1) }'
packman="${PACKMAN_DIR:-$src/../packman}"
vaulter_ref="${VAULTER_REF:-HEAD}"
packman_version=$(php -r '$c = json_decode((string) file_get_contents($argv[1]), true); foreach ($c["repositories"] ?? [] as $r) { if (($r["url"] ?? null) === "../packman/packages/*" && isset($r["options"]["versions"]["axiomasoft/packman"])) { echo $r["options"]["versions"]["axiomasoft/packman"]; exit(0); } } exit(1);' "$src/composer.json" 2>/dev/null || printf '0.1.1')
if [[ -n "${PACKMAN_REF:-}" ]]; then
  packman_ref=$(git -C "$packman" rev-parse --verify "${PACKMAN_REF}^{commit}")
elif packman_ref=$(git -C "$packman" rev-parse --verify --quiet "refs/tags/v${packman_version}^{commit}" 2>/dev/null); then
  :
elif packman_ref=$(git -C "$packman" rev-parse --verify --quiet "b2fea80d85b1787f0a74d22c0f2563fd411ad132^{commit}" 2>/dev/null); then
  :
else
  packman_ref=$(git -C "$packman" rev-parse --verify --quiet "HEAD^{commit}" 2>/dev/null || printf 'b2fea80d85b1787f0a74d22c0f2563fd411ad132')
fi
evidence="${EVIDENCE_DIR:-}"
run=$(mktemp -d)
server_pid=
finish() {
    status=$?
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    if [[ -n "$evidence" ]]; then
        mkdir -p "$evidence"
        for name in checksums.sha256 composer-packages.txt doctor.json tables.txt frontend-tree.json vaulter-commit.txt packman-commit.txt versions.txt server.log journey.log; do
            if [[ -f "$run/$name" ]]; then
                cp "$run/$name" "$evidence/$name"
            fi
        done
    fi
    printf 'Логи и lock-файлы: %s\n' "$run"
    exit "$status"
}
trap finish EXIT
mkdir -p "$run/artifacts" "$run/composer-home" "$run/frontend" "$run/consumer"
{
    printf 'php '; php -v | head -n 1
    printf 'node '; node -v
    printf 'npm '; npm -v
    printf 'composer '; composer --version
} > "$run/versions.txt"
php "$src/scripts/release/build-artifacts.php" --root="$src" --ref="$vaulter_ref" \
    --version=1.0.0 --channel=public --out="$run/public-release"
for package in vaulter vaulter-core vaulter-documents vaulter-drive vaulter-media; do
    cp "$run/public-release/axiomasoft-$package-1.0.0.zip" "$run/artifacts/"
done
python3 - "$packman" "$run/artifacts" "$packman_ref" "$packman_version" <<'PYTHON'
import io, json, pathlib, subprocess, sys, zipfile

repo, out = map(pathlib.Path, sys.argv[1:3])
ref = subprocess.check_output(['git', '-C', repo, 'rev-parse', '--verify', f'{sys.argv[3]}^{{commit}}'], text=True).strip()
packman_version = sys.argv[4]
for name in ['core', 'plugins', 'schema']:
    archive = subprocess.check_output(['git', '-C', repo, 'archive', '--format=zip', f'{ref}:packages/{name}'])
    with zipfile.ZipFile(io.BytesIO(archive)) as origin:
        manifest = json.loads(origin.read('composer.json'))
        manifest['version'] = packman_version
        target = out / (manifest['name'].replace('/', '-') + '.zip')
        with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as dist:
            for path in origin.namelist():
                dist.writestr(path, json.dumps(manifest) if path == 'composer.json' else origin.read(path))
PYTHON
export COMPOSER_HOME="$run/composer-home"
export COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-$run/composer-home/cache}"
unset COMPOSER_AUTH NPM_TOKEN NODE_AUTH_TOKEN DB_URL VAULTER_DB_CONNECTION
export NPM_CONFIG_CACHE="${NPM_CONFIG_CACHE:-$run/npm-cache}"
export NPM_CONFIG_USERCONFIG="$run/empty.npmrc"
touch "$NPM_CONFIG_USERCONFIG"
composer create-project --no-install --no-scripts --no-interaction laravel/laravel "$run/host" 'v13.11.0'
cd "$run/host"
python3 - "$run/artifacts" <<'PY'
import json, pathlib, sys
p = pathlib.Path('composer.json')
m = json.loads(p.read_text())
m['require'] = {'php': '^8.4', 'laravel/framework': '13.43.0', 'axiomasoft/vaulter': '1.0.0'}
m['require-dev'] = {}
m['repositories'] = [{'type': 'artifact', 'url': sys.argv[1]}]
p.write_text(json.dumps(m, indent=2) + '\n')
PY
composer update --no-dev --no-interaction --no-scripts --prefer-dist --no-progress
composer show --locked 'axiomasoft/*' | tee "$run/composer-packages.txt"
python3 - "$run/composer-packages.txt" <<'PY'
import pathlib, sys
text = pathlib.Path(sys.argv[1]).read_text()
required = [
    'axiomasoft/vaulter ',
    'axiomasoft/vaulter-core ',
    'axiomasoft/vaulter-documents ',
    'axiomasoft/vaulter-drive ',
    'axiomasoft/vaulter-media ',
]
missing = [name for name in required if name not in text]
banned = [name for name in ('axiomasoft/vaulter-collaboration', 'axiomasoft/vaulter-filament', 'axiomasoft/vaulter-ai') if name in text]
if missing or banned:
    raise SystemExit(f'unexpected composer set missing={missing} banned={banned}')
PY
cp .env.example .env
export APP_ENV=testing DB_CONNECTION="$e2e_db_connection"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
if [[ "$e2e_db_connection" == sqlite ]]; then
    export DB_DATABASE="${e2e_db_database:-$run/host/database/database.sqlite}"
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
    python3 - ".env" "$DB_DATABASE" <<'PY'
import pathlib, sys
env_path, db_path = pathlib.Path(sys.argv[1]), sys.argv[2]
lines = [l for l in env_path.read_text().splitlines() if not l.startswith(('APP_ENV=', 'DB_CONNECTION=', 'DB_DATABASE=', 'CACHE_STORE=', 'SESSION_DRIVER=', 'QUEUE_CONNECTION='))]
lines += ['APP_ENV=testing', 'DB_CONNECTION=sqlite', f'DB_DATABASE={db_path}', 'CACHE_STORE=array', 'SESSION_DRIVER=array', 'QUEUE_CONNECTION=sync']
env_path.write_text('\n'.join(lines) + '\n')
PY
else
    export DB_HOST="$e2e_db_host" DB_PORT="$e2e_db_port" DB_DATABASE="$e2e_db_database" DB_USERNAME="$e2e_db_username" DB_PASSWORD="$e2e_db_password"
    python3 - ".env" "$DB_HOST" "$DB_PORT" "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD" <<'PY'
import pathlib, sys
env_path, host, port, db, user, pw = sys.argv[1:7]
env_path = pathlib.Path(env_path)
lines = [l for l in env_path.read_text().splitlines() if not l.startswith(('APP_ENV=', 'DB_CONNECTION=', 'DB_HOST=', 'DB_PORT=', 'DB_DATABASE=', 'DB_USERNAME=', 'DB_PASSWORD=', 'CACHE_STORE=', 'SESSION_DRIVER=', 'QUEUE_CONNECTION='))]
lines += ['APP_ENV=testing', 'DB_CONNECTION=pgsql', f'DB_HOST={host}', f'DB_PORT={port}', f'DB_DATABASE={db}', f'DB_USERNAME={user}', f'DB_PASSWORD={pw}', 'CACHE_STORE=array', 'SESSION_DRIVER=array', 'QUEUE_CONNECTION=sync']
env_path.write_text('\n'.join(lines) + '\n')
PY
    php -r 'new PDO("pgsql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3], $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);' "$DB_HOST" "$DB_PORT" "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD"
fi
php artisan key:generate
php artisan package:discover
php artisan migrate --force --no-interaction
php artisan vaulter:install --migrate --no-interaction
sed -i "s/'auth:sanctum'/'auth:web'/" config/vaulter.php
grep -q "'auth:web'" config/vaulter.php
php artisan vaulter:doctor --json > "$run/doctor.json"
if [[ "$DB_CONNECTION" == sqlite ]]; then
    python3 - "$DB_DATABASE" "$run/tables.txt" <<'PYTHON'
import pathlib, sqlite3, sys
with sqlite3.connect(sys.argv[1]) as db:
    names = [row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")]
pathlib.Path(sys.argv[2]).write_text('\n'.join(names) + '\n')
PYTHON
else
    php -r '
$pdo = new PDO("pgsql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3], $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$names = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
file_put_contents($argv[6], implode("\n", $names)."\n");
' "$DB_HOST" "$DB_PORT" "$DB_DATABASE" "$DB_USERNAME" "$DB_PASSWORD" "$run/tables.txt"
fi
for required_table in v_drives v_nodes v_documents v_document_payloads; do
    grep -qx "$required_table" "$run/tables.txt" || {
        echo "Missing expected Vaulter table: $required_table" >&2
        exit 1
    }
done
export FIXTURE_FILE="$run/users.json"
export FIXTURES_DIR="$here/fixtures"
php "$here/laravel/seed.php"

mkdir "$run/build"
git -C "$src" archive "$vaulter_ref" | tar -x -C "$run/build"
cd "$run/build"
npm ci --no-audit --no-fund
npm --workspace @vaulter/vue run build
npm pack --workspace @vaulter/vue --pack-destination="$run"
python3 - "$run" <<'PYTHON'
import hashlib, pathlib, sys
run = pathlib.Path(sys.argv[1])
archives = sorted((run / 'artifacts').glob('*.zip')) + sorted(run.glob('*.tgz'))
(run / 'checksums.sha256').write_text(''.join(
    f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.relative_to(run)}\n'
    for path in archives
))
PYTHON
git -C "$src" rev-parse "$vaulter_ref" > "$run/vaulter-commit.txt"
git -C "$packman" rev-parse "$packman_ref" > "$run/packman-commit.txt"

cp -a "$here/frontend/." "$run/frontend/"
cp -a "$here/tests" "$run/frontend/tests"
cp "$here/playwright.config.ts" "$run/frontend/playwright.config.ts"
cd "$run/frontend"
npm ci --no-audit --no-fund
npm install --no-audit --no-fund --save "$run/vaulter-vue-1.0.0.tgz"
node --input-type=module <<'JS'
import assert from 'node:assert/strict'
import * as api from '@vaulter/vue'
for (const name of ['VaulterDocumentNode', 'VaulterDrive', 'VaulterViewer', 'BlockId', 'createVaulterClient', 'VaulterPlugin']) {
    assert.ok(api[name], `missing export ${name}`)
}
assert.equal(Object.hasOwn(api, 'VaulterSpreadsheet'), false)
console.log('documents-basic exports: VaulterDrive VaulterDocumentNode VaulterViewer')
JS
npm ls --all --json > "$run/frontend-tree.json"
python3 - "$run/frontend-tree.json" <<'PYTHON'
import json, pathlib, sys
banned = {
    'yjs', '@vaulter/vue-collaboration', 'handsontable', 'hyperformula', 'exceljs', 'unzipper', 'mammoth',
}
pending = [json.loads(pathlib.Path(sys.argv[1]).read_text())]
while pending:
    dependencies = pending.pop().get('dependencies', {})
    for name, child in dependencies.items():
        if name in banned or name.startswith('@hocuspocus/') or name.startswith('@tiptap-pro/') or name.startswith('@univerjs-pro/') or name.startswith('@univer-cli/'):
            raise SystemExit(f'Unexpected dependency in documents-basic: {name}')
        pending.append(child)
PYTHON
if [[ -z "${BROWSER_BIN:-}" ]]; then
    if [[ -n "${CI:-}" ]]; then
        npx --no-install playwright install --with-deps chromium
    else
        npx --no-install playwright install chromium
    fi
fi
npx vite build --target esnext --base /app/ --outDir "$run/host/public/app"
cp "$here/laravel/routes/web.php" "$run/host/routes/web.php"
sed -i "s/'auth:web'/'auth.basic'/" "$run/host/config/vaulter.php"
grep -q "'auth.basic'" "$run/host/config/vaulter.php"

port=$(python3 -c "import socket; s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1])")
origin="http://127.0.0.1:$port"
(cd "$run/host/public" && exec php -S "127.0.0.1:$port" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) > "$run/server.log" 2>&1 &
server_pid=$!
curl --retry 20 --retry-all-errors --retry-delay 1 --silent --show-error --fail "$origin/app/index.html" >/dev/null
curl --retry 20 --retry-all-errors --retry-delay 1 --silent --show-error --fail "$origin/" >/dev/null
kill -0 "$server_pid"
cd "$run/frontend"
ORIGIN="$origin" FIXTURE_FILE="$FIXTURE_FILE" FIXTURES_DIR="$here/fixtures" \
    npx playwright test --config "$run/frontend/playwright.config.ts" | tee "$run/journey.log"
