# Продолжение PLAN3 P3.8–P3.10: ревью (Часть А) и прогоны/фиксы (Часть Б)

Репозиторий: `/home/vostrikov/projects/packages/vaulter`, ветка `main`, база — `04aa3640`.
Код P3.8–P3.10 написан в рабочем дереве **без коммита** (≈225 путей: `git status --short`, `git diff HEAD`
плюс неотслеживаемые файлы). Пункты в статусе `in_progress`, проверки (Validation) ещё не прогонялись,
кроме быстрых точечных (см. «Что уже проверено»).

`plan.py` = `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py`,
план = `2026.10.13-№1-VAULTER-KERNEL`. Контекст пункта: `plan.py context <план> P3.x --stdout` (JSON;
сгенерированные Markdown-виды не читать и не править). Журнал решений исполнителя:
`plan.py show <план> P3.8|P3.9|P3.10` и `plans/2026.10.13-№1-VAULTER-KERNEL/log.jsonl` (события
assumption/deviation/blocker/note от 2026-10-14 по P3.8–P3.10).

## Что сделано (кратко)

- **P3.8** — нейтральный `Vaulter\Operations\OperationContext` (actor, `TenantRef`, `ScopeRef`, correlation/causation,
  idempotency key 1–255 байт, systemReason, `telemetry()` без PII); новый `DriveContext` (operation + drive, фабрики
  `for()`/`system()`, проверка persisted drive и scope=drive); механическая замена `OperationContext` → `DriveContext`
  во всех src/tests пакетов; `HttpOperationContextFactory::operation()/driveContext()`; target-map, allowlists i1/i2,
  public-api снимки, `OperationContextTest`.
- **P3.9** — `OperationRunner::write()` (стадии 1–3, 5–9; стадия 4 — seam `OperationClaim` + `NoOperationClaim`),
  `OperationSpec`, `Operation`, слот `OperationMiddleware` в `CoreModule` с вкладом `core.telemetry`
  (`Observability\TelemetryMiddleware`), `ModuleReadiness` (memo, один запрос к каталогу), problem codes
  `extension_failed` (InvalidExtensionResult стал ProblemException) и `module_not_ready`; lang en/ru, docs errors +
  reference, openapi enum, identities/ledger (D8), зона `core-operations`, ADR-0028, `docs/concepts/operations.md`
  (EN/RU); тесты `OperationRunnerContractTest` (DatabaseMigrations), `ModuleReadinessTest`, `TelemetryMiddlewareTest`.
- **P3.10** — все мутирующие public-методы drive/documents `*Operations`, core-located `AccessOperations`/
  `ShareOperations` и webhook endpoint CRUD (через новый `Vaulter\Webhooks\WebhookEndpointWrites`) идут через
  раннер; авторизация внутри попытки; identity `format:operation-name` (40 имён, D25); arch-тест
  `OperationsUseRunnerTest`; spy-тест `drive/tests/PublicApi/AuthorizationInsideOperationTest`.

## Что уже проверено (исполнителем, 2026-10-14)

- Pint/PHPStan: core (корневой), drive, documents, filament — зелёные.
- `inventory.php --check` — зелёный; `check-doc-links` (+`--mirror`) — зелёный.
- Точечно sqlite: core `tests/Operations`, `tests/Arch/OperationsUseRunnerTest.php`, `tests/Arch/ArchTest.php`,
  `Observability/TelemetryMiddlewareTest.php`, `Support/DomainExceptionStatusTest.php`, `Access`, `Sharing`,
  `Webhooks`, `Http`; drive `tests/PublicApi`, `tests/Drive`; весь `documents` (sqlite); `repo` ZonesMap/TestGroups.
- **Известно красное:** drive `tests/PublicApi/LifecycleBoundaryTest.php` — 2 кейса «repeats public destination
  authorization when the original parent changes after precheck» (событие blocker P3.10 в журнале).
- **Предсуществующее, не от диффа:** `scripts/check-doc-snippets.php docs` — 4 проблемы (`vaulter:webhooks:*`
  не зарегистрированы при webhooks=off); azgard RealAzgard* — дрейф `../azguard` (`PanelBuilder::scopes()`), см.
  события P3.4/P3.5.

---

## Часть А — ревью на Grok 4.7 (read-only, без длинных прогонов)

