# v0.2.0 data fixture

Database dumps of a Vaulter **0.2.0** install holding every data shape the 0.2 → 0.3 upgrade migrations
(OPUS 08 §3) must carry over or refuse. Consumers: V15 (upgrade on the fixture), V14 (`repair-scopes` on
`legacy:snull`), upgrade migrations №1 (preflight), №5 (documents, every `format`), №6 (N04 «second roots»),
№7 (`scope_key`), №8 (soft-deleted grants/shares).

Generated from `v0.2.0` (`983c345c1088363a1f52c6b44096f4e82c6a2cd4`): the tag's own core migrations and the tag's
API/models (`scripts/fixtures/seed-v0.2.php`), run from a `git worktree` of the tag — never the current code.

## Regenerate / verify

```bash
scripts/fixtures/make-v0.2-dump.sh            # rewrite the dumps + SHA256SUMS (recreates the *_test schema)
scripts/fixtures/make-v0.2-dump.sh --verify   # regenerate into a temp dir, compare SHA256SUMS, load, count
FIXTURE_CONFIGS=pgsql-bigint scripts/fixtures/make-v0.2-dump.sh --verify   # one configuration
```

Prerequisites: `scripts/db-preflight.sh pgsql` exit 0 (D04), PHP 8.4 via `scripts/php84`. The worktree
`build/fixtures/v0.2.0` is created and `composer install`ed on first run (`build/` is not committed); the script
refuses a worktree whose HEAD is not `v0.2.0` or that has tracked edits. The tag has no `composer.lock`, so the
resolved stack is recorded here: laravel/framework v13.34.0, orchestra/testbench v11.3.0 (core v11.5.0),
spatie/laravel-package-tools 1.93.3 — a different resolution may change the dumps and fail `--verify`.
Two consecutive runs produce byte-identical files: frozen clock, counter-based ULID/UUID/random-string
factories, fixed bytes, a content-derived encryption IV, `pg_dump --restrict-key`; the DB-clock default
`v_blobs.first_seen_at` is pinned to `committed_at` by the seeder.

