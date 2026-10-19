# Vaulter 1.0 — расширенный архитектурный, security и release-аудит

**Репозиторий:** `axiomasoft/vaulter`  
**Зафиксированная ревизия:** `0be50fd325d7cd35af9b7c227dcc73e5301b62b4` (`main`, 18 октября 2026 года)  
**Цель:** максимально компактное ядро, независимые расширения, безопасное добавление новых типов документов и редакторов, физическое отделение коммерческого collaboration-пакета, подготовка стабильной версии 1.0.

## 1. Итоговый вердикт

Vaulter уже является сильным late-beta/RC-кандидатом с необычно зрелыми для Laravel-пакета архитектурными и тестовыми механизмами. Особенно хорошо выглядят пакетные границы, document-type registry, contract tests для расширений, consumer-isolation в CI, operation/idempotency work и явная threat model. При этом текущая архитектура ещё не достигла состояния, при котором публичное ядро можно безопасно заморозить как 1.0, а collaboration — безболезненно отделить и продавать как полностью независимый коммерческий продукт.

**Текущая интегральная оценка: 7,8/10.** После выполнения блокеров P0 проект можно считать готовым к публичному RC на уровне примерно 8,8/10. После выполнения P1 — архитектурно и операционно готовым к 1.0 на уровне 9+/10.

Основная проблема не в недостатке функций. Наоборот, проект уже достаточно функционален. Основная работа перед 1.0 — окончательно зафиксировать границы, уменьшить поверхность публичного API, разорвать остаточную связанность с collaboration, проверить не только исходный monorepo, но и реально публикуемые артефакты, а также сделать security/supply-chain проверки обязательными воротами релиза.

### 1.1 Оценка по областям

| Область | Оценка | Обоснование |
|---|---:|---|
| Пакетная декомпозиция | 8/10 | Уже выделены `core`, `media`, `drive`, `documents`, `webhooks`, `collaboration`, UI/adapters; направление runtime-зависимости collaboration в основном правильное |
| Компактность ядра | 6,5/10 | Core заявляет ответственность за schema, access control, sharing и audit, содержит зависимость adjacency-list и имеет dev-связи со всеми верхними пакетами |
| Расширяемость документов | 8,5/10 | Есть `DocumentType`, codec, schema version, viewer descriptor, capabilities, upgrades, exporters и contract tests |
| Расширяемость realtime | 6/10 | Есть SPI, но контракт и терминология ориентированы на один Hocuspocus/JWT-путь, тогда как Univer collaboration потребует другого протокола |
| Физическая изоляция collaboration | 6/10 | Collaboration уже отдельный пакет, но остаётся в публичном release manifest, root dev-зависимостях, workflow и общей MIT-публикации |
| Тестовая стратегия | 8,5/10 | Laravel 12/13, SQLite/PostgreSQL/MySQL, serial races, consumer isolation, OpenAPI validation, Node-пакеты и агрегирующий CI gate |
| AppSec | 7,5/10 | Есть threat model, upload/webhook/collaboration controls и production doctor, но нужна полная негативная security matrix и более строгие defaults |
| Supply-chain security | 6/10 | Actions используют mutable tags; не подтверждены blocking SCA, SBOM, attestations и квалификация конечных архивов[^1][^2] |
| Стабильность API 1.0 | 7/10 | Есть OpenAPI и внутренние baselines, но breaking check описан как informative, а весь публичный контракт ещё не формализован[^3] |
| Документация расширений | 8,5/10 | Документированы типы документов, file handlers, viewers, exporters, profiles, permission drivers и upload inspectors |
| Операционная готовность | 7/10 | Есть doctor/hardening guidance, но multi-instance collaboration, quotas и часть scale-механизмов ещё отложены |

## 2. Методика и ограничения

Аудит выполнен как статическое исследование публичной ветки `main`: изучены manifests, release map, CI workflows, ключевые SPI, documentation of extension points, threat model, production hardening checklist, roadmap и история последних архитектурных изменений. Текущий HEAD содержит свежую серию работ по operation catalog, idempotency, optimistic concurrency, replay, scrub/heal, GC и security-матрице мутирующих маршрутов.

Аудит не является доказательством отсутствия уязвимостей. В рамках этого отчёта не выполнялись полноценные локальные тесты текущего HEAD, DAST, fuzzing, pentest, эксплуатация race conditions, malware corpus, нагрузочные испытания или проверка опубликованных Packagist/npm/GHCR артефактов. Поэтому каждое утверждение ниже относится либо к подтверждённой структуре/контракту, либо помечено как рекомендация, которую необходимо подтвердить реализацией и тестом.

## 3. Что уже сделано правильно

### 3.1 Базовое направление зависимостей

`vaulter-documents` требует `vaulter-core` и `vaulter-drive`, а `vaulter-collaboration` требует `core`, `drive` и `documents`; в проверенной production-части community-пакетов прямых импортов пространства имён `Vaulter\Collaboration` не обнаружено. Это правильная основа для commercial add-on: открытая часть владеет стабильным контрактом, закрытая часть реализует его.

`vaulter-collaboration` уже оформлен как самостоятельная Composer library со своим provider и namespace, а не как папка внутри Documents. Следовательно, физическое выделение возможно без переписывания всей системы — но release, tests, licensing и protocol abstractions ещё нужно довести.

### 3.2 Модель document type

`DocumentType` использует стабильный строковый ключ `vendor/name`; класс не сериализуется в документ. Контракт включает schema version, codec, default content, viewer и capabilities. Это важное решение: сохранённые документы не привязаны к PHP class name и переживают переименование/замену implementation.

Документация определяет freeze registry после boot, запрет дубликатов, namespacing ключей, schema upgrade, degraded read-only mode и безопасное поведение при отсутствии расширения. Удаление типа не удаляет данные: объект продолжает существовать, читается opaque/read-only и снова становится редактируемым после возврата расширения. Это один из сильнейших элементов архитектуры.

Contract test kit проверяет формат ключа, round-trip default content, deterministic hash, size limit и идемпотентность upgrade. Такой подход следует распространить на все SPI.

### 3.3 CI и test harness

Основной CI workflow не ограничивается unit-тестами. В нём присутствуют:

- PHPStan и Pint по пакетам;
- Laravel 12/13 matrix;
- SQLite fast lane;
- PostgreSQL и MySQL database lanes;
- отдельные serial tests для forked/race scenarios;
- 100% type coverage для отмеченных пакетов;
- clean consumer installation;
- Vue, Vue collaboration и hub tests;
- repository policy tests и actionlint;
- OpenAPI structure/reference validation;
- единый fail-closed `CI gate`.

Consumer isolation особенно ценен: пакет устанавливается в чистом каталоге только с объявленными siblings, поэтому скрытая зависимость на monorepo или чужие tests должна сломать build. Это правильный фундамент для проверки будущего private collaboration.

### 3.4 Security-thinking

Threat model перечисляет активы и trust boundaries для HTTP API, collaboration hub, webhook egress, upload bytes, direct URLs и tenants. В ней зафиксированы per-node authorization, tenant-scoped dedup, URL guard, quarantine/scanning, transactional events и короткий срок bearer URLs.

Production hardening checklist требует authentication middleware, production permission driver, обязательное scanning, URL guard, `wss://`, раздельные collaboration secrets, короткий token TTL, shared lock-capable cache, несинхронную очередь и production database. Наличие исполняемого `vaulter:doctor --production` — правильный шаг от документации к проверяемой политике.

## 4. Целевая архитектура

### 4.1 Рекомендуемый dependency graph

