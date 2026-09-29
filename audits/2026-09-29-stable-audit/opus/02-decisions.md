# 02 — Журнал решений (Decision Log)

Каждое решение — **утверждённая позиция для плана**, а не вариант. Где решение меняет
продуктовую семантику, это отмечено; владелец может отменить его в
[15-owner-questions.md](15-owner-questions.md), иначе оно действует.

Уровни:

- **T0** — исправить до любого следующего релиза (корректность, безопасность, потеря данных);
- **T1** — канон 1.0: breaking-изменения, которые делаются один раз в окне 0.3;
- **T2** — после 1.0, только аддитивно.

Ссылки `Nxx` — находки из [01-review.md](01-review.md), `Exx` — находки Codex.

---

<a id="d01"></a>
### D01 — Одно окно канона: 0.3.0, затем стабилизация до 1.0 · T1

**Решение.** Все переименования, изменения схемы и REST v1 выходят одним релизом **0.3.0**
(«canon break»). В PHP — жёсткие переименования без алиасов-обёрток (как в VLT-CANON:
пакет пре-1.0). Устаревшие **ключи конфигурации** читаются нормализатором с
`E_USER_DEPRECATED`-логом до 1.0 и удаляются в 1.0. Схема БД обновляется upgrade-миграцией
с сохранением данных. 1.0.0 выпускается, когда зелёные все гейты из
[12-operations-and-release.md](12-operations-and-release.md).

**Почему.** Двойная поверхность (новый API + deprecated-обёртки) удваивает работу исполнителей
и тестов; у 0.2.0 нет обязательств SemVer. Конфиг — исключение: он лежит в опубликованных
файлах хоста и ломается тихо.

**Отвергнуто.** Постепенные переименования по одному в минорах (многократная миграция
потребителей); обёртки `@deprecated` для всех методов (аудит, этап 2).

---

<a id="d02"></a>
### D02 — Пакеты: сохранить набор, сделать зависимости честными · T1

**Решение.**

1. Composer-пакеты остаются: `vaulter-core`, `vaulter-media`, `vaulter-drive`,
   `vaulter-documents`, `vaulter-filament`, `vaulter-azgard`; npm — `@vaulter/vue`;
   плюс `hocuspocus-server`.
2. Добавляется metapackage **`axioma-studio/vaulter`** (`core + media + drive + documents`).
3. `vaulter-documents` **требует** `vaulter-drive` (N15); `vaulter-filament` **требует**
   `vaulter-drive` (N14).
4. Правило владения (фиксируется ADR-0019 «Kernel ownership»):
   - **core (kernel)** владеет всей схемой БД и миграциями, общими моделями (`Drive`, `Node`,
     `File`, `FileRevision`, `Blob`, `NodeGrant`, `NodeShare`, `Activity`, `Outbox`,
     `Webhook*`), доступом, шарингом, аудитом, событиями, профилями, реестром файловых
     обработчиков, контрактами швов и типизированным конфигом ядра;
   - **модуль** (drive, documents) владеет use cases (Operations/Actions/Queries), HTTP,
     командами, конфигом модуля и моделями, которые читает и пишет только он
     (`UploadSession`, `UploadPart`, `Document`, `DocumentRevision`, `NodeComment`);
   - **media** — движок CAS (content store, storage driver, GC, reconcile, repair);
   - **мосты** (azgard, filament) не содержат доменной логики.
5. Всё AzGuard-специфичное (`AzgardGuard`, драйвер, карта прав, ключи `azgard.*`)
   переносится из core в `vaulter-azgard` (N21).

**Почему.** Установки «core без drive» не существует: без `DriveProvisioner` нельзя создать drive.
Честные `require` дешевле, чем обратное слияние core+drive (отмена ADR-0015) или новые пакеты.

**Отвергнуто.** Слияние drive в core (огромный перенос namespace); пакеты
`contracts/kernel/sharing/webhooks/collaboration` (нет независимой установки).

---

<a id="d03"></a>
### D03 — Канонический словарь · T1

Полная таблица — [03-glossary-and-renames.md](03-glossary-and-renames.md). Ключевые выборы:

| Понятие | Термин | Почему не альтернатива |
|---|---|---|
| Модель хоста, владеющая файлами | **Owner** | Уже колонки `owner_type/owner_id`; «workspace» в коде и AzGuard значит другое |
| Логическое файловое пространство | **Drive** (было Storage) | `Storage` конфликтует с фасадом Laravel; `Workspace` занят (см. выше) |
| Именованная политика для drives | **Profile** (было Panel) | Пакет поставляет Filament-plugin для Filament *panels* |
| Снимок содержимого | **Revision** (было Version) | «version» занято версией API, `schema_version` документа и версией пакета |
| Адаптер прав хоста | **Permission driver** (было HostRoleDriver/PermissionResolver) | Три имени → одно; «driver» — выбираемая в конфиге реализация, по-Laravel |
| Обработчик формата файла | **File handler** (без изменений) | ADR-0018 D7 |
| Тип структурированного документа | **Document type** | Отдельно от file handler и редактора |
| Клиентский компонент показа/редактирования | **Viewer** (ключ `vendor/name`) | Бэкенд не знает имён Vue-компонентов |
| Субъект операции | **Actor** (user или system) | `actorId` как аргумент удаляется |

---

<a id="d04"></a>
### D04 — Переименования выполняются одним механическим проходом · T1

**Решение.** Карта «было → стало» — [03-glossary-and-renames.md](03-glossary-and-renames.md) §3–§8;
она обязательна для исполнителей: классы, методы, аргументы, конфиг, таблицы/колонки,
маршруты, события, TS-типы. Проход делается по FQN-якорям (`php:namespace-refactor-safety`),
после каждого пакета — зелёные arch-тесты и обновлённые API-snapshot'ы с ревью диффа.

---

<a id="d05"></a>
### D05 — Профиль = runtime-политика; режим собственных таблиц удаляется · T1

**Решение.** Профиль (`Vaulter\Profiles\Profile`) — именованный набор **рантайм-политик**
для drives: разрешённые классы владельцев, диск, квота по умолчанию, `UploadPolicy`,
драйвер прав, разрешённые типы документов, включение grants/share links/comments, хранение
ревизий и корзины. Эффективная политика = глобальная ∧ профиль (профиль только сужает).

Удаляются: own-tables режим (`tablePrefix()/tables()/idStrategy()/models()`,
`vaulter:panel:migrations`, `PanelMigrationGenerator`, `HasVaulterPanel`, `PanelTableResolver`),
мёртвые хуки `pipes()/resource()/fillNode()/presentNode()/booted()` (N12), колонка
`storage_type`.

**Почему.** Own-tables работает только на уровне генерации миграций (репозитории и связи
используют глобальные модели) — это ложное обещание. Изоляцию данных закрывают
`vaulter.database.connection` (после исправления N01) и strict tenancy. Если режим когда-нибудь
понадобится, он добавляется аддитивно.

---

<a id="d06"></a>
### D06 — Публичный PHP API остаётся Laravel-native · T1

**Решение.**

- Методы принимают и возвращают Eloquent-модели; аргумент-узел имеет тип
  `Node|int|string` (модель или ключ).
- Handle **никогда не доверяет** состоянию переданной модели: берёт ключ, перечитывает узел
  в пределах своего drive (и под блокировкой для записи); чужой drive → `NodeNotFoundException` (N27).
- Списки возвращают `NodePage` (items + `nextCursor`), а не `Collection`.
- Неоднозначные позиционные аргументы заменяются типизированными `*Options`/`UploadSource`.
- `actorId`-параметры удаляются из всех методов: субъект задаётся на handle (D07).

**Отвергнуто.** `NodeReference`/`WorkspaceReference`-абстракции поверх Eloquent (аудит):
нет потребителя, который выиграл бы от этого.

---

<a id="d07"></a>
### D07 — Публичный API всегда авторизует; системный путь явный · T0

**Решение.**

```php
$drive = Vaulter::drive($project);                 // actor = текущий пользователь guard'а, лениво
$drive = Vaulter::drive($project)->actingAs($user); // явный пользователь, новый immutable handle
$drive = Vaulter::drive($project)->asSystem('nightly import'); // без ACL, аудит как system
```

- Каждая операция handle вызывает `OperationGuard` (D08) до выполнения.
- Если actor не задан и guard не вернул пользователя → `MissingActorException` (HTTP 401).
- `asSystem($reason)` обходит ACL, но **не** tenancy (D10) и **не** инварианты; пишет
  activity с `actor_type = 'vaulter:system'` и причиной.
- Очереди и консоль обязаны явно выбрать `actingAs()` или `asSystem()`: в worker нет
  «текущего пользователя».

**Почему.** Единственная граница, которая гарантирует одинаковое поведение HTTP, фасада,
Filament и jobs. Laravel-аналог — `Gate` с текущим пользователем и `Gate::forUser()`.

