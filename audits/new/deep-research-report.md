# Техническое исследование Univer и проектирование Excel/XLSX-слоя для Tiptap + Node/TS

iturn380648image2turn380648image7turn380648image4turn380648image0

## Ключевой вывод и границы достоверности

По состоянию на 20 ноября 2026 года **Univer — это уже не просто spreadsheet-widget**, а модульный Office SDK с тремя слоями: открытый Web SDK, коммерческий Server SDK и коммерческий AI SDK. Открытый репозиторий `dream-num/univer` содержит реальные модели документов, spreadsheet-модель, формульный движок, рендерер, dependency injection, command/mutation framework, Facade API, плагины Sheets и Docs и headless-runtime. Server SDK добавляет collaboration, OT, revisions, persistence и Office conversion; AI SDK добавляет агентный runtime, inspection/execution и Worktree. Сам проект прямо декларирует эту границу: Web SDK core опубликован под Apache-2.0, а коммерческие Web-плагины, Server SDK и AI SDK распространяются отдельно. citeturn513323search2turn964726search5

Это важный ответ на главный вопрос про «реверс-инжиниринг backend». **Внутренности коммерческого backend нельзя достоверно восстановить по открытому репозиторию**, потому что код Server SDK целиком не открыт. Но публичная документация достаточно подробно раскрывает его контракты: `UniverCollabEndpoint`, `UniverCollabService`, Database Adapter, snapshots, changesets, revisions, OT, WebSocket rooms/presence/ACK, idempotency, Redis clustering, PostgreSQL/S3 adapters и Worktree. Поэтому ниже я четко разделяю: «подтверждено исходниками/API» и «рекомендуемая архитектура для вашего приложения». citeturn972990search0turn972990search1

Главная инженерная рекомендация для вашего случая: **не пытаться расширять Tiptap до Excel**. Tiptap должен остаться редактором `doc`-документов, а spreadsheet должен стать отдельным типом документа с собственным canonical state, UI и computation model. Univer идеально подходит именно как spreadsheet runtime/editor, потому что он уже решает четыре самые дорогие проблемы: Spreadsheet UI, Excel-подобную модель, вычисление формул и operation-based editing. Но для полноценного XLSX round-trip и production collaboration почти наверняка потребуется коммерческая часть Univer. citeturn964726search1turn919249search2turn513323search0

Предлагаемая модель верхнего уровня:

```text
                           ┌──────────────────────────────┐
                           │      Document Registry       │
                           │ id / type / owner / ACL      │
                           │ currentVersion / metadata    │
                           └──────────────┬───────────────┘
                                          │
                    ┌─────────────────────┴─────────────────────┐
                    │                                           │
             DOC / DOCX                                  SHEET / XLSX
                    │                                           │
           ┌────────▼────────┐                         ┌────────▼────────┐
           │   Tiptap/PM     │                         │     Univer      │
           │ canonical JSON  │                         │ IWorkbookData   │
           └────────┬────────┘                         └────────┬────────┘
                    │                                           │
          doc operations / versions                  commands / mutations
                    │                                           │
                    └─────────────────────┬─────────────────────┘
                                          │
                              Node.js / TypeScript API
                                          │
                        ┌─────────────────┼─────────────────┐
                        │                 │                 │
                   PostgreSQL          Redis               S3
                registry/versions   realtime/jobs     originals/exports
```

В вашей архитектуре **общим должен стать не JSON-формат Word и Excel, а оболочка документа**: идентификатор, тип, версия, ACL, lifecycle, blob storage, audit/event log, AI tool interface и export pipeline. Внутренние модели Tiptap и Univer должны оставаться независимыми.

## Что реально находится внутри Univer

### Runtime, Unit и Plugin model

Базовый объект — `Univer`. В исходном `packages/core/src/univer.ts` он создает Injector, instance service, command service, lifecycle service, resource manager, undo/redo, locale/theme/config services и регистрирует конструкторы моделей для Sheets и Docs. При создании Unit нужные плагины запускаются для соответствующего `UniverInstanceType`; `Workbook` используется для Sheet, `DocumentDataModel` — для Doc. Это видно непосредственно в коде. citeturn568789search0

В терминах SDK **Unit — самостоятельный редактируемый объект**: workbook, document, presentation и т. д. Workbook с двадцатью worksheets остается одним Unit; worksheets — внутренние объекты workbook. Каждому Unit соответствует `unitId`. Snapshot — сериализуемое представление Unit, но не live-state редактора. Для Sheets это `IWorkbookData`, для Docs — `IDocumentData`. citeturn964726search1turn964726search2

Это один из наиболее полезных паттернов для вашей архитектуры. Я бы фактически скопировал эту концепцию на уровень вашего backend:

```ts
type DocumentKind =
  | 'rich_text'
  | 'spreadsheet'
  | 'pdf'
  | 'presentation';

interface DocumentDescriptor {
  id: string;
  kind: DocumentKind;
  title: string;

  tenantId: string;
  ownerId: string;

  currentVersion: bigint;

  editor:
    | { engine: 'tiptap'; schemaVersion: string }
    | { engine: 'univer'; schemaVersion: string };

  originalBlobId?: string;
  createdAt: string;
  updatedAt: string;
}
```

То есть `document.id` — бизнес-ID вашего продукта, а `univerUnitId` при необходимости является **runtime/provider identifier**, но не primary key всей бизнес-модели.

### Dependency injection и lifecycle

Univer не представляет собой набор React-компонентов. В ядре используется DI-контейнер. Межплагиновые зависимости объявляются через `@DependentOn`, а зависимости внутри плагина передаются через Injector/service tokens. Основные стадии lifecycle: `onStarting`, `onReady`, `onRendered`, `onSteady`. Документация рекомендует регистрировать dependencies/commands/controllers на `onStarting`, работать с созданными models на `onReady`, а DOM/Canvas-расширения подключать не раньше `onRendered`. citeturn964726search0turn568789search5

Реальный пример `UniverSheetsFilterPlugin` подтверждает эту модель: он зависит от `UniverFormulaEnginePlugin` и `UniverSheetsPlugin`, добавляет свои services/controllers в Injector в `onStarting`, затем активирует нужные dependency objects на `onReady`. citeturn568789search5