**Стратегия:** три независимых ревьюера **параллельно** (у каждого свежий контекст, один дифф-срез), затем один
сводный отчёт. P3.8 — механический, его можно ревьюить облегчённо; основной вес — на P3.9 и P3.10 (жёсткий риск:
concurrency/транзакции, авторизация, публичный API).

**Общие правила для всех ревьюеров**

- Только чтение: никаких правок кода, `git add/commit/stash/checkout`, `plan.py start/finish/commit`.
- Разрешённые проверки (быстрые): `scripts/php84 php vendor/bin/phpstan analyse …` пакета, `pint --test`,
  `scripts/php84 php scripts/inventory/inventory.php --check`, точечные тесты `scripts/t sqlite -p <pkg> <1–3 файла>`.
- **Запрещено:** полные наборы (`scripts/t sqlite -p <pkg>` без путей), любые `pgsql`/`mysql`, `scripts/t all`,
  `baseline`, мутации, load/k6, coverage, `composer update`.
- Находка = серьёзность (BLOCKER/HIGH/MEDIUM/LOW), `файл:строка`, конкретный сценарий отказа (вход/состояние →
  неверный результат), ссылка на нарушенное правило пункта/решение (D5, D8, D21, D25, D27) или инвариант (R1–R5).
  Без доказуемого сценария — не находка, а вопрос.
- Источник требований: `plan.py context <план> P3.x --stdout` — `item.rules`, `item.scope`, `documents`
  (`knowledge/p3-operations#operation-context|operation-runner`, `knowledge/p3-modules#slots`,
  `knowledge/architecture#operation-chains`), `decisions`.
- Результат: `plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P3.8-P3.10/review-<R1|R2|R3>.md` (вердикт
  APPROVE / APPROVE_WITH_NITS / CHANGES_REQUESTED, список находок, что проверено и чем, фактическая сессия/модель).

**R1 — P3.8 (контекст).** Файлы: `packages/core/src/Operations/{OperationContext,DriveContext}.php`,
`packages/core/src/ValueObjects/Operations/*`, `packages/core/src/Http/Support/HttpOperationContextFactory.php`,
выборочно механическая замена (`git diff HEAD -- packages/*/src | grep -n DriveContext`), снимки
`packages/*/tests/Arch/__snapshots__/public-api.txt`, `tests/Repo/Arch/Fixtures/{target-map/rules,allowlists}`.
Вопросы: telemetry без PII и без ключа; та же валидация и сообщения (correlation, persisted drive);
`driveId()` переживает правку модели; не осталось ли конструирования контекста с иной семантикой;
корректность удаления stale-записей allowlist.

**R2 — P3.9 (раннер).** Файлы: `packages/core/src/Operations/{OperationRunner,Operation,OperationSpec,
OperationMiddleware,OperationClaim,NoOperationClaim,ModuleReadiness}.php`, `Observability/TelemetryMiddleware.php`,
`Exceptions/{Modules/ModuleNotReadyException,Support/InvalidExtensionResult}.php`, `CoreModule.php`,
`Providers/BindingsRegistrar.php`, `Doctor/Checks/SchemaTablesCheck.php`, тесты `packages/core/tests/Operations/*`,
`Observability/TelemetryMiddlewareTest.php`, ADR `docs/adr/0028-*.md`.
Вопросы: порядок стадий; nested — без собственной транзакции/ретрая/afterCommit; ретрай только на уровне 0 целой
попытки, `attemptNo`, ровно один набор afterCommit; `depth` корректен при исключениях, при Octane (singleton);
middleware: ровно один `$next`, `===`, тот же Operation, вето до `$next`; readiness: ноль запросов на boot, один на
первый write, SQL каталога на pgsql (`current_schema()`)/mysql (`database()`) и префикс соединения; поведение при
транзакции хоста (RefreshDatabase/внешний `DB::transaction`); коды/переводы/identities.

