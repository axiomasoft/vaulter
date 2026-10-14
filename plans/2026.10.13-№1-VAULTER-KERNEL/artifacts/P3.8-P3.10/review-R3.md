# Ревью R3 — P3.10 (перевод мутирующих операций на OperationRunner)

Ревьюер: R3, модель Sonnet 5.5 (claude-sonnet-5-5), read-only. Дата: 2026-10-14. База `04aa3640`, рабочее дерево без коммита.

## Вердикт: CHANGES_REQUESTED

Поведенческая эквивалентность методов в целом подтверждена: коды, события и порядок блокировок Action-ов не менялись, ретраи перенесены один к одному. Но есть одна находка HIGH (снимок MySQL REPEATABLE READ до блокировки дерева), три MEDIUM (авторизация upload вне попытки, `ScopeRef` из сырого параметра маршрута в webhook-записях, ретраи/повторные `write()` несовместимы с D25) и красный `LifecycleBoundaryTest`. Продуктового дефекта в красном тесте нет, дефектна имитация гонки. Правильная форма предложена ниже.

Всего находок: 10 (1 HIGH, 4 MEDIUM, 3 LOW, 2 вопроса). BLOCKER-ов нет.

## Находки

### F1 — HIGH (только MySQL): снимок REPEATABLE READ берётся до блокировки дерева
- Где: `OperationRunner.php:118-123` (корневая транзакция открывается до попытки) вместе с `FolderOperations.php:25-26`, `NodeOperations.php` (`authorized()` в попытке) и т.д. Затем Action берёт `lockTreeShared` и читает соседей: `NameConflictResolver::sibling/resolve` (простой SELECT, комментарий «Caller holds the drive tree and parent locks»).
- Сценарий (MySQL/InnoDB, транзакции по умолчанию REPEATABLE READ):
  1. T1 `folders()->create('a', P)`. Попытка читает родителя через `locator->find` и ACL. Это первый согласованный (не блокирующий) SELECT, он фиксирует снимок S0.
  2. T2 параллельно создаёт `a` в P, держит блокировку дерева (на MySQL `lockTreeShared` берёт эксклюзивную строку `drives`) и коммитится.
  3. T1 получает блокировку. Блокирующие чтения (`lockForUpdate`) видят свежие данные, а простой SELECT соседей читает снимок S0 и не видит `a`.
  4. `names->resolve` считает имя свободным. Уникального индекса `(parent_id, name)` в `create_v_nodes_table.php` нет (`grep unique` пуст), поэтому вставка проходит.
  5. Итог: два одноимённых узла в одной папке при `OnNameConflict::Fail`, либо потеря автопереименования.
- До диффа первым оператором Action-транзакции была блокировка. Снимок строился после неё, поэтому такой гонки не было. В коде есть прецедент осведомлённости: `UploadSessionStoreRepository.php:64` («silently broke the contract … on MySQL alone») и `ChangeOperations.php:90`.
- Нарушено: R-инварианты блокировок «drive → nodes → file → quota → CAS», правило пункта «ни одно ожидание не меняется». На pgsql (READ COMMITTED) дефекта нет.
- Статус: доказательство по семантике InnoDB. mysql-прогонов мне запрещены, поэтому не воспроизведено. Нужен прогон Concurrency-тестов drive на mysql (пользователь/владелец).
- Варианты правки: (a) читать соседей и целевые строки блокирующим чтением (`lockForUpdate`/`sharedLock`) в `NameConflictResolver` и `commitNewVersion`; (b) на MySQL брать блокировку дерева до чтений попытки; (c) явно зафиксировать READ COMMITTED для корневых транзакций раннера на MySQL.