Отсюда важный практический вывод: **ваши интеграции с Univer лучше оформлять отдельными plugin/service слоями, а не React effects, напрямую трогающими внутреннее состояние workbook**.

Например:

```ts
@DependentOn(UniverSheetsPlugin)
export class AppSpreadsheetBridgePlugin extends Plugin {
  static override pluginName = 'APP_SPREADSHEET_BRIDGE';
  static override type = UniverInstanceType.UNIVER_SHEET;

  constructor(
    _config: undefined,
    @Inject(Injector) private readonly injector: Injector,
    @ICommandService private readonly commands: ICommandService,
  ) {
    super();
  }

  override onStarting() {
    this.disposeWithMe(
      this.commands.onCommandExecuted((command) => {
        // translate relevant application-level signals
        // DO NOT persist on every raw internal event.
      }),
    );
  }
}
```

### Commands, mutations и undo/redo

Это, вероятно, **самая важная архитектурная идея Univer**.

Любое содержательное изменение проходит через command system; snapshot напрямую изменять нельзя. Facade API в конечном итоге вызывает команды. Commands описывают пользовательское действие, Mutations меняют модель, Operations обслуживают interaction state. Такая трасса изменений дает undo/redo, interception, collaboration и event observation. citeturn964726search1turn964726search0

Реальный `SetRangeValuesCommand` показывает цепочку:

```text
Facade
  ↓
SetRangeValuesCommand
  ↓
SetRangeValuesMutation
  ↓
SheetInterceptorService
  ↓
dependent mutations
  ↓
UndoRedoService.pushUndoRedo(...)
```

В исходнике команда вызывает `SetRangeValuesMutation`, получает дополнительные undo/redo mutations от interceptors и сохраняет сформированный набор в `IUndoRedoService`. citeturn568789search3turn228733search1

Именно поэтому **сохранение XLSX через React state типа `onChange => JSON.stringify(workbook)` было бы архитектурно неверным**. Источник операций должен оставаться Univer; ваш persistence layer должен либо периодически получать snapshot, либо подключаться к operation/collaboration layer.

### Facade API

Facade — рекомендованный application-facing слой. `FWorkbook`, `FWorksheet`, `FRange`, `FDocument` и т. п. скрывают значительную часть внутренней plugin/command архитектуры. `FWorkbook.save()` через ResourceLoader формирует актуальный `IWorkbookData`; документация прямо рекомендует snapshot получать через Facade, а не читать initial object. citeturn568789search1turn568789search8

Для coding-agent это значит:

**использовать Facade API для 90% application code; внутренние services/commands — только там, где Facade не дает нужной возможности**.

### Rendering

Univer использует собственный Canvas/render engine, а не DOM-table. UI, модели и rendering разделены. Для Docs архитектура похожа на MVVM: DocumentDataModel → DocumentViewModel → layout/skeleton → Canvas; пользовательский input идет обратно через controller → command → mutation. citeturn228733search9

Это еще одна причина не пытаться встроить spreadsheet в ProseMirror node как тысячи DOM-ячееек. Более надежный вариант — самостоятельный Univer viewport рядом с Tiptap editor shell.

## Spreadsheet-модель, формулы и XLSX fidelity

### IWorkbookData

Публичный quick-start collaboration содержит минимальный валидный `IWorkbookData`:

```ts
const unitData: IWorkbookData = {
  id: UNIT_ID,
  rev: 1,
  name: 'Persistent Sheet',
  appVersion: '',
  locale: LocaleType.EN_US,

  sheetOrder: ['sheet-1'],

  sheets: {
    'sheet-1': {
      id: 'sheet-1',
      name: 'Sheet 1',
      rowCount: 100,
      columnCount: 26,
      cellData: {},
    },
  },

  styles: {},
  resources: [],
};
```

Эта структура используется непосредственно официальным примером Collaboration SDK. citeturn894249search3turn894249search6

Концептуально:

```text
IWorkbookData
├─ id
├─ rev
├─ name
├─ locale
├─ styles
├─ sheetOrder[]
├─ sheets{}
│  └─ IWorksheetData
│     ├─ id
│     ├─ name
│     ├─ rowCount
│     ├─ columnCount
│     └─ cellData
└─ resources[]
```

Cells — не плоская огромная `[][]`; на практике API использует sparse object/matrix structures. `ICellData` содержит value/formula/style-related data. Например, официальная customization документация демонстрирует:

```ts
[
  { v: 'East', t: 1 },
  { v: 128000, t: 2 },
  { v: 142000, t: 2 },
  { f: '=SUM(B2:C2)' },
]
```

То есть literal хранится через `v`, type через `t`, формула через `f`, стиль через `s`. citeturn228733search13turn228733search6

Вам **не следует проектировать свою собственную промежуточную Excel JSON-модель**, если Univer становится spreadsheet engine. Храните его canonical snapshot как opaque/versioned JSONB или в collaboration storage, а свои бизнес-метаданные держите отдельно.

### IDocumentData и отличие от Tiptap

Univer Docs хранит `IDocumentData`, где body содержит `dataStream`, text runs, paragraphs, sections, custom blocks, tables, custom ranges и т. д. Историческое архитектурное описание показывает именно эту stream-based модель. citeturn228733search5

Это **не ProseMirror JSON**.

Поэтому я не рекомендую переводить существующие Word/Tiptap документы в Univer Docs только ради унификации. Вы получите новую задачу fidelity-conversion между:

```text
DOCX
 ↕
Univer IDocumentData
 ↕
ProseMirror/Tiptap JSON
```

Вместо существующего:

```text
DOCX
 ↕
ваш текущий converter
 ↕
Tiptap JSON
```

Если Tiptap уже качественно решает ваш Word use case, сохраните его.

### Formula engine

Формульный движок Univer является отдельным пакетом `@univerjs/engine-formula`; документация описывает parsing, function registration, dependency management и calculation. Он умеет строить dependency tree и через Facade отдавать dependents/dependency information. citeturn919249search0turn919249search1