---

<a id="d08"></a>
### D08 — Слои приложения и кто кого вызывает · T1

```
HTTP-контроллеры ─┐
Filament actions ─┼──►  Operations (публичные, авторизуют) ──► Actions (@internal: инварианты,
Код хоста ────────┘        │                                    транзакция, события)
                           └──► Queries (@internal: чтение + ACL-сужение)      │
Jobs/Console ──(OperationContext::system)──────────────────────────────────────┘──► Repositories (@internal)
```

- **Operations** — реализации sub-API handle (`NodeOperations`, `FileOperations`, …): резолвят
  узлы, вызывают `OperationGuard`, собирают `*Input`, вызывают Action/Query.
- **Actions** — `execute(OperationContext $context, XxxInput $input): Result`; держат все
  инварианты (дерево, квота, upload policy, имена) и транзакцию; публикуют события через
  `EventRecorder` (D22). Авторизацию не делают.
- **Queries** — чтение с точным ACL-сужением (D12).
- Контроллеры и Filament **не** импортируют Actions/Repositories (arch-тест).
- HTTP — клиент того же PHP API, что и хост: паритет прав по построению.

**Почему.** «Кто может» и «что допустимо» разделены; ни один публичный вход не пропускает ни то,
ни другое. Убирает копипасту `guard()/guardCreate()/authorizable()` из ~8 контроллеров.

---

<a id="d09"></a>
### D09 — Скрытие ресурсов · T1

Нет права `View` на узел (или узел в другом drive/tenant) → `NodeNotFoundException` (404).
Есть `View`, но нет нужной способности → `PermissionDeniedException` (403). Листинг/поиск
никогда не раскрывают узлы без `View` (D12).

---

<a id="d10"></a>
### D10 — Tenancy: opt-in strict mode · T1

**Решение.** `vaulter.tenancy.enabled = false` по умолчанию (как сейчас). При `true` обязателен
`vaulter.tenancy.resolver` — класс, реализующий `Vaulter\Contracts\Tenancy\TenantResolver`;
иначе исключение при boot во **всех** окружениях. В strict-режиме:

- `DriveProvisioner` записывает `tenant_id` из резолвера; если резолвер реализует необязательный
  `ResolvesOwnerTenant::tenantOf(Model $owner)`, тенант владельца обязан совпасть с текущим, иначе
  `NodeNotFoundException` (защита от drive «чужого» проекта, созданного не в том тенанте);
- `OperationGuard` сравнивает `drive.tenant_id` с текущим тенантом → иначе `NodeNotFound`;
- резолв маршрутов (D31) фильтрует по тенанту;
- `BlobScope` берёт тенант drive;
- jobs сериализуют тенант в контексте и проверяют его при исполнении; тенант исчез → job
  падает без продолжения в другом контексте;
- обслуживание без тенанта — только `Vaulter::withoutTenancy(fn)` в командах GC/reconcile/repair.

`TenantFootgunGuard` удаляется (заменён проверкой boot). Host-owned scoping через
кастомные модели (ADR-0003) продолжает работать как дополнительный слой.

---

<a id="d11"></a>
### D11 — Модель прав: бит `Comment`, один драйвер на профиль · T1 (меняет семантику)

**Решение.**

- `Permission`: `Read=1, Update=2, Create=4, Delete=8, Share=16, Comment=32`;
  `Permission::all()` — метод, case `All` удаляется.
- `GrantLevel::bits()`: Reader = Read; Commenter = Read|Comment; Writer = Read|Comment|Update|Create;
  Organizer = Writer|Delete; Owner = all.
- `Ability::Comment` требует `Comment` (N07). Биты уровня вычисляются при чтении
  (`level->bits() | permissions`), миграция данных grants не нужна.
- Удалить `PermissionLevel` (мёртвый enum).
- Драйвер прав выбирается по профилю drive (`Profile::permissionDriver()`), по умолчанию —
  глобальный (N12). `AccessResolver` берёт драйвер из профиля.
- Публичные ссылки: `level=commenter` у share запрещён в 1.0 (анонимный комментарий не
  поддерживается) — валидация при создании.

---

<a id="d12"></a>
### D12 — Точная видимость в листинге и поиске через пакетную оценку · T0

**Решение.** Листинг детей, поиск, корзина и «shared with me» возвращают только узлы с
эффективным `View`, для **любого** драйвера. Алгоритм:

1. SQL выбирает кандидатов страницы по курсору (сортировка + tie-breaker `id`), с переизбытком
   `k × per_page` (k = 2, максимум `vaulter.listing.scan_budget` = 1000 строк за запрос).
2. `AccessResolver::evaluateMany(Authorizable, iterable<Node>)` группирует кандидатов по
   родителю: эффективный набор родителя считается **один раз** (обход предков + роль-лейн
   драйвера), затем для каждого ребёнка сворачиваются только его собственные grants
   (один запрос `WHERE node_id IN (...)`) и `inherit_grants`.
3. Отфильтрованные элементы идут в страницу; курсор = ключ **последнего просмотренного**
   кандидата, не последнего выданного. Недобор страницы допустим; `nextCursor` не null, пока
   кандидаты не кончились.
4. Счётчики `total` не выдаются там, где они раскрывали бы скрытые узлы.

Тот же `evaluateMany` заполняет `abilities` в `NodeResource` (D33). Materialized read-model
(ROADMAP H.4) остаётся в T2 как оптимизация, не условие корректности.

---

<a id="d13"></a>
### D13 — Инварианты дерева и имён · T0

**Решение (проверяются в Actions, под блокировкой):**

- родитель/назначение — **активная (не в корзине) папка того же drive**; корень нельзя
  переместить, переименовать, удалить в корзину или скопировать как узел;
- `parent_id IS NULL` допустим **только** у корня drive (уникальный частичный индекс
  `(drive_id) WHERE parent_id IS NULL` на PG/SQLite, generated column на MySQL — как у существующего
  sibling-unique) — закрывает N04;
- move берёт `VaulterDatabase::lockTree($driveId)` (advisory lock на PG, `SELECT ... FOR UPDATE`
  строки drive на MySQL/SQLite) и проверяет цикл внутри транзакции (N05);
- имена: NFC-нормализация (`Normalizer`, при отсутствии `intl` — doctor warning), запрет
  разделителей и управляющих символов, длина ≤ 255 байт UTF-8, сравнение уникальности
  по `lower(name)` как сейчас;
- поведение при конфликте имени — `OnNameConflict::Fail` (по умолчанию) | `Rename`
  («name (2).ext») | `AddRevision` (только для загрузки файла поверх файла).

---

<a id="d14"></a>
### D14 — Файловые обработчики: только файлы, без имён Vue · T1

**Решение.**

- Контракт `FileHandler` остаётся (ADR-0018). Реестр `DocumentHandlerRegistry` →
  `Vaulter\Handlers\FileHandlerRegistry` (контракт `Vaulter\Contracts\Handlers\FileHandlerRegistry`
  получает `resolve()`).
- `FileHandler::id()` → `key()` в формате `vendor/name` (`vaulter/preview`, `vaulter/download`);
  `FileHandler::ui()` → `viewer(FileDescriptor): ViewerDescriptor{key, props}`; поле `route`
  удаляется; ключ viewer'а — тоже `vendor/name` (`vaulter/pdf-preview`, `vaulter/download`).
- `TipTapHandler` и `UniverHandler` удаляются: документы получают viewer из `DocumentType`
  (D15), а файлы `.md/.docx/.xlsx` — из обычных preview/download-обработчиков, пока не появится
  импорт «файл → документ» (T2) (N16).
- Мемоизация реестра по (mime, ext, capability) сохраняется; контракт документирует, что
  `capabilities()` не должны зависеть от других полей дескриптора.

---

<a id="d15"></a>
### D15 — Реестр типов документов · T1

**Решение.** В `vaulter-documents`:

- контракт `Vaulter\Documents\Contracts\DocumentType` (ключ, метка, `schemaVersion()`, `codec()`,
  `defaultContent()`, `viewer()`), необязательные интерфейсы `SupportsCollaboration`,
  `UpgradesSchema`, `ProvidesTemplates`(T2);
- `DocumentTypeRegistry`: регистрация FQCN из конфига и из provider'ов, ключи
  `^[a-z0-9-]+/[a-z0-9-]+$`, дубликат → `DuplicateDocumentTypeException` (замена только через
  `replace()`), freeze после `booted` приложения;
- встроенные типы: `vaulter/rich-text` (TipTap, Yjs), `vaulter/markdown` (TipTap с ограниченной
  схемой), `vaulter/spreadsheet` (Univer, при фиче `spreadsheets`, без коллаборации);
  `table` и `template` удаляются (данные мигрируют в `vaulter/rich-text`);