### F2 — MEDIUM: `upload`/`addRevision` авторизуются вне попытки, окно TOCTOU равно длительности стейджинга
- Где: `FileOperations.php:57,66` (upload), `:77` (addRevision), `:218` (повторная авторизация при `NodeNameConflict`); `write()` оборачивает только `ingest->receipt` (`:206,222`).
- Сценарий: актор с Create на папке P начинает загрузку. `prepare()` потоково стейджит и инспектирует тело, что может занять минуты. За это время ACL отзывают. Затем `receipt()` в транзакции проверяет только структуру (`assertDestination`, `lifecycle->assertWritable`, трэш), а право не проверяет, и коммитит файл. Отозванный пользователь успешно записывает данные.
- Относительно HEAD это не регрессия (там авторизация тоже шла до стейджинга). Но правило пункта требует «авторизация внутри попытки», а исключение записано как deviation без оценки этого риска. Оценка безопасности: исключение безопасно для утечки (не читает байты невидимого назначения), небезопасно для отзыва прав в окне загрузки.
- Рекомендация: оставить дешёвую предпроверку до стейджинга (она не считается авторизацией операции) и добавить авторитетную авторизацию внутри попытки (`authorizeCreate`/`Edit`). Либо оформить принятие риска владельцем.
- `AuthorizationInsideOperationTest` эту ветку не покрывает (см. F6).

### F3 — MEDIUM: `WebhookEndpointWrites` строит `ScopeRef` из сырого параметра до проверки Gate
- Где: `WebhookEndpointWrites.php:41-47`, `ScopeRef.php` (id ≤ 191 байт), маршруты `core/routes/webhooks.php:37-42` без `where`-ограничений.
- Сценарий: `PATCH /webhook-endpoints/<200 символов>` от авторизованного пользователя. `new ScopeRef('webhook_endpoint', $endpoint)` бросает `InvalidArgumentException`, вероятно 500 (маппинг не проверял). Раньше это был 403 для пользователя без `manage` либо 404 `WebhookEndpointNotFound` для менеджера. Тот же эффект на пустой id/`TenancyMisconfiguredException`: при включённой строгой tenancy и неразрешённом тенанте `tenancy->current()` бросается до Gate, а раньше отвечал бы 403.
- Нарушено: порядок 401/403/404 из вопросов R3, правило «ни один ожидаемый статус не меняется».
- Правка: для авторизованного пользователя тоже делать Gate до построения контекста (или хотя бы строить `ScopeRef` внутри попытки и нормализовать длинный id в 404), а tenancy-проверку выполнять после Gate.

### F4 — MEDIUM (вперёд, D25/P3.12): несколько `write()` с одним ключом идемпотентности в одной логической операции
- Где: `TrashOperations.php:61-80` (`restore`: до 3 `write()`), `:112-131` (`empty`: `write('purge')` на каждый корень), `FileOperations.php:206,222` (`upload` с `AddRevision`: первый `write()` падает 409 `NodeNameConflict`, затем второй `write()`).
- Сценарий после P3.12: `OperationContext` один, ключ один, операция `drive.trash.purge` одна, scope один. D25 фиксирует кортеж (tenant, scope, actor, operation, key) и «детерминированный 4xx после claim» фиксирует квитанцию внешней транзакцией. (1) `empty()` с ключом: второй корень даёт ту же `scope_digest`, но другой хэш ресурса, значит 422 mismatch (или replay первого корня). (2) `upload`+`AddRevision`: 409 первого `write()` попадает в квитанцию, второй `write()` возвращает replay 409 и ревизия не создаётся. (3) `restore`: цикл повторов после `RestoreDestinationChanged` (409) вместо повтора получит replay.
- Это не ломает P3.10 по тестам, но структура несовместима с «Вложенные операции имеют один outer receipt». Нужно либо свести логический вызов к одной операции (повтор/ветка внутри попытки), либо зафиксировать в плане, как P3.12 различает такие вызовы (ключ на корень/на попытку).