Архитектура формульного движка включает dependency analysis/dirty marking. После изменения ячейки зависимые формулы маркируются dirty и вычисляются в dependency order. Для reference-функций наподобие `INDIRECT`/`OFFSET` делается дополнительный parser/lexer analysis. Calculation service также поддерживает circular execution, progress и interruption. citeturn919249search4

На больших workbook вычисления можно перенести в Web Worker. Основной runtime тогда содержит UI/rendering, а formula plugins запускаются с `notExecuteFormula: true`; worker регистрирует formula engine и sheet formula worker plugins. Между потоками используется `@univerjs/rpc`. citeturn919249search2

Для вашего frontend это должно выглядеть примерно так:

```text
React / App Shell
       │
       ▼
Univer UI / Canvas
       │
       ├──── Commands / Mutations ──────┐
       │                                │
       ▼                                ▼
Workbook model                    RPC main plugin
                                        │
                                   Web Worker
                                        │
                              formula dependency graph
                                        │
                              calculation / results
```

Если workbook потенциально содержит десятки тысяч формул, **worker mode лучше заложить сразу**, даже если в MVP сначала оставить вычисления на main thread.

### Headless runtime

Univer content runtime работает и в Node.js без editor UI. Официальная документация показывает headless composition из `@univerjs/core`, product plugins, formula engine и Facade. UI packages в Node runtime не нужны. citeturn919249search3turn919249search5

Это очень ценно для AI и backend jobs:

```text
REST/API request
   ↓
load snapshot
   ↓
Headless Univer
   ↓
apply Facade operations
   ↓
calculate formulas
   ↓
save snapshot
```

То есть вам не нужен браузер/Puppeteer для каждого «AI, поменяй B7».

### Import/export XLSX

Это место, где проходит граница open-source/commercial.

Univer browser **сам не открывает XLSX напрямую**. Официальная архитектура требует server-side conversion: XLS/XLSX → Univer document data, а при экспорте наоборот. За это отвечает `@univerjs-pro/exchange-node`. citeturn972990search6turn727394search2

Для Sheets официально поддерживаются browser API import `.xls`, `.xlsx`, `.csv`, `.tsv`, export `.xlsx`, `.csv`, `.tsv`; server-side API также документирует импорт `.xlsm`. citeturn964726search4turn727394search6

Два основных потока:

```text
без collaboration

XLSX bytes
   ↓
exchange-node
   ↓
IWorkbookData
   ↓
browser Univer
   ↓
FWorkbook.save()
   ↓
IWorkbookData
   ↓
exchange-node
   ↓
XLSX bytes
```

и:

```text
с collaboration

XLSX
 ↓
Exchange
 ↓
create collaborative Unit
 ↓
unitId
 ↓
browser loadSheetAsync(unitId)
 ↓
OT / realtime edits
 ↓
latest confirmed Unit
 ↓
Exchange
 ↓
XLSX
```

Именно такую схему описывает Univer. citeturn972990search6turn894249search4

Для DOCX параллельный API существует: `.doc/.docx` import, `.docx` export; snapshot API возвращает `IDocumentData`. citeturn727394search0

### Fidelity

Важно понимать: «поддержка XLSX» и «100% Excel fidelity» — разные вещи. Официальный feature matrix Univer прямо перечисляет неполные/неподдерживаемые функции: например, нет dedicated chart sheet, нет Goal Seek и Scenario Manager, некоторые chart capabilities частичны; VBA также не импортируется/экспортируется как работающий automation project. citeturn513323search3

Поэтому я рекомендую **хранить оригинальный XLSX binary отдельно от canonical JSON**.

```text
original.xlsx  ───────► S3 immutable
      │
      ▼
Exchange
      │
      ▼
IWorkbookData ────────► active editable representation
      │
      ▼
edited export.xlsx ───► S3 derived artifact
```

Это дает возможность forensic comparison и future re-import.

Для regression fidelity тестирования нужны «golden workbooks» с:

- merged cells;
- formulas;
- dates + 1900/1904 date system;
- number formats;
- named ranges;
- hidden rows/columns/sheets;
- filters;
- validations;
- conditional formatting;
- hyperlinks;
- comments;
- images;
- tables;
- pivots;
- charts;
- protected sheets;
- external references;
- unsupported VBA/XLSM cases.

XLSX conversion должно тестироваться **семантически**, а не binary-equality: XML package будет реорганизован converter'ом.

## Collaboration, backend и реальное хранение

### Это OT, а не CRDT

Univer официально утверждает, что Collaboration SDK использует **Operational Transformation**. Client сначала применяет edit локально; server устанавливает authoritative order, трансформирует concurrent operations, подтверждает revision и рассылает изменения другим clients. Это не last-write-wins по snapshot и не CRDT. citeturn972990search0turn972990search4

Транспорт разделен:

```text
HTTP:
  load snapshot
  load missing changesets
  request session ticket
  submit changesets

WebSocket:
  Session
  Room membership
  ACK
  remote changes
  Presence
  cursor/selection
```

Такое разделение прямо описано в collaboration docs. citeturn972990search4

### Revision и changeset

Концептуально:

```text
Snapshot revision N
       │
       ├─ Changeset N+1
       ├─ Changeset N+2
       ├─ Changeset N+3
       ▼
Current head revision N+3
```

Snapshot не пишется после каждого keystroke. Confirmed changesets формируют непрерывную revision history, а snapshots периодически материализуют состояние для ускорения загрузки. citeturn972990search4turn894249search1

Это правильная архитектура и для вашего продукта. Даже если не покупать Collaboration SDK, имеет смысл различать:

```text
document_version     = материализованная версия
document_event       = операция / command batch
document_export      = производный файл
```

### Server modules

Подтвержденная server composition выглядит так:

```text
Browser
   │
   ▼
Collaboration Client
   │
HTTP + WS
   │
   ▼
Node Transport
   │
   ▼
UniverCollabEndpoint
   │
   ▼
UniverCollabService
   │
   ▼
Database Adapter
```

Endpoint отвечает за client protocol, Sessions, Rooms, Presence, ACK и broadcasts; Service — за Unit lifecycle, OT и revisions; Database Adapter — за atomic persistence. citeturn972990search0

Официальные adapters:

- in-memory;
- SQLite;
- PostgreSQL;
- PostgreSQL + S3 для Sheet blocks;
- Redis adapter для multi-node pub/sub и session-ticket store. citeturn972990search1

То есть предложенные вами **Node + PostgreSQL + Redis + S3 очень близки к архитектуре, которую сам Univer предлагает для production**. citeturn972990search1turn972990search2

### Что Database Adapter реально хранит

Документация прямо перечисляет:

```text
Unit record + current head revision
revisioned snapshot
confirmed changesets
Sheet blocks referenced by snapshot
submission idempotency records
soft-delete / recovery / permanent-delete state
```

При этом users, roles, ACL, folders и product metadata в core collaboration adapter не входят. citeturn894249search5turn894249search1

Именно поэтому Univer **не заменяет вашу application DB**.

Database Adapter должен обеспечивать не просто CRUD, а transactional contracts:

- atomic Unit creation;
- compare-and-swap по expected revision;
- submission idempotency;
- continuous revision sequence. citeturn894249search5

### Не надо угадывать закрытую SQL-схему Univer

Публичная документация не публикует полную internal production DDL коммерческого PostgreSQL adapter. Поэтому любые конкретные названия его таблиц были бы выдумкой.

Но ваша **application-side SQL model** может и должна быть явной:

```sql
create type document_kind as enum (
  'rich_text',
  'spreadsheet'
);

create table documents (
  id uuid primary key,
  tenant_id uuid not null,
  owner_id uuid not null,

  kind document_kind not null,
  title text not null,

  engine text not null,
  engine_unit_id text,

  current_version bigint not null default 0,

  original_blob_id uuid,
  metadata jsonb not null default '{}',

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz
);

create index documents_tenant_kind_idx
  on documents (tenant_id, kind);
```

Снапшоты:

```sql
create table document_versions (
  document_id uuid not null references documents(id),
  version bigint not null,

  schema_version text not null,
  content_hash text not null,

  snapshot jsonb,
  snapshot_blob_id uuid,

  created_by uuid,
  created_at timestamptz not null default now(),

  primary key (document_id, version)
);
```

Оригиналы/экспорты:

```sql
create table blobs (
  id uuid primary key,
  tenant_id uuid not null,

  storage_key text not null unique,
  content_type text not null,
  size_bytes bigint not null,
  sha256 text not null,

  created_at timestamptz not null default now()
);

create table document_exports (
  id uuid primary key,
  document_id uuid not null references documents(id),
  version bigint not null,

  format text not null,
  blob_id uuid not null references blobs(id),

  converter text not null,
  converter_version text not null,

  created_at timestamptz not null default now()
);
```

Permissions:

```sql
create table document_acl (
  document_id uuid not null references documents(id),
  subject_type text not null,
  subject_id uuid not null,
  role text not null,

  primary key(document_id, subject_type, subject_id)
);
```

AI jobs:

```sql
create table agent_runs (
  id uuid primary key,
  tenant_id uuid not null,
  document_id uuid,

  agent_name text not null,
  status text not null,

  base_version bigint,
  result_version bigint,

  tool_trace jsonb,
  error jsonb,

  created_at timestamptz not null default now(),
  finished_at timestamptz
);
```

### PostgreSQL vs S3

Я бы не складывал огромный `IWorkbookData` JSONB в `documents`.

Правило:

```text
< ~2–5 MB compressed snapshot:
    Postgres JSONB допустим

large workbook:
    JSON snapshot → gzip/zstd → S3
    Postgres → pointer + hash + schemaVersion
```

Это соответствует и направлению официального adapter `collaboration-database-postgres-s3`, который переносит Sheet blocks в S3, уменьшая размер PostgreSQL и backup footprint. citeturn972990search1

### Redis

Redis нужен **не как source of truth**.

Использовать:

```text
presence
pub/sub
session tickets
distributed locks where unavoidable
rate limits
conversion job coordination
short-lived caching
```

В multi-node Univer Redis применяется именно для cross-node pub/sub и shared Session Ticket store; persistence остается в DB adapter. citeturn972990search2

### Authentication и authorization

В Collaboration SDK WebSocket не должен доверять `userID`, присланному клиентом. Authentication выполняется на HTTP transport, сервер выдает opaque one-time Session Ticket; после его потребления создается WebSocket Session. citeturn972990search3

Кроме `joinUnit`, нужны независимые policy checks на:

```text
readUnitData
submitChangeset
createUnit
deleteUnits
recoverUnits
```

Официальная документация отдельно подчеркивает, что запрет JOIN недостаточен: snapshot/changeset можно читать HTTP-маршрутом. citeturn972990search3

Это стоит буквально перенести в ваше security design.

### Scaling

Официальная схема multi-node:

```text
                Load balancer
                 /         \
                /           \
       Collab Node A       Collab Node B
            │                   │
            └──────┬────────────┘
                   │
               PostgreSQL
                   │
                  S3

Node A <──── Redis Pub/Sub ────> Node B
             +
       Session Tickets
```

Для тяжелого XLSX conversion Univer советует выделять отдельные conversion nodes, потому что Office conversion CPU-intensive и может ухудшать collaboration latency. citeturn894249search2turn972990search4

Значит production decomposition:

```text
api-service
collaboration-service
conversion-worker
agent-worker
```

а не один monolithic Node process.

## AI, агенты и «слияние агентов»

### Что подтверждено

Univer AI SDK **не является LLM orchestrator'ом**. Документация прямо говорит, что приложение выбирает model, agent workflow и tool protocol. SDK предоставляет Office capabilities: inspection, API lookup, content execution, screenshots/layout verification и Collaboration Runtime. citeturn972990search7turn972990search8

То есть концептуально:

```text
Claude / GPT / custom Agent
          │
          ▼
your tool layer
          │
          ├─ inspectWorkbook
          ├─ readRange
          ├─ runFacadeCode
          ├─ calculate
          ├─ screenshot
          └─ commit
          │
          ▼
Univer Headless / Collaboration Runtime
```

Сам Univer не требует, чтобы LLM напрямую понимала `IWorkbookData`.

### Worktree

Именно Worktree, скорее всего, создает впечатление «слияния агентов».

Worktree — isolated draft поверх Unit:

```text
trunk revision
      │
      └── Worktree
             │
             ├─ pull
             ├─ edits
             ├─ calculations
             ├─ screenshots/tests
             ├─ mark Ready
             │
             └─ human review
                    │
                    ├─ reopen
                    └─ merge
```

Draft имеет независимые snapshots, changesets, revisions, presence и rooms. После Ready он замораживается для review, а merge идет обратно через Core Collaboration Service и его authorization boundary. citeturn972990search9turn894249search4

Это **не CRDT merge двух агентов**, не Git merge файлов и не magic semantic agent merge.

Более того, документация прямо говорит: multi-Unit merge **не атомарен**; Units merge'ятся по одному. citeturn894249search4

### Как реализовать аналог у вас

Я рекомендую ввести:

```ts
interface AgentWorkspace {
  id: string;
  documentId: string;
  baseVersion: bigint;

  state: 'draft' | 'ready' | 'merged' | 'rejected';

  createdByAgent: string;
  createdAt: string;
}
```

Agent работает не над production snapshot, а над branch/worktree.

Tool schema:

```ts
type AgentTool =
  | {
      name: 'sheet.inspect';
      input: {
        documentId: string;
        sheet?: string;
        range?: string;
      };
    }
  | {
      name: 'sheet.set_values';
      input: {
        workspaceId: string;
        sheet: string;
        range: string;
        values: unknown[][];
      };
    }
  | {
      name: 'sheet.set_formula';
      input: {
        workspaceId: string;
        sheet: string;
        cell: string;
        formula: string;
      };
    }
  | {
      name: 'sheet.calculate';
      input: {
        workspaceId: string;
      };
    }
  | {
      name: 'sheet.export_preview';
      input: {
        workspaceId: string;
      };
    }
  | {
      name: 'workspace.mark_ready';
      input: {
        workspaceId: string;
      };
    };
```

**Не разрешать модели** выполнять произвольный SQL или редактировать raw snapshot.

### Execution

AI SDK предлагает именно content-execution: Unit binding + Facade code. Runtime умеет `pull()`, execution и `commit()`. Документация предупреждает, что несколько Unit runtimes не образуют общую cross-document transaction. citeturn972990search7

Если вы будете строить свой tool calling, я рекомендую два режима:

```text
safe declarative tools
    ↓
setValue / setFormula / formatting / insertRows

expert sandbox
    ↓
restricted TypeScript execution
    ↓
Univer Facade
```

Второй режим должен выполняться в sandbox/worker с:

- timeout;
- memory limit;
- network disabled;
- no filesystem;
- allowlisted Facade surface;
- max mutations;
- audit log.

## Сравнение Univer с альтернативами

### Univer

Сильная сторона Univer состоит в том, что это **полная spreadsheet architecture**, а не только XLSX parser или grid: editor, data model, formula engine, commands/mutations, rendering, collaboration, headless mode, Office conversion и AI-oriented runtime находятся в одной экосистеме. Open-source core — Apache-2.0; advanced Web features, Server SDK, conversion, collaboration и AI SDK входят в commercial offering. citeturn513323search0turn513323search2

Текущие npm core packages включают, среди прочих, `@univerjs/core`, `@univerjs/sheets`, `@univerjs/docs`, `@univerjs/engine-formula`, `@univerjs/engine-render`, `@univerjs/rpc`, `@univerjs/sheets-formula`, `@univerjs/sheets-ui`; npm listing на момент исследования показывает cohort 1.5.2. citeturn964726search7

Очень важно **держать все Univer packages на одной версии**; документация прямо предупреждает об этом. citeturn964726search1

### SheetJS

SheetJS Community Edition — Apache-2.0 и очень сильный parser/writer. Он преобразует spreadsheet files в Common Spreadsheet Format и обратно. Это прежде всего **file/data processing library**, не полноценный Excel UI/runtime. citeturn978059search0turn409225search0

SheetJS читает и пишет формулы, но его CE не следует рассматривать как полноценный Excel calculation engine: документация отдельно показывает, что formulas могут быть прочитаны без вычисленного JS result. citeturn409225search2

Плюсы:

```text
+ XLS/XLSX parsing
+ broad format support
+ server + browser
+ permissive license
+ удобно для ETL/export
```

Минусы для вашей задачи:

```text
- нет полноценного spreadsheet editor
- нет готового command model
- нет collaboration
- нет полноценного formula runtime
- UI придется делать отдельно
```

**Использовать SheetJS вместо Univer имеет смысл только если Excel UI очень простой.**

### ExcelJS

ExcelJS — MIT, ориентирован на programmatic XLSX/CSV manipulation, styling, rows/cells, images и streaming writer/reader. citeturn978059search3turn409225search5

Ключевое ограничение: ExcelJS не вычисляет формулы; при записи formula result нужно предоставить отдельно. citeturn409225search7

Отличный backend export library; слабая основа для интерактивного Excel editor.

### Luckysheet

Luckysheet — MIT, но проект больше не поддерживается; репозиторий прямо рекомендует перейти на Univer и был архивирован 30 октября 2025 года. citeturn978059search6turn978059search7

Для нового продукта **не использовать**.

### FortuneSheet

FortuneSheet — TypeScript/React fork-наследник Luckysheet, MIT, имеет formulas, Excel import/export plugin и collaboration-oriented operations. Документация говорит, что `onOp` эмитит операции, пригодные для backend syncing. citeturn978059search8turn978059search2

Однако сам проект предупреждает, что до stable 1.0 data structure/API могут меняться. citeturn978059search8

Для open-source-only прототипа вариант достойный, но по архитектурной зрелости, conversion fidelity и backend ecosystem Univer выглядит значительно сильнее.

### Handsontable + HyperFormula

Handsontable — коммерческий DataGrid; permissive MIT закончился на старой 6.2.2, production commercial use требует соответствующей лицензии. citeturn978059search4

Формулы обеспечиваются отдельным HyperFormula. HyperFormula — headless spreadsheet calculation engine с dependency recalculation; лицензируется GPLv3 либо коммерчески. citeturn602021search0turn602021search1

У HyperFormula нет встроенного XLSX importer: официальная документация предлагает использовать ExcelJS или SheetJS. citeturn409225search8