- неизвестный тип (расширение удалено): документ остаётся в дереве, контент доступен как
  непрозрачный JSON для чтения и скачивания; edit/export/collab → `DocumentTypeUnavailableException` (409);
- `Vaulter::registerDocumentType()`, `documentTypes()` и `enabled_document_types` удаляются.

Детали — [06-extension-points.md](06-extension-points.md) §3.

---

<a id="d16"></a>
### D16 — Агрегат `Document` симметричен `File` · T1

**Решение.** Новая таблица `v_documents` (1:1 к узлу): `type`, `schema_version`,
`current_revision_id`, `revision_count`, `collab_epoch`. Голова ревизии хранится в агрегате
(`v_files.current_revision_id`, `v_documents.current_revision_id`); из `v_nodes` удаляются
`current_version_id` и `file_id` (N03). Связь узел→агрегат — через `node_id UNIQUE` в агрегате.

```
Node ─┬─ File ── FileRevision ── FileRevisionBlob ── Blob
      └─ Document ── DocumentRevision
```

---

<a id="d17"></a>
### D17 — Содержимое узла по kind: `NodeContentLifecycle` · T1

**Решение.** Контракт в core:

```php
interface NodeContentLifecycle
{
    public function kind(): NodeKind;
    public function copy(OperationContext $context, Node $source, Node $copy): CopyResult; // bytes added
    public function purge(OperationContext $context, Node $node): void;                   // до удаления узла
}
```

Drive регистрирует `FileContentLifecycle`, documents — `DocumentContentLifecycle`, папки —
рекурсия в `CopyNodeAction`. `CopyNodeAction`/`PurgeNodeAction` диспетчеризуют по kind; kind
без зарегистрированного lifecycle → `UnsupportedNodeKindException` (N06).

---

<a id="d18"></a>
### D18 — Единый ingest загрузок на реальных байтах · T0 (политика) / T1 (API)

**Решение.**

- Вход: `UploadSource` (фабрики `fromUploadedFile`, `fromPath`, `fromStream`, `fromString`,
  `fromPsrStream`) + `UploadOptions` (`folder`, `name`, `onConflict`, `declaredMimeType`).
  Пакет закрывает только ресурсы, которые открыл сам; PSR-потоки читаются чанками.
- Все пути (single HTTP, PHP, tus, S3 complete) сходятся в `IngestFileAction`:
  stage → факты (реальный размер, sha256, MIME через `finfo`, расширение) → `UploadPolicy`
  профиля → цепочка `UploadInspector` → CAS commit → метаданные.
- Сохраняемый `mime_type` — определённый; заявленный — в `file_revisions.declared_mime_type`.
  Несовпадение семейства MIME и расширения → отказ, если `uploads.reject_mime_mismatch = true`
  (по умолчанию `true`).
- Quarantine: колонка `file_revisions.scan_status` (`not_required|pending|clean|infected|failed`)
  и контракт `ContentScanner` в 1.0; при `uploads.scan.required = true` голова с не-`clean`
  статусом недоступна для download/preview/export/share (`ContentUnavailableException`, 423).
  Встроенный ClamAV-адаптер — T2.

---

<a id="d19"></a>
### D19 — Resumable uploads · T1

Авторизация: `initiate` — `Create` на папке (или `Edit` на файле для новой ревизии) через
`UploadOperations`; transport-шаги (HEAD/PATCH/parts) проверяют владение сессией
(`created_by` + drive + tenant); `complete` повторно проверяет ACL на момент завершения и
идёт через `IngestFileAction`. Повторный `complete` уже завершённой сессии возвращает тот же
узел/ревизию (идемпотентно).

---

<a id="d20"></a>
### D20 — CAS: беспотерьная область дедупликации · T0

**Решение.**

- `Vaulter\ValueObjects\Cas\BlobScope`: `global()`, `tenant(string)`, `drive(string)`;
  `key()` → `global` | `tenant:{id}` | `drive:{id}` (id — строка без преобразований).
- `BlobStore::commit(mixed $stream, BlobScope $scope, ?string $disk = null): Blob`.
- `v_blobs.scope_key varchar(191) NOT NULL`, `UNIQUE(scope_key, sha256)` заменяет пару
  «unique по колонкам scope + отдельный unique storage_key» (NULL больше не участвует).
- Физический ключ объекта: `{scope-segment}/{sha[0:2]}/{sha}` с URL-safe сегментом; reconciler
  распознаёт старые (`s{int}`, `t{int}`, `snull`, `tnull`) и новые ключи, включая UUID с дефисами.
