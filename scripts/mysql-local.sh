#!/usr/bin/env bash
# A MySQL test server that outlives a run (local work only), the MySQL twin of scripts/pg-local.sh.
#
#   scripts/mysql-local.sh up       start it (idempotent), write var/mysql-local.env
#   scripts/mysql-local.sh down     remove the container and its data
#   scripts/mysql-local.sh status   up|down
#
# A native mysqld cannot keep its data in memory here (its AppArmor profile forbids /dev/shm); on disk the core
# DatabaseMigrations partition alone took 702 s. This runs the CI image (mysql:8.4, see weekly-full.yml) in Docker with
# the data directory on tmpfs and the throw-away durability settings of db-test-env.sh: the same partition takes 158 s.
# While it is up, `scripts/test-db.sh mysql` (so `scripts/t mysql …`) reuses it as a DB_EXTERNAL server.
# Container: vaulter-mysql-local on 127.0.0.1:${VAULTER_MYSQL_PORT:-33061}. Databases still end in _test. Not for CI.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="$root/var/mysql-local.env"
name=vaulter-mysql-local
port="${VAULTER_MYSQL_PORT:-33061}"
image="${VAULTER_MYSQL_IMAGE:-mysql:8.4}"

alive() {
    [[ "$(docker inspect --format '{{.State.Health.Status}}' "$name" 2>/dev/null)" == healthy ]]
}

case "${1:-}" in
    up)
        command -v docker >/dev/null || { echo 'mysql-local: docker is required' >&2; exit 1; }
        if ! alive; then
            docker rm -fv "$name" >/dev/null 2>&1 || true
            docker run -d --name "$name" -p "127.0.0.1:$port:3306" --tmpfs /var/lib/mysql:rw,mode=1777 \
                -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=vaulter_test -e MYSQL_USER=vaulter -e MYSQL_PASSWORD=secret \
                --health-cmd='mysqladmin ping -h 127.0.0.1 -uroot -psecret --silent' \
                --health-interval=1s --health-timeout=3s --health-retries=120 \
                "$image" --log-bin-trust-function-creators=1 --max-connections=400 --skip-log-bin \
                --innodb-flush-log-at-trx-commit=0 --sync-binlog=0 --innodb-doublewrite=0 >/dev/null
            for _ in $(seq 1 120); do alive && break; sleep 1; done
            alive || { docker logs --tail 20 "$name" >&2; echo 'mysql-local: did not become healthy' >&2; exit 1; }
            # the worker databases of scripts/t (-j up to 8) and the grant db_use_external relies on
            docker exec -e MYSQL_PWD=secret "$name" mysql -uroot -e "GRANT ALL ON *.* TO 'vaulter'@'%'" >/dev/null
        fi
        mkdir -p "$root/var"
        printf 'export DB_HOST=127.0.0.1 DB_PORT=%q DB_USERNAME=vaulter DB_PASSWORD=secret DB_DATABASE=vaulter_test DB_CONNECTION=mysql\n' \
            "$port" > "$env_file"
        echo "mysql-local: up on port $port (container $name)"
        ;;
    down)
        docker rm -fv "$name" >/dev/null 2>&1 || true
        rm -f -- "$env_file"
        echo 'mysql-local: down'
        ;;
    status) if alive; then echo up; else echo down; fi ;;
    *) echo 'usage: scripts/mysql-local.sh up|down|status' >&2; exit 2 ;;
esac
