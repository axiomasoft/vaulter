# План проверок после mid-audit 2026-10-08

Это единственный актуальный порядок прогонов после аудита. В этой сессии **не запускались**
Pest/PHPUnit, PHPStan, PG/MySQL, хаб, CI или negative controls. Написанные тесты ещё не доказали
поведение. Выполнены только `php -l`, Pint изменённых файлов и проверки JSON/OPUS.

Корень: `/home/vostrikov/projects/packages/vaulter`; ветка `audit/2026-10-08`.
PHP — `scripts/php84`; сначала проверить `git status --short`. Не трогать чужие dirty пути;
никаких mutation tools, push, amend, `--no-verify`, `git add -A`. Команды ниже сохранять с
cwd, env, выводом, exit, executed/passed/skipped, временем и candidate/source hashes в
`artifacts/P4-midaudit/receipts/`. Красный тест или skip не равен pass. Повторять только при
изменившихся входах или после owning исправления (D51).

## 0. Перед запуском

- P4.3 перевезён в WIP-коммите `977f856d`, а не в dirty tree. Полный type/codec аудит этой
  сессией исключён по prompt. Item остаётся `in_progress`; четыре audit fix-коммита не закрывают его.
- Прочитать `ledger.json`, `item-matrix.json`, `P4.3/check-profile.json`, `completion-notes.md`,
  `negative-controls.md`, обновлённый `sonnet-handoff.md`. EV320–329 требуют проверки решения
  при закрытии P4.3; принятие этой работы данным аудитом не подразумевается.
- До P4.3 V82 написать указанный в scope fresh-application provider boot test
  `packages/documents/tests/Providers/InvalidDefaultDocumentTypeTest.php` (NOTE330). Проверять
  настоящий booted callback с отсутствующим default_type и зарегистрированным default;
  не обходить freeze/boot вручную. Файл пока **не создан**.
- Завершить en/ru doc leftovers, перечисленные в handoff P4.3 §4, по текущему API. Это не
  основание переписывать файлы чужой сессии; baseline сейчас закоммичен.
- AzGuard: штатно восстановить зависимости `axioma-studio/azguard-core` и
  `axioma-studio/azguard-context`, требуемые `packages/azgard/composer.json`. Если package
  недоступен, остановить только эту полосу и оставить её non-green. Не использовать временный
  composer override и не превращать отсутствие dependency в skip. Остальные SQLite проверки
  могут идти независимо; фазу с неполной матрицей не закрывать.

## G1. Golden: сначала разрешённые изменения поверхности

Сверить source diff с D54 и WIP P4.3, затем регенерировать **только два** golden-файла:

```bash
# cwd: repository root
UPDATE_SNAPSHOT=1 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch/PublicApiSnapshotTest.php
# cwd: packages/documents
UPDATE_SNAPSHOT=1 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch/PublicApiSnapshotTest.php
```

Ожидаемый diff core `packages/core/tests/Arch/__snapshots__/public-api.txt`:
добавлены поля всех публичных `Data/Snapshots/*` (без `Mapping/*`), `Page` с
`items/nextCursor/scanned/count/hasMore/getIterator` (без internal constructor), случаи
`QuotaState`, `ScanStatus`, `ShareAudience`; WIP добавляет `KindPresenter`, меняет
`ContentCapabilities` без richText и `NodePresentation` с nullable id/documentType/typeAvailable.
Generator теперь также фиксирует поля и методы ContentCapabilities, NodePresentation,
ViewerDescriptor и FileDescriptor. Убедиться, что соответствующие блоки добавлены в golden. Service-locator
baseline не увеличивать; это отдельный ratchet, не разрешение обновить его вверх.

Ожидаемый diff documents: Contracts `DocumentType/DocumentCodec/DocumentContent/
SupportsCollaboration/UpgradesSchema`, `Types/*`, публичные `Content/*`, `DocumentPresenter`,
`DocumentTypeContractTests`, новые/перемещённые exceptions, `DocumentsConfig::types/defaultType`
без enabledDocumentTypes/spreadsheetsEnabled; `create(?string $type=null)`, content contract в
DocumentSnapshot, registry/facade Types namespace. Источник подробностей — handoff P4.3 §1.
Не принимать неожиданное удаление методов или изменение abilities. Audit Action corrections
не меняют public signatures. Drive golden регенерировать **не требуется**.

```bash
# cwd: repository root
 git diff -- packages/core/tests/Arch/__snapshots__/public-api.txt packages/documents/tests/Arch/__snapshots__/public-api.txt
```

После утверждения ожидаемого diff убрать `UPDATE_SNAPSHOT` из среды на всех дальнейших шагах.
G1 подтверждает F02/F14, EV295/296/311 и WIP surface; не доказывает codec safety.

