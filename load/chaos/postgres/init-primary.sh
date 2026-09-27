#!/bin/sh
# Chaos HA primary: replication role only. Never a production endpoint.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'replicator') THEN
        CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD 'secret';
    END IF;
END
$$;
SQL
cat >> "$PGDATA/pg_hba.conf" <<'HBA'
host replication replicator 0.0.0.0/0 md5
host replication replicator ::0/0 md5
HBA
