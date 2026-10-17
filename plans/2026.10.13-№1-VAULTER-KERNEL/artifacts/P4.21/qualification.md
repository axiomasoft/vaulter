# Квалификация P4 (Fast-набор, D41/D45)

Дата: 2026-10-17. Исполнитель: оркестратор (sonnet-5-5), без субагента.

| V | Проверка | Результат |
|---|---|---|
| V1 | `scripts/t sqlite -p core,media,drive,documents,webhooks,filament,repo` | НЕ ЗАПУСКАЛСЯ (тяжёлый пакетный прогон; указание владельца, D45 → PLAN4). Свидетельства: F1 по затронутым файлам/каталогам в каждом пункте P4.5–P4.20 зелёные; `-p repo` целиком — 239 тестов зелёные (P4.19, P4.20). |
| V2 | `scripts/t sqlite -p azgard` | НЕ ЗАПУСКАЛСЯ (то же). azgard ArchTest/PublicApiSnapshot правились в P4.8, phpstan azgard зелёный (V7). |
| V3 | `scripts/t baseline --compare` | exit 0 (устаревший `vue.list` переписан: `baseline --write -p vue`) |
| V4 | `inventory.php --check` | exit 0 |
| V5 | `schema-snapshot.sh --driver=sqlite --all-presets --rollback --compare=…/expected` | exit 0 |
| V6 | `git diff --quiet 2a657840 -- …/Schema/original` | exit 0 (original не тронут) |
| V7 | pint --test + phpstan по корню и 6 пакетам | exit 0 |
| V8 | `vue typecheck` + `api:check` | exit 0 после `api:generate`: `src/api/schema.d.ts` отставал от `openapi/vaulter-v1.yaml` с P4.7 (+1 строка, 409 `blob_unavailable`) |
| V9 | check-doc-links (+--mirror) | exit 0 |
| V10 | `consumer-check.sh webhooks --dry-run` | exit 0 |
| V11 | этот файл | есть |

Отложено в PLAN4: V1/V2 (пакетные прогоны sqlite), pgsql/mysql (I15, KeyColumnTypesTest, CleanInstallMatrixTest, AggregateLockTest advisory-ключ `vaulter:drives:ID`), реальный `consumer-check.sh` (core требует корневые файлы/root-only dev-зависимости), mutation/coverage/stand/load.