## C1. Arch и HTTP/value regressions

```bash
# cwd: repository root
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch packages/core/tests/Support packages/core/tests/WriteGuard
# cwd: packages/drive
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch tests/WriteGuard
# cwd: packages/documents
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch
```

Ожидание: exit0. F01: derived query builder и returning insert не пишут без scope;
в scope запись разрешена. Возрастная ветка Laravel без `insertOrIgnoreReturning` имеет
один объяснённый skip только этого version-specific case; на версии с методом skip запрещён.
F10: 14 HTTP cases JSON+HTML; клиентские domain ошибки 409/413/422, integration/boot500
только явно перечисленные. F12: Node/Grant/Share/Activity даты — UTC ISO strings, null сохранён.
F02/F14: golden и readonly properties фиксируются; Operations/handler не утекли в Eloquent.

## C2. Исправления admission + закрытие P4.3 V1/V2/V3/V82

```bash
# cwd: packages/drive
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Drive/DrivePolicyEnforcementTest.php tests/Drive/CopyNodeTest.php tests/Drive/QuotaLedgerTest.php tests/Drive/RevisionRetentionTest.php tests/PublicApi
# cwd: packages/documents — P4.3 V1
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Types tests/Document tests/Unit tests/Operations
# cwd: repository root — P4.3 V2
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Configuration packages/core/tests/Profiles
# cwd: packages/filament — P4.3 V3
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Feature
# cwd: packages/documents — P4.3 V82, только после написания теста
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Providers/InvalidDefaultDocumentTypeTest.php
```

Arch-части P4.3 V1/V2 и Support-часть V2x уже подтверждены в C1: привязать его
receipt к этим validation IDs, повторять только после изменения входов. Остальные директории
запускаются выше.

F03: конфликтное имя `a.`+253 символа расширения отклоняется без второго Document.
F04: lowered maxFileBytes проверяется на standalone/subtree copy и growing restore;
refusal не меняет head/revision count/quota, smaller restore после снижения лимита разрешён.
P4.3: V10 unknown type content/copy/trash, V34 file preview-only, duplicate/reserved/freeze,
degraded/newer schema read-only и no lossy save в последовательном случае. Тип/schema races
отдельно F16/P4.7: прохождение C2 их не закрывает. V82 требует nonzero executed и реальный boot.

## C3. Затронутые потребители и полоса guard

```bash
# cwd: packages/documents — P4.3 impact V1x
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Feature tests/Profiles tests/Providers tests/Configuration tests/Collaboration tests/Comment tests/Schema
# cwd: repository root — P4.3 impact V2x
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Feature packages/core/tests/Unit packages/core/tests/Content packages/core/tests/ObservabilityDocuments packages/core/tests/E2E packages/core/tests/Providers
# cwd: packages/drive
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_WRITE_GUARD=1 ../../scripts/php84 vendor/bin/pest --ci tests/PublicApi tests/Drive/DrivePolicyEnforcementTest.php tests/Drive/QuotaLedgerTest.php tests/Uploads/P3/ScanWorkRecoveryTest.php
# cwd: packages/documents
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_WRITE_GUARD=1 ../../scripts/php84 vendor/bin/pest --ci tests/Operations tests/Document/DocumentCopyLifecycleTest.php
# cwd: packages/media
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_WRITE_GUARD=1 ../../scripts/php84 vendor/bin/pest --ci tests/Cas/BlobGcBatchTest.php
# cwd: packages/azgard — после штатного восстановления dependencies
 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests
```

Ожидание exit0: internal writers сами открывают scope, фикстуры используют объявленные
helpers; batched GC не переключён на N+1 model events. Не исправлять guard failure глобальным
выключением флага/ручным binding. AzGuard dependency failure остаётся реальным препятствием
своей полосы. SQLite skips исключительно PG cases записывать по именам; не считать их выполненными.

## C4. P4.3 V80/V81, static analysis и negative controls

Pint проверить из cwd пакета; для core корневой конфиг. Audit PHP paths перечислены в
`code-commits.json`; применять Pint только к изменённым в текущей check-сессии файлам при
исправлении. В следующей сессии package-wide `--test` из V80 разрешён, в audit-сессии он не запускался.