```text
axioma-studio/vaulter-core
    ├── module lifecycle / registration
    ├── tenant context and identifiers
    ├── operation pipeline / idempotency primitives
    ├── transaction / lock / clock abstractions
    ├── problem-details and event envelopes
    └── stable generic SPI

axioma-studio/vaulter-media
    └── core
        ├── CAS
        ├── blob metadata
        ├── storage drivers
        ├── integrity / scrub / GC primitives
        └── content scanning seam

axioma-studio/vaulter-drive
    └── core + media
        ├── node tree
        ├── ACL / shares
        ├── uploads / versions
        ├── trash / purge
        └── file presentation

axioma-studio/vaulter-documents
    └── core + drive
        ├── document types and codecs
        ├── revisions / comments
        ├── schema upgrades
        ├── imports / exports
        └── protocol-neutral collaboration SPI

axioma-studio/vaulter-webhooks
    └── core event/outbox contracts

axioma-studio/vaulter-filament
    └── public packages only

@vaulter/vue
    └── OpenAPI/client contract

PRIVATE: axioma-studio/vaulter-collaboration
    └── core + drive + documents
        ├── Hocuspocus/Yjs driver
        ├── collaboration hub
        ├── private Vue bindings
        ├── Redis scale-out
        └── commercial deployment/licensing
```

Ключевое инвариантное правило:

> Ни один public production package и ни один unit/contract test public core не должен требовать private collaboration package, его namespace, container image или private registry credentials.

### 4.2 Что должно остаться в core

Core должен владеть только тем, что нужно как минимум двум независимым доменным пакетам и не знает о Node, Blob, Document, TipTap, Yjs, Hocuspocus или конкретном UI.

Оставить:

- module descriptor и lifecycle;
- tenant context/resolver contracts;
- stable identifiers/value objects общего назначения;
- operation catalog, receipt/idempotency primitives;
- transaction runner abstractions;
- clock, lock и retry policy contracts;
- generic event envelope/outbox interface;
- RFC 7807-style problem representation и common error codes;
- observability hooks без vendor-specific SDK;
- SPI annotations и compatibility metadata.

Вынести из core:

- adjacency-list и node hierarchy — в Drive;
- node ACL/sharing — в Drive либо отдельный access package только при наличии второго реального consumer;
- document/viewer-specific DTO — в Documents;
- blob/CAS implementation — в Media;
- webhook delivery implementation — в Webhooks;
- интеграционные tests верхних пакетов — в repository compatibility suite;
- любые Hocuspocus/JWT/collaboration implementation details — в private add-on.

### 4.3 Не создавать абстракции без второго consumer

«Максимально гибко» не означает «интерфейс для каждого класса». Иначе compact core превратится в платформу абстракций, которую невозможно эволюционировать. Новый SPI добавляется только если выполнено хотя бы одно условие:

1. Есть две реально разные реализации.
2. Это доверительная граница: storage, auth, scanner, network transport.
3. Это сознательно публичная extension point с contract test kit.
4. Это необходимо для отделения private add-on.

Для каждого SPI нужно фиксировать: owner package, lifecycle, error semantics, concurrency model, serialization rules, compatibility promise и contract tests.

## 5. Finding A — collaboration ещё не отделён как коммерческий продукт

**Приоритет:** P0 / release blocker  
**Риск:** коммерческий, лицензионный, архитектурный, supply-chain  
**Подтверждение:** collaboration, Vue collaboration и hub image перечислены в общем release manifest; PHP package имеет MIT license и публичный source URL. Root monorepo также включает collaboration в `require-dev`.

### 5.1 Почему это проблема

Физическое расположение в `packages/collaboration` недостаточно. Пока пакет:

- находится в публичной Git history;
- опубликован под MIT;
- участвует в public release map;
- проверяется из public root workspace;
- использует общий version cadence через `self.version`;
- имеет public Vue package и public hub image;

он остаётся частью открытого продукта на уровне исходников и release process.

Уже опубликованный под MIT код нельзя сделать «неполученным»: пользователи сохраняют ранее выданные права на опубликованные версии. Можно закрыть только будущие версии/новый код. Это не юридическое заключение; перед коммерческим запуском лицензионную схему должен проверить юрист.

### 5.2 Конкретное решение

#### Этап A0 — немедленная заморозка

- Не добавлять новые commercial features в public `packages/collaboration`, `packages/vue-collaboration` и hub.
- Зафиксировать последний публичный commit/tag и список файлов, опубликованных под MIT.
- Решить продуктовую модель: proprietary package, source-available commercial license либо hosted service.
- Отдельно определить, какие protocol contracts остаются public и какие implementations становятся private.

#### Этап A1 — очистить public contracts

В `vaulter-documents` оставить только protocol-neutral типы:

```php
interface CollaborationProtocolDriver
{
    public function protocol(): string;

    public function supports(DocumentTypeId $type): bool;

    public function issueSession(
        DocumentReference $document,
        CollaborationGrant $grant,
        CollaborationContext $context,
    ): CollaborationSession;
}
```

`CollaborationSession` не должен требовать Hocuspocus-specific полей. Рекомендуемая форма:

```php
final readonly class CollaborationSession
{
    public function __construct(
        public string $protocol,
        public string $endpoint,
        public string $accessToken,
        public DateTimeImmutable $expiresAt,
        public CollaborationMode $mode,
        public array $clientOptions = [],
    ) {}
}
```

`clientOptions` должен проходить schema validation и иметь protocol-specific namespace; туда нельзя помещать secrets, server internals или произвольные PHP objects.

#### Этап A2 — создать private repository

Перенести туда:

- Hocuspocus driver;
- JWT minting implementation;
- management callbacks;
- Node hub;
- Redis extension;
- private Vue collaboration package;
- Dockerfile и image workflow;
- Helm/Kubernetes examples;
- commercial telemetry/entitlements, если они нужны;
- collaboration-specific load/security tests.

Не переносить туда public protocol contracts и общие DTO: иначе public Documents не сможет компилироваться и документировать extension point без private dependency.

#### Этап A3 — очистить public monorepo

Изменить:

- `release/packages.json`: удалить PHP collaboration, Vue collaboration и hub image;
- root `composer.json`: убрать collaboration из `require-dev`;
- `packages/core/composer.json`: убрать collaboration из `require-dev`, repositories и suggest descriptions, где смешаны Documents/Hocuspocus;
- npm workspaces: убрать private workspace;
- CI: убрать private lanes и private checkout credentials;
- docs: оставить protocol-level integration guide, а commercial docs перенести в private/customer portal;
- metapackage: collaboration только как optional commercial recommendation, не как dependency.

#### Этап A4 — independent versioning

Private package не должен использовать `self.version` для public packages. Рекомендуемый диапазон после стабилизации:

```json
{
  "require": {
    "axioma-studio/vaulter-core": "^1.0",
    "axioma-studio/vaulter-drive": "^1.0",
    "axioma-studio/vaulter-documents": "^1.0"
  }
}
```

Private CI должен проверять минимум:

- lowest supported public `1.x`;
- latest stable public `1.x`;
- следующий public RC;
- PostgreSQL/MySQL;
- single-node и Redis multi-node modes;
- rolling upgrade `N-1 → N`.

#### Этап A5 — private distribution

- Composer: Private Packagist/Satis/GitHub Packages либо собственный Composer repository. Composer repository является источником package metadata и версий.[^4]
- npm: private scope и OIDC trusted publishing, чтобы отказаться от long-lived tokens; npm trusted publishing использует OIDC и автоматически создаёт provenance для поддерживаемых публичных сценариев. Для private repo provenance limitations нужно проверить отдельно.[^5]
- Containers: private GHCR, immutable digest, signed/attested image.
- Entitlement: лицензия не должна быть частью core request path. При недоступности licensing service уже активная локальная установка не должна повреждать документы.