Стек получается:

```text
Handsontable
+
HyperFormula
+
SheetJS/ExcelJS
+
your collaboration
+
your persistence
```

Это гибко, но вам придется самостоятельно связывать четыре модели.

### AG Grid

AG Grid Community — MIT, Enterprise лицензируется отдельно. citeturn978059search5

AG Grid умеет Enterprise Excel export, но Excel import официально демонстрируется через сторонний `xlsx` parser. Его модель — data grid, не workbook editor. citeturn409225search11turn409225search12

То есть для CRM table — прекрасно. Для «Excel inside product» — не то.

### Матрица

| Решение | Excel-like UI | XLSX IO | Formula compute | Realtime collab | Headless | Общая оценка |
|---|---:|---:|---:|---:|---:|---|
| Univer | Да | Да, Server SDK | Да | Да, commercial | Да | **лучший fit** |
| SheetJS | Нет | Очень хорошо | Нет полноценного engine | Нет | Да | converter |
| ExcelJS | Нет | Хорошо | Нет | Нет | Да | backend generation |
| FortuneSheet | Да | Плагин | Да | базовая архитектура | ограниченно | OSS fallback |
| Handsontable + HF | Grid | внешняя | Да | самостоятельно | Да | modular but costly |
| AG Grid | Grid | export; import external | business expressions, не Excel engine | самостоятельно | server-side data | не workbook editor |

С практической точки зрения **Univer имеет смысл, если spreadsheet становится продуктовой функцией**, а не просто таблицей данных.

## Целевая архитектура Tiptap + Univer

### Frontend

Рекомендованная структура:

```text
App
├─ DocumentShell
│  ├─ Toolbar
│  ├─ Presence
│  ├─ Version status
│  └─ Agent actions
│
├─ RichTextDocumentEditor
│  └─ Tiptap
│
└─ SpreadsheetDocumentEditor
   └─ Univer
```

React routing:

```tsx
export function DocumentEditor({ document }: Props) {
  switch (document.kind) {
    case 'rich_text':
      return <TiptapDocument documentId={document.id} />;

    case 'spreadsheet':
      return <UniverSpreadsheet documentId={document.id} />;
  }
}
```

Минимальная Univer интеграция:

```tsx
function UniverSpreadsheet({ documentId }: { documentId: string }) {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!containerRef.current) return;

    const { univer, univerAPI } = createSpreadsheetRuntime({
      container: containerRef.current,
    });

    let disposed = false;

    void (async () => {
      const dto = await api.documents.getSpreadsheet(documentId);

      if (disposed) return;

      univerAPI.createWorkbook(dto.snapshot);
    })();

    return () => {
      disposed = true;
      univer.dispose();
    };
  }, [documentId]);

  return <div ref={containerRef} className="h-full w-full" />;
}
```

Для production следует создавать runtime через отдельную factory, чтобы package registration не размазывался по React tree.

```ts
export function createSpreadsheetRuntime(
  opts: { container: HTMLElement }
) {
  // exact preset/plugin set pinned in one module
}
```

### Canonical state

Для rich text:

```text
canonical = ProseMirror JSON
```

Для spreadsheet:

```text
canonical = Univer IWorkbookData
```

Для обоих:

```text
document registry
version
ACL
original blob
derived exports
audit
agent workspaces
```

Не пытаться сделать:

```json
{
  "type": "document",
  "content": [
    {
      "type": "spreadsheet",
      "cells": [...]
    }
  ]
}
```

как главный storage.

Можно сделать **embedded spreadsheet reference** внутри Tiptap:

```json
{
  "type": "spreadsheetEmbed",
  "attrs": {
    "documentId": "018f...",
    "sheetId": "sheet-123",
    "range": "A1:H20"
  }
}
```

Но spreadsheet должен жить как самостоятельный Unit.

### Backend API

Минимальный REST API:

```text
POST   /documents
GET    /documents/:id
PATCH  /documents/:id

POST   /documents/:id/import
POST   /documents/:id/export

GET    /documents/:id/snapshot
PUT    /documents/:id/snapshot

GET    /documents/:id/versions
POST   /documents/:id/versions/:version/restore

POST   /documents/:id/agent-workspaces
POST   /agent-workspaces/:id/ready
POST   /agent-workspaces/:id/merge
```

DTO:

```ts
interface SpreadsheetSnapshotDTO {
  documentId: string;
  version: string;
  schemaVersion: string;
  snapshot: IWorkbookData;
  etag: string;
}
```

Save:

```http
PUT /documents/:id/snapshot
If-Match: "version-31"
```

```json
{
  "baseVersion": "31",
  "snapshot": { "...": "IWorkbookData" }
}
```

Conflict:

```http
409 Conflict
```

Это нужно даже без collaboration, чтобы два tabs не перетирали друг друга.

### MVP persistence

Если realtime пока не нужен:

```text
User edit
   ↓
Univer local commands
   ↓
debounce 1–3 sec
   ↓
FWorkbook.save()
   ↓
PUT snapshot
   ↓
Postgres/S3
```

Но autosave нельзя делать на каждую mutation.

Рекомендация:

```text
dirty event
 ↓
debounce 2 sec
 ↓
snapshot
 ↓
hash(snapshot)
 ↓
if changed:
    save
```

Плюс forced save:

```text
before export
before route change
before agent operation
on explicit Save
```

### Production collaboration

Если нужна настоящая совместная редактура, лучше **не строить собственный OT**, а подключить Univer Collaboration SDK.

Поток:

```text
React Univer
   │
Collaboration Client
   │
HTTP / WebSocket
   ▼
Node Transport
   ▼
Endpoint
   ▼
Collab Service
   ▼
Postgres/S3
```

Официальный quick-start использует именно `UniverCollaborationClientPlugin`, snapshot URL, changeset URL, WebSocket URL и session-ticket URL. citeturn972990search5

Если коммерческая лицензия исключена, тогда проще выбрать Yjs/CRDT **для Tiptap**, но для spreadsheet придется создавать собственный mapping spreadsheet commands → CRDT, что является отдельным крупным проектом.

Я бы не смешивал:

```text
Tiptap Yjs CRDT
+
Univer OT
```

