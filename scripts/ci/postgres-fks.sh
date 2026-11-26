#!/usr/bin/env bash
set -euo pipefail

images=("$@")
if (( ${#images[@]} == 0 )); then
    images=(
        'postgres:18.7-alpine@sha256:0115267ab5a509022df9319c77be365cef335adb71556c75f60f5360ee247e71'
        'postgres:19.0-alpine@sha256:8b0ff4cdf221f0c53d7fcda681d3b461be725c492cd881c5ec9c0ad25023f188'
    )
fi

php_bin="${PHP84_BIN:-$(command -v php8.4 || command -v php || :)}"
if [[ -z "$php_bin" || ! -x "$php_bin" ]]; then
    echo 'postgres-fks: php binary not found' >&2
    exit 1
fi

cid=''
cleanup() {
    if [[ -n "$cid" ]]; then
        docker rm -f "$cid" >/dev/null 2>&1 || true
        cid=''
    fi
}
trap cleanup EXIT

for image in "${images[@]}"; do
    cid="$(docker run -d --rm -p 127.0.0.1::5432 \
        --tmpfs /var/lib/postgresql:rw,size=512m \
        -e POSTGRES_HOST_AUTH_METHOD=trust \
        -e POSTGRES_USER=postgres \
        -e POSTGRES_DB=vaulter_laravel \
        -v "$PWD/scripts/ci/unindexed-fks.sql:/checks/unindexed-fks.sql:ro" \
        "$image")"

    for _ in $(seq 1 60); do
        if docker exec "$cid" pg_isready -h 127.0.0.1 -p 5432 -U postgres -d vaulter_laravel >/dev/null 2>&1; then
            break
        fi
        sleep 0.5
    done
    docker exec "$cid" pg_isready -h 127.0.0.1 -p 5432 -U postgres -d vaulter_laravel >/dev/null 2>&1

    port="$(docker port "$cid" 5432/tcp | head -n 1 | awk -F: '{print $NF}')"
    if [[ -z "$port" ]]; then
        echo "postgres-fks: failed to resolve mapped port for $image" >&2
        exit 1
    fi

    DB_PORT="$port" "$php_bin" /dev/stdin "$PWD" <<'PHP_MIGRATE'
<?php

declare(strict_types=1);

$root = $argv[1];
require $root.'/vendor/autoload.php';

$app = \Orchestra\Testbench\Foundation\Application::create(
    basePath: \Orchestra\Testbench\default_skeleton_path(),
    options: [
        'load_environment_variables' => false,
        'extra' => [
            'providers' => [
                \Vaulter\VaulterServiceProvider::class,
                \Vaulter\Media\VaulterMediaServiceProvider::class,
                \Vaulter\Drive\VaulterDriveServiceProvider::class,
                \Vaulter\Documents\VaulterDocumentsServiceProvider::class,
                \Vaulter\Webhooks\VaulterWebhooksServiceProvider::class,
            ],
        ],
    ],
);

$config = $app->make('config');
$config->set('database.default', 'pgsql');
$config->set('database.connections.pgsql', [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '5432',
    'database' => 'vaulter_laravel',
    'username' => 'postgres',
    'password' => '',
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
]);

$status = $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', ['--force' => true]);
if ($status !== 0) {
    fwrite(STDERR, "postgres-fks: migrate exited with {$status}\n");
    exit($status);
}
PHP_MIGRATE

    docker exec -i "$cid" psql -U postgres -d vaulter_laravel -Xq -v ON_ERROR_STOP=1 -f /checks/unindexed-fks.sql

    has_idx="$(docker exec -i "$cid" psql -U postgres -d vaulter_laravel -Atq -v ON_ERROR_STOP=1 <<'SQL_CHECK_IDX'
SELECT COUNT(*)
FROM pg_catalog.pg_index i
JOIN pg_catalog.pg_class c ON c.oid = i.indexrelid
JOIN pg_catalog.pg_class t ON t.oid = i.indrelid
WHERE t.relname = 'v_files'
  AND c.relname = 'v_files_head_fk_idx'
  AND i.indisvalid
  AND i.indisready
  AND i.indpred IS NULL
  AND (
      SELECT array_agg(a.attname::text ORDER BY k.ord)
      FROM unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord)
      JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum
  ) = ARRAY['current_revision_id', 'id'];
SQL_CHECK_IDX
)"
    if [[ "$has_idx" != '1' ]]; then
        echo "postgres-fks: v_files_head_fk_idx (current_revision_id, id) missing on $image" >&2
        exit 1
    fi

    docker exec -i "$cid" psql -U postgres -d vaulter_laravel -Xq -v ON_ERROR_STOP=1 -c \
        'CREATE TABLE rail_laravel_child (id bigint PRIMARY KEY, drive_id char(26) NOT NULL REFERENCES v_drives(id));'
    if diagnostic="$(docker exec -i "$cid" psql -U postgres -d vaulter_laravel -Xq -v ON_ERROR_STOP=1 -f /checks/unindexed-fks.sql 2>&1)"; then
        echo 'unindexed-fks.sql accepted an unindexed foreign key' >&2
        exit 1
    fi
    if [[ "$diagnostic" != *'Unindexed foreign keys detected: rail_laravel_child.rail_laravel_child_drive_id_fkey'* ]]; then
        echo "postgres-fks: expected the missing index diagnostic, received: $diagnostic" >&2
        exit 1
    fi

    cleanup
done