### 5.3 Definition of Done

- Public repository собирается, тестируется и релизится без private credentials.
- `composer install axioma-studio/vaulter-*:^1.0` не разрешает private package.
- Private package устанавливается в clean Laravel app только через private registries.
- Public package tests не импортируют `Vaulter\Collaboration\*`.
- Compatibility matrix private/public выполняется отдельно.
- Документ без private collaboration остаётся полностью доступным в single-user/read-only collaboration-neutral режиме.

## 6. Finding B — core недостаточно компактный

**Приоритет:** P0  
**Риск:** размывание границ, рост BC surface, сложность тестирования и отделения пакетов.

`vaulter-core` описан как kernel of schema, access control, sharing, audit events and extension contracts и требует `staudenmeir/laravel-adjacency-list`. Иерархическое дерево — свойство Drive, а не generic kernel. Core также имеет dev-зависимости от Documents, Drive, Media, Webhooks и Collaboration.

### 6.1 Целевой kernel budget

Ввести машинно проверяемый budget:

| Метрика | Правило |
|---|---|
| Runtime dependencies | Новая dependency только через ADR и architecture-owner approval |
| Domain models | В core запрещены `Node`, `Document`, `Blob`, `Share`, `Revision` |
| Верхние imports | Запрещены namespaces Media/Drive/Documents/Webhooks/Collaboration |
| Database tables | Только действительно cross-cutting tables; domain tables принадлежат модулям |
| Public classes | Рост baseline требует changeset и классификацию BC |
| Production LOC | Измерять trend; рост >10% за minor требует объяснения |
| Tests | Core unit/contract tests работают без верхних packages |
| Optional features | Никакого activation через случайный `class_exists`; только явная config/tag registration |

`class_exists` сейчас встречается в основном для config validation или optional integration detection. Это не обязательно дефект, но каждое использование следует классифицировать: validation допустима, скрытая активация функции — нет.

### 6.2 Пошаговый рефакторинг

1. Создать `docs/architecture/dependency-rules.md` с допустимым graph.
2. Добавить architecture test, который парсит imports/Composer requires.
3. Перенести adjacency-list dependency и node hierarchy в Drive.
4. Перенести node sharing/ACL, если они находятся в core, в Drive.
5. Разделить repository-level integration tests и core package tests.
6. Удалить верхние packages из `packages/core/require-dev`; общий root может собирать интеграцию отдельно.
7. Создать public API allowlist: только `Contracts`, approved value objects, facades и module entry points.
8. Всё остальное пометить `@internal`/Internal attribute и исключить из BC promise.

### 6.3 Нужен ли отдельный `vaulter-contracts`

Не создавать его автоматически. Дополнительный package оправдан только если private collaboration или сторонний plugin должен зависеть от contracts без Laravel runtime/core implementation. До появления такого требования contracts лучше оставить в компактном core, иначе package count и version matrix вырастут без выгоды.

### 6.4 Definition of Done

- Core production dependency graph не содержит Drive-specific hierarchy library.
- Core tests проходят после физического удаления sibling packages из checkout.
- Architecture test запрещает обратные зависимости.
- Public API core перечислен явно и сравнивается в CI.
- Размер/зависимости kernel зафиксированы baseline-файлом.

## 7. Finding C — DocumentType связан с Drive/UI

**Приоритет:** P0 перед фиксацией API 1.0.

`DocumentType` возвращает `ContentCapabilities` и `ViewerDescriptor` из namespace Drive. В результате content model структурированного документа знает о presentation abstractions другого домена. Это усложнит React/headless/mobile clients и разные редакторы одного формата.

### 7.1 Разделить четыре ответственности

```php
interface DocumentType
{
    public function id(): DocumentTypeId;
    public function schema(): DocumentSchema;
    public function codec(): DocumentCodec;
    public function defaultContent(): DocumentContent;
}

interface DocumentCapabilityProvider
{
    public function capabilities(DocumentContext $context): DocumentCapabilities;
}

interface DocumentEditorProvider
{
    public function editors(DocumentTypeId $type): iterable;
}

interface DocumentPresentationProvider
{
    public function present(DocumentSnapshot $snapshot): DocumentPresentation;
}
```

Преимущества:

- backend-only document type не обязан объявлять UI;
- один тип может иметь Vue, React, Filament и read-only viewers;
- capability может зависеть от actor, profile, tenant plan и installed services;
- private collaboration может добавить collaborative editor, не заменяя сам type;
- Documents больше не импортирует Drive presentation DTO.

### 7.2 Совместимая миграция до 1.0

Так как проект ещё до 1.0, лучше исправить контракт сейчас, а не нести legacy десятилетие. Но migration должна быть управляемой:

1. Добавить новые contracts и adapters для текущих built-in types.
2. Перевести внутренний resolver на новые contracts.
3. Оставить старые methods на один RC с deprecation warning.
4. Обновить generator и contract test kit.
5. Обновить OpenAPI presentation schema.
6. Удалить старые methods до final 1.0, а не после него.

### 7.3 Contract tests

`DocumentTypeContractTests` расширить проверками:

- deterministic `id` и schema descriptor;
- decode/encode property-based round-trip;
- rejection of oversized/deep content;
- forward-version read-only behavior;
- upgrade chain `v1 → current`, а не только один step;
- no mutation of input arrays;
- stable canonical hash across key ordering;
- editor absence does not make content unreadable;
- collaboration absence does not make local editing unavailable.

## 8. Finding D — collaboration SPI слишком Hocuspocus-centric

**Приоритет:** P0.

Текущий `CollaborationDriver` прямо описывает external Node hub, room JWT, epoch, schema version и writable flag. Это приемлемая реализация Yjs/Hocuspocus, но не нейтральный платформенный контракт. Roadmap уже отмечает, что Univer использует собственный OT engine и требует отдельного realtime transport.

### 8.1 Registry вместо singleton

Вместо одного configured driver нужен immutable registry:

```php
interface CollaborationProtocolRegistry
{
    public function register(CollaborationProtocolDriver $driver): void;
    public function require(string $protocol): CollaborationProtocolDriver;
    public function has(string $protocol): bool;
}
```

Document type/capability должен объявлять protocol requirement:

```php
final readonly class CollaborationCapability
{
    public function __construct(
        public string $protocol,      // yjs, univer-ot, wopi
        public bool $supported,
        public array $features = [],  // awareness, comments, presence
    ) {}
}
```

### 8.2 Не включать authorization в transport

Текущий контракт правильно говорит, что access decision выполняется до driver. Это сохранить и усилить типами:

```php
final readonly class CollaborationGrant
{
    public function __construct(
        public ActorId $actor,
        public TenantId $tenant,
        public DocumentId $document,
        public CollaborationMode $mode,
        public int $accessVersion,
        public DateTimeImmutable $expiresAt,
    ) {}
}
```

Driver кодирует уже принятое решение, но не запрашивает модели/permission driver сам. Это исключает расхождение authorization logic между REST и realtime.

### 8.3 Protocol compatibility

Каждый protocol должен иметь:

- version (`yjs/1`, `univer-ot/1`);
- seed/checkpoint schema;
- maximum update/frame/document size;
- epoch semantics;
- reconnect/resume semantics;
- read-only enforcement;
- revocation bound;
- error code catalog;
- client/server compatibility matrix.

## 9. Finding E — не хватает полного extension lifecycle

