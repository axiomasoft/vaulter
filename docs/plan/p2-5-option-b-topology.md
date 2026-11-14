# P2 #5 — Целевая топология пакетов (Option B) — ADR-0015 + durable plan

> **Примечание (пост-факт):** этот документ — decision-лог допереименования. Упоминаемый ниже
> рабочий нейминг **DocDrive**/`vaulter-docdrive` — то же самое, что впоследствии вышло под именем
> пакета `axioma-studio/vaulter-documents` (namespace **Vaulter Documents**). Актуальное имя и
> историю переименования см. в `CHANGELOG.md`; тело документа ниже не переписано намеренно.
> Аллокация «`Documents\*` (registry/handlers/services) → docdrive» (ниже, §TL;DR и §Аллокация)
> тоже устарела: engine-ownership (D10) впоследствии увёл registry/`DocumentHandlerRegistry`/
> `NodeHandlerResolver`/`DocumentFileDescriptorFactory`/baseline-хендлеры обратно в core
> (`Vaulter\Engines\*`); `vaulter-documents` держит только свои форматные хендлеры
> (`TipTapHandler`, `UniverHandler`) в `Vaulter\Documents\Engines\Handlers\*`. Актуальные FQCN
> и P2.3-переезд (`Documents\Documents\*` → `Documents\Engines\*`/`Export`/`Collaboration`,
> `Drive\Uploads\StagedUpload` → `Drive\Support\Uploads\StagedUpload`) см. `UPGRADE.md`
> [Unreleased] («Engine ownership (D10)», «Catch-all folders resolved by role… (VLT-CANON P2.3)»).

> **Статус:** Proposed (design-фаза `/task:design`, кода нет). Primary source для фаз Kernel-harden →
> Drive → DocDrive. Продолжение линии `docs/plan/p2-5-vaulter-media-extraction.md` (media уже извлечён).
> Ветка при исполнении: своя feature-ветка от `origin/main`. НЕ push/PR без явной просьбы.
>
> **Как поднять из файла после /clear:** читать этот файл целиком + §Аллокация + §Enabling refactor +
> §Фазы. Основания: ADR D2/D3 (Vaulter Media/Drive/DocDrive), D15 (один репо, path-repos + subtree-split),
> RAG «schema-owner package» (см. §Провенанс). Доказательная база — 5 code-mapper'ов (§Провенанс).
>
> **Аддендум:** namespace-стратегия для `vaulter-drive`/`vaulter-docdrive` (D22–D26) решена в
> отдельном файле `docs/plan/p2-5-namespace-strategy-drive-docdrive.md` — RENAME-TO-SINGLE-PREFIX
> для ОБОИХ пакетов (`Vaulter\Drive\*`/`Vaulter\DocDrive\*`), с точными PSR-4-картами,
> коллизионным инвентарём (10 директорий) и обновлённым arch-guard-планом. См. §Enabling refactor
> п.6 ниже.

## TL;DR — решение

Наивная формулировка «вынести Drive (вместе с деревом узлов) и DocDrive из core» **неверна**: она рождает
жёсткий цикл. `AccessResolver::can(…, Node)`, `ShareContext`, `ActivityRecorder`, `NodePolicy` и сам
`VaulterServiceProvider` (policy/route-bind/broadcast) **жёстко** типизированы на конкретный
`Vaulter\Models\Drive\Node`; а `media`-GC жёстко ссылается на `Models\Drive\FileVersion` +
`Repositories\Drive\FileVersionBlobStoreRepository`. Поэтому:

**`vaulter-core` = «kernel + единый владелец схемы»** (RAG-паттерн schema-owner package): владеет **всем**
деревом данных (модели Node/Storage/File/FileVersion/FileVersionBlob/Blob + NodeGrant/NodeShare/Activity) и
**всей** схемой (`PanelTablesBuilder` целиком + все миграции + все `v_*`), плюс Access/ACL, Sharing, Activity,
Reliability/webhooks, Panels, Config, node/file/version/blob-репозитории, CAS-контракты, id-strategy, tenancy.

