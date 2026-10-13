# P1.6 — соответствие старых jobs новым

Правило: ни одна проверка, бывшая на PR (кроме мутаций, coverage, load — D3/D4), не пропадает.
`ci.yml` всегда запускается (без path-filter); `changes` решает, какие линии нужны.

| Старый workflow / job | Что проверял | Новый job | Заметка |
|:--|:--|:--|:--|
| tests.yml `test` (L12/TB10, L13/TB11, SQLite, pest --ci) | core на двух линиях | `fast` / core × L12, L13 | `scripts/t sqlite -p core -j 4`; версии проверяются после `composer update` |
| tests.yml `test` — `composer validate` (root, core --strict) | валидность composer | `repo` | |
| tests.yml `test` — `composer test:types` (100%) | type coverage core | `fast` (шаг Type coverage, `matrix.types`) | в каждой ячейке, как раньше; перенос по замеру — P1.9 |
| tests.yml `test-pgsql` — шаги по схемам (base, Ulid, IdStrategy×3, TablePrefix, ActivityGate, Spatie) | серверные тесты core на PG16 | `db` / pgsql / core | `--group=database`, схемы-партиции и БД на воркера делает `scripts/t` |
| tests.yml `test-pgsql` — concurrency harness (4 passed, 0 skipped) | форк-гонки на PG | `serial` / pgsql / core | `--group=serial -j1 --no-skips` (Concurrency входит в serial) |
| (нового не было) | MySQL 8.4 | `db` / mysql / каждый пакет | tmpfs, root-пользователь для per-worker БД |
| drive.yml `drive` (L12/L13) | Pint, PHPStan max, Pest, type coverage | `quality` / drive; `fast` / drive × L12, L13 | |
| drive.yml `drive-pgsql` (Uploads, Concurrency) | upload-commit на PG | `db` / pgsql / drive; `serial` / pgsql / drive | |
| media.yml `media` | то же | `quality` / media; `fast` / media × 2 | |
| media.yml `media-pgsql` (tests/Cas) | CAS-замки на PG | `db` / pgsql / media; `serial` / pgsql / media | |
| documents.yml `documents` | то же | `quality` / documents; `fast` / documents × 2 | |
| documents.yml `documents-pgsql` (Document, Schema) | yjs bytea, jsonb gin | `db` / pgsql / documents; `serial` / pgsql / documents | |
| azgard.yml `azgard` (L12/L13, checkout AzGuard) | Pint, Larastan, Pest | `quality` / azgard; `fast` / azgard × 2 | шаг checkout AzGuard сохранён (`matrix.azguard`); serial-кейсов нет |
| filament.yml `filament` (Laravel 13 / TB11) | Pint, Larastan, Pest, type coverage, Rector advisory | `quality` / filament (Rector `continue-on-error`); `fast` / filament × L13 | только L13 по `composer.json` |
| static-analysis.yml `larastan` | PHPStan level max, core | `quality` / core | root phpstan.neon |
| code-style.yml `pint` (+ Rector advisory) | Pint всего репозитория | `quality` / core | Rector остаётся advisory, не blocking |
| frontend.yml `vue` (Node 20, 22) | api:check, typecheck, test, build | `vue` | те же шаги |
| frontend.yml `hocuspocus` (Node 22) | npm ci, check, test | `hub` | те же шаги |
| openapi.yml `breaking` (oasdiff) | совместимость со стабильным тегом | `openapi.yml` без изменений | информативный (D21), не required; path-filter остаётся, т.к. workflow не required |
| (нового не было) | валидность спеки | `openapi` в ci.yml | blocking: OpenAPI 3.x, локальные `$ref`, уникальные operationId |
| (нового не было) | harness, policy, actionlint | `repo` | `scripts/t sqlite -p repo`, workflow-policy + unit, actionlint 1.7.7 |
| pr-check.yml, docs.yml, load.yml, mutation.yml, release-npm.yml, hocuspocus-image.yml | — | без изменений | mutation/load — ручные (P1.2) |
| monorepo-split.yml `verify-release-sha` | 8 имён checks последнего зелёного | `qualification` (weekly-full, точный SHA) → `verify-release-sha` (`CI gate` на том же SHA) → `split` | polling и lookup «последнего зелёного» нет |

## Новое
- `ci-gate` («CI gate») — единственный required-check; логика — `workflow-policy.py gate` (тесты на fake needs).
- `weekly-full.yml`: пн 04:00 UTC / вручную / reusable для тега: `--full` по пакетам × L12/L13, pgsql и mysql полностью, pcov coverage (floor core 80), stand, Node, repo.