- `vaulter:blobs:repair-scopes --dry-run|--execute`: инвентаризация объектов в `snull/tnull`,
  вывод правильной области из связей ревизия→файл→узел→drive, проверка sha256, копирование в
  новые области, атомарное переключение ссылок, удержание старого объекта до GC. Неизвестный
  тенант не угадывается (отчёт, ручное решение).
- `blob.disk` — авторитетен для чтения; дедупликация между дисками при `scope=global|tenant`
  допустима (ADR-0012 остаётся), изоляция дисков профилей требует `dedup.scope = drive` —
  doctor это проверяет.

---

<a id="d21"></a>
### D21 — Конкурентность: атомарные инварианты и порядок блокировок · T0

**Решение.**

- Квота: внутри транзакции
  `UPDATE v_drives SET used_bytes = used_bytes + :d WHERE id = :id AND (quota_bytes IS NULL OR used_bytes + :d <= quota_bytes)`;
  0 строк → `QuotaExceededException` и откат. Предпроверка остаётся только ради быстрого отказа.
  Семантика — байты головных ревизий (как сейчас).
- Номер ревизии: `SELECT ... FOR UPDATE` строки `v_files`/`v_documents`, номер =
  `revision_count + 1`, delta квоты — от **перечитанной** головы.
- Канонический порядок блокировок: drive (tree lock) → узлы по возрастанию id → агрегат
  (file/document) → CAS advisory lock `(scope_key, sha256)`. Любое Action, берущее больше
  одной блокировки, следует порядку (arch-ревью + тест deadlock-сценария на PG).
- `VaulterDatabase::transaction()` повторяет транзакцию на `40001`/`40P01` (PG) и deadlock
  MySQL до 3 раз.
- Promote объекта в хранилище не входит в SQL-транзакцию: документированная сага
  «stage → promote → metadata commit → при откате объект-сирота → reconciler после grace».

---

<a id="d22"></a>
### D22 — Единый конвейер событий · T0 (идентичность) / T1 (конвейер)

**Решение.**

- База `Vaulter\Events\DomainEvent` (abstract readonly): `eventId` (ULID, создаётся в
  конструкторе), `occurredAt`, `actor` (`ActorRef`), `driveId`, `correlationId`,
  `aggregateType`, `aggregateId`, `sequence` (D23), `type()` → `EventType`.
- `EventRecorder::record(DomainEvent)` вызывается **внутри** транзакции Action на соединении
  Vaulter: пишет activity (если фича `activity`) и outbox (если `webhooks`) с
  `event_id = eventId`; затем `afterCommit` диспатчит Laravel-событие для слушателей хоста.
  Ошибка записи outbox/activity откатывает операцию (durable-контракт).
- Дедупликация outbox — только по occurrence id (повтор доставки), команды различаются всегда
  (E8). Идемпотентность команд — отдельный механизм (T2, `Idempotency-Key`).
- `WebhookEvent` и `ActivityVerb` сливаются в `Vaulter\Enums\Events\EventType`; каталог
  дополнен документами, ревизиями, комментариями и загрузками ([08-data-model-and-migration.md](08-data-model-and-migration.md) §6).
- Подписчики `RecordsOutbox`, `RecordsDriveOutbox`, `RecordsActivity`, `RecordsDriveActivity`
  удаляются; события больше не реализуют `ShouldDispatchAfterCommit` (after-commit даёт recorder).
- Webhook payload — конверт в стиле CloudEvents: `id, type, source, time, drive_id, subject,
  sequence, data`.

---

<a id="d23"></a>
### D23 — `v_nodes.sequence`: одна версия узла для ETag и порядка · T1

Целочисленный `sequence` увеличивается при каждой мутации узла (rename, move, trash, restore,
новая ревизия, изменение контента). Используется как ETag (`W/"{sequence}"` не нужен — strong
`"{id}.{sequence}"`), как `expectedSequence` в PHP API (`StaleNodeException`, 412) и как
`sequence` события (порядок для потребителей webhooks).

---

<a id="d24"></a>
### D24 — Коллаборация: поколение комнаты и совместимость схемы · T1

- `v_documents.collab_epoch`: restore ревизии и смена типа/мажорной схемы увеличивают epoch;
  токен и колбэки хаба несут epoch; `store` со старым epoch → 409, хаб выгружает документ и
  переподключает клиентов (N17).
- Токен несёт `schema_version`; клиент со старшей/младшей несовместимой схемой получает
  read-only.