Текущий document type отлично решает structured content, но uploaded `.xlsx` остаётся file; import `file → document` не реализован. Roadmap предусматривает Univer single-user snapshot, WOPI handlers и дополнительные exporters. Для «любых типов документов» нужны отдельные extension points, а не рост `DocumentType`.

### 9.1 Рекомендуемые SPI

#### `DocumentImporter`

```php
interface DocumentImporter
{
    public function key(): string;
    public function supports(ImportProbe $probe): bool;
    public function limits(): ImportLimits;
    public function import(ImportSource $source, ImportContext $context): ImportResult;
}
```

Обязанности: MIME/signature probe, target document type, warnings/loss report, async execution, limits. Importer не должен напрямую менять Drive; он возвращает staged result, который commit-операция сохраняет транзакционно.

#### `DocumentExporter`

Уже существующий seam сохранить, но добавить capabilities и execution mode: sync только для дешёвого pure transform; PDF/DOCX/large XLSX — async worker. Экспорт должен возвращать content type, extension, checksum, length и sanitised filename.

#### `DocumentTranscoder`

Преобразует один structured type в другой и возвращает loss report. Не объединять с importer: transcoder работает с доверенной canonical document model, importer — с недоверенным внешним файлом.

#### `DocumentIndexer`

Извлекает search text/metadata из canonical snapshot. Индексация должна быть derived read model, а не частью write transaction.

#### `DocumentThumbnailer`

Генерирует preview из immutable revision. Выполнение изолировано, deduplicated по revision hash.

#### `SchemaMigrationPlan`

Read-time upgrade полезен, но для больших breaking schema migrations нужен observable bulk plan: dry-run, count, checkpoint, resume, rollback strategy и per-document error report.

### 9.2 Унифицированный extension descriptor

Каждое расширение публикует descriptor:

```php
final readonly class ExtensionDescriptor
{
    public function __construct(
        public string $key,
        public string $version,
        public array $capabilities,
        public array $requiredServices,
        public array $limits,
    ) {}
}
```

Descriptor нужен для admin UI, diagnostics, compatibility reports и client capability negotiation. Он не должен превращаться в произвольный service locator.

### 9.3 Contract test kits

Предоставить public reusable suites:

- `DocumentTypeContractTests`;
- `DocumentImporterContractTests`;
- `DocumentExporterContractTests`;
- `CollaborationProtocolContractTests`;
- `StorageDriverContractTests`;
- `PermissionDriverContractTests`;
- `WebhookTransportContractTests`.

Каждый kit проверяет happy path, invalid input, limits, deterministic output, retry/idempotency, error taxonomy и concurrency assumptions.

## 10. Finding F — безопасные defaults должны быть жёстче

**Приоритет:** P1, часть пунктов P0.

Сейчас production hardening частично возложен на host и `vaulter:doctor`. Doctor полезен, но безопасная архитектура не должна позволять случайно запустить insecure production и лишь вывести warning. CISA рекомендует рассматривать безопасность клиентов как core business requirement продукта.[^6]

### 10.1 Политика severity

Каждая doctor finding получает один из режимов:

- `fatal`: приложение не boot-ится в production;
- `error`: `vaulter:doctor --production` non-zero; deploy gate блокируется;
- `warning`: допустимый осознанный риск;
- `info`: состояние.

Fatal candidates:

- отсутствует auth middleware на публичных routes;
- permission driver `null`/allow-all;
- одинаковые JWT и webhook secrets;
- collaboration management port публичен;
- webhook URL guard выключен;
- strict tenancy включена без resolver;
- debug/test endpoint включён;
- production encryption/signing key пустой или default.

### 10.2 Рекомендуемые defaults

- Permission: deny-all до явной настройки.
- Upload: quarantine до scan verdict; scanner bypass только с явным policy flag.
- Download: strict/proxy mode по умолчанию; direct URL — opt-in.
- Webhooks: redirects off; если on — каждый hop проходит URL guard.
- Collaboration: disabled до явной конфигурации; `wss://`; management bind private-only.
- Queue: production boot error при `sync` для операций, которым нужна durability.
- Logs: secrets/tokens/content никогда не логируются; IDs и fingerprints — да.
- Limits: conservative global defaults, tenant overrides только в сторону ужесточения без enterprise policy.

## 11. Finding G — нужна полная authorization matrix

**Приоритет:** P1 / security release gate.

Последние коммиты добавляют security-матрицу мутирующих маршрутов. Этого недостаточно: утечки чаще возникают в reads/list/search/export/direct URL. OWASP относит broken object-level authorization к ключевым API-рискам и требует object-level check для функций, принимающих object identifiers.[^7][^8]

### 11.1 Матрица субъектов

Для каждой операции тестировать:

- unauthenticated;
- authenticated without tenant;
- actor другого tenant;
- actor того же tenant без grant;
- direct grant read;
- inherited grant read/write;
- read-only share;
- revoked grant;
- deleted actor;
- service/system actor;
- admin с ограниченным scope;
- stale access version.

### 11.2 Матрица объектов

- drive root;
- folder;
- file/current revision;
- old revision;
- document/current snapshot;
- comment;
- trash item;
- upload session;
- direct URL;
- export job;
- collaboration session;
- webhook endpoint/receipt;
- object другого tenant с совпадающим numeric ID.

### 11.3 Матрица операций

- get/list/search;
- create/update/delete/restore/purge;
- move/copy;
- upload begin/patch/commit;
- download/direct URL;
- revision list/read/restore;
- comments;
- export;
- collaboration session/reconnect/append;
- webhook create/update/redeliver;
- admin/doctor endpoints.

### 11.4 Anti-enumeration

Тестировать одинаковый status/problem shape для absent и inaccessible object, если продукт сознательно скрывает существование; отсутствие PII в problem details; отсутствие различимого timing на критичных путях; pagination totals не должны раскрывать скрытые объекты.

### 11.5 Definition of Done

Security matrix хранится как data provider, а не набор случайных tests. Добавление нового route/operation без строки в matrix ломает CI. Политика должна сравниваться с OpenAPI operation IDs и operation catalog, чтобы endpoint невозможно было забыть.

## 12. Finding H — webhook SSRF требует adversarial qualification

**Приоритет:** P1.

Threat model заявляет URL guard, allowed schemes, blocked ranges, optional allow ranges и response cap. Это правильный дизайн, но SSRF-защита определяется деталями DNS/connect/redirect/retry. OWASP рекомендует allowlist/validation и defense-in-depth для outbound destinations.[^9][^10]

### 12.1 Обязательные проверки

- IPv4 decimal/octal/hex encodings;
- IPv4-mapped IPv6;
- link-local, loopback, RFC1918, CGNAT, multicast;
- cloud metadata ranges;
- DNS name, который меняет answer между validation и connect;
- CNAME chain в private range;
- redirect public → private;
- redirect HTTPS → HTTP;
- userinfo/fragment/IDN/punycode edge cases;
- trailing dot и mixed-case host;
- proxy environment variables;
- Unix socket/custom stream wrappers;
- retry после изменения DNS;
- TLS SNI/hostname mismatch;
- oversized/chunked/decompression-bomb response.

### 12.2 Реализация

- Resolve host непосредственно перед connect.
- Проверить все resolved IP, не только первый.
- По возможности connect к проверенному IP с сохранением TLS hostname/SNI.
- Повторять guard на каждом redirect и retry.
- Ограничить redirect count, response bytes, decompressed bytes, connect/read/total timeout.
- Egress network policy должна блокировать private/metadata ranges независимо от application guard.
- Логировать policy decision без URL credentials/query secrets.