на уровне общего operation engine. Пусть оба используют собственную collaboration семантику, а общими остаются identity, ACL, registry и audit.

### Import pipeline

```text
POST /documents/import
        │
        ▼
multipart upload
        │
        ├──► antivirus / MIME / size validation
        │
        ├──► S3 original blob
        │
        ▼
detect kind
        │
        ├─ DOCX → existing doc converter → Tiptap JSON
        │
        └─ XLSX → Univer Exchange → IWorkbookData
                                   │
                                   ▼
                           create document
```

Важно: не доверять extension.

Проверять:

```text
magic bytes / container
MIME
compressed size
uncompressed size
zip entry count
external links
OLE attachments
macros
resource count
image sizes
formula count
sheet dimensions
```

Univer Exchange документация отдельно отмечает OLE attachments и то, что native Office applications/macros сервером не запускаются. citeturn972990search6

### Export pipeline

```text
POST /documents/:id/export?format=xlsx
           │
           ▼
check ACL
           │
           ▼
flush collaboration pending edits
           │
           ▼
load confirmed latest snapshot
           │
           ▼
Exchange export
           │
           ▼
write S3 blob
           │
           ▼
signed download URL
```

Для collaborative documents перед export нужно дождаться synchronization pending edits, иначе export может не содержать последние изменения; документация Univer это прямо предупреждает. citeturn972990search6

### Versioning

Разделить:

```text
revision       = collaboration internal order
version        = user-visible checkpoint
export         = generated artifact
```

Не показывать пользователю 17 423 OT revisions как 17 423 версии.

User-visible version создавать:

```text
manual save
import
agent merge
restore
periodic checkpoint
milestone
```

### Security

Обязательные меры:

```text
tenant_id on every DB row
RLS or explicit tenant filters
server-side ACL for every read/write
session tickets for WS
signed S3 URLs
malware scanning
Office zip-bomb limits
conversion worker isolation
timeout
memory limit
audit log
rate limiting
agent tool scopes
```

Никогда не использовать UI state вроде `readOnly=true` как security boundary. Univer также прямо говорит, что UI read-only не заменяет server-side `submitChangeset` authorization. citeturn972990search3

### Лицензирование

Open-source Univer core можно использовать коммерчески под Apache-2.0. Commercial edition включает Server SDK, Office conversion, real-time collaboration и AI SDK. Официальная pricing page в текущей версии указывает Business от $99/month, но реальные ограничения должны проверяться перед покупкой, так как licensing является изменяемой коммерческой информацией. citeturn513323search0

Особенно важно: Business лицензия ограничивает server-side deployment topology; multi-instance scaling/load balancing/failover требует Enterprise согласно текущему соглашению. citeturn513323search1

Это может существенно повлиять на архитектуру SaaS.

## Техническое ТЗ для coding-агента

### Фаза исследования существующего проекта

Coding-agent прежде всего должен найти:

```text
1. Где хранится Tiptap JSON.
2. Что является document primary key.
3. Как устроены ACL.
4. Как DOCX импортируется сейчас.
5. Как DOCX экспортируется сейчас.
6. Где лежат binary originals.
7. Есть ли version column / optimistic locking.
8. Есть ли Redis.
9. Есть ли S3-compatible storage.
10. Есть ли WebSocket infrastructure.
11. Какая React/router архитектура.
12. Есть ли background job queue.
```

До изменений создать ADR:

```text
ADR-001 Document Registry
ADR-002 Spreadsheet Engine = Univer
ADR-003 XLSX Conversion Strategy
ADR-004 Collaboration Strategy
ADR-005 Binary Storage
ADR-006 Agent Workspace
```

### Фаза Document Registry

Ввести единый `documents`.

Acceptance criteria:

```text
[ ] existing Word docs migrated without loss
[ ] URL does not depend on editor engine
[ ] document.kind determines editor
[ ] document ACL works identically for Word/XLSX
[ ] soft delete supported
[ ] original binary optional
```

### Фаза Univer frontend MVP

Установить **один pinned package cohort**. Не использовать range версий вроде `^1.5.2`, потому что Univer требует aligned package versions. citeturn964726search1

Пример принципа:

```json
{
  "dependencies": {
    "@univerjs/core": "1.5.2",
    "@univerjs/sheets": "1.5.2",
    "@univerjs/sheets-ui": "1.5.2",
    "@univerjs/engine-formula": "1.5.2",
    "@univerjs/engine-render": "1.5.2",
    "@univerjs/sheets-formula": "1.5.2"
  }
}
```

Конкретный набор packages coding-agent должен сверить с выбранным preset/plugin mode текущей версии.

Acceptance:

```text
[ ] create empty workbook
[ ] multiple sheets
[ ] edit values
[ ] edit styles
[ ] formulas calculate
[ ] undo / redo
[ ] copy paste
[ ] save IWorkbookData
[ ] reload snapshot 1:1
```

### Фаза local persistence

Implement:

```ts
interface SpreadsheetRepository {
  load(documentId: string): Promise<{
    version: bigint;
    snapshot: IWorkbookData;
  }>;

  save(args: {
    documentId: string;
    expectedVersion: bigint;
    snapshot: IWorkbookData;
    actorId: string;
  }): Promise<{
    version: bigint;
  }>;
}
```

DB transaction:

```sql
begin;

select current_version
from documents
where id = $1
for update;

-- compare expectedVersion

insert into document_versions (...);

update documents
set current_version = current_version + 1
where id = $1;

commit;
```

Acceptance:

```text
[ ] concurrent stale save → 409
[ ] crash does not create half-version
[ ] same snapshot hash not duplicated
[ ] save is idempotent
```

### Фаза XLSX

Если используете Univer Exchange:

```text
upload
→ save original
→ Exchange import
→ validate IWorkbookData
→ create spreadsheet document
```

Acceptance test corpus минимум 30 XLSX.

Проверять:

```text
cell values
formula strings
formula results
sheet count
sheet names
merged cells
formats
hidden state
filter
validation
conditional formatting
comments
images
named ranges
table refs
```

После:

```text
XLSX
→ import
→ snapshot
→ export
→ reopen in Excel
```

И автоматический comparison через SheetJS либо OpenXML parser.