```bash
# cwd: repository root
 scripts/php84 vendor/bin/pint --test packages/core
 scripts/php84 vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
# cwd: packages/documents
 ../../scripts/php84 vendor/bin/pint --test
 ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress
# cwd: packages/drive
 ../../scripts/php84 vendor/bin/pint --test
 ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress
# cwd: packages/filament
 ../../scripts/php84 vendor/bin/pint --test
 ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress
# cwd: packages/azgard — dependency gate только этой полосы
 ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress
# cwd: repository root — P4.3 V81
 python3 plans/2026.09.29-№1-VAULTER-STABLE/artifacts/coverage/opus-coverage.py check
 python3 /home/vostrikov/.codex/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py check 2026.09.29-№1-VAULTER-STABLE
```

PHPStan exit0, без suppressions для сломанного контракта. Laravel12/13: returning-insert
statement не помечен Override, потому что older version его не имеет; проверить signature
и анализ на обеих поддерживаемых версиях в consumer/compatibility matrix P8, не менять
lockfile на месте ради текущего прогона. Current local анализ не доказывает второй вариант.

P4.3 NC1–NC5 из `P4.3/negative-controls.md` выполнить по таблице (не mutator):
NC1 freeze registry, NC2 reserved prefix, NC3 degraded refusal, NC4 core no editor names,
NC5 kind presenter/V34. Только точные named tests, среда SQLite как C2, UPDATE_SNAPSHOT unset.
Записать actual RED, восстановить точные исходные bytes/hash, затем GREEN; не делать git reset
или checkout поверх чужих правок. Дополнительные audit controls:

| ID | Временная поломка | Точная проверка | Ожидание |
|---|---|---|---|
| A-NC1 | убрать `$query->writeScope = $this->writeScope` в GuardsStatements::newQuery | root, `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/WriteGuard/WriteScopeTest.php --filter="fresh statement builder"` | RED: ожидаемая WriteOutsideOperationException не возникла; restore→GREEN |
| A-NC2 | убрать `assertFileSize` из CopyNodeAction::assertLimits | packages/drive, `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Drive/DrivePolicyEnforcementTest.php --filter="file copies"` | RED: copy не отказала; restore→GREEN |
| A-NC3 | удалить повторную проверку generated candidate policy в CreateDocumentAction::freeName | packages/documents, `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Operations/DocumentOperationsTest.php --filter="oversized generated"` | RED: no InvalidNodeNameException/второй Document; restore→GREEN |
| A-NC4 | изменить type свойства NodeSnapshot::$sizeBytes в рамках совместимого reflection-only изменения | root, `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch/PublicApiSnapshotTest.php` | RED golden diff именно этого свойства; восстановить до запуска прочих тестов |

Если мутация контроля не дошла до проверяемого поведения и дала syntax/setup error,
это не валидный RED control. Уточнить bounded control и сохранить оба вывода.

## H1. Уже написанные отложенные PG acceptance, один интеграционный барьер

Перед каждой DB полосой применить native test-isolation-guard/db preflight. Host connection,
не Docker DNS; **только** `vaulter_test`, не dev/prod. Команда проверки существующей базы:

```bash
# cwd: repository root
 env DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret bash scripts/db-preflight.sh pgsql
```

Никакой автоматической пересоздачи БД при отказе preflight. Эти два пропущенных acceptance
не подтверждены SQLite; выполнить на согласованном candidate в next check-сессии, если она
закрывает mid-audit integration block, либо единожды owning P4.7 V90 (D51). Записать, где
receipt уже получен; не гонять второй раз на неизменных входах.

```bash
# cwd: packages/documents — P4.2 V4
 env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Document/DocumentCopyLifecycleTest.php
# cwd: packages/documents — P4.8 V4
 env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Operations/DocumentAbilityMatrixTest.php --filter="tenant"
```

Ожидание executed>=1, skipped0, exit0 и именованные matched tests. Подтверждают lifecycle,
tenant boundaries, View/Download distinction; не закрывают F05/F06/F13/F15/F16.

## H2. Кумулятивный PG barrier P4.6, только если evidence устарела/отсутствует

Current audit менял только guard/admission, а не порядок блокировок; прежние зелёные
P4.9/P4.12 PG/MySQL receipts не повторять ради нового SHA. На итоговом P4.6 phase barrier
исполнять actual phase.validation, добавив эти follow-ups, если конкретные входы изменились
или нет пригодного receipt:

```bash
# cwd: packages/drive; тот же PG preflight и env
 env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Concurrency/OutermostRetryRealFailureTest.php tests/Concurrency/ScanWorkRecoveryRaceTest.php tests/Concurrency/RevisionPruneRaceTest.php tests/Concurrency/QuotaRaceTest.php tests/Concurrency/NodeSequenceBoundaryTest.php
```

OLD-R1: backend SQLSTATE40P01/40001, повтор всего unit, exhaustion409, no partial write;
scan state Actions не нарушили восстановление; retention+quota защита head/pin. OLD-Q3 не
добавляет отдельного запуска: local framework aggregate()->setAggregate снимает ORDER BY
при отсутствии groups, поэтому подозрение PG42803 отвергнуто чтением.

