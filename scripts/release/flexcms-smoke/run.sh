#!/usr/bin/env bash
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
src=$(git -C "$here" rev-parse --show-toplevel)
packman="${PACKMAN_DIR:-$src/../packman}"
vaulter_ref="${VAULTER_REF:-b230f6e27}"
packman_ref="${PACKMAN_REF:-a5a5442}"
run=$(mktemp -d)
server_pid=
finish() {
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    printf 'Логи и lock-файлы: %s\n' "$run"
}
trap finish EXIT
mkdir "$run/artifacts" "$run/composer-home"
php "$src/scripts/release/build-artifacts.php" --root="$src" --ref="$vaulter_ref" \
    --version=1.0.0 --channel=public --out="$run/public-release"
for package in vaulter vaulter-core vaulter-documents vaulter-drive vaulter-media; do
    cp "$run/public-release/axiomasoft-$package-1.0.0.zip" "$run/artifacts/"
done
python3 - "$packman" "$run/artifacts" "$packman_ref" <<'PYTHON'
import io, json, pathlib, subprocess, sys, zipfile

repo, out = map(pathlib.Path, sys.argv[1:3])
ref = sys.argv[3]
for name in ['core', 'plugins', 'schema']:
    archive = subprocess.check_output(['git', '-C', repo, 'archive', '--format=zip', f'{ref}:packages/{name}'])
    with zipfile.ZipFile(io.BytesIO(archive)) as origin:
        manifest = json.loads(origin.read('composer.json'))
        manifest['version'] = '0.1.1'
        target = out / (manifest['name'].replace('/', '-') + '.zip')
        with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as dist:
            for path in origin.namelist():
                dist.writestr(path, json.dumps(manifest) if path == 'composer.json' else origin.read(path))
PYTHON
export COMPOSER_HOME="$run/composer-home"
unset COMPOSER_AUTH NPM_TOKEN NODE_AUTH_TOKEN DB_URL VAULTER_DB_CONNECTION
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
cp .env.example .env
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$run/host/database/database.sqlite"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
touch "$DB_DATABASE"
php artisan key:generate
php artisan package:discover
php artisan migrate --force --no-interaction
php artisan vaulter:install --migrate --no-interaction
sed -i "s/'auth:sanctum'/'auth:web'/" config/vaulter.php
php artisan vaulter:doctor --json > "$run/doctor.json"
python3 - "$DB_DATABASE" "$run/tables.txt" <<'PYTHON'
import pathlib, sqlite3, sys
with sqlite3.connect(sys.argv[1]) as db:
    names = [row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")]
pathlib.Path(sys.argv[2]).write_text('\n'.join(names) + '\n')
PYTHON
export FIXTURE_FILE="$run/users.json"
php "$here/host.php"

mkdir "$run/build" "$run/frontend"
git -C "$src" archive "$vaulter_ref" | tar -x -C "$run/build"
cd "$run/build"
npm ci --prefer-offline --no-audit --no-fund
npm --workspace @vaulter/vue run test -- tests/document-node.test.ts tests/arch/no-collab-deps.test.ts tests/spreadsheet.test.ts
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
cd "$run/frontend"
npm init -y
npm install --no-audit --no-fund "$run/vaulter-vue-1.0.0.tgz" 'vue@3.5.46' '@tiptap/pm@3.35.0' '@tiptap/core@3.35.0' '@tiptap/starter-kit@3.35.0'
node --input-type=module <<'JS'
import assert from 'node:assert/strict'
import * as api from '@vaulter/vue'
assert.ok(api.VaulterDocumentNode)
console.log('VaulterSpreadsheet exported:', Boolean(api.VaulterSpreadsheet))
JS
npm ls --all --json > "$run/frontend-tree.json"
python3 - "$run/frontend-tree.json" <<'PYTHON'
import json, pathlib, sys
pending = [json.loads(pathlib.Path(sys.argv[1]).read_text())]
while pending:
    dependencies = pending.pop().get('dependencies', {})
    for name, child in dependencies.items():
        if name in ('yjs', '@vaulter/vue-collaboration') or name.startswith('@hocuspocus/'):
            raise SystemExit(f'Unexpected dependency in FlexCms: {name}')
        pending.append(child)
PYTHON
npm install --no-audit --no-fund --save-dev playwright@1.61.1
if [[ -z "${BROWSER_BIN:-}" ]]; then
    npx --no-install playwright install chromium
fi
cp "$here/editor.mjs" "$here/browser.mjs" .
printf '%s\n' '<!doctype html><html><body><main id="app"></main><script type="module" src="./editor.mjs"></script></body></html>' > index.html
"$run/build/node_modules/.bin/vite" build --target esnext --base ./ --outDir "$run/host/public/browser-probe"
sed -i "s/'auth:web'/'auth.basic'/" "$run/host/config/vaulter.php"

# Ask the kernel for a free port and verify this server actually owns it before testing.
port=$(python3 -c "import socket; s=socket.socket(); s.bind(('127.0.0.1',0)); print(s.getsockname()[1])")
origin="http://127.0.0.1:$port"
(cd "$run/host/public" && exec php -S "127.0.0.1:$port" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) > "$run/server.log" 2>&1 &
server_pid=$!
curl --retry 12 --retry-all-errors --retry-delay 1 --silent --show-error --fail "$origin/browser-probe/index.html" >/dev/null
kill -0 "$server_pid"
node browser.mjs "$origin" "$FIXTURE_FILE"