### Фаза worker calculation

Когда formula benchmark превышает UX budget, включить Web Worker. Univer официально поддерживает worker formula execution через `@univerjs/rpc`. citeturn919249search2

Performance acceptance:

```text
100k cells without formulas:
  initial interaction < agreed budget

10k formulas:
  UI remains responsive

paste 10k cells:
  no >500ms long task on main thread
```

Фактические thresholds зафиксировать после baseline benchmark на целевых устройствах.

### Фаза AI tools

Начать не с «agent executes JS», а с declarative tools:

```ts
getWorkbookInfo()
listSheets()
getRange()
setRangeValues()
setCellFormula()
insertRows()
deleteRows()
setNumberFormat()
setStyle()
findText()
recalculate()
validateWorkbook()
```

Каждый write tool:

```text
authorize
→ load workspace
→ validate arguments
→ execute Facade command
→ calculate
→ save
→ return diff
```

Response агенту:

```json
{
  "ok": true,
  "changedRanges": ["Sales!B2:E12"],
  "newVersion": "44",
  "warnings": []
}
```

Не возвращать весь workbook в LLM context.

### Фаза agent branch

Добавить workspace:

```text
production doc v41
      │
      └── agent workspace base=v41
             │
             ├─ edits
             ├─ validation
             └─ proposed v42
```

При merge:

```text
if production == base:
    fast-forward

else:
    require conflict resolution / Worktree OT
```

Если покупаете Univer Worktree, использовать его вместо собственного branching implementation. Worktree именно для такого human-review flow и предназначен. citeturn972990search9

### Фаза collaboration

Не писать собственный realtime spreadsheet protocol первым этапом.

Порядок:

```text
MVP
snapshot autosave

Production v1
optimistic locking

Production v2
Univer Collaboration SDK

Production v3
multi-node + Redis
```

Acceptance collaboration:

```text
[ ] two users editing same cell
[ ] two users insert/delete rows concurrently
[ ] formulas remain valid
[ ] reconnect after 30 s offline
[ ] duplicated submission not applied twice
[ ] cursor/presence
[ ] read-only user cannot submit changes
[ ] export waits for confirmed edits
```

Эти сценарии соответствуют OT/retry/idempotency behavior, который документирует Collaboration SDK. citeturn972990search4turn894249search5

### Фаза production observability

Метрики:

```text
document_load_ms
snapshot_save_ms
snapshot_size_bytes
changeset_commit_ms
active_ws_sessions
pending_changesets
formula_calc_ms
formula_count
xlsx_import_ms
xlsx_export_ms
xlsx_failure_rate
agent_run_ms
agent_tool_calls
agent_merge_conflict_rate
```

Tracing:

```text
requestId
tenantId
userId
documentId
unitId
revision
agentRunId
```

Не логировать содержимое workbook по умолчанию.

### Критические риски

Самый высокий технический риск — **не UI, а XLSX fidelity**. Spreadsheet с простыми values/styles легко сделать на десятках библиотек; Excel-compatible round-trip для сложных production файлов значительно тяжелее. Univer решает большую часть этой проблемы commercial Exchange, но его реальные limits нужно проверять на собственном corpus. Официальный feature matrix подтверждает, что полного паритета с Excel нет. citeturn513323search3

Второй риск — licensing lock-in. Open-source editor/formula core доступен свободно, но collaboration и first-party Office conversion относятся к коммерческой части. citeturn513323search0turn513323search2

Третий риск — coupling к внутренним API. Поэтому следует максимально держаться Facade и documented plugins; прямые imports из внутренних `src/*` нельзя использовать в application code. Facade специально существует как application-facing слой. citeturn964726search1turn568789search1

Четвертый риск — попытка сделать один JSON для Word и Excel. Их модели принципиально различны. Унифицировать надо document lifecycle и infrastructure, а не внутреннее содержимое.

### Итоговая рекомендуемая конфигурация

Для вашего проекта я бы выбрал следующий стек:

```text
Frontend
────────────────────────────────────────
React
Tiptap                    → Word/rich text
Univer Sheets             → Excel/spreadsheets
Univer Formula Worker     → calculations

Application API
────────────────────────────────────────
Node.js
TypeScript
Fastify / Nest / Express
Document Registry
ACL
Version API
Agent Tool API

Persistence
────────────────────────────────────────
PostgreSQL
  documents
  document_versions
  ACL
  exports
  agent_runs

S3
  original DOCX/XLSX
  large snapshots
  exports
  previews

Redis
  pub/sub
  job coordination
  presence/session tickets
  cache

Spreadsheet services
────────────────────────────────────────
Univer Web SDK
Univer Exchange Server SDK   ← если нужен XLSX fidelity
Univer Collaboration SDK     ← когда нужен realtime
Univer Worktree              ← agent review/merge

AI
────────────────────────────────────────
ваш LLM orchestrator
       ↓
strict tool schemas
       ↓
Headless Univer / Collaboration Runtime
```

На уровне domain architecture центральный принцип должен быть таким:

```text
Document ≠ editor JSON

Document
  ├─ identity
  ├─ type
  ├─ ACL
  ├─ version lifecycle
  ├─ blobs
  ├─ history
  ├─ agent workspace
  └─ engine-specific state
        ├─ Tiptap JSON
        └─ Univer IWorkbookData
```

Это даст вам возможность позже добавить PPTX, PDF или другой редактор без очередной переделки всей backend-модели.

И последнее важное решение: **для первой реализации не подключать одновременно collaboration, Worktree, AI SDK и Excel conversion**. Архитектуру сразу сделать совместимой с ними, но реализацию идти вертикальными slices:

```text
Document Registry
      ↓
Univer local spreadsheet
      ↓
Snapshot persistence
      ↓
XLSX import/export
      ↓
Formula worker
      ↓
Agent tools
      ↓
Collaboration
      ↓
Worktree / review
      ↓
Multi-node scaling
```

Так вы получите работающий Excel editor рано, не создавая временную архитектуру, которую потом придется выбрасывать. Архитектурно это направление максимально совпадает с тем, как сам Univer разделяет Web SDK, Exchange, Collaboration и AI SDK. citeturn727394search3turn972990search8