## 13. Finding I — upload и офисные форматы требуют sandbox

**Приоритет:** P1 для базовых uploads; XLSX importer — P2 после платформы.

Threat model признаёт uploaded bytes недоверенными и требует size/MIME policy и scanner obligation. OWASP рекомендует allowlisting extensions, проверку типа/signature, generated filenames, size limits, storage outside webroot, malware scanning и при необходимости content disarm/reconstruction.[^11][^12]

### 13.1 Общий upload pipeline

```text
receive chunks
  → enforce byte/quota limits
  → assemble in quarantine
  → verify declared size/checksum
  → signature/MIME inspection
  → malware scan / inspectors
  → policy verdict
  → immutable CAS commit
  → metadata transaction
  → async derived jobs
```

Ни scanner, ни importer не должен читать объект до окончания durable assembly. Ошибка чтения storage не равна «объект отсутствует»; последние scrub/heal изменения уже движутся в правильном направлении.

### 13.2 XLSX/OOXML threat model

Обязательные риски:

- ZIP bomb и nested archives;
- huge shared strings / worksheet dimensions;
- macro-enabled files;
- embedded OLE objects;
- external workbook links;
- formulas with network/file references;
- XXE и unsafe XML parser settings;
- CSV/formula injection при экспорте;
- path traversal в ZIP entries;
- symlinks, если используется external unzip;
- CPU/memory exhaustion;
- data loss при unsupported features;
- hidden sheets/cells и metadata leakage.

### 13.3 Worker isolation

Office/PDF conversion выполнять:

- в отдельном container/process;
- без network по умолчанию;
- non-root;
- read-only filesystem;
- с temp quota;
- CPU/memory/PID/file-descriptor/time limits;
- seccomp/AppArmor;
- без host socket/mount;
- с pinned image digest;
- с deletion of temp data после завершения.

OWASP рекомендует deterministic base image digest, production-only dependencies, `npm ci`, non-root/no-new-privileges, resource limits, read-only filesystem и container scanning.[^13][^14]

### 13.4 ImportResult обязан сообщать потери

Нельзя молча обещать Excel fidelity. `ImportResult` должен содержать:

- imported document;
- warnings;
- unsupported features;
- dropped external links/macros;
- original artifact reference;
- importer version;
- source checksum;
- reversible/irreversible flag.

## 14. Finding J — collaboration security и scale

**Приоритет:** P1 для коммерческого 1.0.

Hardening guide требует `wss://`, distinct secrets, TTL не более 300 секунд, shared drain cache и непубличный management port. Roadmap указывает, что Redis multi-instance и per-tenant/document concurrency caps ещё отложены. Для продаваемого realtime product это не «косметический backlog», а часть service reliability и abuse control.

### 14.1 Token contract

JWT/session token должен включать:

- `iss`, `aud`, `sub`;
- tenant/document identifiers;
- protocol/version;
- mode/rights;
- document epoch/generation;
- access version;
- `iat`, `nbf`, `exp`, `jti`;
- `kid` в header для rotation.

Требования:

- algorithm allowlist;
- reject `none` и unexpected algorithms;
- bounded clock skew;
- key rotation с overlap;
- distinct signing domains for user session and management webhook;
- no secrets in URL query, если возможно;
- token fingerprint в logs вместо token.

### 14.2 Revocation model

Чётко определить exposure bound:

- короткий token TTL ограничивает reconnect;
- epoch/access version отклоняет stale append;
- active socket должен быть отключён через control channel либо гарантированно стать read-only/invalid на следующем update;
- права перепроверяются на durable append/checkpoint;
- revoke tests выполняются между auth, append, checkpoint и reconnect.

### 14.3 Abuse controls

- connections per IP/actor/tenant/document;
- frames/sec и bytes/sec;
- maximum frame/update/document state;
- awareness/presence rate;
- max pending persistence queue;
- slow-consumer timeout;
- decompression limits;
- circuit breaker на callback/storage;
- tenant-scoped metrics without high-cardinality actor labels.

### 14.4 Multi-instance

До заявления horizontal scaling нужны tests:

- два hub instance + shared Redis;
- reconnect на другой instance;
- concurrent checkpoint;
- duplicate/reordered pub/sub messages;
- Redis temporary outage;
- rolling upgrade mixed versions;
- split-brain/fencing;
- drain with zero data loss;
- tenant quota consistency.

Если это не готово к первой продаже, product edition должна честно называться single-node и иметь documented capacity envelope.

## 15. Finding K — supply-chain security не является release gate

**Приоритет:** P0/P1.

CI использует tags вроде `actions/checkout@v4`, `actions/cache@v5`, `setup-python@v5`, `setup-node@v4`, `shivammathur/setup-php@v2`. GitHub рекомендует pinning third-party actions на full-length commit SHA; это единственный immutable reference mode для action.[^15][^1]

### 15.1 Исправления workflows

- Все `uses:` pin на полный SHA, рядом Renovate-style comment с human version.
- Service images pin по digest.
- Docker base images pin по digest.
- Минимизировать `permissions` на job level.
- Publish jobs использовать protected environments и required reviewers.
- PR from fork никогда не получает publish/private credentials.
- `persist-credentials: false` для checkout там, где push не нужен.
- Shell scripts использовать `set -euo pipefail`.
- Артефакты между jobs передавать с checksums.
- `npm install` в CI заменить на `npm ci` при наличии lockfile; OWASP отмечает deterministic behavior `npm ci`.[^13]

### 15.2 Dependency/SCA gate

Добавить:

- `composer audit --locked`;
- `npm audit`/OSV scanner по lockfiles;
- container scan после build;
- secret scanning;
- license policy scan;
- Dependabot/Renovate;
- suppression file только с owner, justification и expiry.

SCA предназначен для определения известных уязвимостей в third-party dependencies и должен анализировать фактически разрешённые версии.[^16][^17]

### 15.3 SBOM и provenance

Для каждого PHP archive, npm tarball и OCI image:

- CycloneDX/SPDX SBOM;
- SHA-256 checksum;
- signed build provenance;
- immutable tag/digest;
- verification instructions.

GitHub artifact attestations связывают artifact digest с repository, workflow, commit SHA и triggering event и могут проверяться через GitHub CLI. Для npm provenance позволяет проверить, где и как package был собран; supported trusted publishing убирает long-lived tokens.[^18][^19][^20][^5]

### 15.4 Private repository caveat

Для private collaboration проверить ограничения выбранного GitHub/npm plan: GitHub attestations для private/internal repositories могут требовать Enterprise Cloud, а npm provenance для private repositories имеет ограничения. Если native attestation недоступна, использовать Sigstore/cosign с OIDC и собственную verification policy.[^20][^5]

## 16. Finding L — тестируется monorepo, а не конечный дистрибутив

**Приоритет:** P0.

Consumer isolation уже проверяет чистую установку sibling packages, что является сильной защитой. Но roadmap прямо относит zip-archive qualification к следующей фазе. Path repository checkout и опубликованный dist archive — разные продукты: в archive могут отсутствовать migrations/config/assets либо остаться monorepo-only files.

### 16.1 Release qualification pipeline

Для каждого PHP package:

1. Export package из exact commit/tag.
2. Проверить allowlist файлов.
3. Запретить path repositories и `minimum-stability: dev` в consumer artifact.
4. `composer validate --strict`.
5. Установить package из локального archive/repository в пустой Laravel app.
6. Запустить package discovery.
7. Выполнить `config:cache`, `route:cache`, migration publish/run.
8. Запустить smoke/contract tests.
9. Повторить на lowest/highest supported Laravel/PHP/dependency set.
10. Сравнить generated archive manifest/checksum.
11. Создать SBOM и attestation.
12. Только затем publish.

