# Mid-phase audit PLAN2 VAULTER-STABLE — 2026-10-08

**Вердикт: продолжение разработки допустимо по обновлённому плану, готовность 1.0 не доказана.** Накопленные отклонения получили конкретные решения/исправления/owning acceptance. Пять HIGH остались обязательными MOVE в P4.6/P4.7; до них небезопасно принимать базу как стабильный выпуск или выполнять проблемные глубокие lifecycle операции/dedup-off downgrade. Это static audit, не заявление о воспроизведении или GREEN тестах.

База на входе: `main@977f856d`, чистое дерево; ветка результата `audit/2026-10-08`. P4.3 был перевезён WIP-коммитом. Условие prompt исключает его полный аудит, поэтому он **не проверен полностью и не закрыт**; проверены лишь влияющие на закрытые пункты швы. P0–P3 изучались по follow-ups/regression contracts, без повторного полного аудита. Чужие analyst/views/documents agent edits на входе отсутствовали; агентские настройки не менялись, views только генерировались plan.py.

## Главные выводы

1. Полного runtime GREEN нет: этот audit соблюдает запрет тестов/анализаторов/DB; написанные проверки переданы следующей сессии.
2. Реестр содержит 144 отдельных записей: ACCEPT 99, CORRECT 12, MOVE 32, REVERT 1. Ни одной записи без verdict/action/owner.
3. D54 принимает scalar attribution snapshot, existing Subject, fileId/scanned, base-query guard и persistence actorId; не создаёт отсутствующий actor_type.
4. D53 согласован с Access/Sharing/P4-I21: owner_id без grants, одноимённые роли с объявленными writer/organizer изменениями; скрытого legacy нет.
5. OPUS raw текст/1438 unit ids/hashes не изменены; lost recount unit12:7e1380c4 переназначена P4.6, literal input regenerated через plan.py.
6. P4.1 aggregate/immutable checkpoints/single writer/state_version и epoch vs node.sequence статически согласованы с D34/D35; scalar writer actorId принят D54.
7. P4.2 primary/fallback lifecycle, PurgeOnly marker, opaque copy и 1-revision copy согласованы; предел глубины F13 делает subset traversal незавершённым.
8. Роли дают union до inherit boundary, Download отделён от View; DocumentOperations content View/export Download и comments resolve Comment AND author|Edit соответствуют норме.
9. Шары используют HMAC header session, generation/fingerprint и live issuer/audience checks; прошлый P4.10 PG долг получил P4.12 receipt; ru зеркала ещё принадлежат P6.
10. Файловая quota считает все retained revisions, trash включён; head/pin protected и deletion/restore проходят aggregate locks. Recount CLI отсутствует и теперь обязательна P4.6.
11. Исправлен high F04: per-file copy/growing restore проверяют maxFileBytes, shrinking old files сохраняет доступность; тесты проверяют отсутствие побочных writes.
12. Исправлены обходы WriteScope через newQuery/returning insert и gap golden DTO properties/Page/state enums; version compatibility12/13 остаётся отдельной матрицей.
13. Generated document conflict name повторно проходит NamePolicy; oversized extension regression написана. Core error dataset и REST timestamp assertion добавлены.
14. Открытый high F05: content writes могут последовать за concurrent trash; comment lock/race gap F06 конкретизирован в P4.6 V82/V83.
15. Открытый high F13: policy depth100 конфликтует с mutation traversal64; partial copy/trash и cascade purge accounting требуют full membership либо fail-closed.
16. Открытый high F15: comment event dispatch после nested transaction не гарантирует outer commit, rollback может оставить опубликованный broadcast payload.
17. Открытый high F09: equal-byte dedup=off downgrade не представим старым UNIQUE(sha256); fixture scope reset не product fix. Additive safety/preflight запланированы.
18. Открытый high F16 на seam P4.8↔P4.3: lossy/schema precheck вне D lock может устареть после restore newer-schema; отдельный PG guard acceptance P4.7 V4.
19. Future P4.4–P4.7 и P5–P8 согласованы; PG path corrected, brace task_files expanded, stale CHANGELOG permission/D25 wording corrected, legacy projections archived без потери.
20. P4.3 не прошёл полный type/codec аудит: clean input/WIP977f856d triggered explicit prompt exclusion. Handoff обновлён; fresh-app default_type boot test NOTE330/V82 ещё нужно написать.

## Находки