### F5 — MEDIUM (процесс): сетевой I/O внутри `write()` без записи deviation
- Где: `UploadOperations.php:59-70` (`initiate` → S3 `CreateMultipartUpload` в `UploadSessionManager::initiate`, строка 99, внутри транзакции), `:79-94` (`complete` → `s3->complete`, сборка), `:100-104` (`abort` → S3 abort).
- Правило scope: «I/O (стейджинг, сеть) вынесен до write()/после commit». В журнале deviation перечислены только `upload`/`addRevision` и `patch`/`putPart`.
- Эффект при ретрае (deadlock/serialization): повтор `CreateMultipartUpload` оставляет осиротевший мультипарт; повтор `CompleteMultipartUpload` для завершённого S3-ключа даёт `NoSuchUpload`, поэтому исход операции становится сетевой ошибкой вместо успеха. Относительно HEAD поведение то же (там были `withSession(attempts: 3)` и `transaction()` на уровне 0), то есть регрессии нет, но заявленное в пункте правило не выполнено и не отмечено.
- Рекомендация: записать deviation и вынести S3 initialise/complete/abort за границы повторяемой попытки или сделать их идемпотентными по состоянию сессии.

### F6 — LOW: тест `AuthorizationInsideOperationTest` слабее правила «ровно один раз внутри попытки»
- Где: `packages/drive/tests/PublicApi/AuthorizationInsideOperationTest.php:61-92`.
- Покрыты только `nodes()->rename` (сравнение с эталоном) и `folders()->create` (`not->toBe([])`, без проверки количества). Из 40 операций проверены 2. Нет upload/addRevision (где авторизация намеренно вне попытки), restore/purge/empty, documents, shares, access, webhooks.
- Шпион стоит на `override()` драйвера прав: решения, закэшированные `AccessResolver`/в памяти, не будут посчитаны, значит «ровно один раз» может недосчитывать. Вопрос: считает ли драйвер каждое решение?
- Правило пункта требует spy на `OperationGuard`. Предложение: заменить `OperationGuard` декоратором-шпионом, параметризовать по списку операций (хотя бы по одному вызову на модуль), проверять и количество, и уровень транзакции.

### F7 — LOW: `OperationsUseRunnerTest` — строковая проверка тела метода
- Где: `OperationsUseRunnerTest.php:57-98`.
- Достаточно, чтобы тело содержало `$this->runner->write(` (в том числе в комментарии, в ветке, которая не выполняется, или в замыкании, не вызываемом никогда). `*Operations` ядра (`AccessOperations`/`ShareOperations`) вносятся вручную: новый класс в `core/src/Operations` не обнаружится. Исключения `patch`/`putPart`/`status` определены по имени. Нет проверки, что авторизация выполняется внутри попытки.
- Не мешает мержу, но проверка слабее, чем звучит в заголовке «every public mutating operation runs through the runner». Предложение: проверять через рефлексию/фейк-раннер поведенчески (подмена `OperationRunner` на запись вызовов, вызов каждого публичного мутатора с фейком) или хотя бы убирать комментарии (`token_get_all`) перед `str_contains`.

### F8 — LOW: длинная ручная строка `format:operation-name` в identities
- Где: `tests/Repo/Arch/Fixtures/identities/protocol.json` (запись `format:operation-name`, `manual: true`, 40 имён в одной строке).
- Я сверил список с кодом: 40 имён совпадают с `write('<verb>')` и `OperationSpec('webhooks.endpoints.'.$verb)`, расхождений нет. Но связи «имена в строке = имена в коде» тест не обеспечивает: новое имя можно добавить в код и забыть в строке. Вопрос: проверяет ли `inventory --check` эту строку? Если нет, добавить проверку в `OperationsUseRunnerTest` (собрать имена через фейк-раннер из F7).

### F9 — LOW: два публичных метода под одним именем операции
- `DocumentOperations::update` и `updateContent` оба идут в `documents.documents.update` (`DocumentOperations.php:159,168`). Для идентичности допустимо (одна семантика), но `updateContent(yjsState)` вызывается коллаборацией, а `update` публичным API. Телеметрия и будущий идемпотентный replay не отличат их. Вопрос владельцу, нужен ли отдельный verb (`update_state`).