Для npm:

1. `npm pack --dry-run` и allowlist contents.
2. Установка `.tgz` в clean consumer, не workspace symlink.
3. ESM/CJS/types/exports tests.
4. Build против lowest/current Node.
5. Provenance-enabled publish.

Для image:

1. Build by digest.
2. Scan OS/npm dependencies.
3. Проверить non-root user, read-only compatibility, healthcheck.
4. Integration test image by digest.
5. Generate SBOM/attestation/signature.
6. Promote tested digest без rebuild.

### 16.2 Запрет rebuild-after-test

Publish должен продвигать ровно тот artifact digest, который прошёл tests. Нельзя отдельно тестировать source tree, а потом повторно собирать release — это разрывает доказательство.

## 17. Finding M — публичный API 1.0 ещё не полностью определён

**Приоритет:** P0.

SemVer требует объявить точный публичный API; версия 1.0.0 фиксирует его, а несовместимые изменения после этого требуют major bump. В Vaulter public API шире PHP interfaces.[^3][^21]

### 17.1 Полный каталог public contract

Зафиксировать:

- PHP classes/interfaces/traits/enums, разрешённые для consumer;
- constructors и named parameters;
- service-container bindings и tags;
- config keys, types, defaults, aliases, deprecation rules;
- events и payload schemas;
- exception/problem codes;
- Artisan commands, options, output/exit codes;
- OpenAPI paths, methods, schemas, headers и pagination;
- database migration/upgrade semantics;
- document type keys и schema versions;
- operation catalog names;
- viewer/editor/protocol keys;
- npm exports, Vue props/events/composables;
- persistence formats, которые должен понимать operator/plugin;
- environment variables hub/image;
- compatibility guarantees для storage and queue jobs.

### 17.2 Internal-by-default

Namespace visibility policy:

- `Contracts`, documented DTO/value objects и entry points — public.
- `Internal`, `Support`, `Repositories`, implementation models — non-API.
- Concrete Eloquent models не обещать как stable extension point, если можно дать repositories/read DTO.
- Named arguments являются фактическим PHP API: parameter rename может сломать consumers. Либо документировать promise, либо запрещать named arguments для нестабильных constructors.

### 17.3 Blocking BC tools

- PHP BC: Roave BackwardCompatibilityCheck или эквивалент. Инструмент сравнивает revisions и завершает CI non-zero при BC break.[^22][^23]
- OpenAPI: текущий breaking comparison перевести из informative в blocking после первого RC; oasdiff находит удалённые endpoints, новые required parameters и narrowed types.[^24][^25]
- Config: JSON/schema snapshot.
- Events/problems: golden catalogs.
- Database: upgrade fixtures from previous releases.
- npm: API Extractor/exports/type snapshot.

### 17.4 Deprecation policy

Рекомендуемая политика 1.x:

- deprecated API живёт минимум один minor cycle;
- warning не спамит hot path;
- replacement и removal version указаны;
- automated upgrade note/rector rule, где возможно;
- config alias читает старое значение, но doctor сообщает migration;
- removal только в 2.0, кроме security emergency с отдельным advisory.

## 18. Finding N — manifests и metadata создают неверные ожидания

**Приоритет:** P0-low / P1.

Root workspace требует public core/media/drive/documents через `@dev`, использует path repository, `minimum-stability: dev` и отдельно добавляет webhooks/collaboration в dev requirements. Это нормально для development workspace, но не должно попадать в consumer dist.

Core description/suggest одновременно называет Documents пакетом TipTap/export и упоминает, что realtime Hocuspocus JWT minting требует JWT dependency. После коммерческого split metadata должна строго отражать границу: Documents предоставляет protocol-neutral seam, collaboration package реализует realtime.

### 18.1 Исправить описания

- Core: generic kernel без TipTap/Yjs keywords, если эти технологии не являются runtime core.
- Documents: structured document behavior, codecs, revisions, import/export contracts.
- Collaboration public docs: только protocol SPI.
- Commercial package: конкретные Yjs/Hocuspocus/Univer features.
- Metapackage: чётко указать included OSS modules и отдельно commercial add-on.

### 18.2 Version constraints

- Root monorepo может оставаться dev workspace.
- Split release manifests не должны содержать path repositories.
- Public packages могут использовать synchronized tags, если это осознанная policy.
- Private collaboration должен иметь независимый cadence и ranges `^1.x`, а не `self.version`.

## 19. Finding O — governance и security disclosure неполны

**Приоритет:** P1.

В корне есть `CONTRIBUTING.md`, CHANGELOG и UPGRADE, но в проверенном root/.github listing не видны `SECURITY.md`, CODEOWNERS и Dependabot configuration. Перед 1.0 они необходимы не как украшение, а как механизм управления изменениями.

### 19.1 Добавить `SECURITY.md`

Содержание:

- supported versions;
- private reporting channel;
- expected acknowledgement and remediation windows;
- encryption key/public key для sensitive reports;
- coordinated disclosure policy;
- CVE/GHSA process;
- scope public/private components;
- safe harbor;
- как получать patched private packages.

### 19.2 CODEOWNERS

Обязательные owners:

- core/SPI;
- migrations/schema;
- auth/ACL/tenancy;
- upload/scanner;
- webhook URL guard;
- release workflows;
- OpenAPI/problem catalogs;
- private collaboration bridge.

Branch protection требует review соответствующего owner и `CI gate`.

### 19.3 Architectural Decision Records

До 1.0 принять ADR:

- public/private product boundary;
- kernel responsibility and dependency graph;
- document/editor/presentation split;
- collaboration protocol registry;
- public API/BC policy;
- artifact qualification/provenance;
- security defaults;
- schema and data migration policy;
- supported database/PHP/Laravel lifecycle.

## 20. Finding P — repository hygiene

**Приоритет:** P2, часть — P0-low.

В root отслеживается `coverage.xml` размером около 459 KB. Это generated artifact: он быстро устаревает, раздувает Git и может вводить в заблуждение. Удалить из history текущей ветки необязательно; достаточно удалить из HEAD и добавить в `.gitignore`, если нет чувствительных данных. Полную rewrite history делать только после отдельной оценки ссылок/tags.

Также проверить, что `.agents`, `.claude`, `.grok`, `.swissknife` и подобные инструментарные каталоги действительно нужны пользователям source distribution. Если они нужны только maintainers, исключить их из package archives через export allowlist, даже если оставить в monorepo.

## 21. Performance и resilience

### 21.1 Не оптимизировать ACL без измерений

Roadmap осознанно откладывает ACL materialized read model до появления profiling evidence. Это правильно. Перед добавлением cache/closure table нужно определить SLO и собрать traces для:

- tree depth;
- grants per ancestor;
- list/search cardinality;
- permission driver latency;
- invalidation frequency.

Если cache появится, key должен включать tenant, actor/subject, node, ACL/tree/model versions и иметь unambiguous encoding. Cache miss/error не должен превращаться в allow.

### 21.2 Performance budgets

Определить измеримые budgets, значения установить после baseline, а не выдумывать:

- p50/p95/p99 list/read/write;
- upload throughput;
- collaboration append/checkpoint latency;
- maximum tested document/blob size;
- queue retry age;
- webhook delivery latency;
- scrub/GC throughput;
- DB query count per operation;
- memory peak importer/exporter.

Load workflow уже существует отдельно от PR CI. Для 1.0 release candidate результаты должны сохраняться и сравниваться с предыдущим RC; regression threshold становится release gate.

### 21.3 Fault injection