MySQL run сейчас не нужен: схема не менялась. Final P4.6 собственные V5/phase.validation
требуют MySQL по новым миграциям; preflight команда там:
`env DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret bash scripts/db-preflight.sh mysql`.
D32 закрывает старый MySQL baseline, MariaDB best-effort вне обязательной матрицы.

## F. Новые owning tests после реализации MOVE (не запускать отсутствующие файлы сейчас)

Эти проверки — обязательные acceptance будущих items, не утверждение, что код уже исправлен.
Точные команды также записаны JSON validation; порядок реализации P4.4→P4.5→P4.7→P4.6 сохранён.

| Item/check | cwd | env и команда | Что должно доказать |
|---|---|---|---|
| P4.7 V4 | packages/documents | `env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Concurrency/DegradedContentRaceTest.php` | F16: restore нового schema после optimistic precheck не позволяет lossy overwrite; executed>=1,skipped0 |
| P4.6 V82 | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Operations/MutationLivenessTest.php` | F05/F06 locked trash/archive/deleting refuse writes; state/revisions/comments unchanged |
| P4.6 V83 | packages/documents | `env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Concurrency/DocumentMutationLivenessRaceTest.php tests/Concurrency/CommentMutationRaceTest.php` | реальные backend pids/barrier, no late mutation after lifecycle linearization |
| P4.6 V84 | packages/drive | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Drive/RecountDrivesTest.php` | F08 recount all retained/trash, dry-run no writes, scope isolation, audit drift, idempotence |
| P4.6 V85 | packages/drive | `env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Concurrency/QuotaRecountRaceTest.php` | recount||upload/deleteRevision не перезаписывает quota stale суммой |
| P4.6 V86 | root | `env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret scripts/php84 vendor/bin/pest --ci packages/core/tests/Upgrade/OffDedupRollbackSafetyTest.php` | F09 equal-byte off: lossless rollback либо preflight отказ ДО destructive DDL; CAS/tenant refs целы |
| P4.6 V87 | packages/drive | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Drive/DeepSubtreeLifecycleTest.php` | F13 depth65/100/profile>100 complete membership+ACL+quota/CAS/event ids или fail-closed before any write |
| P4.6 V88 | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/AfterCommit/CommentAfterCommitTest.php` | F15 события отсутствуют до outer commit/после rollback, ровно одно after commit; fixture DatabaseMigrations |

OLD-Q5/D33 уже реализован: SchemaBuilder::addNodeSequence вызывает capability guard до DDL,
NodeSequenceSchemaTest имеет refusal dataset. Дополнительная MySQL V89 не нужна; существующие
schema barriers сохраняются. Это статическая сверка, новые прогоны не выполнялись.

OLD-Q2: P8 release design получает отдельный large-file idle-in-transaction/lock-hold
measurement через существующий load-стенд и принятую budget policy; команда определяется
на детализации P8 по фактическому стенду, сейчас load не запускается.

P4.4: удалить core DocumentExporter+DocumentContent вместе (EV320), execute its own V1/V2.
P4.5: own V1/V3/V4/V5 доказывают PHP feature/type/schema gating, server load/seed/fence,
ro-store refusal and restore room generation. Хаб ещё не реализован: не запускать e2e в этой
check-сессии P4.3. P4.6 phase barrier включает остальные V901–V931 по JSON, без новых
полных review и без mutation.

## Остановка и закрытие

Мелочь можно чинить в рамках текущей check-сессии: imports, precise PHPStan shapes,
форматирование, documented golden registration, stale test names, en/ru doc mirrors. Каждую
правку записать в P4.3 log (actual observed actor) и перезапустить только затронутый check.

СТОП и owning item/владелец: утечка tenant/Download, потеря bytes/revisions, иной lock order,
неожиданная миграция/public signature/ability, malformed codec accepting lossy write,
необъяснённый golden diff, guard repair через выключение защиты, required PG skip/0 tests,
DB preflight на неверной базе. Зафиксировать реальный failure и входы, без ослабления тестов.

P4.3 finish — только после V1/V2/V3/V80/V81/V82, NC1–NC5, doc leftovers и решений по
EV320–329. Уже выполненные G1/C1 части могут быть использованы как receipt своего broader
check по неизменным входам, но нельзя объявить весь набор выполненным по его подмножеству.
Открытые HIGH MOVE F05/F09/F13/F15/F16 остаются открыты до owning acceptance P4.6/P4.7;
прохождение текущих C-групп не является доказательством готовности 1.0.