- Пользователь без `Edit` получает read-only соединение; отзыв прав действует на следующем
  `authenticate`/`store` (мгновенный разрыв — T2).
- Бинарный Yjs-state — источник истины для collaborative-типов; JSON — проекция.

---

<a id="d25"></a>
### D25 — База данных: соединение Vaulter и типы ключей хоста · T0

- `Vaulter\Database\VaulterDatabase`: `connection()`, `transaction(Closure, int $attempts = 3)`,
  `lockTree(int|string $driveId)`, `advisoryLock(string $key)`, `driver()`. Все Actions,
  репозитории, CAS, GC, relay используют только его (N01). Arch-тест запрещает
  `DB::transaction`, `DB::connection()` без аргумента и фасад `DB` в `src`.
- Новый ключ `vaulter.ids.host_keys`: `string` (по умолчанию, `varchar(64)`) | `bigint` | `uuid` | `ulid`.
  Применяется к `v_drives.owner_id`, `v_node_grants.subject_id`, всем `tenant_id` и всем
  actor-колонкам (N02). Стратегия `ids.strategy` управляет только первичными/внешними ключами
  таблиц Vaulter.
- doctor сверяет фактические типы колонок с конфигом (N28).

---

<a id="d26"></a>
### D26 — Конфигурация по владельцам и типизированная · T1

Детали — [07-configuration.md](07-configuration.md). Суть: файл на пакет (`vaulter.php`,
`vaulter-media.php`, `vaulter-drive.php`, `vaulter-documents.php`, `vaulter-azgard.php`,
`vaulter-filament.php`); ключи, влияющие на схему, живут в `vaulter.php`; каждый пакет строит
свой readonly-объект конфигурации при boot и валидирует его (ошибки безопасности — исключение
во всех окружениях); статический `Support\Config` удаляется; `config('vaulter...')` вне
конфиг-классов запрещён arch-тестом.

---

<a id="d27"></a>
### D27 — Одна модель удаления и жизненный цикл drive · T1

- `SoftDeletes` удаляется со всех моделей; у узлов остаётся доменная корзина `trashed_at`,
  purge — физическое удаление; grants удаляются физически (история — activity/outbox);
  shares — `revoked_at`.
- Drive: `status` = `active|archived|deleting`; операции `archive`, `delete` (асинхронный
  purge всех узлов, ревизий и ссылок на blobs, затем строки drive; GC освобождает байты).
- Опциональный trait `Vaulter\Concerns\HasDrives` для моделей хоста: `drive(?string $profile)`,
  `drives()` (morphMany), и `vaulter.drives.delete_with_owner` (по умолчанию `false`) —
  запускает удаление drives при `deleted` владельца (N22).

---

<a id="d28"></a>
### D28 — Экспорт принадлежит documents · T1

Контракт `DocumentExporter` и реестр переносятся в `vaulter-documents`; экспортёр объявляет
`documentTypes()` и `format()`; реестр резолвит по паре (тип, формат). HTTP-экспорт отдаёт
файл (`Content-Disposition`), а не JSON. `VaulterManager::registerExporter/exporter/exporterFormats`
удаляются.

---

<a id="d29"></a>
### D29 — Ошибки: RFC 9457 и стабильные коды · T0 (маппинг) / T1 (формат)

Все исключения Vaulter реализуют `Vaulter\Exceptions\Contracts\ProblemException`
(`problemCode(): string`, `httpStatus(): int`, `problemDetails(): array`); один
`ProblemRenderer` регистрируется для запросов маршрутов Vaulter и отдаёт
`application/problem+json`. Сообщение «Why/Fix/Docs» остаётся в тексте исключения для логов,
но не уходит клиенту как `detail`. Таблица кодов — [10-http-api-v1.md](10-http-api-v1.md) §6.

---

<a id="d30"></a>
### D30 — REST v1 · T1

URL-версия `/{prefix}/v1`; имена маршрутов `vaulter.v1.*`; карта ресурсов, курсоры,
`ETag`/`If-Match`, конверт ошибок — [10-http-api-v1.md](10-http-api-v1.md). OpenAPI 3.1
поддерживается вручную в `openapi/vaulter-v1.yaml`; контрактные тесты сверяют ответы со
схемой; TS-типы Vue генерируются из неё.

---

<a id="d31"></a>
### D31 — Структура HTTP-слоя · T1

- Контроллеры — однодейственные (`__invoke`), `Http/Controllers/<Domain>/<Verb><Noun>Controller`.
- Нет глобальных `Route::bind`: параметры — скаляры, резолв через `HttpResourceResolver`
  внутри маршрутов Vaulter с учётом drive и тенанта (N10).
