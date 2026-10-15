# Единый ревью P3.16 + P3.17 — grok-4.7 (high), read-only, одна сессия

Маршрут: `plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high`; поток — review-2.jsonl (review.jsonl — оборванный первый запуск, не раунд). Session: 01a13eab-468a-7c22-a494-fc63f8674ba5

**Вердикт: changes.** Поведения `packages/*/src` вне атрибутов, `use Vaulter\Attributes\…` и docblock `@internal` не менялось. Разметка Spi:Call (`SinkResult`, `OutboxEnvelope`, …), `Icon`/`Label`/`HasIcon`/`HasLabel` = Api и `DriveProvisioner` = Internal совпадает с `p3-surface`. Api/Spi не наследуют Internal и не подмешивают Internal-трейт. В `i13.json` 207 записей (не 216): все `internal-type`, `removed_in` P7, `host-input` и `domain-model` нет.

## high

1. `packages/core/tests/Concerns/FreshApplication.php:56`, проверка `packages/core/tests/Modules/ActivationMatrixTest.php:131`.  
   Слушатель `QueryExecuted` ставится в `defineEnvironment`, а Testbench вызывает его после `RegisterProviders` (`vendor/orchestra/testbench-core/src/Concerns/CreatesApplication.php:534` и `:543`). Запрос из `register()` провайдера проходит по sqlite-файлу и не попадает в `FreshApplication::$queries`. `AcmeProbe` считает только конструкторы, которые сами зовут `resolved()`.  
   Репро (не запускать): в `AcmeProvider::register()` добавить `DB::select('select 1')` и `app()->singleton(stdClass::class, fn () => new stdClass)`. `matrixAssertZeroCost` остаётся зелёным.

2. `packages/core/src/Testing/Contracts/ModuleContractTests.php:74`. ADR-0027 (`docs/adr/0027-kernel-boundaries-and-modules.md:74`) обещает нулевую цену резолва. `App::resolving` не видит уже созданные `config` / `Kernel` / `db`, ни `bind`/`singleton`/`instance`/`afterResolving` без сборки. Слушатели после проверки остаются, их глушит только `$watch->on = false`. Отрицательный тест ловит лишь `make(stdClass::class)` и `DB::select`.  
   Репро: `BrokenModule::register()` делает `app('config')->get('vaulter.modules')` и `app()->singleton(...)`. `assertModuleContract` зелёный. Кит также не смотрит ФС, HTTP и часы; `seal` проверяется одним `routes('late.php')`.

3. `packages/core/tests/Modules/AcmeModuleContractTest.php:225`, фикстура `AcmeDownloadGuard.php:31`, обёртка `packages/core/src/Content/DownloadGuards.php:32`.  
   `allow`, `deny` и `explode` ждут один и тот же `ContentUnavailableException`. У голого `new Node` следующий guard (`ContentAvailabilityGuard.php:64`) вызывает `NodeNotFoundException::forId(null)` — `TypeError`, его оборачивают в `ContentUnavailableException`. Вето модуля от «ничего не бросил» неотличимо.  
   Репро: в `AcmeDownloadGuard::check` все три режима сделать пустыми. Тест и `$checks === 1` остаются зелёными.

4. `packages/documents/src/Content/DocumentStateWriter.php:39` (`@internal` и `#[Api]`), манифест `packages/documents/tests/Arch/__snapshots__/surface.txt:50`, правило `other-packages:prefix:Vaulter\Documents\Content\` (`target-map.json:4893`, `note: null`).  
   D27: класс остаётся Internal. Префикс поставил Api, в манифест попали `write()` и `checkpoint()` с доменными моделями (I13 их только откладывает до P7). `apply-surface.php:122` снимает `@internal` только у типа с поверхностью Internal, поэтому старый снимок documents класс по-прежнему прячет, новый — публикует. Тот же разъезд у `ContentDecoder.php:30`, `DocumentContentLifecycle.php:27`, `ReadContent.php:16`.  
   Репро: сравнить эти два снимка documents; правка правила на Internal уронит `surface.txt`, текущие тесты — нет.

5. `packages/core/src/Webhooks/StandardWebhookVerifier.php:24`, эвристика `scripts/inventory/apply-surface.php:262`.  
   Хост вызывает `new StandardWebhookVerifier` (`docs/guides/webhooks.md:143`). Конструктор стал `#[Internal]` из-за необязательного `WebhookSigner` со значением по умолчанию. Вызов в рантайме жив, в манифесте сигнатуры нет.  
   Репро: генератор манифеста по этому файлу не содержит `__construct`; гайд при этом требует именно этот вызов.