Tool: `pg_dump` 16.15 (the server's major, not the `pg_wrapper` default),
`--no-owner --no-privileges --no-comments --inserts --rows-per-insert=100 --quote-all-identifiers`.
The dump header names the exact `pg_dump` build, so `--verify` is byte-exact only on PostgreSQL 16.15 — a local
maintenance check, not a CI gate. Loading needs `psql` ≥ 16.10 (`\restrict`); `--verify` also checks the committed
files against `SHA256SUMS` (`sha256sum -c`).

## Configurations

| Dump | Status |
|:--|:--|
| `pgsql-bigint.sql` | generated |
| `pgsql-ulid.sql` | generated |
| `pgsql-bigint-preflight-red.sql` | generated |

Feature flags at seed time: `document_versions`, `share_links`, `comments` on (the document version table
exists only with `document_versions`); tenancy off; host-key strategy = the dump's `bigint`/`ulid`.

## Composition (each main dump)

| Shape | Count | Rows | For |
|:--|:--|:--|:--|
| storages of three owners (`owner_type = fixture_host`); bigint `owner_id` `1,2,3`; ulid `01ARZ3NDEKTSV4RRFFQ69G5FAV/W/X` | 3 | `v_storages` | №2, №3; arbitrary string HK — P1.7/V23 |
| tree per storage: root, `Projects`, `Projects/Reports 2025`, `Projects/2025` (moved), `Archive`, files, `old.txt` in trash, `Temp/` folder in trash | 10 nodes/storage | `v_nodes` | №6, AD27 |
| files with 1 version (`brief.txt`, `old.txt`, `note.txt`, `*.bin`) and 3 versions (`q1.csv`) | 16 files, 22 versions | `v_files`, `v_file_versions` | №4, V03 |
| `legacy:snull`: `dedup_scope=storage`, `storage_id NULL` (brief.txt of storage 1) | 1 | `v_blobs` | №7, V14 |
| `legacy:tnull`: `dedup_scope=tenant`, `tenant_id NULL` (tenancy off) | 2 | `v_blobs` | №7, V14 |
| one blob shared by two storages (`shared.bin` in storages 2 and 3) | 1 | `v_blobs` | №7, V14 |
| `dedup_scope=global` blob (`global.bin`, storage 1) | 1 | `v_blobs` | №7 (`global`) |
| documents of every 0.2 `DocumentType`: `richtext` (Notes, 2 versions), `table` (Budget), `markdown` (Readme, 2 versions), `template` (Letter template) | 4 | `v_nodes`, `v_node_document_versions` | №5 |
| N04 «second roots»: documents with `parent_id NULL` — `Loose note` and `Projects` (name clash with the root's `Projects` folder) | 2 | `v_nodes` | №6 N04 repair |
| grants on `Projects`: active user + role, soft-deleted (`deleted_at`) user + role, and an active re-grant of the soft-deleted user | 5 | `v_node_grants` | №8 (hard delete, uniqueness rebuild) |
| shares on `brief.txt`: active, revoked (`revoked_at`), soft-deleted (`deleted_at`), revoked then soft-deleted | 4 | `v_node_shares` | №8 (`coalesce(revoked_at, deleted_at)`) |
| activity: create, upload, version, rename, move, trash, restore, grant, share, upload-session | 72 | `v_activity` | №9, P5 |
| outbox: relayed (`dispatched`) and `pending`; webhook endpoint + 4 delivered deliveries | 68 / 1 / 4 | `v_outbox`, `v_webhook_*` | №9, P5 |
| tus upload in progress (`uploading`, 4 of 10 bytes) | 1 | `v_upload_sessions` | P3.7 |

Raw SQL only where the 0.2 API never produces the shape (each marked `raw:` in the seeder): `legacy:snull`
(0.2 always stamps the uploading storage), the DB-clock `first_seen_at`, and the preflight-red rows below.
The N04 second roots, `legacy:tnull`, soft-deleted grants/shares come from the 0.2 API itself.

`pgsql-bigint-preflight-red.sql` holds only the shapes that make preflight №1 refuse, one of each (negative test
in P2.1): a node with `v_nodes.deleted_at` set (`gone.txt`), a document node without version rows (`Headless`),
and own tables of a storage panel (`vlegacy_storages`, one row). These shapes are absent from the main dumps.

## Row counts (`--verify` compares these lines)

```text
pgsql-bigint.sql: migrations=19 v_activity=72 v_blobs=21 v_file_version_blobs=22 v_file_versions=22 v_files=16 v_node_comments=0 v_node_document_versions=8 v_node_grants=5 v_node_shares=4 v_nodes=40 v_outbox=68 v_storages=3 v_upload_parts=0 v_upload_sessions=1 v_webhook_deliveries=4 v_webhook_endpoints=1 owners=1,2,3
pgsql-ulid.sql: migrations=19 v_activity=72 v_blobs=21 v_file_version_blobs=22 v_file_versions=22 v_files=16 v_node_comments=0 v_node_document_versions=8 v_node_grants=5 v_node_shares=4 v_nodes=40 v_outbox=68 v_storages=3 v_upload_parts=0 v_upload_sessions=1 v_webhook_deliveries=4 v_webhook_endpoints=1 owners=01ARZ3NDEKTSV4RRFFQ69G5FAV,01ARZ3NDEKTSV4RRFFQ69G5FAW,01ARZ3NDEKTSV4RRFFQ69G5FAX
pgsql-bigint-preflight-red.sql: migrations=19 v_activity=3 v_blobs=1 v_file_version_blobs=1 v_file_versions=1 v_files=1 v_node_comments=0 v_node_document_versions=0 v_node_grants=0 v_node_shares=0 v_nodes=4 v_outbox=3 v_storages=1 v_upload_parts=0 v_upload_sessions=0 v_webhook_deliveries=0 v_webhook_endpoints=0 vlegacy_storages=1 owners=1
```

## MySQL

`v0.2.0` cannot be installed on MySQL 8.0 (checked on 8.0.46): its nodes migration adds the STORED generated
column `v_nodes.sibling_active` over `parent_id`, whose foreign key is `ON DELETE CASCADE`; MySQL rejects that
(`SQLSTATE[HY000] 1215 Cannot add foreign key constraint`, `PanelTablesBuilder.php:1139` at the tag). The tag's
CI had no MySQL job, so 0.2 data on MySQL cannot exist and the fixture is PostgreSQL-only (PLAN2 D09). The 0.2 → 0.3
upgrade is proven on these PostgreSQL dumps; MySQL support of 0.3 is proven from a fresh 0.3 install.