### Q1 — вопрос: `WebhookEndpointWrites` и гость при политике, допускающей `null`-пользователя
Если хост определил `Gate::define('manage', fn (?User $u) => …)`, разрешающий гостя, раньше `Gate::authorize` проходил и контроллер работал; теперь `HttpOperationContextFactory::user()` бросает `MissingActorException` (строка 176). Подтверждено только чтением кода; реальна ли такая политика у хостов, неизвестно.

### Q2 — вопрос: `TrashOperations::purge` и лог при отказе после авторизации
`reportFailure` вызывается только если `$source` уже установлен (после `guard->authorize`). Если отказ `PermissionDeniedException` исходит уже из `PurgeNodeAction` (проверка поддерева), он логируется, как в HEAD. Если же отказ исходит из middleware/readiness до попытки, лога нет (как и должно быть). Расхождений с HEAD не нашёл, просьба подтвердить, что именно это и было задумано.

## Что проверено и как

Эквивалентность методов (diff HEAD против рабочего дерева, чтение всех файлов):
- `drive/src/Operations/{File,Folder,Node,Trash,Upload}Operations.php`: перенос тел в замыкания попытки один к одному. `restore` по-прежнему повторяет цикл на `RestoreDestinationChanged`, теперь через отдельный `write()` на каждый заход. `purge`: логирование отказов перенесено на условие «источник уже авторизован». `empty`: флаг `$authorized` воспроизводит прежнюю семантику (отказы авторизации молча пропускаются, сбои после авторизации логируются и накапливаются в `$firstFailure`), счётчик прежний.
- `documents/src/Operations/{Document,Comment}Operations.php`: тела целиком внутри замыкания, порядок авторизации/проверок не изменился.
- `core/src/Operations/{Access,Share}Operations.php`: такая же механика, чтения вне раннера (`grants`, `explain`, `paginate`, `list`).
- Webhook: `WebhookEndpointWrites` + шесть контроллеров. Порядок для гостя: Gate до контекста (403 как раньше). Для авторизованного пользователя: сначала контекст/tenancy, потом Gate в попытке (см. F3).
- Блокировки: авторизация и `locator->find` не берут блокировок (`grep lock` по `Access/`, `NodeLocator`, `TreeGuard::lock` только в Action), порядок «drive → nodes → file → quota → CAS» Action-ов не изменён. Единственная содержательная перемена порядка — чтения до блокировки в той же транзакции (F1).
- Байты/сеть: `ingestSource` стейджит и инспектирует до `write()`, `PreparedUpload` переиспользуется между попытками, `BlobStore::commitStaged` идемпотентен (комментарий «Promotion is idempotent», `media/src/Cas/BlobStore.php:67`); `DeleteFileRevisionAction`/`PurgeNodeAction` меняют только БД (байты — GC). Сетевой I/O внутри попытки: см. F5.
- Идентичности: 40 имён в identities сверены с кодом (3+5+4+5+1+7+4+2+3+6 = 40).
- Модули: `OPERATIONS_READS` покрывает все публичные методы 9 классов (по чтению исходников: `find/children/search/starred/properties/abilities/abilitiesMany`, `revisions/read`, `list`, `status/patch/putPart`, `grants/explain/paginate`, `list/paginate`, `content/revisions/revisionPage/export/collaborationSession`, `list`). `collaborationSession` выдаёт токен драйвера без записи в БД, чтение корректно.
- Прямых вызовов мутирующих Action-ов из контроллеров вне `Webhooks` нет (`grep Action` по `Http/Controllers` без Webhooks — пусто). Прочих `Gate::authorize` мутаций вне `WebhookEndpointWrites` нет (три оставшиеся — чтения `viewAny`).

Запущено (точечно, sqlite, без полных наборов):
- `scripts/t sqlite -p drive packages/drive/tests/PublicApi/LifecycleBoundaryTest.php`: 2 failed, 14 passed (оба падения на `LifecycleBoundaryTest.php:358`, `RestoreDestinationRequiredException` не брошено). Это совпадает с blocker в журнале.
- `scripts/t sqlite -p drive packages/drive/tests/PublicApi/AuthorizationInsideOperationTest.php`: ok.
- `scripts/t sqlite -p core packages/core/tests/Arch/OperationsUseRunnerTest.php`: 4 passed.