**Пакеты-фичи владеют ПОВЕДЕНИЕМ** (и своими НЕ-связанными моделями), требуют core, схему не определяют:
- **`vaulter-media`** (готов) — CAS-движок. requires core.
- **`vaulter-drive`** — файл/папка/upload **поведение**: `Actions\Drive`, `StorageManager`, `DriveContext`,
  Drive+Upload контроллеры/маршруты, `Events\Drive`, весь **Uploads** (вкл. его модели `UploadSession/Part`).
  requires core + media.
- **`vaulter-docdrive`** — документный **движок** вкл. свои модели: `NodeComment`/`NodeDocumentVersion`,
  `Documents\*` (handlers/registry/services), `Actions\Documents`, `Collaboration\*`, `Export`,
  `Casts\BinaryString`, документные контроллеры/маршруты. requires **только core** (НЕ media, НЕ drive).

**Ключевые следствия:** цикла нет (все стрелки внутрь, к core); `filament` остаётся **core-only** (использует
Node/Storage/ACL/NodeReadRepository — всё kernel; правка composer НЕ нужна); `vue` не затрагивается (HTTP-клиент);
`permissions-azguard` не затрагивается. `PanelTablesBuilder` **не режется** — самый рискованный кусок остаётся
целым в kernel. Отложенный вынос `v_blobs`-схемы **снимается как «не выносим»**: core — постоянный владелец
схемы by design (см. §Обсуждение, ревизия прежней заметки).

## Decision Table

