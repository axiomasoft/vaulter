# P2 #5 — Аддендум к ADR-0015: namespace-стратегия для `vaulter-drive`/`vaulter-docdrive`

> **Примечание (пост-факт):** этот документ — decision-лог допереименования. Упоминаемый ниже
> рабочий нейминг **DocDrive**/`vaulter-docdrive` (`Vaulter\DocDrive\*`) — то же самое, что
> впоследствии вышло под именем пакета `axioma-studio/vaulter-documents` (namespace
> **Vaulter Documents**). Актуальное имя и историю переименования см. в `CHANGELOG.md`; тело
> документа ниже не переписано намеренно.

> **Статус:** Proposed (design-фаза `/task:design`, кода нет). Аддендум к
> `docs/plan/p2-5-option-b-topology.md` (§Enabling refactor, п.6 — там сейчас только заглушка
> «три фазы alias→use-update→hard-rename (как в media)», без разбора «по какому пакету» и без
> учёта коллизий директорий). Продолжает нумерацию решений глобального леджера
> `docs/plan/00-master-plan.md` (там D1…D21) — новые решения здесь: **D22–D26**.
>
> **Как поднять из файла после /clear:** прочитать целиком. Основания: §Аллокация и §Enabling
> refactor п.6 в `p2-5-option-b-topology.md`; media-прецедент (`p2-5-vaulter-media-extraction.md`,
> commit `5e65ea8`); текущий инвентарь `packages/core/src/*` (см. §Инвентарь ниже — числа и пути
> проверены напрямую по репозиторию, не по памяти). Composer PSR-4 longest-prefix-wins подтверждён
> внешней RAG-проверкой (§Провенанс).

## Вопрос аддендума

Два кандидата namespace-стратегии для извлечения `vaulter-drive`/`vaulter-docdrive` из
`packages/core/src`:

- **KEEP-SCATTERED** — оставить исторические имена (`Vaulter\Actions\Drive\*`,
  `Vaulter\Models\Uploads\*`, `Vaulter\Documents\*`, `Vaulter\Collaboration\*`,
  `Vaulter\Http\Controllers\Drive\*`, …) без переименования; новый пакет просто физически
  забирает эти файлы к себе, composer.json партиционирует их по префиксам.
- **RENAME-TO-SINGLE-PREFIX** — свести весь код пакета под один отличимый корень
  (`Vaulter\Drive\*` / `Vaulter\DocDrive\*`), как уже сделано для media (`Vaulter\Cas\*` →
  `Vaulter\Media\Cas\*`).

Решать **по каждому пакету отдельно** (drive и docdrive могут разойтись).

## TL;DR — решение

**D22: `vaulter-drive` → RENAME-TO-SINGLE-PREFIX (`Vaulter\Drive\*`).**
**D23: `vaulter-docdrive` → RENAME-TO-SINGLE-PREFIX (`Vaulter\DocDrive\*`).**

Оба пакета независимо приходят к одному ответу — не потому что решение скопировано без анализа
(асимметрия в §Обсуждение показывает, что у docdrive аргумент даже сильнее drive), а потому что
ключевой фактор — **выразимость arch-guard одной строкой** — одинаково весом для обоих, а
альтернатива (KEEP-SCATTERED) не даёт **никакой** экономии на самом дорогом шаге (физический
перенос файлов обязателен в любом случае — см. D24) и **при этом** платит постоянный налог на
сопровождение arch-теста.

**D24: физическое расселение 9 коллизионных директорий — обязательный пререквизит, НЕ зависящий от
D22/D23**, переносится в Фазу K (kernel-hardening), а не в Фазы D1/D2.
**D25: форма arch-guard** — одна строка на пакет (`expect('Vaulter')->not->toUse('Vaulter\Drive')`),
плюс новые peer-guard'ы drive⊥docdrive и docdrive⊥media (T11/T6 сейчас не проверены тестом).
**D26:** `GrantController`+2 grant-реквеста физически покидают папку `Http\Controllers\Drive`/
`Http\Requests\Drive` (остаются в core, но не в «чужой» по имени папке) — иначе после переезда
drive/docdrive эти папки не опустеют и будут вводить в заблуждение.

## §Инвентарь — точный список коллизионных директорий (проверено по репозиторию)