- Три стека middleware: `routes.middleware.authenticated` (по умолчанию `['api', 'auth:sanctum']`),
  `routes.middleware.public` (`['api']`), `routes.middleware.hub` (`['api']`) (N09).
- Контроллер: request → context (`HttpOperationContextFactory`) → Operations → Resource.

---

<a id="d32"></a>
### D32 — Vue SDK · T1

Реестр viewer'ов — на экземпляр приложения через plugin (`provide/inject`), не глобальный
`Map`; типы API генерируются из OpenAPI; компонент-заглушка для неизвестного viewer/типа;
удаление наследия (`docVaultClientKey`); проверка версии API по заголовку. Детали —
[11-frontend-sdk.md](11-frontend-sdk.md).

---

<a id="d33"></a>
### D33 — Ресурсы REST без внутренних указателей, abilities пакетно · T1

`NodeResource` получает abilities из `evaluateMany` для всей страницы; поля `file_id`,
`current_version_id` удаляются; добавляются `sequence`, `etag`, `document.type`,
`viewer`, `has_revisions`.

---

<a id="d34"></a>
### D34 — Эксплуатация: doctor, install, планировщик, генераторы · T1

- `vaulter:doctor` с расширяемыми проверками (`DoctorCheck` из каждого пакета), `--production`,
  `--json`, код выхода ≠ 0 при ошибке.
- `vaulter:install` — интерактивный выбор middleware, драйвера прав, публикации и миграций,
  печать проверки doctor.
- Планировщик регистрирует задачи пакетов автоматически (`vaulter.schedule.enabled = true`,
  частоты конфигурируемы) (N23).
- Команды — `vaulter:<area>:<verb>`; генераторы `vaulter:make:profile|file-handler|document-type|permission-driver|exporter`.

---

<a id="d35"></a>
### D35 — Набор для тестов хоста и контрактные наборы расширений · T1

`Vaulter\Testing\`: фабрики моделей, `InteractsWithVaulter` (acting-as, fake storage disk,
assert-helpers событий), контрактные наборы `FileHandlerContract`, `DocumentTypeContract`,
`PermissionDriverContract`, `ExporterContract` для авторов расширений. Production-код не
импортирует `Testing` (arch-тест).

---

<a id="d36"></a>
### D36 — Релиз и артефакты · T0 (блокеры) / T1

Metapackage; проверка согласованности версий split-пакетов; `vaulter-azgard` публикуется только
при тегированном `azguard-core`, иначе исключается из релиза; clean-consumer установка из собранных
архивов; upgrade 0.2 → 0.3 на данных. Детали — [12-operations-and-release.md](12-operations-and-release.md).

---

<a id="d37"></a>
### D37 — Поддержка СУБД · T1

PostgreSQL 15+ — основная, полный набор тестов, включая конкурентность; MySQL 8.0+/MariaDB 10.11+ —
поддерживается, CI smoke + тесты инвариантов; SQLite — только dev/test (doctor в production
выдаёт ошибку). Обоснование: advisory locks, частичные индексы, SERIALIZABLE-ретраи.

---

<a id="d38"></a>
### D38 — Документация следует за кодом · T1

README и `docs/` переписываются после канона; каждый PHP/TS-пример из guide — исполняемый
рецепт в тестах (`tests/Recipes`), а не свободный текст; внутренние коды задач (`P2 #5`, `F-P3.3-02`,
`H.5`) удаляются из docblock'ов `src`, ADR-ссылки остаются как `@see docs/adr/...`.

---

<a id="d39"></a>
### D39 — Сознательно отложено (T2, аддитивно)

`Idempotency-Key` для POST; обобщённая таблица ссылок на blobs (`v_blob_references`) для
вложений документов и производных; shortcuts; импорт «файл → документ»; ClamAV-адаптер;
`pg_trgm`-поиск; миниатюры; мгновенный отзыв collab-соединений; materialized effective grants;
ретеншн/физическая квота истории.

---

<a id="d40"></a>
### D40 — Зарезервированные поля узла · T1

`NodeKind::Shortcut` остаётся в enum как зарезервированный: создание в 1.0 отклоняется
(`UnsupportedNodeKindException`), клиенты обязаны показывать неизвестный kind безопасно.
`is_pinned` и `position` становятся управляемыми через `NodeOperations::update()` и `PATCH nodes/{node}`.
