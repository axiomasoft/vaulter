# 01 — Независимая проверка: с чем согласен, что оспариваю, что найдено нового

Снимок кода: `7feeebd` (= `8f07701` по прикладному коду; между ними только документы аудита).
Метод: чтение исходников всех PHP-пакетов, маршрутов, схемы, конфигурации, тестов и ADR.
«Подтверждено» ниже = прочитанная статическая цепочка в коде, **не** выполненный тест или exploit.
Сценарии динамического подтверждения вынесены в [14-verification.md](14-verification.md).

## 1. Итог в одном абзаце

Исходный аудит правильно называет главную болезнь — нет единой канонической модели — но
ошибается в части фактов (after-commit, «нет cursor», «нет unique») и предлагает слишком
широкий фронт (отказ от Eloquent, 8 конфиг-файлов, дробление пакетов). Codex верно сузил
фронт до обещаний пользователю, но остановился на уровне «выбрать варианты». При повторном
чтении кода я нашёл **ещё ~28 дефектов**, из которых 8 — корректность/безопасность данных,
не обнаруженные ни аудитом, ни Codex (N01–N08). Они сильнее всего влияют на порядок работ.

## 2. Исходный аудит: позиция по пунктам

| Тезис аудита | Позиция | Основание / что делаю вместо |
|---|---|---|
| README описывает несуществующий `PermissionResolver` API (P0) | **Согласен, шире** | README целиком описывает удалённый DocVault API: `useDocuments`, `VaulterFileManager`, `@vaulter/filament`, «Documents independent of Drive» (N20). Переписать README после канона, а не патчить строку |
| Программный Drive API не авторизует (P0) | **Согласен** | Решение: публичный API всегда авторизует, доверенный путь — явный `asSystem($reason)` ([D07](02-decisions.md#d07)) |
| `registerDocumentType()` — ложная точка расширения (P0) | **Согласен** | Заменяется типизированным `DocumentTypeRegistry` в пакете documents ([D15](02-decisions.md#d15)) |
| ULID/UUID ломают CAS scope (P0) | **Согласен, подтверждено** (E1 Codex) | `BlobScope` + `scope_key`, ремонт старых `snull/tnull` ([D20](02-decisions.md#d20)) |
| Composer URLs `axioma-studio` → `axiomasoft` (P0) | **Частично** | Ссылки homepage/support/docs — да; vendor `axioma-studio/*` не менять (Codex прав). Плюс `VaulterException::docs()` и `askToStarRepoOnGitHub` ведут на неверный owner |
| HEAD не подтверждён CI (P0) | Согласен как release-gate, не как дефект кода | [12-operations-and-release.md](12-operations-and-release.md) |
| `core` владеет слишком многим (P1) | **Оспариваю формулировку** | Проблема не в размере, а в нечестных зависимостях: documents и filament фактически требуют drive (N14, N15). Решение — честные зависимости и правило владения, не новые пакеты ([D02](02-decisions.md#d02)) |
| Typed normalized configuration (P1) | Согласен | Но делить по пакетам-владельцам, не на 8 файлов; schema-влияющие ключи — в core ([D26](02-decisions.md#d26)) |
| Tenant isolation fail-closed (P1) | Согласен как **opt-in strict mode** | Host-owned режим ADR-0003 сохраняется; strict включается `TenantResolver` ([D10](02-decisions.md#d10)) |
| Upload policy различается по входам (P1) | Согласен, подтверждено (E4) | Политика профиля применяется к staged bytes в едином ingest ([D18](02-decisions.md#d18)) |
| `/v1` и OpenAPI (P1) | Согласен | URL-версия `/v1`, ручной OpenAPI 3.1 + контрактные тесты ([D30](02-decisions.md#d30)) |
| Glossary ADR (P1) | Согласен, но **не** `Workspace` для Storage | В коде «workspace» уже означает host-владельца (`AclScope::workspaceType`, `canCreateInWorkspace`), а в AzGuard — workspace-контекст. Выбрано `Drive` ([D03](02-decisions.md#d03)) |
| Убрать Eloquent из публичных контрактов | **Оспариваю** (как Codex) | Laravel-native API на моделях; аргументы — `Node|int|string`, которые handle перечитывает сам ([D06](02-decisions.md#d06)) |
| `FileHandler` → `FormatDriver` | **Оспариваю** | ADR-0018 D7 только что выбрал `FileHandler`; «driver» в Laravel — реализация менеджера. Меняю только реестр (`DocumentHandlerRegistry` → `FileHandlerRegistry`) и убираю Vue-имена из дескриптора ([D14](02-decisions.md#d14)) |
| Actions без суффикса `Action` | **Оспариваю** | Канон ADR-0017 и arch-тесты уже на суффиксе; переименование 40 классов ничего не даёт |
| Single-action controllers | Согласен | `NodeController` (454 строки) и `TusUploadController` (550) дублируют guard-логику; разбиваются ([D31](02-decisions.md#d31)) |
| События dispatch до commit | **Факт неверен** (E7 Codex) | Но реальная проблема глубже: два разных режима событий в kernel и drive. Единый конвейер ([D22](02-decisions.md#d22)) |
| Quota race, version race, name race | Согласен | Атомарный условный UPDATE, счётчик ревизий на строке файла, канонический порядок блокировок ([D21](02-decisions.md#d21)) |
| 8 config-файлов, модули `contracts/kernel/...` | **Оспариваю** | Лишняя поверхность без выигрыша |
| `vaulter:doctor` | Согласен, расширяю | Расширяемые проверки от каждого пакета ([D34](02-decisions.md#d34)) |

## 3. Codex (01–04): позиция

| Вывод Codex | Позиция |
|---|---|
| E1 CAS scope loss | Согласен. Добавляю: колонки `tenant_id`/`storage_id` в `v_blobs` сами типизированы по стратегии ID Vaulter, а tenant — это ключ хоста (N02) |
| E2 quota без атомарного лимита | Согласен. Семантика head-bytes сохраняется |
| E3 PHP API доверяет вызывающему | Согласен. Плюс: handle не проверяет, что переданный `Node` принадлежит его storage (N27) |
| E4 upload policy по входам | Согласен |
| E5 `registerDocumentType` не подключён | Согласен. Плюс: `TipTapHandler` объявляет `.docx/.md`-**файлы** редактируемыми без пути конвертации (N16) |
| E6 README/metadata | Согласен, шире (N20) |
| E7 after-commit есть; outbox может потеряться | Согласен. Решение — запись outbox и activity в той же транзакции **и на том же соединении**; последнее сейчас невозможно из-за N01 |
| E8 дедупликация по состоянию схлопывает легитимные события | Согласен, подтверждено в `RecordsDriveOutbox` (ключи `node.trashed:{id}`, `node.renamed:{id}:{name}`) |
| E9 tenancy — принятый контракт | Согласен; strict mode как opt-in, не смена умолчания |
| E10 cursor/unique частично есть | Согласен. Но утечка метаданных в листинге шире, чем описано (N08) |
| «Не менять имена ради эстетики» | **Частично оспариваю.** Переименования, которые я принимаю, устраняют конкретные коллизии: `Storage` vs фасад Laravel `Storage`; `Panel` в пакете, который сам поставляет Filament-plugin для Filament *panels*; `version` vs `schema_version` документа и версия API. Пре-1.0 — единственное дешёвое окно |
| «Сохранить набор пакетов» | Согласен + честные `require` и metapackage |
| URL или header versioning | Выбираю URL `/v1` (кэш, логи, простота для хоста) |
| ACL listing: exact SQL или projection | Выбираю третий путь: **пакетная точная оценка соседей** по общей цепочке предков (новая идея, [D12](02-decisions.md#d12)) — без materialized read-model |

## 4. Новые находки (не было ни в аудите, ни у Codex)

Серьёзность: **S1** — порча/потеря данных или обход доступа; **S2** — неверное поведение/нарушение
контракта; **S3** — долг, мешающий 1.0.

### N01 (S1). Выделенное соединение `vaulter.connection` лишает все транзакции атомарности

Модели читают соединение из `vaulter.connection` (`packages/core/src/Models/Concerns/ResolvesVaulterConnection.php`),
схема тоже (`PanelTablesBuilder::schemaConnection/dbConnection`, строки 43–56). Но **все ~40** `DB::transaction(...)` в Actions,
`BlobLock` (`DB::connection()->getDriverName()`, `packages/core/src/Cas/BlobLock.php:92`), GC/relay и
`UploadSessionStoreRepository` используют **соединение по умолчанию**. При настроенном отдельном
соединении (фича рекламируется в `docs/guide/database-compatibility.md`, раздел «Dedicated database connection»):
записи Vaulter идут вне открытой транзакции, advisory-lock CAS берётся на чужой БД, SERIALIZABLE-ретраи GC
относятся не к той сессии. Гарантии «race-free commit/GC» и атомарности outbox при этом исчезают молча.

### N02 (S1). Колонки ключей хоста типизированы стратегией ID Vaulter

`v_storages.owner_id`, `v_node_grants.subject_id`, все `tenant_id` создаются `$ids->reference()`, а все
actor-колонки (`created_by`, `owner_id` узла, `trashed_by`, `granted_by`, `actor_id`) — `$ids->actor()`
(`packages/core/src/Schema/UlidIds.php:31-39`, `AutoIncrementIds.php:22-30`, `PanelTablesBuilder.php:164,218`).
Это ключи **хоста**, а не Vaulter. По умолчанию (`ids.default = ulid`) они `char(26)`: хост с UUID-ключами
пользователей/проектов/ролей (36 символов) получает ошибку вставки на PostgreSQL; при `bigint` — хост с
ULID/UUID-пользователями не может ни выдать grant, ни записать аудит.

### N03 (S1). `nodes.current_version_id` полиморфен без дискриминатора

Для файлов он указывает на `v_file_versions.id`, для документов — на `v_node_document_versions.id`
(`packages/documents/src/Actions/Documents/CreateDocumentNodeAction.php:75`, ещё 4 места в documents). При этом
`Node::currentVersion()` всегда строит `BelongsTo(FileVersion)` (`packages/core/src/Models/Drive/Node.php:174`).
При `bigint`-ID у документа `current_version_id = 7` резолвится в **чужую** версию файла `id = 7`; при ULID —
в `null`. Любой общий код, идущий через `currentVersion`, для документа ошибается.

### N04 (S2). Документ без `parent_id` становится вторым корнем и выпадает из листинга

`DocumentNodeController::store` передаёт `parent = null`, `CreateDocumentNodeAction` пишет `parent_id = NULL`
(строка 62), тогда как Drive кладёт такие узлы в корневую папку. `GET storages/{storage}/nodes` листает детей
корня — документ не виден. Уникальность имён `(parent_id, lower(name)) WHERE ...` не защищает `parent_id IS NULL`
(NULL различны), поэтому одинаковые имена «корней» допустимы.

### N05 (S1). Инварианты дерева не проверяются; гонка циклов при move

Ни одно Action не проверяет, что назначение — **активная папка того же drive**: можно переместить/скопировать
узел, создать папку или загрузить файл «внутрь» файла, документа или удалённой в корзину папки (PHP API и HTTP с
чужим `parent_id` в пределах storage). `MoveNodeAction::assertNoCycle` выполняется **до** транзакции и без
блокировок (строки 44, 54): параллельные `A→B` и `B→A` оба проходят проверку и образуют цикл.

### N06 (S2). Копия документа — пустой документ

`CopyNodeAction::copyNode` копирует полезную нагрузку только для `File`, рекурсию — для `Folder` (строки 101–106).
Для `Document` создаётся узел `kind=document` без ревизий и без указателя головы. Нет механизма «содержимое узла
по его kind» для copy/purge/size.

### N07 (S2, продуктовая семантика). `Reader` может комментировать; уровень `Commenter` бессмыслен

`Ability::Comment` требует только бит `Read` (`packages/core/src/Enums/Access/Ability.php:47`), а
`GrantLevel::Commenter` даёт те же биты, что `Reader` (`GrantLevel.php:68`). Тест закрепляет это
(`packages/documents/tests/Comment/NodeCommentApiTest.php:31`). Контроллер комментариев при этом
документирует «the dedicated comment bit», которого нет.

### N08 (S1). Листинг раскрывает запрещённых детей

`NodeController::index` проверяет `View` только у родителя, затем `narrowToAccessible()`. Для драйверов
`gate`/`spatie`/`azgard` (не grant-authoritative) сужение **не применяется вообще**
(`NodeReadRepository::grantFilteringApplies`, строка 471): любой, кто видит папку, видит имена, размеры и MIME
всех детей, включая узлы с явным `deny`. Для `database` — сознательный superset (docblock «LISTING SEMANTIC»,
строка 356). Имена файлов — это данные; для DMS это утечка.

### N09 (S2). Один список middleware на три разные поверхности

Все маршруты — защищённые, публичные share-ссылки (`share/{token}`) и server-to-server колбэки хаба
(`collab/authenticate|store`) — оборачиваются одним `Config::middleware()` (`packages/core/routes/api.php:22-23`).
Добавить `auth:sanctum` нельзя, не сломав публичные ссылки и хаб. По умолчанию `['api', metrics]`: при чистой
установке каждый защищённый маршрут отвечает 403 (fail-closed, но не документировано ни в install, ни в quick-start).

### N10 (S2). Глобальные `Route::bind('node')` и `Route::bind('storage')`

`VaulterServiceProvider::registerRouteBindings` (строки 397, 403) регистрирует биндинги **для всего приложения**:
любой маршрут хоста с параметром `{node}` или `{storage}` начинает резолвиться в модели Vaulter. Биндинг `node`
ищет по глобальному ID без учёта drive/tenant.

### N11 (S2). Доменные ошибки превращаются в HTTP 500

`render()` есть только у 7 из 26 исключений. `CycleException`, `CrossStorageMoveException`,
`NotADocumentNodeException`, `InvalidTipTapContentException`, `NodeDocumentVersionNotFoundException`,
`UnknownExportFormatException`, `BlockedOutboundUrlException` отдают 500; тесты это закрепляют
(`packages/documents/tests/Document/DocumentNodeApiTest.php:98`). Конверт — `{"message": "[Vaulter] ... Why ... Fix ... Docs"}`
без машинного кода.

### N12 (S3). Большая часть API панелей — мёртвый код

`Panel::pipes()/resource()`, `StoragePanelProvider::fillNode()/presentNode()/pipes()/booted()` не вызываются нигде в `src`.
Флаги панели (`features`, `withoutGrants()`) влияют только на генерацию собственных таблиц; в рантайме
`Config::featureEnabled()` вызывается без панели. `Panel::model()` в рантайме не используется, репозитории берут
глобальные модели — значит режим собственных таблиц (own-tables) работает только на уровне генерации миграций.
Документированный «per-panel role lane» не реализован: `AccessResolver` всегда берёт драйвер по умолчанию.

### N13 (S3). Две модели удаления

`SoftDeletes` (`deleted_at`) на `Storage`, `Node`, `File`, `NodeGrant`, `NodeShare` и доменная корзина
`trashed_at` на узлах. Уникальные индексы зависят от обоих (`PanelTablesBuilder.php:1133`), `ShareResolver`
проверяет оба. Purge удаляет физически — `deleted_at` у узлов никогда не нужен.

### N14 (S1). Filament обходит авторизацию назначения и не объявляет зависимость

Действия в `packages/filament/src/Resources/NodeResource.php` проверяют право только через `->visible()` и вызывают
неавторизующий фасад (`Vaulter::forStorage(...)->move(...)`, строка 264). У `move` проверяется `Move` на исходном
узле, но не `Create` на папке назначения — перенос в папку, куда пользователь писать не может. Фасад требует
`vaulter-drive`, а `packages/filament/composer.json` требует только core.

### N15 (S2). `vaulter-documents` фактически требует `vaulter-drive`

Создать storage без `StorageManager` (drive) нельзя; тесты documents поднимают `VaulterDriveServiceProvider`
(`packages/documents/tests/TestCase.php:34`). README утверждает обратное.

### N16 (S2). `TipTapHandler` объявляет файлы `.docx/.doc/.md` редактируемыми и совместными

Пути импорта/сохранения бинарного файла в TipTap нет («registerable pipe, M4b+» — не реализован). Клиент получает
`editable: true, collaborative: true` для файла, который отредактировать нельзя.

### N17 (S2). Restore документа при живом хабе будет перезаписан

`RestoreDocumentVersionAction` копирует старый `yjs_state` (строка 54). Хаб держит новый Y.Doc в памяти и следующим
`onStoreDocument` записывает его поверх — восстановление теряется. Нет fencing/поколения комнаты.

### N18 (S3). Каталог событий неполон и продублирован

`WebhookEvent` и `ActivityVerb` — два почти одинаковых enum; в каталоге нет событий документов, комментариев,
ревизий документов и загрузок (последние — только в activity).

### N19 (S3). Статический `Support\Config` (1578 строк) в core знает ключи всех пакетов

Коллаборация, TipTap, uploads, версии документов читаются через core. Дубли: `id_type`/`ids.default`,
`max_versions`/`versions.max`, `share_token_generator`/`share.token_generator`, `uploads.max_bytes`/`drive.max_upload_kb`,
`enabled_document_types`/`DocumentType` enum. `docs/guide/configuration.md` устарел (драйвер `reverb` по умолчанию,
несуществующие namespace экспортёров).

### N20 (S3). README описывает удалённый продукт — см. §2.

### N21 (S3). AzGuard-специфика живёт в core, а мост требует `dev-main`

`Contracts\Access\AzgardGuard`, `Drivers\Permission\AzgardPermissionResolver`, `Support\AzgardConfig` и ключи
`azgard.*` — в core; `vaulter-azgard` содержит только адаптер и требует `axioma-studio/azguard-core: dev-main` —
с таким требованием стабильный релиз невозможен.

### N22 (S2). Нет жизненного цикла drive при удалении владельца

`v_storages.owner_*` — морфная ссылка без FK. Удаление Project оставляет drive, узлы и байты навсегда; операции
удаления drive нет.

### N23 (S3). Эксплуатация: 7 команд нужно расписать вручную, doctor отсутствует

`relay-outbox`, `retry-webhooks`, `prune-webhooks`, `sweep-uploads`, `purge-trash`, `gc-blobs`, `reconcile-blobs` —
ни одна не регистрируется в планировщике. Имена несогласованы (`vaulter:make-panel` против `vaulter:panel:migrations`).

### N24 (S3). `NodeResource` — N обходов ACL на страницу и внутренние указатели наружу

Для каждого узла страницы вызывается `effectiveGrants()` с обходом предков. В JSON уходят `file_id` и
`current_version_id` — внутренняя схема становится REST-контрактом.

### N25 (S3). Vue: глобальный реестр движков и ручные типы

`engineRegistry.ts` — модульный `Map` (общий для всех app-инстансов/SSR). Экспорт `docVaultClientKey` — наследие DocVault.
`types.ts` (487 строк) пишется руками, без связи с сервером, и уже разошёлся с ним:
`GrantLevelName = 'reader' | 'commenter' | 'editor' | 'manager'` (`packages/vue/src/types.ts:214`), тогда как
сервер использует `reader|commenter|writer|organizer|owner`.

### N26 (S3). Три имени одного понятия доступа и мёртвые enum

Контракт `HostRoleDriver`, классы `*PermissionResolver`, ключи `drivers.permission` + `permission_drivers`.
`PermissionLevel` не используется в `src`; `Permission::All = 31` дублирует `allBits()`.

### N27 (S2). Handle не проверяет принадлежность узла

`DriveContext` передаёт полученный `Node` в Actions без проверки `node.storage_id === handle.storage.id`; `trash`,
`forceDelete`, `rename` чужого узла через «чужой» handle проходят. `fopen()` для `SplFileInfo` не закрывается.

### N28 (S3). Миграции читают конфиг в момент запуска

Стандартные миграции строят таблицы через `(new Panel)->id('default')` и текущие `ids`/`dedup.scope`/`tenant`.
Смена этих ключей после миграции молча расходится со схемой; обнаружить можно только вручную.

## 5. Что сознательно не делаю

- Не меняю ORM-подход, не ввожу `NodeReference`/remote backend.
- Не дроблю пакеты (sharing/webhooks/collaboration остаются где есть).
- Не переименовываю Actions, `FileHandler`, `yjs_state` (ADR-0017/0018 остаются в силе).
- Не делаю обязательными AV-сканер, tenancy-фреймворк, OCR, отдельный поисковый кластер.
- Не ввожу materialized effective-grants до профилирования: пакетная оценка соседей закрывает листинг без неё.