Добавить tests:

- DB commit succeeds, queue publish fails;
- blob write succeeds, metadata transaction fails;
- metadata exists, blob read errors;
- duplicate/reordered jobs;
- process killed during upload commit;
- webhook timeout after remote side accepted request;
- Redis lock expires mid-operation;
- GC races with new revision;
- collaboration checkpoint races with revoke;
- clock skew and expired receipts.

## 22. Observability и operations

### 22.1 Stable signals

Metrics:

- operation duration/failure by operation code;
- stale write/idempotency replay counts;
- upload quarantine/scanner outcomes;
- blob integrity failures/heals;
- webhook retries/dead rows;
- collaboration connections, rejected frames, checkpoint lag;
- queue lag;
- GC/scrub examined and reclaimed bytes.

Не использовать tenant/document/actor IDs как unbounded metric labels. Их можно помещать в structured logs/traces с redaction and access controls.

### 22.2 Audit semantics

Activity/audit event должен содержать:

- event ID and occurred time;
- tenant and actor reference;
- operation code;
- target type/ID;
- outcome;
- request/correlation/receipt ID;
- access version where relevant;
- redacted metadata.

Не хранить content, JWT, webhook secret или signed URL. Roadmap откладывает hash chaining/partitioning; это допустимо до подтверждённого compliance/scale requirement, но retention и tamper assumptions нужно документировать.

### 22.3 Runbooks

Обязательные runbooks:

- restore from backup;
- rotate collaboration/webhook keys;
- replay/dead-letter recovery;
- scanner outage;
- CAS integrity incident;
- tenant data export/deletion;
- rollback failed release;
- migration recovery;
- Redis/hub outage;
- compromised package/image.

## 23. Конкретный roadmap до 1.0

### Phase 0 — Freeze и решения (1–3 дня)

| ID | Работа | Результат |
|---|---|---|
| VLT-100 | Зафиксировать RC baseline SHA | Все аудиты и compatibility snapshots привязаны к одному commit |
| VLT-101 | Заморозить public collaboration | Новый commercial code не попадает в MIT history |
| VLT-102 | ADR public/private boundary | Утверждены contracts, implementations, license и registries |
| VLT-103 | ADR kernel scope | Утверждён dependency graph и kernel budget |
| VLT-104 | Объявить public API policy | Определено, что защищает SemVer |

### Phase 1 — Architecture surgery (1–2 недели)

| ID | Работа | Зависимость | Definition of Done |
|---|---|---|---|
| VLT-110 | Удалить core dev-dependency на collaboration | VLT-102 | Core clean install/test без private package |
| VLT-111 | Перенести adjacency/node concerns в Drive | VLT-103 | Core manifest не содержит hierarchy dependency |
| VLT-112 | Разделить DocumentType/content/editor/presentation | VLT-104 | Documents не импортирует Drive viewer DTO |
| VLT-113 | Добавить collaboration protocol registry | VLT-112 | Yjs и fake second protocol проходят contract suite |
| VLT-114 | Подготовить private collaboration repo | VLT-102, VLT-113 | Clean private install поверх public packages |
| VLT-115 | Очистить public release manifest/workflows | VLT-114 | Public release не содержит private artifacts |
| VLT-116 | Добавить dependency architecture tests | VLT-103 | Запрещённая import/Composer edge ломает CI |

### Phase 2 — Release hardening (1–2 недели)

| ID | Работа | Definition of Done |
|---|---|---|
| VLT-120 | Archive qualification PHP | Каждый dist ставится в clean consumer app |
| VLT-121 | npm tarball qualification | `.tgz` тестируется вне workspace |
| VLT-122 | Image-by-digest qualification | Тестируется и публикуется один digest |
| VLT-123 | Blocking PHP/OpenAPI/config BC | Несанкционированный break ломает PR |
| VLT-124 | Pin Actions/images | Все external refs immutable |
| VLT-125 | SCA/SBOM/provenance | Release содержит verifiable metadata |
| VLT-126 | SECURITY/CODEOWNERS/automation | Работает disclosure и dependency update process |

### Phase 3 — Security qualification (2–3 недели)

| ID | Работа | Definition of Done |
|---|---|---|
| VLT-130 | Полная authorization matrix | Every OpenAPI operation covered |
| VLT-131 | SSRF adversarial suite | DNS/redirect/IP bypass corpus проходит |
| VLT-132 | Upload malicious corpus | Quarantine/limits/scanner verified |
| VLT-133 | Collaboration revoke/abuse tests | TTL/epoch/disconnect/limits verified |
| VLT-134 | Secret/log redaction suite | Tokens/content отсутствуют в logs/errors |
| VLT-135 | Secure production boot policy | Fatal insecure configs блокируют deploy |

### Phase 4 — Reliability и RC (2–4 недели)

| ID | Работа | Definition of Done |
|---|---|---|
| VLT-140 | Fault-injection suite | Known partial-failure states recoverable |
| VLT-141 | Backup/restore drill | Восстановлен test tenant с checksums |
| VLT-142 | Performance baseline | SLO/budgets измерены и сохранены |
| VLT-143 | Multi-instance private collaboration | Rolling/reconnect/Redis scenarios green |
| VLT-144 | RC1 | Полный release rehearsal |
| VLT-145 | RC2 | Upgrade RC1→RC2 и consumer pilot green |
| VLT-146 | 1.0.0 | Все exit criteria выполнены |

## 24. Приоритизированный backlog

### P0 — блокирует первый RC

- Отделить будущий commercial collaboration юридически и физически.
- Убрать collaboration из public release manifest и core dev graph.
- Утвердить компактную ответственность core; перенести Drive-specific hierarchy.
- Развязать `DocumentType` и Drive/UI presentation.
- Ввести collaboration protocol registry.
- Квалифицировать реальные PHP/npm/image artifacts.
- Сделать PHP/OpenAPI/config BC проверки blocking.
- Pin Actions и images.
- Зафиксировать public API и support policy.
- Исправить metadata после split.
- Удалить generated coverage artifact из HEAD.

### P1 — блокирует final 1.0

- Полная authorization matrix.
- SSRF adversarial tests.
- Upload/malware/ZIP/OOXML security tests.
- Secure defaults/fatal production checks.
- SCA, SBOM, signing/provenance.
- Redis multi-instance collaboration и quotas либо честный single-node scope.
- Fault injection, backup/restore, rollback drills.
- SECURITY.md, CODEOWNERS, vulnerability process.
- Performance baseline и release regression gate.
- Upgrade fixtures между RC.

### P2 — после стабильного 1.0 kernel

- `DocumentImporter` и sandboxed XLSX ingestion.
- `DocumentTranscoder` и loss reports.
- WOPI/OnlyOffice/Collabora adapters.
- PDF/DOCX exporters в isolated workers.
- Search/indexer/thumbnailer SPI.
- ACL materialized read model только по profiling evidence.
- Activity hash-chain/partitioning при compliance/scale demand.
- Distroless hub image после operational maturity.
- Additional client adapters (React/Livewire) без изменения content model.

## 25. Release exit criteria

Версия 1.0 готова только если все пункты истинны:

### Architecture

- Public core работает без collaboration и верхних packages.
- Запрещённые dependency edges автоматически ломают CI.
- Новый document type не требует изменения core.
- Новый editor не требует изменения document content type.
- Новый realtime protocol не требует изменения core/Documents internals.

### Packaging

- Каждый package archive устанавливается в clean consumer.
- Private package использует version ranges и private registries.
- Тестируется ровно публикуемый digest.
- Package contents allowlisted.

### Compatibility