| ID | Severity | Источник | Вердикт | Действие/owner |
|---|---|---|---|---|
| F01 | medium | `packages/core/src/Database/GuardsStatements.php:31` | CORRECT | Scope now propagates and returning statement guards without version-specific Override; C1/C3 + matrix P8. → `1e906591d19db5` |
| F02 | medium | `packages/core/tests/Arch/PublicApiSnapshotTest.php:350` | CORRECT | Added discovery/properties/readonly/state enums; regenerate core golden expected additive blocks. → `1e906591d19db5`, `b944590934a38d` |
| F03 | medium | `packages/documents/src/Actions/Documents/CreateDocumentAction.php:137` | CORRECT | Generated candidate revalidated; written test no second aggregate; C2. → `8d652d8d37efa4` |
| F04 | high | `packages/drive/src/Actions/Nodes/CopyNodeAction.php:132` | CORRECT | Per-file copy/growing restore admission; tests quota/head/revisions unchanged, smaller restore allowed. C2. → `add57ffd206590` |
| F05 | high | `packages/documents/src/Actions/Documents/UpdateDocumentContentAction.php:62` | MOVE | P4.6 V82/V83 locked liveness + archive; lock redesign kept together. → `PLAN2.P4.6` |
| F06 | medium | `packages/documents/src/Actions/Comments/AddCommentAction.php:40` | MOVE | P4.6 T→N→comment, sameStorage and Mutation author-delete; V82/V83. → `PLAN2.P4.6` |
| F07 | medium | `knowledge/P4-design#Access/Snapshots/Errors;knowledge/P4-invariants#Invariants` | CORRECT | D54 accepted; design/invariant/coverage notes corrected through plan.py. → `PLAN2.D54` |
| F08 | medium | `artifacts/coverage/ledger-P4.tsv#12:7e1380c4` | MOVE | Unit transferred P4.6; serialized recount+audit/dry-run/idempotence tests V84/V85. → `PLAN2.P4.6` |
| F09 | high | `log.jsonl:290;packages/core/database/migrations/2026_10_01_000007_blob_scope_key.php#down` | MOVE | P4.6 additive rollback safety/preflight before destructive DDL V86; no operational off downgrade until fixed. → `PLAN2.P4.6` |
| F10 | medium | `packages/core/tests/Support/DomainExceptionStatusTest.php:25` | CORRECT | 14 cases over real Laravel route JSON+HTML Accept; boot/integration500 explicitly distinguished, C1. → `1e906591d19db5` |
| F11 | medium | `phases/P4/P4.7.json#validation/V3;phases/P4/P4.5.json#task_files` | CORRECT | Correct cwd/path/change; expand task braces; D51/53/54 future contexts. → `plan/metadata` |
| F12 | medium | `artifacts/P4.13/review-verdict.md:8` | CORRECT | New Node/Grant/Share/Activity timestamp UTC string/null assertion C1. → `1e906591d19db5` |
| F13 | high | `packages/core/src/Repositories/Drive/NodeReadRepository.php:49` | MOVE | P4.6 V87 full traversal or fail-closed BEFORE writes; depths65/100/config>100 payload+ACL+quota/CAS/event membership. → `PLAN2.P4.6` |
| F14 | low | `packages/core/src/Data/Page.php:23` | CORRECT | Mark internal; properties/count/hasMore/iterator frozen, C1. → `1e906591d19db5` |
| F15 | high | `packages/documents/src/Actions/Comments/AddCommentAction.php:49;packages/documents/src/Events/Comments/CommentAdded.php:21` | MOVE | P4.6 V88: outermost afterCommit, exactly-once success and no event on rollback across add/resolve/delete. → `PLAN2.P4.6` |
| F16 | high | `packages/documents/src/Operations/DocumentOperations.php:157;packages/documents/src/Actions/Documents/UpdateDocumentContentAction.php:73` | MOVE | P4.7 check locked Document writeability; PG DegradedContentRaceTest V4, no new codec contract. → `PLAN2.P4.7` |

Для каждой записи ledger сохранены исходный ref, сценарий/обоснование, действие, target и честный status (`corrected_unverified` для кода). `REVERT EV224` — историческая slot→aggregate идея, отменённая EV230 ещё до audit; окончательный no-op не откатывался.

## Сверка закрытых пунктов

| Item | Статическая сверка | Результат |
|---|---|---|
| P4.1 | 8 include-обязательств; 110 OPUS units; D34, D35, D54 | F05, F15, F16; runtime по check-plan |
| P4.2 | 5 include-обязательств; 14 OPUS units; D30, D48, D54 | F13; runtime по check-plan |
| P4.8 | 8 include-обязательств; 25 OPUS units; D34, D35, D38, D44, D45, D54 | F03, F05, F06, F15, F16; runtime по check-plan |
| P4.9 | 4 include-обязательств; 18 OPUS units; D45, D46, D53 | F07, F13; runtime по check-plan |
| P4.10 | 3 include-обязательств; 1 OPUS units; D47, D53, D54 | EV275; runtime по check-plan |
| P4.11 | 5 include-обязательств; 1 OPUS units; D39, D40, D41, D42, D43, D54 | F03, F04, F13, EV249; runtime по check-plan |
| P4.12 | 4 include-обязательств; 2 OPUS units; D48, D49, D54 | F04, F08, F09; runtime по check-plan |
| P4.13 | 6 include-обязательств; 2 OPUS units; D44, D54 | F01, F02, F10, F12, F14; runtime по check-plan |