## medium

6. `ActivationMatrixTest.php:65` и `:179`. `matrixSurface` сравнивает ключи слушателей, не списки, и только корни `vaulter*`. Тест `drive=false` (D39) проверяет маршруты, одну команду, чужой запрос и счётчик запросов. Слушатель drive на уже известное событие или binding не `AcmeService` тест не краснит.  
   Репро: при неактивном acme повесить слушатель на событие, которое уже есть в графе. `matrixSurface` совпадёт.

7. `AcmeModuleContractTest.php:179`, исполнитель `AcmeRestrictionRunner.php:16`. Сужение считает фикстура и бросает `AcmeRestrictionViolation`. Ядро `SlotKind::Restriction` не вызывает. Удаление проверки из раннера гасит тест, ядро остаётся зелёным. Observer (слушатель Laravel не откатывает транзакцию) не покрыт.

8. `AcmeModuleContractTest.php:166`. «imports ⊆ поверхность» читает только строки `^use` в `AcmeModule/*.php`. FQCN с `\`, строки-имена классов и `Broken/` не видны.  
   Репро: `new \Vaulter\Some\Internal()` без `use`. Тест зелёный.

9. `scripts/inventory/lib/Surface.php:164`. I13 смотрит типы членов из нативного AST. Docblock, `extends`/`implements`/`use`, `@method` фасада и `Illuminate\Database\Eloquent\Model` (его нет в индексе) не нарушения. Ветка `domain-model` недостижима, если модель сама `#[Internal]` (`internal-type` раньше). Allowlist это фиксирует: 207/207 `internal-type`. `Allowlist.php:20`, `:26`, `:34` — `never` только для `host-input`, блок I13 с P7 — согласованы, но категория в списке не используется.

10. `TargetMap.php:213` и `:389`. Снос `packages/core/tests/Arch/__snapshots__/public-api.txt` выключил baseline-guard для типов только core: Internal без note больше не с чем сравнивать. Заметки `Contracts\Drive\DriveHandleFactory` (`target-map.json:3069`) и `Drive\DriveHandleFactory` (`:7633`) всё ещё «P3 decides» / «open», а атрибут уже Internal. `p3-surface` называет `DriveHandleFactory` входом хоста. `OutboxConfig` (`core-kernel.json:726`) хост читает через `VaulterConfig::outbox()` (`VaulterConfig.php:194`), класс Internal, члены в манифест не входят. Осознанно внутренние: `EventTypeCast`, `EventsReference`, `ConsumerState`, `DeliveryState`, `OutboxRelay`, `OutboxEvent`, `HttpResourceResolver`, `ExactListing`, `OperationGuard` — заметки описывают relay, cast и замену middleware.

11. Фабрики `packages/core/database/factories/*` остались `#[Api]`, `Surface::read` ходит только в `packages/*/src`. Старый golden их публиковал, новый манифест нет.

12. `apply-surface.php:100`. Уже стоящий атрибут поверхности не сверяется с картой: `--check` молчит при неверной метке. Повторный прогон идемпотентен и неверен. `GroupUse` в `src` нет.

13. Прирост I2 на атрибуты `Vaulter\Attributes\{Api,Spi,SpiKind,Internal}` — тот же `needs-explicit-require`, что и прочие импорты core при транзитивном require (снятие в P4). Отдельного вида нарушения нет. Узкое исключение сканера для этих четырёх атрибутов не раздувало бы список на каждый новый класс до P4.

## low