| # | Решение | Выбор | Обоснование | Обратимо |
|---|---|---|---|---|
| T1 | Где живёт `Node`/`v_nodes` | **core-kernel** | Access/Sharing/Activity/NodePolicy/Provider жёстко типизированы на `Node`; `Node` несёт staudenmeir adjacency-list + `getTable()`/`getParentKeyName()` CTE-ловушки. Вынос инвертировал бы граф (core→drive). | нет |
| T2 | Владелец схемы (все `v_*` + `PanelTablesBuilder`) | **единый — core** (schema-owner package) | RAG: при обилии cross-package FK один schema-owner надёжнее; один поток миграций, детерминированный порядок, `PanelTablesBuilder` не режем (устраняет самый рискованный рефактор). | да |
| T3 | Storage-core модели (File/FileVersion/FileVersionBlob/Blob) | **core-kernel** | `media`-GC жёстко ссылается на `FileVersion` + `FileVersionBlobStoreRepository`; вынос в drive → цикл media→drive. Это blob-liveness-хребет, общий для media и drive. | нет |
| T4 | `vaulter-drive` scope | **поведение + Uploads-модели** | Drive-модели (File/FileVersion) — в kernel (T3); drive владеет Actions/controllers/StorageManager/DriveContext/Events + Uploads (его модели `UploadSession/Part` никто кроме drive не трогает). | да |
| T5 | `vaulter-docdrive` scope | **движок + свои модели** | `NodeComment`/`NodeDocumentVersion` не нужны ни media, ни kernel-доменам (Activity — только через события, мягко) → чисто выносимы вместе с handlers/collab/export. | да |
| T6 | Зависимость `docdrive` | **только core** | DocDrive использует Node/NodeKind/NodeReadRepository/AccessResolver (всё kernel) и **не** касается CAS/media (контент inline: JSON + `yjs_state` bytea через `BinaryString`). | да |
| T7 | Зависимость `drive` | **core + media** | Upload/Download коммитят в CAS через `Contracts\Cas\*` (media-движок). | да |
| T8 | Политика FK | **все FK внутрь, к kernel-таблицам; soft-FK там, где нужна толерантность к устаревшим строкам** | 6 жёстких FK уже указывают на `v_nodes`/`v_storages`/`v_blobs`; sweeper/GC/moved-node ссылки уже осознанно НЕ hard-FK. | да |
| T9 | Дефолты swappable-моделей в `Config` | **инверсия в провайдеры пакетов** (media-паттерн) | Для моделей, что уезжают (Uploads → drive; Comment/DocVersion → docdrive), `Config`-фолбэки нельзя оставлять hard-ссылкой (core→feature цикл); провайдер пакета мёржит дефолт. Node/Storage/File/FileVersion/Blob остаются в kernel — их дефолты остаются в `Config`. | да |
| T10 | Legacy DocVault (`Document*`, `v_documents`) | **никуда — уже вырезан** (ADR-0014, drop-миграция `000023`) | Классов/таблиц нет; будущий data-мост (если понадобится) — отдельный пакет, не часть docdrive. | нет |
| T11 | Порядок фаз | **Kernel-harden → (drive ∥ docdrive)** | drive и docdrive независимы (ни один не требует другого); оба требуют kernel-hardening (T9 + разбор seam'ов). | да |
| T12 | `v_blobs`-схема | **остаётся в core** (ревизия прежнего «отложено до Drive») | T2/T3: core — schema-owner; `BlobStoreRepository` (core) + `FileVersionBlob` (core) ссылаются на `Blob` → перенос схемы/модели в media бессмысленен и рискован. media = чистый движок. | да |

## Целевой граф пакетов (цикла нет)

```
                         vaulter-core  (kernel + schema-owner; НИ одной Vaulter-зависимости)
                          ▲   ▲   ▲   ▲
              ┌───────────┘   │   │   └───────────────┐
   vaulter-media          vaulter-drive        vaulter-docdrive        vaulter-filament
   (+Flysystem)           (→core, →media)      (→core)                 (→core)   [без изменений]
   requires core          requires core+media  requires core

   vaulter-permissions-azguard → core (ACL-шов; без изменений)
   @vaulter/vue (npm)         → HTTP only; PHP-split не влияет
```

Доказательство отсутствия цикла: единственные «обратные» риски — (а) core→feature через `Config`-дефолты
(снимается T9-инверсией), (б) media→drive через `FileVersion`/`FileVersionBlobStoreRepository` (снимается T3 —
эти классы остаются в kernel). После этого все Vaulter-стрелки указывают в core; core не требует ничего из
экосистемы. Идентично инварианту media-извлечения.

## Аллокация: класс/таблица → пакет

### Схема (все `v_*` + `PanelTablesBuilder`) — **core** (T2), целиком, без изменений
16 `define*()` методов остаются в `packages/core/src/Schema/PanelTablesBuilder.php`; миграции —
`packages/core/database/migrations/*`. Правило именованных индексов (деривация из `$table->getTable()`, НЕ
хардкод) и порядок миграций (числовой префикс `000007..`) — инвариант, не трогаем. Модели-владельцы могут жить
в feature-пакетах, но таблицу они адресуют строкой `Config::table(...)`, а не классом builder'а → нет связи.

### Модели
| Модель | Пакет | Причина |
|---|---|---|
| `Models\Drive\Node`, `Models\Drive\Storage` | **core** | T1 (спина всего дерева; adjacency-list) |
| `Models\Drive\File`, `FileVersion`, `FileVersionBlob` | **core** | T3 (blob-liveness хребет; media-GC зависит) |
| `Models\Cas\Blob` | **core** | ссылается на `FileVersionBlob`; `BlobStoreRepository` (core) держит его |
| `Models\Access\NodeGrant`, `Models\Sharing\NodeShare`, `Models\Activity\Activity`, `Models\Webhooks\*` | **core** | kernel-домены |
| `Models\Uploads\UploadSession`, `UploadPart` | **drive** | только Uploads-домен их трогает |
| `Models\Documents\NodeComment`, `NodeDocumentVersion` | **docdrive** | только docdrive; media/kernel не зависят |

### Слои поведения
| Что | Пакет |
|---|---|
| `Access\*` (AccessResolver, Abilities, ValueObjects), `Policies\NodePolicy`, `Actions\Access\*`, `Repositories\Access\*`, `Contracts\Access\*`, `Drivers\Permission\*`, `Enums\Access\*` | **core** |
| `Sharing\*`, `Actions\Sharing\*`, `Repositories\Sharing\*`, `Http\Controllers\Sharing\*`, `Contracts\Sharing\*` | **core** |
| `Activity\*`, `Repositories\Activity\*`, `Http\Controllers\Activity\*` | **core** |
| `Webhooks\*`, outbox, команды Relay/Retry/Prune | **core** |
| `Repositories\Drive\{NodeReadRepository, NodeStoreRepository, StorageReadRepository, StorageStoreRepository, FileStoreRepository, FileVersion*Repository, FileVersionBlobStoreRepository}` | **core** (node/file/version/blob data-access; используется drive/docdrive/filament/media) |
| `Repositories\Cas\BlobStoreRepository`, `Cas\BlobLock`, `Contracts\Cas\*` | **core** (как после media-извлечения) |
| `Panels\*`, `Support\Config`, `Schema\IdStrategy*`, `Enums\Drive\NodeKind`, общие `ValueObjects\*`, concerns `HasVaulterKeys/HasExtraData` | **core** |
| `Contracts\Collaboration\*` (референсит `Node`) | **docdrive** (требует core; core сам collab не биндит) |
| `Actions\Drive\*` (Upload/CreateFolder/Move/Rename/Copy/Trash/Restore/ForceDelete), `Drive\{DriveContext,StorageManager}`, `Events\Drive\*`, `Exceptions\Drive\*`, `Enums\Drive\BulkAction`, `ValueObjects\Drive\*`, `Data\{CreateDriveFolder,UploadFile,MoveNode,RenameNode,CopyNode}Data`, `Http\Controllers\Drive\{Node,Download,Storage}Controller`, `Http\Requests\Drive\*`, `Http\Resources\Drive\*`, команда PurgeTrash | **drive** |
| Uploads: `Uploads\*`, `Actions\Uploads\CommitUploadAction`, `Repositories\Uploads\*`, `Enums\Uploads\*`, `Contracts\Uploads\*`, `Data\Uploads\*`, `Http\Controllers\Uploads\*`, команда SweepUploads | **drive** |
| `Documents\*` (registry/handlers/services), `Actions\Documents\*`, `Repositories\Documents\*`, `Events\Documents\*`, `Enums\Documents\*`, `Enums\Collaboration\*`, `Exceptions\Documents\*`, `ValueObjects\Documents\*`, `Data\{CreateDocumentNode,UpdateDocumentContent,CreateNodeComment,PersistYjsSnapshot,CreateNamedVersion,CollaborationSession}Data` | **docdrive** |
| `Collaboration\*` (drivers/manager/call-resolver), `Casts\BinaryString`, `Export\*`, `Support\{TipTapRenderer,DocumentContentHasher}`, `Support\Concerns\HasTiptapExtensions` | **docdrive** |
| `Http\Controllers\Drive\{DocumentNode,NodeCollaboration,NodeComment}Controller` → переименовать в `Http\Controllers\Documents\*`, `Http\Middleware\ResolveCollaborationCall`, документные Requests/Resources | **docdrive** |

## Политика схемы и cross-package FK (T2/T8)

- **Единый владелец схемы = core.** Feature-пакеты миграций **не** определяют — потребляют core-схему.
  RAG-обоснование: много таблиц, на которые ссылаются несколько доменов; стабильный порядок install; один
  поток миграций для ревью breaking-DB-изменений.
- **6 жёстких FK — все внутрь**, в kernel-таблицы: `v_files.node_id`→`v_nodes`, `v_node_comments.node_id`→
  `v_nodes`, `v_node_grants.node_id`→`v_nodes`, `v_node_shares.node_id`→`v_nodes`,
  `v_node_document_versions.node_id`→`v_nodes`, `v_upload_sessions.storage_id`→`v_storages`,
  `v_file_version_blobs.blob_id`→`v_blobs`, `v_file_versions.file_id`→`v_files`. Все определяются одним core-builder'ом
  через строковое имя таблицы (`$panel->table('nodes')`) — нулевая связь на PHP-классы.
- **Soft-FK инвариант сохраняется**: `root_node_id`, `current_version_id`, upload-session→node/blob и т.п. —
  осознанно НЕ hard-FK (толерантность к sweeper/GC/moved-node). Не превращать их в hard при разносе.

## Enabling refactor (kernel-hardening) — обязательные seam-правки ДО drive/docdrive

Это отдельная фаза (см. §Фазы, Фаза K). Точный список:

1. **T9 — инверсия дефолтов `Config` для уезжающих моделей.** В `Support\Config` убрать hard-`use` +
   hard-фолбэки для `uploadSessionModel/uploadPartModel` (→drive) и `nodeCommentModel/nodeDocumentVersionModel`
   (→docdrive); читать из config, guard-throw при отсутствии; провайдер соответствующего пакета делает
   `mergeConfigFrom` дефолта (в тот же ключ `vaulter.*`, как media сделал с `vaulter.cas.*`). Node/Storage/
   File/FileVersion/FileVersionBlob/Blob дефолты — ОСТАЮТСЯ в `Config` (модели в kernel).
2. **Разбор seam'а `Contracts\Collaboration\NodeCollaborationDriver`**: контракт референсит `Node` (kernel) →
   уезжает в docdrive (docdrive→core). Убедиться, что core (kernel) сам его НЕ биндит; биндинг
   `NodeCollaborationDriverManager` переезжает в docdrive-провайдер. Broadcast-канал (Reverb private channel,
   `VaulterServiceProvider::registerBroadcastChannel`, gated `document_versions`) — переносится в docdrive-провайдер.
3. **Маршруты**: монолитный `routes/api.php` режется по пакетам — drive-маршруты (nodes/storages/download/
   grants?/uploads) в drive-провайдер (`->hasRoute('drive')`); docdrive-маршруты (documents/content/versions/
   comments/collab) в docdrive-провайдер. `{node}` route-model binding — остаётся в kernel-провайдере (Node — kernel),
   feature-маршруты его переиспользуют. **Grant-контроллер** (`Http\Controllers\Drive\GrantController`) — это ACL
   (Access-домен) → маршрут остаётся в kernel, не в drive.
4. **ServiceProvider split**: kernel-провайдер держит node policy + `Route::bind('node')` + kernel-команды +
   Access/Sharing/Activity/Webhooks-проводку; drive-провайдер — Drive/Upload команды(PurgeTrash/SweepUploads)/
   события/маршруты/биндинги; docdrive-провайдер — handlers-registry/collab/broadcast/маршруты/биндинги.
5. **Arch-тесты (дисциплина namespace-refactor-safety)**: (а) dependency-direction — `packages/core/src` НЕ
   ссылается на `Vaulter\Drive\*`/`Vaulter\DocDrive\*` (по образцу media-проверки «0 ссылок core→media»);
   (б) namespace-parity доменных папок; (в) миграции kernel лексикографически раньше feature-миграций (если
   feature когда-либо добавит свои — сейчас нет). Проверка порядка миграций — если T2 соблюдён (все миграции в
   core), это неактуально; арх-тест на dependency-direction — главный.
6. **Namespace-стратегия — РЕШЕНО (D22/D23, аддендум `p2-5-namespace-strategy-drive-docdrive.md`):
   RENAME-TO-SINGLE-PREFIX для ОБОИХ пакетов** (`Vaulter\Drive\*`, `Vaulter\DocDrive\*`) — не
   KEEP-SCATTERED. Пререквизит **до** git mv в D1/D2 — физическое расселение 10 коллизионных
   директорий (аддендум §Инвентарь: `Enums\Drive`, `Http\Controllers\Drive`,
   `Http\Requests\Drive`, `Http\Resources\Drive`, плоские `Data\`/`Support\`/`Support\Concerns\`/
   `Http\Middleware\`/`Http\Requests\`/`Console\`), часть Фазы K. Три фазы
   alias→use-update→hard-rename — как в media (дисциплина `php:namespace-refactor-safety`).
   Ловушки-префиксы: `Node` ⊂ `NodeComment`/`NodeDocumentVersion`/`NodeGrant`/`NodeShare` — при
   массовых find/replace не задеть (строковая ловушка на именах классов, отдельно от
   namespace-путей аддендума). Точные PSR-4-карты обоих пакетов и обновлённый arch-guard-план
   (`Vaulter\Drive`/`Vaulter\DocDrive` guard'ы + peer-guard'ы drive⊥docdrive, docdrive⊥media) — в
   аддендуме.

## Фазы исполнения

> Порядок T11. Каждая фаза — своя feature-ветка от `origin/main`, свой `/task:dev`, оба гейта зелёные,
> дисциплина namespace-refactor-safety, arch-тест dependency-direction. Media-паттерн (провайдер мёржит
> дефолты, seam-контракты в core, provider в core-TestCase) — образец.

### Фаза K — Kernel-hardening (design-lite → dev, риск: MED)
Не выносит пакетов; готовит швы. Scope = Enabling refactor пп.1–6: `Config`-инверсия, collab-seam разбор,
подготовка провайдера/маршрутов к разрезанию (можно поэтапно), arch-тест dependency-direction (пока тривиально
зелёный — 0 feature-пакетов). **Критерий приёмки:** core-гейт зелёный (pint+phpstan max+pest+type-cov 100%);
`Config` больше не hard-ссылается на модели, которые в будущих фазах уедут; добавлен arch-тест-заготовка.
**Риск MED:** трогает `Config` (SSOT) и провайдер; поведение неизменно.

### Фаза D1 — `vaulter-drive` (dev, риск: HIGH)
Scope = §Аллокация «drive» (Actions/StorageManager/DriveContext/Events/Drive-контроллеры/маршруты + весь Uploads
вкл. модели). Модели File/FileVersion/FileVersionBlob/Blob и вся схема — ОСТАЮТСЯ в core. composer: drive requires
core+media (+path-repo ../core, ../media). Провайдер drive: команды PurgeTrash/SweepUploads, Drive/Upload маршруты,
Uploads-модель-дефолты (mergeConfig). core: require-dev drive + провайдер в core-TestCase (тесты Drive/Uploads);
core-провайдер снимает Drive/Upload-специфику. Тесты `tests/Drive/*`+`tests/Uploads/*`+Drive-часть `tests/Feature/*`
→ drive-пакет. CI: `drive.yml` (+ PG-линия для upload-commit-critical) + matrix `+drive` в monorepo-split.
**Критерий:** оба гейта зелёные; `filament` по-прежнему core-only (не сломан); PG upload-линия исполняется;
arch-тест «0 ссылок core→drive» зелёный. **HIGH:** upload/commit-транзакции, StorageManager, cross-package провод.

### Фаза D2 — `vaulter-docdrive` (dev, риск: HIGH)
Scope = §Аллокация «docdrive» (модели Comment/DocVersion + Documents/handlers + Actions + Collaboration + Export +
BinaryString + документные контроллеры/маршруты + collab-webhooks/broadcast). Схема (`v_node_comments`,
`v_node_document_versions`) — ОСТАЁТСЯ в core. composer: docdrive requires **только core**. Провайдер docdrive:
handler-registry, collab-manager/broadcast, документные маршруты, Comment/DocVersion-модель-дефолты (mergeConfig).
Тесты `tests/Document/*`+`tests/Comment/*`+collab/tiptap unit → docdrive-пакет. CI: `docdrive.yml` (+ PG-линия для
yjs_state bytea round-trip + jsonb gin) + matrix `+docdrive`. **Критерий:** оба гейта зелёные; collab e2e-стенд
(`testbench.yaml`) регистрирует docdrive-провайдер; arch-тест «0 ссылок core→docdrive» зелёный; `NodeCollaborationDriver`
контракт больше не в core. **HIGH:** collab/webhook-провод, broadcast-канал, yjs-персист, большой домен.

### Фаза R — Release Option B (owner, one-shot) — вне /task:dev
Создать split-репо `axioma-studio/vaulter-{drive,docdrive}` (media/core/filament/permissions-azguard уже в matrix),
секрет `GH_ACCESS_TOKEN`, Packagist, первый `v0.1.0` (subtree-split + Changesets для npm), ghcr hocuspocus-образ.

## Ops-хвосты media (закрыть параллельно, вне зависимостей) — ЗАКРЫТО

1. **`CHANGELOG.md` — запись о breaking-переносе media (НЕ changeset!). ГОТОВО.** Коммит `1d37375`: точный текст
   ниже присутствует в `[Unreleased] → ### Removed` дословно.
   ```md
   ### Removed
   - **BREAKING (pre-1.0): the CAS engine moved out of `vaulter-core` into the new standalone package
     `axioma-studio/vaulter-media`** (P2 #5, D2/D3). The concrete content-addressed blob engine —
     `Sha256ContentStore`, `FlysystemStorageDriver`, `BlobStore`, `BlobGarbageCollector`, `BlobReconciler`,
     `StagedTempObject` — and the `vaulter:gc-blobs` / `vaulter:reconcile-blobs` commands moved from
     `Vaulter\Cas\*` to `Vaulter\Media\Cas\*` / `Vaulter\Media\Console\*`. Hosts referencing those FQCNs
     directly, or relying on `vaulter-core` alone for Drive file upload/download/versioning, must now also
     `require axioma-studio/vaulter-media`. `Contracts\Cas\*`, the `v_blobs` schema, `Models\Cas\Blob`,
     `Enums\Cas\BlobStatus` and `Repositories\Cas\BlobStoreRepository` remain in core. Config paths
     (`vaulter.cas.*`, `vaulter.dedup.*`) are unchanged. Dependency direction is one-way (media → core; no cycle).
   ```
2. **media CI структурно исправен — ПОДТВЕРЖДЕНО ЗЕЛЁНЫМ.** `media.yml`: media-джоб pint+phpstan max+pest+
   type-cov 100%; media-pgsql-джоб гоняет `tests/Cas` на postgres:16 (advisory-xact-lock/FOR UPDATE/SERIALIZABLE/
   orphan-prune пути, которые SKIP на SQLite). Первый реальный прогон на `main` (push, `5e65ea8`, run
   `28543971661`) — оба джоба `completed/success`; более ранний PR-прогон на `refactor/extract-vaulter-media`
   тоже зелёный.
3. **Coverage `--min=80` асимметрия — ОСОЗНАННЫЙ ПАТТЕРН, оставлено как есть.** Подтверждено: root `tests.yml`
   (джоб `test`) гоняет `--coverage --min=80 --coverage-clover=coverage.xml` + Codecov-аплоад над
   `packages/core/src`; `test-pgsql` — без coverage (schema/portability-линия, ожидаемо). `drive.yml` и
   `documents.yml` (пакет `docdrive` был переименован в `documents`) зеркалят паттерн `media.yml` один-в-один:
   Pint + PHPStan max + Pest + type-cov 100%, без line-coverage-гейта и без Codecov. Это уже 3-я точка данных
   (media → drive → documents), подтверждающая намеренный house-style: type-coverage-only для тонких,
   feature-scoped пакетов над ядром, с полным Pest-сьютом (включая выделенные Postgres-линии для
   критических секций). Решение: **не добавлять** `--coverage --min=80` в drive.yml/documents.yml — 100%
   type-coverage + Postgres-линии дают более сильную структурную гарантию для таких пакетов, чем %-line-coverage
   порог, а добавление line-coverage потребовало бы Codecov-обвязки и подбора порога per-package за
   маргинальную выгоду. Если монорепо-wide line-coverage parity станет жёстким требованием политики — можно
   вернуться и добавить `--coverage --min=80` + Codecov-степ в оба джоба, по образцу `tests.yml`.

## Инварианты / критерии приёмки (каждая фаза)
1. Оба гейта зелёные: pint + phpstan **max** + pest + type-cov 100% (core и новый пакет).
2. **Нет цикла**: arch-тест «0 ссылок `core/src` → `Vaulter\Drive`/`Vaulter\DocDrive`»; feature→core только.
3. Ноль изменений поведения (механическое извлечение); dedup-scope и все feature-флаги целы.
4. Контракты (`Contracts\Cas/Access/Collaboration/Documents/Sharing/Uploads`) = швы, phpstan-clean с обеих сторон.
5. `filament`/`vue`/`permissions-azguard` не сломаны (filament остаётся core-only — проверить composer + гейт).
6. Единый поток миграций в core; порядок `000007..` не нарушен; именованные индексы деривятся из имени таблицы.
7. Breaking (пре-релиз 0.1.0) — запись в `CHANGELOG.md [Unreleased]` на каждый вынос.

## Обсуждение (спорное — рецензия и рекомендация)

Главная развилка: **насколько «толстыми» делать drive/docdrive** (кто владеет схемой и моделями). Три варианта.

### Вариант 1 — «Данные в kernel, поведение в пакетах» (schema-owner core) — **РЕКОМЕНДОВАН**
core владеет всей схемой (`PanelTablesBuilder` цел) и всеми storage-core-моделями; drive/docdrive владеют
поведением (и docdrive — своими несвязанными моделями Comment/DocVersion; drive — Uploads-моделями).
- **Плюсы:** самый низкий риск (не режем `PanelTablesBuilder` — драйвер-aware индексы/деривация имён/порядок
  миграций остаются целыми); **RAG прямо это советует** для схемы с обилием общих FK (один поток миграций,
  стабильный install-order); `filament` остаётся **core-only** (данные в kernel) — консюмеры не усложняются;
  переиспользует **проверенный media-паттерн** (поведение наружу, данные/контракты внутрь); цикл снимается
  малыми швами (T9-инверсия + T3). Пакеты — реальные (drive = файл-менеджер+resumable-upload поверх kernel;
  docdrive = крупный документный движок со своими моделями).
- **Минусы:** drive не владеет схемой своих таблиц (они в core) → «менее самостоятелен»; но это **вынужденно**
  blob-liveness-связью media↔version-blob (T3) и осознанно (schema-owner by design, T2). `v_blobs` «навсегда» в core
  (не в media) — но это by design, не долг.

### Вариант 2 — «Полное владение схемой по пакетам»
Разрезать `PanelTablesBuilder` на per-package builder'ы; каждый пакет владеет своими миграциями; `v_blobs`→media;
File/FileVersion/FileVersionBlob→drive.
- **Плюсы:** учебниковая независимость пакетов; каждый self-contained.
- **Минусы:** **самый высокий риск** — режем драйвер-aware индекс-SQL (PG/SQLite partial-index fallbacks),
  деривацию имён и cross-package порядок миграций (date-stamp-конвенция без enforced-констрейнта); **RAG прямо
  предостерегает** от peer-to-peer FK-связывания для heavily-shared схемы; чтобы File/FileVersion уехали в drive,
  надо разорвать media→drive-зависимость новым швом `Contracts\Cas\LivenessSource` (деликатный рефактор GC).
  Практической выгоды над Вариантом 1 почти нет (пакеты всё равно ставятся вместе; drive и так requires media).

### Вариант 3 — «Не дробить дальше»
Оставить drive+docdrive в core; сателлитами только media/filament.
- **Плюсы:** ноль риска/усилий; честно про запутанность дерева.
- **Минусы:** отказ от продуктовой цели D2/D3 (Drive как отдельная поверхность); core остаётся монолитом; docdrive —
  крупный чисто-выносимый домен (`Documents\*`/`Collaboration\*`/`Models\Documents\*`), вынос заметно облегчил бы core.

**Рекомендация: Вариант 1.** Он единственный, кто (а) не режет `PanelTablesBuilder` (снимает главный риск),
(б) совпадает с RAG-нормой для shared-schema-with-FK, (в) держит `filament` core-only, (г) переиспользует
проверенный media-паттерн, (д) даёт реальные пакеты. Вариант 2 добавляет риск без пропорциональной выгоды;
Вариант 3 бросает цель. **Ревизия прежней заметки** (`p2-5-vaulter-media-extraction.md`: «v_blobs-схема выносится
вместе с Drive») — при Варианте 1 схема `v_blobs` **остаётся в core осознанно** (core — schema-owner), а не «когда-нибудь
переедет»; это чище и убирает cross-package FK `v_file_version_blobs→v_blobs` из зоны риска.

## Провенанс
- **RAG (Perplexity, 2026-07):** «shared-schema Laravel monorepo → schema-owner package владеет общими таблицами +
  cross-cutting FK; feature-пакеты владеют только локальными таблицами; FK — внутрь, к schema-owner; не плодить
  peer-to-peer FK-связывание. Filament — не пример shared-schema; Statamic — per-capability миграции». Прямо
  поддерживает T2/T8/Вариант 1.
- **5 code-mapper'ов (Sonnet, evidence с file:line):** (A) Node-coupling → Node в kernel; (B) PanelTablesBuilder/FK →
  16 таблиц, 6 hard-FK внутрь, soft-FK-паттерн, string-based FK-таргеты, деривация имён индексов; (C) Drive-surface →
  цикл AccessResolver→Node + Config→models + Uploads=sub-feature; (D) DocDrive → hard-dep на Node, ноль media,
  legacy вырезан; (E) consumers/ops → filament hard-dep на модели, vue/permissions чисты, media CI ок, breaking →
  CHANGELOG не changeset.
- Проверено вручную: media ссылается на `Models\Drive\FileVersion` + `Repositories\Drive\FileVersionBlobStoreRepository`
  (→ T3: storage-core-модели в kernel, иначе media→drive цикл).