Полные include-строки, unit ids и code paths — `item-matrix.json`; буквальный норматив — knowledge/artifacts-P4-design-<item>-opus. Нет generic «всё выполнено»: новые найденные пробелы вынесены в F-таблицу. Ранние отклонения закрыты D11/D18/D19/D30–D33; published history/неизвестные mutation verdict не переписывались.

## Сквозные швы

| Шов | Вывод и действие |
|---|---|
| Snapshot/Page/WriteScope ↔ Documents API ↔ type availability | Публичные Operations возвращают snapshots/Page, core через NodeDocumentHeads registry wiring; shape accepted D54; F01/F02/F14 corrected, F16 locked writeability MOVE P4.7; full codecs excluded. |
| Access roles ↔ Documents/share/revisions abilities | View/Download split и per-node copy authorization согласованы по code trace; D53 source priority reconciled. H1 tenant receipt still pending. |
| Revision/quota ↔ copy/purge ↔ single writer | Quota all retained revisions, D34 checkpoints/source working copy согласованы; F04 corrected, F08 recount MOVE, F13 traversal MOVE. |
| name_key/NamePolicy ↔ create/copy/move/restore | NFC(casefold(NFC)) key + policy on new names traced; initial generated document variant bypass F03 fixed; source legacy names not silently normalized. |
| shares ↔ tenancy/egress | ShareResolver issuer/audience/generation/password checks + safe node scope, ContentAvailabilityGuard byte boundary traced; previewer has no Download. No new static leak found on inspected path; race/backend acceptance not executed. |
| errors ↔ HTTP ↔ docs ↔ datasets | revision_pinned/comment_not_found entered design D54, docs already described; new core real-route14-case dataset, documents dataset retained. No claim these unrun tests passed. |
| public golden ↔ UPGRADE/CHANGELOG ↔ internal | Missing DTO properties freeze repaired; Page ctor internal. Root upgrade/changelog and en/ru drive docs updated. Golden files intentionally not regenerated until next session G1. |
| config ↔ normalizer ↔ docs | P4.11 fixed name limit, P4.1 checkpoint key, P4.12 global dedup refusal consistent. P4.3 default_type/types legacy normalization full audit excluded; V82 and en/ru leftovers owned by closure. |

## Изменения плана и незакрытая работа

D54 принят по явным полномочиям prompt («все отклонения … разрулила, согласовала») с actor `openai/gpt-6`; effort не задан, поскольку наблюдаемого значения не было. D53 получил applies_to и включён в P4.9 decisions. Новая политика проверок не введена: действует D51. Closed items не переоткрывались и their historical RED/skipped receipts не исправлялись на GREEN.

P4.4: delete exporter+core content вместе; P4.5: final enabled/type/schema/ro/live hub seam и schema.js create; P4.7: исправленный drive PG V3 и F16 V4; P4.6: F05/F06/F08/F09/F13/F15, tests V82–V88, status enum/trashRoot, guard/AzGuard barrier. P5: missing Laravel→EventType pipeline; P6: client fields/naming/ru mirrors; P7: operational commands/runbooks; P8: actual compatibility/release matrix without old MySQL baseline debt. OLD-Q5 также закрыт фактическим capability guard и существующим NodeSequenceSchemaTest; повторная V89 не нужна.

Три обязательства перед ближайшими прогонaми: закончить P4.3 doc leftovers, написать его fresh-app boot test V82, восстановить штатный AzGuard dependency для соответствующей полосы. Новые F-group тесты ещё отсутствуют и запускаются только после owning реализации. Открытые HIGH не исчезают после успешных SQLite команд.

## Проверки и коммиты

`php -l` и Pint выполнены только по изменённым PHP файлам core/drive/documents, exit0; receipts `cheap-checks-*.json`. Финальные plan check: 0 ошибок/0 предупреждений; OPUS: 1438 единиц, 254 секции, 0 нарушений; render выполнен, git diff --check exit0. Receipts — `cheap-checks-plan-final.json`; промежуточный coverage FAIL из-за устаревшего P4.7 literal input исправлен regeneration через plan.py и сохранён как факт. Heavy runs не выполнялись.

- `1e906591` — fix(vaulter-stable): Защита WriteScope и фиксация snapshot API (PLAN2 audit 2026-10-08)
- `add57ffd` — fix(vaulter-stable): Лимит размера при copy и restore ревизии (PLAN2 audit 2026-10-08)
- `8d652d8d` — fix(vaulter-stable): Проверка имени документа после conflict rename (PLAN2 audit 2026-10-08)

- `b9445909` — test(vaulter-stable): Golden для полей presentation DTO (PLAN2 audit 2026-10-08)

Документация, JSON плана, generated views и audit artifacts — отдельный финальный scoped commit этой ветки (sha сообщается при доставке, без self-referential hash в artifact). Push не выполнялся. Единый следующий порядок: [`check-plan.md`](check-plan.md).