**R3 — P3.10 (перевод операций).** Файлы: `packages/drive/src/Operations/*.php`,
`packages/documents/src/Operations/*.php`, `packages/core/src/Operations/{Access,Share}Operations.php`,
`packages/core/src/Webhooks/WebhookEndpointWrites.php`, `packages/core/src/Http/Controllers/Webhooks/*`,
`packages/core/tests/Arch/OperationsUseRunnerTest.php`, `packages/drive/tests/PublicApi/
AuthorizationInsideOperationTest.php`, `tests/Repo/Arch/Fixtures/identities/*`.
Вопросы: эквивалентность поведения каждого метода (коды, события, порядок блокировок); авторизация ровно один
раз и внутри попытки (кроме осознанного отклонения upload/addRevision — оценить его безопасность); байты/сеть вне
повторяемой попытки (upload staging, tus patch/S3 putPart/complete/initialise/abort, purge, promote); семантика
`TrashOperations::{purge,empty,restore}` (логирование отказов, счётчик, повтор при `RestoreDestinationChanged`);
`UploadOperations::complete` (ретрай внутри раннера вместо `withSession(attempts: 3)`); webhook CRUD: порядок
401/403/404, tenancy, гость; полнота `OPERATIONS_READS` и исключений arch-теста; задача blocker
`LifecycleBoundaryTest` — предложить корректную форму теста гонки.

**Сводка:** после трёх ревью — `artifacts/P3.8-P3.10/review-summary.md`: дедуп находок, привязка к пункту, что
чинить в Части Б (только CONFIRMED с доказуемым сценарием), что — рекомендации/вопросы владельцу.
Записать в журнал: `plan.py log <план> P3.x --type note --summary "Ревью R#: <вердикт>, <N> находок, файл …"`.

---

## Часть Б — прогоны и фиксы (после Части А)

1. Сессия объявляет свой фактический route (`--actor <provider>/<model>/<effort>` из настроек рантайма, не из текста).
   Пункты уже `in_progress`; повторный `start` не нужен, если `plan.py next` не требует.
2. Применить подтверждённые находки ревью — каждая в своём пункте, в границах `scope.include`; новое отклонение —
   `plan.py log … --type deviation`.
3. Разобрать blocker P3.10 (`LifecycleBoundaryTest`, 2 кейса): переработать имитацию гонки так, чтобы конкурентное
   изменение переживало откат попытки (например, перенос в pgsql `Concurrency` с отдельным соединением/форком),
   **без изменения ожидаемого исключения**; если без изменения ожидания нельзя — остановиться и вынести вопрос владельцу.
4. Прогнать Validation каждого пункта ровно по `item.validation` (длинный вывод — в файл):
   - P3.8: V1–V11 (`scripts/t sqlite -p core|drive|documents|filament|azgard`, Pint/PHPStan core/drive/documents/
     filament, inventory, `scripts/t baseline --compare`);
   - P3.9: V1–V7 (включая `scripts/t pgsql -p core packages/core/tests/Operations/OperationRunnerContractTest.php`);
   - P3.10: V1–V13 (включая pgsql `--group=database` для core Concurrency/Access/Sharing, drive, documents).
   Общие прогоны (sqlite всего пакета, baseline) достаточно выполнить один раз на итоговом дереве и засчитать всем
   пунктам, которые их требуют. Красное вне диффа (azgard RealAzgard-дрейф `../azguard`) — проверить на базе
   (`git worktree` на `04aa3640`), записать deviation с доказательством, не чинить чужое.
5. Ожидаемые доработки по итогам прогонов: тесты, считающие запросы на записях (readiness добавляет 1 запрос на
   первый write процесса); тесты, фиксирующие уровень транзакции/порядок; код `internal_error` → `extension_failed`
   для сбоев NodeSnapshotContributor; `concurrent_update` после исчерпания ретраев. Ожидание существующего теста
   меняется только с deviation и доказательством (правило P3.10).
6. Закрытие: `plan.py finish <план> P3.8|P3.9|P3.10 --status done|done_with_deviations --check V1=0 … --deviation …`.
   Коммит: файлы пунктов пересекаются (замена контекста P3.8 и перевод на раннер P3.10 в одних и тех же Operations) —
   по прецеденту P3.5–P3.7 допустим единый executor-коммит (`plan.py commit <план> P3.10 --role executor` с
   `--path` для файлов P3.8/P3.9 и событием deviation «единый коммит P3.8–P3.10 из-за общих файлов»), либо три
   коммита, если удастся разделить чисто. Никаких `git add -A`, `--no-verify`, amend, push.
7. Финальный отчёт: результат по каждому пункту, отклонения, жёсткие риски (concurrency, авторизация, публичный
   API) с рекомендацией `task:review`, и точная строка `plan.py next <план>`.