Не запускалось (запрещено правилами): pgsql/mysql, полные наборы, PHPStan/Pint (исполнитель сообщил, что зелёные).

## Предложенная форма имитации гонки для `LifecycleBoundaryTest` (blocker пункта P3.10)

Причина падения. Тест использует `RefreshDatabase`: весь тест идёт в одной внешней транзакции, а `OperationRunner` открывает корневую транзакцию (сейвпоинт уровнем `base + 1`). Хук `Node::retrieved` выполняет `UPDATE` родителя на уровне `operationLevel` — то есть внутри корневой транзакции. Action видит это изменение под блокировкой и бросает `RestoreDestinationChangedException`. Исключение откатывает корневую транзакцию (сейвпоинт) и вместе с ней гоночное изменение. Цикл повторов в `TrashOperations::restore` делает второй заход, где родитель уже не trashed, и восстановление проходит успешно. Продуктовой проблемы нет: настоящий конкурентный писатель коммитит независимо. Нужна гонка, изменение которой переживает откат попытки.

Форма A (рекомендуется, работает на sqlite и в `RefreshDatabase`, детерминирована): повторять гоночный UPDATE после отката попытки.
- Хук остаётся как есть, но фиксирует, что гонка произошла, и сохраняет замыкание обновления.
- Подписаться на `Illuminate\Database\Events\TransactionRolledBack` (`app('events')->listen(...)`): когда `$event->connection->transactionLevel() === $base` (где `$base = $db->transactionLevel()` до вызова `restore`) и `$raced` истинно, а повтор ещё не выполнен, выполнить то же `UPDATE` вне операции через `app(VaulterDatabase::class)->write(...)` и пометить `$reapplied = true`. Условие `=== $base` отсекает откат внутреннего сейвпоинта Action (после него уровень равен `$operationLevel`).
- Остальное в тесте не меняется: ожидаемое исключение `RestoreDestinationRequiredException`, проверки `trashed_at`, `sequence`, `parent_id` и `$raced`. Дополнительно утверждать `$reapplied === true`, чтобы тест не мог пройти вхолостую.
- Смысл: «конкурентный коммит случился после предпроверки и пережил откат попытки», как и в продакшене. Второй заход цикла `restore` видит родителя в корзине и бросает ожидаемое исключение. Ожидание не меняется.
- Не проверял на практике: правка тестового файла мне запрещена. Проверить, что Laravel в используемой версии диспатчит `TransactionRolledBack` и для отката до сейвпоинта; иначе слушать `TransactionRolledBack` через `DB::afterRollBack`/`TransactionBeginning` уровня `base + 1` второго захода.

Форма B (настоящая конкурентность, только pgsql): вынести кейс в Concurrency-тест на `DatabaseMigrations` с `ForkRace` (`packages/core/tests/Concurrency/ForkRace.php`, `ForkRace::skipUnlessSupported`) или вторым PDO-соединением (`DB::connectUsing`). Дочерний процесс коммитит `UPDATE` родителя, пока родитель держит корневую транзакцию между предпроверкой и блокировкой. Нужен барьер между «предпроверка прошла» и «Action взял блокировку». На sqlite такой тест пропускается, поэтому форма B дополняет форму A, а не заменяет её.

## Что чинить в Части Б (CONFIRMED с доказуемым сценарием)
- LifecycleBoundaryTest — форма A (тест, ожидание не меняется).
- F3 — порядок Gate/`ScopeRef` в `WebhookEndpointWrites`.
- F2 — решение владельца (повторная авторизация в попытке либо принятие риска).
- F1 — после подтверждения на mysql (Concurrency-тесты drive/core, `--group=database`).
- F4, F5 — записать deviation/решение до P3.12; код — по решению владельца.
- F6–F9, Q1–Q2 — рекомендации/вопросы.