PSR-4 у Composer резолвит **самый длинный совпадающий префикс** (подтверждено RAG, §Провенанс) —
это значит, что **разные под-namespace одного корня** (`Vaulter\Actions\Drive\` vs
`Vaulter\Actions\Access\`) партиционируются между пакетами штатно, без переименований. Проблема —
не в разделяемых КОРНЯХ, а в директориях, где **файлы разных будущих владельцев физически лежат
рядом** — там PSR-4 не поможет: одна директория не может «принадлежать» двум пакетам одновременно
без переноса файлов.

Обнаружено **10** таких директорий (9 нашли исследовательские агенты + 1 доп. проверка вручную,
`Vaulter\Console\`):

| # | Директория | Кто остаётся | Кто уходит |
|---|---|---|---|
| 1 | `Enums/Drive/` | `NodeKind.php` (core, T1) | `BulkAction.php` → drive |
| 2 | `Http/Controllers/Drive/` | `GrantController.php` (core/Access, см. Enabling refactor п.3) | `{Node,Download,Storage}Controller.php` → drive; `{DocumentNode,NodeCollaboration,NodeComment}Controller.php` → docdrive (с переименованием) |
| 3 | `Http/Requests/Drive/` | `{Store,Destroy}NodeGrantRequest.php` (core/Access — см. D26) | `{CopyNode,CreateFolder,DriveNameRules,MoveNode,RenameNode,UploadFile}Request.php` → drive; `{CreateDocumentNode,CreateNamedVersion,UpdateDocumentContent}Request.php` → docdrive |
| 4 | `Http/Resources/Drive/` | `NodeGrantResource.php` (core/Access) | `{Node,Storage,FileVersion}Resource.php` → drive; `{NodeComment,NodeDocumentVersion}Resource.php` → docdrive |
| 5 | `Data/` (плоский, без под-папки) | `CollaborationSession.php`* (core-seam, см. сноску) | `{CreateDriveFolder,UploadFile,MoveNode,RenameNode,CopyNode}Data.php` → drive; `{CreateDocumentNode,CreateNamedVersion,CreateNodeComment,PersistYjsSnapshot,UpdateDocumentContent}Data.php` → docdrive |
| 6 | `Support/` (плоский) | `{AzgardConfig,Config,DefaultStorageAdapter,EnumCaseAttributeResolver,GateConfig,Identity,RandomShareTokenGenerator,RequestContext,ShareToken,SpatieConfig,VaulterSchema}.php` (core, 11 файлов) | `{TipTapRenderer,DocumentContentHasher}.php` → docdrive |
| 7 | `Support/Concerns/` | `{HasIcon,HasLabel}.php` (core) | `HasTiptapExtensions.php` → docdrive |
| 8 | `Http/Middleware/` (плоский) | `ResolveShareToken.php` (core/Sharing) | `ResolveCollaborationCall.php` → docdrive |
| 9 | `Http/Requests/` (плоский, корень) | `VaulterFormRequest.php` (core, база) | `StoreNodeCommentRequest.php` → docdrive |
| 10 | `Console/` (плоский) | `{MakePanelCommand,PanelMigrationsCommand}.php` (core/Panels), `{PruneWebhooksCommand,RelayOutboxCommand,RetryWebhooksCommand}.php` (core/Webhooks) | `PurgeTrashCommand.php` → drive; `SweepUploadsCommand.php` → drive (Uploads) |

*`Data\CollaborationSession.php` — плоский файл без суффикса `Data` (в отличие от соседей); в
Decision Table плана (Аллокация, строка docdrive) числится в списке docdrive-Data-классов вместе с
`CreateDocumentNodeData` и др. — **фактически уезжает в docdrive**, а не остаётся core-seam-ом; в
таблице выше это исправлено (сноска, не отдельная строка), чтобы не запутать будущего исполнителя:
проверить при исполнении, кто на него ссылается (`Contracts\Collaboration\NodeCollaborationDriver`
возвращает `Data\CollaborationSession` — сам контракт тоже уезжает в docdrive, см. Аллокацию
топологии, строка `Contracts\Collaboration\*` → docdrive), несостыковок с core после переноса быть
не должно.

**Вывод №1 (общий для обеих стратегий):** физическая релокация (`git mv`) этих ~10 директорий —
**обязательный шаг независимо от того, KEEP-SCATTERED или RENAME**. Ни одна из двух стратегий не
избавляет от него: PSR-4 партиционирует по каталогам, а не «по половине файлов внутри одного
каталога». Это отвечает на вопрос п.(2) брифа: разница в blast-radius между стратегиями — **не** в
переносе файлов (он одинаков), а **только** в том, переписываются ли `namespace`/`use`-строки
после переноса.

## (1) Выразимость arch-guard'а «core ⊥ пакет» по namespace-префиксу

Текущий guard для media (`packages/core/tests/Arch/ArchTest.php:303-306`):

```php
arch('core (and all Vaulter code) never depends on the extracted vaulter-media package')
    ->expect('Vaulter')
    ->not->toUse('Vaulter\Media')
    ->ignoring('Vaulter\Media');
```

Комментарий прямо над ним (`:294-302`) — не случайная деталь, а **явно оставленный командой
крючок**: «Scaffold for the upcoming Drive/DocDrive extractions (Phase D1/D2): once those packages
exist, extend this with their owned namespaces using the same `->ignoring(...)` shape.» То есть
однострочный guard на единый префикс — это уже принятая конвенция, а не гипотеза этого аддендума.

Pest `expect(...)->toUse(...)` матчит **по префиксу namespace-строки** (подтверждено: `packages/media/tests/Arch/ArchTest.php:13-18`, `expect('Vaulter\Media')->not->toUse([...])` матчит весь
поддерево `Vaulter\Media\*`). При RENAME-TO-SINGLE-PREFIX guard остаётся однострочным навсегда —
он автоматически покрывает любой новый класс, добавленный под `Vaulter\Drive\*`/`Vaulter\DocDrive\*`
в будущем, без правки теста.

При KEEP-SCATTERED пришлось бы либо:
- (а) перечислить массив из ~14 (drive) / ~19 (docdrive) scattered-префиксов в `->not->toUse([...])`
  — **хрупко**: пропущенный при будущем добавлении новый scattered-namespace **не роняет тест**
  (false negative, тихая деградация guard'а, а не явная ошибка), либо
- (б) для «настоящих» коллизий на уровне листового класса (`Vaulter\Enums\Drive\BulkAction` в одной
  папке с `NodeKind`, который остаётся) — писать exception-правило на **конкретный класс**
  (`expect(\Vaulter\Enums\Drive\BulkAction::class)`, Pest arch это умеет), что вообще не
  масштабируется и требует ручного решения при каждом будущем добавлении в такую папку.

**Вывод:** KEEP-SCATTERED не «невыразим» технически (правило (а)/(б) можно написать), но
структурно хуже — не самообновляющийся guard, риск тихой деградации. RENAME даёт guard, который
живёт без сопровождения, как и было задумано автором комментария в `ArchTest.php:294-302`.

## (2) Blast-radius / three-phase-safety: KEEP vs RENAME

Общий (одинаковый для обеих стратегий) шаг — physical `git mv` ~10 директорий из §Инвентарь плюс
все «чистые» (не коллизионные) под-namespace'ы drive/docdrive (см. §PSR-4-карты ниже) —
суммарно **~130+ файлов**, переезжающих из `packages/core/src` в `packages/drive/src` /
`packages/docdrive/src`.

Разница — **только** в том, что происходит ПОСЛЕ переноса:

| | KEEP-SCATTERED | RENAME-TO-SINGLE-PREFIX |
|---|---|---|
| `namespace X;` в перенесённых файлах | не меняется | переписывается (напр. `Vaulter\Actions\Drive` → `Vaulter\Drive\Actions`) |
| `use X\Y;` по всему репо, ссылающиеся на перенесённые классы | не меняются | переписываются (grep-scope: core src — 0 хитов по данным consumer-агента; `filament`/`media` — 0 хитов, см. §(4); тесты — переезжают вместе с классами) |
| composer.json нового пакета | либо (a) общий плоский корень `"Vaulter\\": "src/"`, разделяемый с core (Composer сливает несколько base-dir на один префикс — фактически работает, подтверждено RAG), либо (b) явный список под-префиксов (см. §PSR-4-карты «частичный» вариант) | один явный корень `"Vaulter\\Drive\\": "src/"` / `"Vaulter\\DocDrive\\": "src/"` |
| Итоговая правка | git mv + composer.json | git mv + composer.json + ~130 файлов с новой `namespace`-строкой + N `use`-строк (N мало — см. (4)) |
| Риск при исполнении | ниже разовый диф, но риск (а) тихой деградации arch-guard, (б) резолюции классов по shared flat root в dev-режиме (до `composer dump-autoload -o` порядок поиска директорий для одного префикса зависит от порядка регистрации пакетов — не строго детерминирован, известная Composer-особенность) | выше разовый диф, но диф **чисто механический** — идентичен уже проверенному three-phase паттерну media (alias→use-update→hard-rename), для которого в проекте уже есть дисциплина `php:namespace-refactor-safety` |

Ключевое наблюдение по прецеденту: у media (§Провенанс) был **идеальный кандидат** для дешёвого
`KEEP-SCATTERED`-варианта (a) — единственный чистый под-namespace `Vaulter\Cas\*` (9 файлов, ни
одной коллизии на уровне директории) можно было оставить как есть и просто физически перенести в
`packages/media/src/Cas/*` с общим плоским `"Vaulter\\": "src/"` в обоих composer.json (0 строк
namespace/use менять). Команда **этого не сделала** — выбрала полный rename на `Vaulter\Media\Cas\*`.
Это не доказательство «keep дороже» само по себе, но это **явный прецедент отказа от самого дешёвого
scattered-варианта** даже когда он был технически чист и дёшев — сильный сигнал в пользу того, что
причина не в экономии на файле-переносе, а в тех же соображениях arch-guard/идентичности пакета,
разобранных в (1) и §Обсуждение.

**Three-phase дисциплина** (`php:namespace-refactor-safety`) применяется одинаково к обеим фазам
D1/D2 независимо от выбора стратегии — просто у RENAME есть доп. средний под-шаг «переписать
namespace+use» внутри уже спланированной фазы «git mv + composer scaffold»; отдельной лишней фазы
это не добавляет.

## (3) Точные PSR-4-карты composer.json (после решения D22/D23 — RENAME)

**Инвариант, отвечающий на «как это уживается с single-prefix» из брифа:** single-prefix относится
**только к корню автозагрузки НОВОГО пакета**, а не к запрету слову «Drive»/«Documents» появляться
где-либо ещё. Ровно так уже устроено у media: `Vaulter\Cas\BlobLock` остался в core **одновременно**
с тем, что media получил свой `Vaulter\Media\Cas\*` — совпадение слова «Cas» в обоих местах не
конфликт, потому что физически это разные top-level ветки (`Vaulter\Cas\...` в
`packages/core/src/Cas/`, `Vaulter\Media\Cas\...` в `packages/media/src/Cas/`). Для drive это
означает: `Vaulter\Models\Drive\{Node,Storage,File,FileVersion,FileVersionBlob}` (T1/T3, остаётся в
core) и `Vaulter\Repositories\Drive\*` (остаётся в core) **не переименовываются** и **не
переезжают** — «Drive» в их имени описывает домен данных, а не принадлежность к пакету
`vaulter-drive`. Пакет `vaulter-drive` владеет отдельным, непересекающимся корнем `Vaulter\Drive\*`.
Коллизии по слову нет — коллизия по namespace-ПУТИ была бы, если бы drive тоже назвал что-то
`Vaulter\Models\Drive\...`, чего в карте ниже нет.

### `packages/drive/composer.json` (новый)

```json
{
    "name": "axioma-studio/vaulter-drive",
    "autoload": { "psr-4": { "Vaulter\\Drive\\": "src/" } },
    "autoload-dev": { "psr-4": { "Vaulter\\Drive\\Tests\\": "tests/" } },
    "require": {
        "axioma-studio/vaulter-core": "self.version",
        "axioma-studio/vaulter-media": "self.version"
    },
    "repositories": [
        { "type": "path", "url": "../core", "options": { "symlink": true } },
        { "type": "path", "url": "../media", "options": { "symlink": true } }
    ]
}
```
(Форма 1:1 повторяет `packages/media/composer.json` — `require` + собственный `repositories`-блок
для standalone-установки/тестирования пакета, как у media; см. §Провенанс п.1.)

Целевой namespace-layout `packages/drive/src/` (переименовано из текущих путей в
`packages/core/src/...`, см. §Инвентарь и Аллокацию топологии):

```
Vaulter\Drive\Actions\{CopyNode,CreateFolder,ForceDeleteNode,MoveNode,RenameNode,RestoreNode,TrashNode,UploadFile}Action   (было Actions\Drive\*)
Vaulter\Drive\DriveContext, Vaulter\Drive\StorageManager                                                                   (было Drive\*)
Vaulter\Drive\Events\{FileUploaded,NodeCopied,NodeCreated,NodeForceDeleted,NodeMoved,NodeRenamed,NodeRestored,NodeTrashed,NodeVersionCommitted,StorageCreated} (было Events\Drive\*)
Vaulter\Drive\Exceptions\{CrossStorageMoveException,CycleException,NodeNameConflictException,QuotaExceededException}       (было Exceptions\Drive\*)
Vaulter\Drive\Enums\BulkAction                                                                                             (было Enums\Drive\BulkAction — NodeKind остаётся Vaulter\Enums\Drive\NodeKind в core)
Vaulter\Drive\ValueObjects\WorkspaceRef                                                                                    (было ValueObjects\Drive\WorkspaceRef)
Vaulter\Drive\Data\{CreateDriveFolder,UploadFile,MoveNode,RenameNode,CopyNode}Data                                         (было плоские Data\*Data)
Vaulter\Drive\Http\Controllers\{NodeController,DownloadController,StorageController}                                      (было Http\Controllers\Drive\*, минус GrantController — см. D26)
Vaulter\Drive\Http\Requests\{CopyNodeRequest,CreateFolderRequest,DriveNameRules,MoveNodeRequest,RenameNodeRequest,UploadFileRequest} (было Http\Requests\Drive\*, минус Grant-реквесты — D26)
Vaulter\Drive\Http\Resources\{NodeResource,StorageResource,FileVersionResource}                                           (было Http\Resources\Drive\*, минус NodeGrantResource)
Vaulter\Drive\Console\{PurgeTrashCommand,SweepUploadsCommand}                                                              (было плоский Vaulter\Console\*, см. §Инвентарь #10; паттерн 1:1 как media → Vaulter\Media\Console\*)
-- под-домен Uploads --
Vaulter\Drive\Uploads\{UploadSessionManager,S3MultipartHandler,TusUploadHandler,StagedUpload}                             (было Uploads\*)
Vaulter\Drive\Uploads\Actions\CommitUploadAction                                                                          (было Actions\Uploads\CommitUploadAction)
Vaulter\Drive\Uploads\Repositories\{UploadPartStoreRepository,UploadSessionReadRepository,UploadSessionStoreRepository}   (было Repositories\Uploads\*)
Vaulter\Drive\Uploads\Enums\{UploadProtocol,UploadStatus}                                                                 (было Enums\Uploads\*)
Vaulter\Drive\Uploads\Contracts\UploadProtocolHandler                                                                     (было Contracts\Uploads\UploadProtocolHandler)
Vaulter\Drive\Uploads\Data\UploadInitData                                                                                 (было Data\Uploads\UploadInitData)
Vaulter\Drive\Uploads\Http\Controllers\{S3MultipartController,TusUploadController}                                       (было Http\Controllers\Uploads\*)
Vaulter\Drive\Uploads\Models\{UploadSession,UploadPart}                                                                   (было Models\Uploads\*)
```

**НЕ переезжает** (T1/T3/T4, остаётся в core, имя не трогается):
`Vaulter\Models\Drive\{Node,Storage,File,FileVersion,FileVersionBlob}`,
`Vaulter\Repositories\Drive\{FileStoreRepository,FileVersionBlobStoreRepository,FileVersionReadRepository,FileVersionStoreRepository,NodeReadRepository,NodeStoreRepository,StorageReadRepository,StorageStoreRepository}`,
`Vaulter\Enums\Drive\NodeKind`,
`Vaulter\Http\Controllers\Drive\GrantController` (переезжает физически в `Http\Controllers\Access\GrantController` — D26, но **без смены пакета**, остаётся core).

### `packages/docdrive/composer.json` (новый)

```json
{
    "name": "axioma-studio/vaulter-docdrive",
    "autoload": { "psr-4": { "Vaulter\\DocDrive\\": "src/" } },
    "autoload-dev": { "psr-4": { "Vaulter\\DocDrive\\Tests\\": "tests/" } },
    "require": {
        "axioma-studio/vaulter-core": "self.version"
    },
    "repositories": [
        { "type": "path", "url": "../core", "options": { "symlink": true } }
    ]
}
```
Заметьте: **нет** `require` на `vaulter-media` (T6 — docdrive требует только core; контент inline,
CAS не используется). Peer-guard в D25 закрепляет это тестом.

Целевой namespace-layout `packages/docdrive/src/`:

```
Vaulter\DocDrive\Documents\{CollaborationSessionService,DocumentFileDescriptorFactory,DocumentHandlerRegistry,NodeExportService,NodeHandlerResolver}  (было Documents\*)
Vaulter\DocDrive\Documents\Handlers\{DownloadOnlyHandler,PreviewHandler,TipTapHandler,UniverHandler}                                                   (было Documents\Handlers\*)
Vaulter\DocDrive\Actions\{AddNodeComment,CreateDocumentNode,CreateNamedVersion,DeleteNodeComment,PersistYjsSnapshot,ResolveNodeComment,RestoreDocumentVersion,UpdateDocumentContent}Action (было Actions\Documents\*)
Vaulter\DocDrive\Repositories\{NodeCommentReadRepository,NodeCommentStoreRepository,NodeDocumentVersionReadRepository,NodeDocumentVersionStoreRepository} (было Repositories\Documents\*)
Vaulter\DocDrive\Events\{DocumentContentUpdated,DocumentNodeCreated,DocumentVersionCreated,NodeCommentAdded,NodeCommentDeleted,NodeCommentResolved}       (было Events\Documents\*)
Vaulter\DocDrive\Enums\{Capability,DocumentType,ExportFormat,CollaborationDriverType}                                                                    (было Enums\Documents\* + Enums\Collaboration\CollaborationDriverType)
Vaulter\DocDrive\Exceptions\{CollaborationConfigException,InvalidTipTapContentException,NodeDocumentVersionNotFoundException,NotADocumentNodeException,UnknownDocumentTypeException,UnknownExportFormatException} (было Exceptions\Documents\*)
Vaulter\DocDrive\ValueObjects\{DocumentCapabilities,DocumentExport,FileDescriptor,HandlerDescriptor,HandlerUi,OpaqueDocumentContent,TipTapContent}       (было ValueObjects\Documents\*)
Vaulter\DocDrive\Collaboration\{CollaborationCall,CollaborationCallResolver,HocuspocusNodeCollaborationDriver,NodeCollaborationDriverManager,ReverbNodeCollaborationDriver} (было Collaboration\*)
Vaulter\DocDrive\Casts\BinaryString                                                                                                                      (было Casts\BinaryString — единственный файл в Casts\, коллизии нет)
Vaulter\DocDrive\Export\{HtmlExporter,MarkdownExporter}                                                                                                  (было Export\*)
Vaulter\DocDrive\Support\{TipTapRenderer,DocumentContentHasher}                                                                                          (было плоские Support\* — §Инвентарь #6)
Vaulter\DocDrive\Support\Concerns\HasTiptapExtensions                                                                                                    (было Support\Concerns\HasTiptapExtensions — §Инвентарь #7)
Vaulter\DocDrive\Contracts\Collaboration\{HostUserResolver,NodeCollaborationDriver}                                                                      (было Contracts\Collaboration\*)
Vaulter\DocDrive\Models\{NodeComment,NodeDocumentVersion}                                                                                                (было Models\Documents\*)
Vaulter\DocDrive\Http\Controllers\{DocumentNodeController,NodeCollaborationController,NodeCommentController}                                            (было Http\Controllers\Drive\{DocumentNode,NodeCollaboration,NodeComment}Controller — переименование папки ЧАСТЬ той же операции, что и намещей-рефактор, не отдельный шаг)
Vaulter\DocDrive\Http\Middleware\ResolveCollaborationCall                                                                                                (было плоский Http\Middleware\ResolveCollaborationCall — §Инвентарь #8)
Vaulter\DocDrive\Http\Requests\{CreateDocumentNodeRequest,CreateNamedVersionRequest,UpdateDocumentContentRequest,StoreNodeCommentRequest}                (было Http\Requests\Drive\* + плоский StoreNodeCommentRequest — §Инвентарь #3,#9)
Vaulter\DocDrive\Http\Resources\{NodeCommentResource,NodeDocumentVersionResource}                                                                        (было Http\Resources\Drive\*)
Vaulter\DocDrive\Data\{CreateDocumentNode,CreateNamedVersion,CreateNodeComment,PersistYjsSnapshot,UpdateDocumentContent,CollaborationSession}Data         (было плоские Data\* — §Инвентарь #5; CollaborationSession.php переименовать в CollaborationSessionData.php заодно, устраняя суффиксную непоследовательность — мелкая доп. правка, не блокер)
```

**НЕ переезжает**: `Vaulter\Contracts\Access\*`, `Vaulter\Access\AccessResolver` и всё остальное
kernel-Access/Sharing/Activity/Webhooks/Panels/Schema — docdrive их только потребляет (T6).

## (4) Влияние на импорты консюмеров (filament core-only; vue не трогается)

Проверено напрямую (`use Vaulter\...` grep по `packages/filament/src` и `packages/media/src`):

- **filament** импортирует из «Drive»-семейства **6 строк в 3 файлах**: `Models\Drive\Node`
  (`NodeGrantResource.php:35`, `NodeShareResource.php:37`, `NodeResource.php:31`),
  `Models\Drive\Storage` (`StorageResource.php:19`), `Repositories\Drive\NodeReadRepository`
  (`NodeResource.php:32`), `Enums\Drive\NodeKind` (`NodeResource.php:27`). **Все шесть — классы,
  которые по T1/T3 остаются в core и НЕ переименовываются** (см. §(3), «НЕ переезжает»). Ноль
  хитов на `Actions\Drive`, `Models\Uploads`, `Documents`, `Collaboration`,
  `Http\Controllers\Drive`. **Вывод: RENAME drive/docdrive не трогает filament ни одной строкой** —
  ровно то, что топология уже утверждает («filament remains core-only»), теперь проверено на
  уровне импортов, а не только архитектурно.
- **media** импортирует `Models\Drive\FileVersion` (`Cas/BlobStore.php:12`) и
  `Repositories\Drive\FileVersionBlobStoreRepository` (`Cas/BlobStore.php:14`,
  `Cas/BlobGarbageCollector.php:16`, `Cas/BlobReconciler.php:14`) — оба тоже остаются в core
  неизменными. **Media тоже не трогается.**
- Важный нюанс: `Config::*Model()`-индирекция в filament (`getModel(): string { return
  Config::nodeModel(); }` и т.п.) абстрагирует **резолюцию модели**, но НЕ избавляет от
  compile-time `use`-импортов для тайп-хинтов/конструкторов — если бы какой-то из 6 хитов выше
  указывал на переезжающий класс, `Config::*Model()` бы не спас от правки filament. Здесь просто
  повезло: все 6 — kernel-классы, а не drive/docdrive-behaviour-классы.
- `@vaulter/vue` — HTTP-клиент, PHP-неймспейсы не видит; не проверялся отдельно (не нужно, HTTP
  контракт не завязан на FQCN).

## (5) Прецедент media — что сработало / что болело

(Полная форензика — см. §Провенанс п.2; здесь — выжимка, релевантная namespace-решению.)

**Сработало и переносится 1:1 на drive/docdrive:**
- Один явный корень (`Vaulter\Media\` → `src/`), НЕ шаринг плоского `Vaulter\`-корня с core —
  несмотря на то, что для `Cas\*` (9 файлов, одна чистая директория) шаринг был бы дешевле и
  технически возможен (см. (2)). Команда всё равно выбрала явный rename.
  Command commands тоже переехали (`Vaulter\Console\{Gc,Reconcile}BlobsCommand` →
  `Vaulter\Media\Console\*`) — прямой прецедент для `PurgeTrashCommand`/`SweepUploadsCommand` →
  `Vaulter\Drive\Console\*` (§Инвентарь #10).
- Config-дефолт инверсия: core **удаляет** ключи из своего `config/vaulter.php` (не аддитивный
  merge — `array_merge` иначе даст core-значению приоритет и потеряет дефолт нового пакета), новый
  пакет делает `mergeConfigFrom` на тот же путь (`vaulter.cas.*`), core-акцессоры (`Support\Config`)
  делают **guard-throw** при отсутствии пакета вместо тихого дефолта. Тот же паттерн уже заложен в
  T9 топологии для `uploadSessionModel`/`nodeCommentModel` и т.п. — аддендум ничего нового сюда не
  добавляет, только подтверждает, что паттерн проверен на практике.
- BlobLock-исключение: единственный класс (`Vaulter\Cas\BlobLock`), который логически про CAS, но
  остался в core, потому что его увод создал бы обратный цикл (`Repositories\Cas\BlobStoreRepository`
  (core) → media). Прямая аналогия для drive/docdrive: НЕТ ни одного такого класса — карта в §(3)
  чистая, обратных циклов не создаёт (все ссылки консюмеров указывают на классы, которые и так
  остаются в core, см. §(4)).
- Инвариант «ноль потерь тестов» как верификация: медиа-план фиксировал точное число «67 замыканий
  ушли из core, 0 потерь» — та же дисциплина обязательна для D1/D2 (см. Критерии приёмки в
  топологии, п.1 «оба гейта зелёные»).
- TestCase require-dev регистрация: core регистрирует `VaulterMediaServiceProvider` в
  `packages/core/tests/TestCase.php` для своих тестов, что требует media в `require-dev`. Топология
  уже это предусмотрела для drive (Фаза D1: «core: require-dev drive + провайдер в
  core-TestCase»).

**Болело / доп. риск, который drive/docdrive должны купировать заранее:**
- Наивный аддитивный config-merge молча теряет дефолт — три места в T9 должны явно **удалить**
  старые ключи из core, а не просто добавить новые в пакет (легко забыть при копипасте паттерна).
- Документация НЕ была обновлена синхронно с рефакторингом (`docs/guide/storage-and-blobs.md` и
  др. до сих пор ссылаются на `Vaulter\Cas\Sha256ContentStore` — старый FQCN). Для drive/docdrive
  объём документных страниц больше (Drive — файл-менеджер UX, DocDrive — редактор) → выше риск того
  же дрейфа; включить обновление доков явным пунктом критерия приёмки фаз D1/D2 (топология сейчас
  этого не требует явно — рекомендуется дополнить).
- Масштаб: media — 9 файлов, один чистый namespace-сегмент, легчайший случай. Drive/docdrive — на
  порядок больше (~60 и ~70 файлов, 14 и 19 сегментов, из них 10 коллизионных директорий). Прямой
  перенос трудозатрат «как у media» недооценивает объём работы для D1/D2 — стоит закладывать это в
  оценку риска фаз (топология уже маркирует обе фазы HIGH, это согласуется).

## Обновлённый arch-guard-план (D25)

В `packages/core/tests/Arch/ArchTest.php`, сразу после существующего media-guard'а (`:303-306`),
добавить (мехонически копируя форму, что и предсказывал комментарий `:294-302`):

```php
arch('core (and all Vaulter code) never depends on the extracted vaulter-drive package')
    ->expect('Vaulter')
    ->not->toUse('Vaulter\Drive')
    ->ignoring('Vaulter\Drive');

arch('core (and all Vaulter code) never depends on the extracted vaulter-docdrive package')
    ->expect('Vaulter')
    ->not->toUse('Vaulter\DocDrive')
    ->ignoring('Vaulter\DocDrive');
```

Новые peer-guard'ы (закрепляют T11 «drive и docdrive независимы» и T6 «docdrive requires только
core» тестом, а не только текстом ADR — сейчас это нигде не проверяется автоматически):

`packages/drive/tests/Arch/ArchTest.php` (новый файл, по образцу `packages/media/tests/Arch/ArchTest.php`):
```php
arch('drive never depends on docdrive')
    ->expect('Vaulter\Drive')
    ->not->toUse('Vaulter\DocDrive');
```

`packages/docdrive/tests/Arch/ArchTest.php` (новый файл):
```php
arch('docdrive never depends on drive or media (T6 — docdrive requires only core)')
    ->expect('Vaulter\DocDrive')
    ->not->toUse(['Vaulter\Drive', 'Vaulter\Media']);
```

Порядок исполнения: guard'ы в core добавляются в Фазе K как «пока тривиально зелёные» (0
feature-пакетов ещё не существует — ровно как уже написано в топологии про существующий
dependency-direction-тест-заготовку), полноценно проверяются начиная с Фазы D1/D2, когда классы
физически появляются под `Vaulter\Drive`/`Vaulter\DocDrive`.

## Обновление §Enabling refactor топологии (п.6)

Текущий текст `p2-5-option-b-topology.md:149-150` («Namespace-стратегия: три фазы
alias→use-update→hard-rename (как в media). Ловушки-префиксы...») заменить на:

> 6. **Namespace-стратегия — РЕШЕНО (D22/D23, см. аддендум
>    `p2-5-namespace-strategy-drive-docdrive.md`): RENAME-TO-SINGLE-PREFIX для ОБОИХ пакетов**
>    (`Vaulter\Drive\*`, `Vaulter\DocDrive\*`). Пререквизит **до** git mv в D1/D2 — физическое
>    расселение 10 коллизионных директорий (аддендум §Инвентарь) внутри core, часть Фазы K.
>    Три фазы alias→use-update→hard-rename — как в media; ловушка-префикс `Node` ⊂
>    `NodeComment`/`NodeDocumentVersion`/`NodeGrant`/`NodeShare` — не задеть при массовых заменах
>    (это про **строковый** префикс класса `Node`, не про namespace-путь — не путать с
>    namespace-коллизиями из аддендума, это отдельная ловушка на уровне find/replace-паттернов).

## Обсуждение (спорное — рецензия и рекомендация)

### Вариант 1 — RENAME-TO-SINGLE-PREFIX для обоих пакетов — **РЕКОМЕНДОВАН (D22+D23)**
- **Плюсы:** (а) однострочный, самообновляющийся arch-guard — уже обещан комментарием в
  существующем core `ArchTest.php:294-302`, ничего нового изобретать не нужно; (б) не создаёт
  fragile shared-flat-root Composer-регистрации (порядок резолюции нескольких директорий под одним
  префиксом в dev-режиме — известная Composer-особенность, не строго детерминированная до
  `dump-autoload -o`); (в) согласуется с уже проверенным прецедентом media, включая то, что там
  выбрали rename даже когда shared-root был технически дешевле (§(2)) — конвенция уже установлена,
  расхождение с ней повышает когнитивную нагрузку для контрибьюторов без offsetting выгоды; (г)
  «Drive» в имени core-классов (`Models\Drive\Node`) и «Drive» в корне нового пакета
  (`Vaulter\Drive\*`) не конфликтуют — ровно тот же паттерн, что уже перенёс `Cas` в обоих местах
  для media; (д) физический перенос файлов (самая дорогая часть) одинаков для обеих стратегий —
  экономия KEEP только на переименовании `namespace`/`use`, а это механическая, low-risk, уже
  институционализированная (`php:namespace-refactor-safety`) операция.
- **Минусы:** больше строк в дифе (git mv + namespace-rewrite + use-rewrite), чем чистый KEEP
  (git mv + composer.json). Для docdrive объём переписываемых строк заметно больше (19
  namespace-сегментов vs 14 у drive) — но это одноразовая механическая правка, не текущий
  maintenance tax (в отличие от минуса KEEP — растущий список scattered-префиксов в arch-guard).

### Вариант 2 — KEEP-SCATTERED для обоих пакетов
- **Плюсы:** меньший разовый диф — не нужно трогать `namespace`/`use` в ~130 файлах; сохраняет
  историческую преемственность имён (полезно для git blame/истории коммитов на первый взгляд, хотя
  `git mv` в любом случае разрывает буквальный path).
- **Минусы:** (а) arch-guard либо превращается в перечисление 14–19 префиксов на пакет (тихая
  деградация при забытом добавлении нового scattered-namespace — риск худший, чем «диф побольше»),
  либо требует leaf-класс-level исключений для реальных внутридиректорийных коллизий
  (`Enums\Drive\BulkAction`), что не масштабируется; (б) требует либо fragile shared-flat-root
  composer-регистрации, либо столь же длинного явного списка sub-префиксов в самом composer.json —
  тот же объём ручного учёта, что и в arch-guard, просто в другом файле; (в) единственный
  прецедент в проекте (media) явно отказался от этого варианта даже когда он был дешевле;
  расхождение конвенции между пакетами (drive/docdrive scattered, media единый) фрагментирует
  ментальную модель для будущих контрибьюторов без видимой выгоды, раз физический перенос всё
  равно обязателен.

### Вариант 3 — асимметрия: RENAME для docdrive, KEEP для drive
Рассмотрен по требованию брифа «решить по каждому пакету отдельно» — гипотеза: у drive меньше
коллизионных директорий (5 из 10 приходятся на drive, остальные 5 в основном docdrive-специфичны:
`Support`/`Support\Concerns`/`Http\Middleware` плоские коллизии — все докдрайвовые), значит drive
теоретически «дешевле» оставить scattered.
- **Почему отклонён:** ключевой фактор решения — не количество коллизий (они разрешаются
  одинаково для обеих стратегий, §(2)), а **выразимость arch-guard'а**, которая одинаково весома
  для обоих пакетов независимо от их относительного размера. Асимметрия здесь создала бы два разных
  соглашения о структуре пакета в одной кодовой базе без offsetting выгоды — сама постановка
  вопроса («могут отличаться») была честно проверена (см. §Инвентарь и §(3), где карты составлены
  независимо для каждого пакета), но evidence не разошёлся.

**Рекомендация: Вариант 1 (D22+D23) для обоих пакетов.** Главный аргумент — не экономия на
файловом переносе (он идентичен), а долгосрочная стоимость сопровождения arch-guard'а и
согласованность с уже установленной (и явно закреплённой комментарием в коде) конвенцией single-prefix.

## Провенанс

1. **Composer PSR-4 longest-prefix-wins (внешняя RAG-проверка, Perplexity, 2026-07-02):**
   подтверждено, что при нескольких зарегистрированных PSR-4-префиксах Composer резолвит класс по
   **самому длинному совпадающему префиксу**; разные пакеты МОГУТ регистрировать разные
   под-префиксы одного корня (`Vaulter\Actions\Drive\` в одном пакете, `Vaulter\Models\Drive\` в
   другом, `Vaulter\` в третьем) — все три сосуществуют детерминированно. Также подтверждено, что
   несколько пакетов МОГУТ шарить один и тот же префикс (`Vaulter\` → несколько base-dir),
   Composer сливает их в список директорий для этого префикса и перебирает по порядку регистрации
   — рабочий, но менее идиоматичный механизм (см. §(2), почему media его не использовал).
2. **Media forensics (сабагент, file:line):** composer.json обоих пакетов (`packages/core/composer.json:47-57`, `packages/media/composer.json:32-41`), директория `packages/media/src/` (namespace
   `Vaulter\Media\{Cas,Console}`), arch-guard `packages/core/tests/Arch/ArchTest.php:303-306` +
   комментарий `:294-302`, config-инверсия `packages/media/src/VaulterMediaServiceProvider.php:39-45`
   + `packages/media/config/vaulter-cas.php:26-29` + guard-throw в `packages/core/src/Support/Config.php:847-897`.
3. **Namespace-инвентарь (сабагент + доп. ручная проверка `Console\`, file:line):** полное дерево
   `packages/core/src/` с подтверждёнными путями каждого drive/docdrive-класса; 10 коллизионных
   директорий (9 из сабагента + `Console\` из доп. проверки, см. §Инвентарь).
4. **Arch-тесты и консюмеры (сабагент, file:line):** механика `expect(...)->toUse(...)` как
   префиксный матчинг; 6 хитов `use Vaulter\...Drive...` в filament (`packages/filament/src/Resources/{NodeGrantResource.php:35,NodeShareResource.php:37,NodeResource.php:27,31,32,StorageResource.php:19}`)
   — все указывают на классы, остающиеся в core; 4 хита в media
   (`packages/media/src/Cas/{BlobStore.php:12,14,BlobGarbageCollector.php:16,BlobReconciler.php:14}`) — аналогично.
5. Существующий провенанс `p2-5-option-b-topology.md` (RAG про schema-owner package, 5
   code-mapper'ов) — не переповторяется здесь, актуален без изменений.
