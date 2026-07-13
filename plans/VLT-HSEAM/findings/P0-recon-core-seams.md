# P0 — Рекон швов расширяемости vaulter (core ↔ documents)

**Дата:** 2026-07-10 · **Метод:** read-only рекон субагентом по дереву монорепо · **Статус:** verified по file:line

## Вердикт

Реестр файловых обработчиков **уже существует и чист на слое диспатча**. Идея «папки-реестра
обработчиков» реализована в форме behaviour-пакетов монорепо: `packages/documents` = «расширение
tiptap» поверх core-швов. Осталось **5 резидуальных утечек TipTap** в ядро (см. ниже).

## Карта текущего механизма

- **Контракт хендлера:** `packages/core/src/Contracts/Documents/DocumentHandler.php` —
  `id(): string` (стабильный slug, 'tiptap'/'univer'/'download'), `mimeTypes(): list<string>`
  (точные и wildcard `image/*`, catch-all `*`), `extensions()`, `priority(): int` (выше — важнее;
  DownloadOnly = -1000), `capabilities(FileDescriptor): DocumentCapabilities`,
  `ui(FileDescriptor): HandlerUi` (Vue-компонент + route + props).
  Док-комментарий: «Adding a NEW format is a ONE-CLASS + register operation … NOT gated by the
  sealed NodeKind/DocumentType enum (H.5)».
- **Реестр-контракт (core, минимальный):** `core/src/Contracts/Documents/HandlerRegistry.php` —
  только `register()` + `all()`. Богатый `resolve()` — сознательно documents-internal;
  per-node шов — `Contracts/Documents/NodeHandlerResolver` (`describe(Node, ?mime): HandlerDescriptor`).
  Host без vaulter-documents: резолв контракта «fails loudly with an install hint» (задокументировано).
- **Движок:** `packages/documents/src/Documents/DocumentHandlerRegistry.php` — implements core
  `HandlerRegistry`; сливает 3 источника (container-tag `'vaulter.handlers'` + `config('vaulter.handlers')`
  FQCN-список + runtime `register()`); маршрут mime → ext → capability → priority →
  `DownloadOnlyHandler` (null-object, `'*'`, priority -1000); мемоизация по `mime|ext|capability`.
- **Регистрация (documents SP, `packageRegistered()`):** `singleton(DocumentHandlerRegistry)` +
  `bind(HandlerRegistry, DocumentHandlerRegistry)` (строки 82-83); feature-gate:
  `if (Config::featureEnabled('spreadsheets')) tag([UniverHandler], 'vaulter.handlers')` (88-90);
  `bind(NodeHandlerResolverContract, NodeHandlerResolver)` (94). Config-merge `vaulter-documents.php`
  `handlers`/`exporters`/`models` под существующие ключи `vaulter.*`.
- **Дискриминатор Node:** `kind` string(16) (`PanelTablesBuilder.php:185`), enum
  `core/src/Enums/Drive/NodeKind.php` — `Folder|File|Document|Shortcut`. НО `kind` — только грубый
  роутинг («лист с document_versions»); тонкий «тип → handler» = mime/extension через реестр
  (`NodeKind.php:47-52`: «the DocumentHandlerRegistry, not this enum, decides what a node can do»).
  `FileDescriptor::canonicalMime()` предпочитает sniffed-mime клиентскому (spoof-guard, H.5);
  sniffing — `drive/src/Uploads/MagicByteSniffer.php`.
- **Хендлеры сейчас:** `TipTapHandler` (id 'tiptap', claims `application/vnd.vaulter.document+json`
  + docx/markdown; editable+previewable+collaborative; ui 'VaulterDocEditor'), `UniverHandler`
  (xlsx, `richText: false`, gated 'spreadsheets'), `PreviewHandler`, `DownloadOnlyHandler`.
- **Composer:** tiptap/yjs/prosemirror — НЕ PHP-зависимости (JS-сторона: packages/vue,
  hocuspocus-server). documents требует только core + spatie/laravel-package-tools +
  symfony/html-sanitizer. Связь с TipTap — in-code конвенция, не dependency.
- **Managers в кодовой базе:** ни один не extends `Illuminate\Support\Manager`. Все bespoke:
  enum-match (`NodeCollaborationDriverManager`), config-map+memoise (`HostRoleDriverManager`),
  фабрики (`DriveManager`, `StorageManager`). `VaulterManager.registerHandler()/handlers()`
  делегирует в container-bound `HandlerRegistry`.

## 5 резидуальных утечек TipTap в ядро

| # | Где | Что | Стоимость пост-1.0 |
|---|---|---|---|
| L1 | `core/src/Contracts/Documents/DocumentExporter.php:27` | `export(TipTapContent $content): string` — контракт экспортёра жёстко типизирован на TipTap, не на опаковый `DocumentContent` | major bump (сигнатура публичного контракта) |
| L2 | `core/src/ValueObjects/Documents/TipTapContent.php`, `core/src/Attributes/TiptapExtensions.php`, `core/src/Exceptions/Documents/InvalidTipTapContentException.php` | TipTap-валидатор (allow-list нод/марок) живёт В ЯДРЕ | major bump (namespace move) |
| L3 | `core/src/Schema/PanelTablesBuilder.php:561` | `v_node_document_versions.format` string(16) **default 'tiptap'** — хендлер, забывший проставить format, молча помечается tiptap | миграция у каждого хоста |
| L4 | `core/src/ValueObjects/Documents/DocumentCapabilities.php` | `richText: bool = true` — «историческая форма TipTap» как дефолт core-VO; closed-set булевых флагов | поведенческий BC у клиентов |
| L5 | Нейминг | core-контракты в `Contracts/Documents/*`, интерфейс называется `DocumentHandler`, хотя описывает обработчик ЛЮБОГО файла (image/*, xlsx, cad) | major bump (rename публичного API) |

Смежное: комментарии схемы называют `content` jsonb «TipTap/ProseMirror snapshot»
(`PanelTablesBuilder.php:550-553`); `yjs_state` binary (558) — CRDT-стейт коллаборации
(Yjs = source of truth, TipTap JSON = derived projection; `core/config/vaulter.php:667-671`).

## Опаковый шов (уже есть)

`Contracts/Documents/DocumentContent` (toArray/contentHash/sizeInBytes) — shape-agnostic;
`OpaqueDocumentContent` — не-TipTap sibling (используется UniverHandler). Т.е. ядро УМЕЕТ
хранить произвольный контент; утечки — по краям (exporter, дефолты, нейминг).