- Public API каталог утверждён.
- PHP/OpenAPI/config/events/problems/npm BC checks blocking.
- Upgrade from previous RC tested.
- Deprecation policy опубликована.

### Security

- Production defaults fail closed.
- Authorization matrix покрывает все operations.
- SSRF/upload/collaboration negative suites green.
- Dependency/container scans не имеют неразобранных critical/high по policy.
- Secrets redaction verified.
- Security disclosure process опубликован.

### Supply chain

- External Actions/images pinned.
- SBOM/checksum/provenance созданы для release artifacts.
- Publish использует OIDC/short-lived credentials, где возможно.
- Consumer может проверить artifact origin.

### Operations

- Backup/restore и rollback отрепетированы.
- Performance baseline не регрессировал.
- Runbooks готовы.
- Collaboration edition имеет подтверждённый deployment scope.

## 26. Рекомендуемая последовательность первых изменений

Не начинать с XLSX editor. Первые пять pull requests должны быть архитектурными:

1. **ADR + dependency guard:** зафиксировать целевой graph и kernel budget.
2. **Core isolation:** убрать collaboration/dev обратные зависимости и Drive-specific hierarchy.
3. **Document SPI v2:** разделить content, capabilities, editor/presentation.
4. **Collaboration protocol registry:** адаптировать текущий Hocuspocus как первую implementation.
5. **Repository split:** перенести implementation и release infrastructure в private repo.

После этого:

6. Artifact qualification/BC/supply-chain gates.
7. Security matrix и adversarial tests.
8. RC1/RC2.
9. Только затем importer/exporter/XLSX/WOPI additions как отдельные extension packages.

Такой порядок минимизирует риск: XLSX и новые editors будут строиться уже на стабильных extension points, а не заставят снова менять public API после 1.0.

## 27. Финальная оценка

Vaulter уже заметно выше среднего open-source Laravel package по дисциплине: имеются package split, contracts, immutable registries, graceful missing-extension behavior, multi-database/concurrency tests, consumer isolation, OpenAPI, threat model и production doctor.

Однако «идеальность» перед 1.0 достигается не увеличением числа abstractions и features. Она достигается сокращением обещаний: маленькое ядро, явно объявленный public API, односторонние зависимости, protocol-neutral SPI, проверяемые артефакты, secure defaults и коммерческий add-on, который можно обновлять независимо.

Главный архитектурный критерий успеха:

> Через год новый XLSX/diagram/whiteboard editor, новый storage driver или новый realtime protocol должны добавляться отдельным package с contract tests, без изменения `vaulter-core`, без миграции чужих таблиц и без обязательного обновления остальных adapters.

Если выполнить P0 и P1 из этого отчёта, проект можно выпускать как устойчивую 1.0-платформу, а не просто как большой feature-rich пакет.

---

## References

1. [Secure use reference - GitHub Docs](https://docs.github.com/en/actions/reference/security/secure-use?learn=getting_started&learnProduct=actions) - Pin references to actions to a commit SHA in your workflow files.
This helps mitigate the risk of a ...

2. [Using artifact attestations](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations) - Use artifact attestations to establish build provenance for the software you produce and to verify t...

3. [Semantic Versioning 2.0.0](https://semver.org/) - 1. Software using Semantic Versioning MUST declare a public API.
This API

4. [Repositories - Composer](https://getcomposer.org/doc/05-repositories.md) - A repository is a package source.
It's a list of packages/versions.

5. [Trusted publishing for npm packages](https://docs.npmjs.com/trusted-publishers/) - Trusted publishing allows you to publish npm packages directly from your CI/CD workflows using OpenI...

6. [Signing the pledge](https://www.cisa.gov/securebydesign/pledge) - Products designed with Secure by Design principles prioritize the security of customers as a core bu...

7. [Table of Contents - OWASP API Security Top 10](https://api-security.owasp.org/editions/2023/en/0x00-toc/) - - OWASP Top 10 API Security Risks – 2023
- API1:2023 Broken Object Level Authorization

8. [API1:2023 Broken Object Level Authorization - OWASP API ...](https://api-security.owasp.org/editions/2023/en/0xa1-broken-object-level-authorization/) - implemented at the code level to validate that a user can only access the

9. [Server-Side Request Forgery Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html) - The objective of the cheat sheet is to provide advice regarding the protection against Server Side R...

10. [top10proactive.owasp.org · the-top-10 · c10-stopC10: Stop Server Side Request Forgery - OWASP Top 10 ...](https://top10proactive.owasp.org/the-top-10/c10-stop-server-side-request-forgery/) - - If outgoing requests have to be made, check the target against an allow-list

11. [File Storage Location](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) - - **Set a file size limit**
- **Only allow authorized users to upload files**

12. [Laravel Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Laravel_Cheat_Sheet.html) - You may also refer the File Upload Cheatsheet to learn more.
...
Generate stored filenames, keep upl...

13. [NodeJS Docker - OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/cheatsheets/NodeJS_Docker_Cheat_Sheet.html) - ## 7) Find and fix security vulnerabilities in your Node.js docker image ¶

14. [Docker Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Docker_Security_Cheat_Sheet.html) - The aim of this cheat sheet is to provide a straightforward list of common security errors and best ...

15. [Secure use reference - GitHub Enterprise Server 3.16 Docs](https://docs.github.com/en/enterprise-server@3.16/actions/reference/security/secure-use?learn=adopting_github_actions_for_your_enterprise_ghes&learnProduct=admin) - Pinning an action to a full-length commit SHA is currently the only way to use an action as an immut...

16. [Projects | OWASP Foundation](https://owasp.org/projects) - Dependency-Check is a Software Composition Analysis (SCA) tool suite that identifies project depende...

17. [Dependency-Check](https://devguide.owasp.org/en/05-implementation/02-dependencies/01-dependency-check/) - OWASP Dependency-Check is a tool that provides Software Composition Analysis (SCA) from the command ...

18. [Generating provenance statements](https://docs.npmjs.com/generating-provenance-statements/) - The provenance attestation is established by publicly providing a link to a package's source code an...

19. [Artifact attestations - GitHub Enterprise Cloud Docs](https://docs.github.com/en/enterprise-cloud@latest/actions/concepts/security/artifact-attestations) - Artifact attestations enable you to create unfalsifiable provenance and integrity guarantees for the...

20. [docs.github.com › use-artifact-attestationsUsing artifact attestations to establish provenance for ...](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations) - Artifact attestations enable you to increase the supply chain security of your builds by establishin...

21. [Семантическое Версионирование 2.0.0](https://semver.org/lang/ru/spec/v2.0.0.html) - 1. ПО, использующее Семантическое Версионирование, должно объявить публичный
API.

22. [github.com › Roave › BackwardCompatibilityCheckGitHub](https://github.com/Roave/BackwardCompatibilityCheck/blob/8.5.x/README.md) - A tool that can be used to verify BC breaks between two versions
of a PHP library.

23. [github.com › Roave › BackwardCompatibilityCheckRoave Backward Compatibility Check - GitHub](https://github.com/Roave/BackwardCompatibilityCheck) - A tool that can be used to verify BC breaks between two versions
of a PHP library.

24. [OpenAPI Diff Tool: Compare Specs Online & Find ... - oasdiff](https://www.oasdiff.com/diff) - An OpenAPI diff compares two versions of an OpenAPI (or Swagger) specification and reports what chan...

25. [OpenAPI Breaking Changes: The Complete List of Rules](https://www.oasdiff.com/docs/breaking-changes) - Every OpenAPI breaking change oasdiff detects: 755 distinct changes across schemas, parameters, requ...