14. `config:cache` честно бьёт в ветку `configurationIsCached()` и замороженные корни `vaulter` / `vaulter-acme` (`ConfigCachedProvider`), но это не `ConfigCacheCommand` и не `var_export`. `route:cache` — настоящие `compile()` + `setCompiledRoutes`; `var_export`/require stub нет, `AcmeProbe::reset()` прячет резолвы бута. Для вывода «кэш фиксирует активацию» этого достаточно, для байтового кэша Laravel — нет.

15. `Surface.php:496`. Неразборный аргумент `Spi` становится `Spi:Implement`.

Сопутствующие `zones.json`, `groups.php`, `suites.json` и правило кита в `core-kernel.json` выглядят согласованно: матрица без схемы БД, контракт Acme в database-группе.

## Решения исполнителя (подтверждение прогонами)

| # | Sev | Решение |
|---|---|---|
| 1 | high | Подтверждена репро: провайдер с `DB::select`+`singleton/make` в `register()` оставлял матрицу зелёной (слушатель запросов вешался после регистрации провайдеров). Исправлено: `FreshApplicationSpy` — первым провайдером (запросы + `resolving`); матрица сравнивает список резолвов, bindings и число слушателей на событие между «не установлен» и «установлен, неактивен»; та же репро теперь краснит 4 теста |
| 2 | high | Подтверждена. Кит: добавлены дифф bindings/слушателей/config и `seal` по всем 16 видам деклараций; негативные тесты BINDS/LISTENS/CONFIGURES; пределы (уже созданные объекты, ФС, сеть, часы) записаны в docblock кита и ADR-0027 |
| 3 | high | Подтверждена. Тест вето: guard модуля единственный в реестре (`acmeKernelWith`), allow/deny/explode различимы; порядок «до availability» — отдельный тест |
| 4 | high | Подтверждена: 16 типов с `@internal` в docblock класса были Api/Spi по префиксным правилам. Решение: Internal (по тегу автора и старому снимку: 14 не были публичны; NodePage и MintedShareLink — `@internal` явно называет публичную замену); теги сняты, голдены documents пересобраны |
| 5 | high | Подтверждена (`new StandardWebhookVerifier` в docs/guides/webhooks.md). Эвристика: параметр со значением по умолчанию не делает конструктор техническим; сняты `#[Internal]` с StandardWebhookVerifier, ProfileRegistry, InitiateUploadInput |
| 6 | med | Исправлено вместе с #1 (bindings, слушатели по счётчику, резолвы) |
| 7 | med | Принято как задокументированный пробел: в каталоге 1.0 нет встроенного Restriction-слота и исполнителя в ядре (p3-modules: «механика проверяется фикстурой»); owner-runner в фикстуре — и есть контракт; observer ≠ слот |
| 8 | med | Исправлено: тест читает все имена Vaulter в файлах фикстуры (use, FQCN, ::class, строки), не только `use` |
| 9 | med | Частично: порядок категорий — `domain-model` теперь раньше `internal-type`; пределы I13 (docblock, extends/implements, @method) — по плану («параметр/return/свойство»), оставлены |
| 10 | med | Исправлено: заметки правил 0.x-снимка («P3 decides») заменены решением P3.17; `OutboxConfig` → Api (возвращается публичным `VaulterConfig::outbox()`, конструктор Internal); baseline-guard core снят планом вместе с public-api.txt — принято |
| 11 | med | Исправлено: манифест читает и `database/factories` |
| 12 | med | Отклонено: после перехода target-map на атрибуты карта сама производная от них, сверять нечего; script дополняет только непомеченные типы (кидает на undecided) |
| 13 | med | Отклонено: узкое исключение сканера I2 меняло бы семантику инварианта; запись тождественна ~1000 существующих needs-explicit-require, снимается в P4 одним require core |
| 14 | low | Принято: отклонение уже записано (Testbench не читает кэш конфига), route:cache настоящий |
| 15 | low | Отклонено: аргумент атрибута — константа SpiKind, нераспознаваемый вариант не компилируется |
