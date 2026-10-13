Репозиторий уже значительно зрелее типичного pre-1.0 Laravel-пакета: есть ADR, архитектурные тесты, immutable snapshots, единая авторизационная граница, PostgreSQL concurrency-lane, CAS и хорошо укреплённый Hocuspocus-сервер. Главный следующий шаг — не добавлять ещё больше абстракций, а **сузить kernel, сделать владение данными модульным и заранее зафиксировать open-core границу**, иначе последующее отделение платных пакетов окажется болезненным.

> Аудит ниже — статический архитектурный разбор текущего `main`. Он не заменяет отдельный прогон тестов, профилирование, fault injection и нагрузочные испытания в реальном окружении.

# Архитектурный аудит Vaulter

## Краткий вердикт

Текущую архитектуру можно довести до очень сильного состояния без полного переписывания. Основа уже правильная:

- Публичные операции отделены от внутренних Actions и Queries.
- Авторизация централизована на уровне Operations.
- Eloquent-модели выведены из публичного API и заменены immutable snapshots.
- Drive, media, documents и интеграционные мосты физически разделены.
- CAS строится вокруг immutable content-addressed blobs.
- Коллаборация отделена через `CollaborationDriver`.
- Есть PostgreSQL-тесты реальной конкурентности, а не только SQLite unit tests.
- Есть OpenAPI, mutation testing, load workflow и monorepo split workflow.

Это видно по [ADR-0017](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/docs/adr/0017-package-structure-canon.md), [ADR-0019](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/docs/adr/0019-kernel-ownership.md), [ADR-0020](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/docs/adr/0020-authorized-public-api.md) и [ADR-0026](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/docs/adr/0026-public-api-snapshots.md).

Субъективная оценка текущего состояния:

| Область | Оценка | Основной вывод |
|---|---:|---|
| Публичный PHP API | 8.5/10 | Snapshots и authorized Operations — очень сильная основа |
| Внутренняя архитектура | 7.5/10 | Хорошие слои, но kernel владеет слишком многим |
| Пакетные границы | 7/10 | Набор пакетов правильный, владение schema не соответствует модульности |
| REST API | 7/10 | Нужны системная идемпотентность и optimistic concurrency |
| CAS и файлы | 8/10 | Хорошая база; нужны integrity scrub, repair и формализованный GC |
| Collaboration | 6.5/10 | Хорошая защита, но остаётся риск потери dirty-state и multi-hub конфликтов |
| Тестирование | 8/10 | Сильная CI-база; не хватает release-isolation и fault-injection lanes |
| Observability | 5/10 | Health и stats есть, полноценного telemetry contract пока нет |
| Commercial readiness | 4.5/10 | MIT-код уже публичен, граница Community/Pro ещё не закреплена |

## Главная проблема

Главное архитектурное противоречие находится в [ADR-0019](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/docs/adr/0019-kernel-ownership.md): `core` объявлен владельцем всей базы данных, всех migrations и большинства общих моделей, даже если соответствующий модуль не установлен.

Для обычного монорепозитория это удобно: одна история миграций, один порядок установки, меньше cross-package FK-проблем. Но для будущего open-core это создаёт четыре проблемы:

- Community core будет знать таблицы приватных модулей.
- Установка минимального профиля будет создавать неиспользуемую schema.
- Платный пакет не сможет независимо развивать свою модель данных.
- Любое выделение пакета потребует синхронного изменения публичного core.

Иными словами, физическое разделение пакетов уже есть, но **data ownership пока остаётся монолитным**.

## Целевая структура

Не стоит сейчас раскладывать проект на 15–20 микропакетов. Оптимальная стратегия — сохранить нынешние крупные пакеты, но исправить владение schema и добавить явные product profiles.

```text
vaulter-core
    ↓
vaulter-media
    ↓
vaulter-drive
    ↓
vaulter-documents
    ↓
vaulter-collaboration-hocuspocus
```

Боковые интеграции:

```text
vaulter-core ← vaulter-azgard
vaulter-drive ← vaulter-filament-drive
vaulter-documents ← vaulter-filament-documents
```

Метапакеты:

```text
vaulter-community
vaulter-pro
vaulter-enterprise
```

### Рекомендуемое владение

| Пакет | Чем должен владеть |
|---|---|
| `core` | IDs, Actor, OperationContext, snapshots foundation, Page, error taxonomy, authorization SPI, event envelope, module registry, общая конфигурация |
| `media` | Blob/CAS schema, blob repository, storage backend SPI, checksums, GC, reconcile, repair |
| `drive` | Drive, Node, File, FileRevision, upload sessions, tree operations, basic sharing |
| `documents` | Document, DocumentRevision, comments, document types, editor-neutral content |
| `collaboration-hocuspocus` | Collaboration sessions, room epochs, Yjs snapshots/update log, Hocuspocus adapter и OCI image |
| `azgard` | Только адаптер авторизации |
| `filament-*` | Только admin UI и вызовы публичных Operations |
| `*-pro` | Premium workflows, exports, compliance, extended collaboration и integrations |

### Что оставить в Community

Минимальная OSS-версия должна быть полезным продуктом, а не демонстрацией:

- Core contracts и authorized API.
- Drive/tree.
- Local и S3-compatible storage через Flysystem.
- CAS, checksums и безопасный GC.
- Basic resumable upload.
- Basic document JSON storage.
- REST API и OpenAPI.
- Headless Vue primitives или минимальный file manager.
- Generic authorization adapter.
- Все базовые security и reliability mechanisms.

### Что продавать

| Уровень | Функциональность |
|---|---|
| Pro | Hocuspocus collaboration, presence, comments, advanced revision UI, export PDF/DOCX, templates, document workflows |
| Business | Full-text search adapters, antivirus/DLP, preview pipeline, advanced sharing, branded UI, storage analytics |
| Enterprise | Legal hold, immutable retention, KMS integration, SIEM export, SSO/SCIM bridges, audit sealing, multi-region tooling |
| Hosted | Managed collaboration hub, managed search/indexing, backups, monitoring и operational SLA |

Безопасность, целостность данных и восстановление после сбоев нельзя искусственно ослаблять в Community: это разрушит доверие к OSS-ядру.

## Лицензионная граница

Сейчас `core`, `media`, `drive`, `documents` и метапакет объявлены MIT; метапакет устанавливает все четыре основных пакета через `self.version`. Hocuspocus package отмечен как npm `private`, но его исходники находятся в публичном репозитории и также имеют MIT license.

Практическое следствие: уже опубликованный под MIT код нельзя сделать недоступным существующим пользователям и форкам. Можно прекратить выпускать его новые версии и разрабатывать будущую функциональность privately, но текущая публичная история останется доступной.

Поэтому лучше не строить план как «сделаем всё публично, потом выпилим». Надёжнее:

1. Зафиксировать Community feature map до первого стабильного релиза.
2. Оставить уже публичный код OSS.
3. Новые premium capabilities сразу реализовывать отдельными пакетами.
4. Не допускать импортов из приватных пакетов в Community.
5. Не размещать premium implementation за feature flag внутри OSS-классов.
6. Если планируется dual licensing, заранее решить вопрос CLA; DCO сам по себе не передаёт право перелицензировать вклад.

Юридическую схему MIT/open-core/CLA стоит отдельно подтвердить с профильным юристом до приёма внешних contributions.

## Kernel и модули

### Сузить core

Текущий `core` по ADR владеет Drive, Node, File, FileRevision, Blob, sharing, activity, outbox и webhook models. Это постепенно превращает его не в kernel, а в «весь продукт без use cases».

Хороший kernel должен содержать только то, без чего невозможно согласованное выполнение модулей:

- Identity types.
- Actor и tenant context.
- Correlation/causation IDs.
- Transaction coordinator.
- Event recorder/outbox contract.
- Authorization boundary.
- Stable snapshots и error objects.
- Capability registry.
- Module compatibility validation.

Blob schema должна принадлежать `media`; Node/File schema — `drive`; Document schema — `documents`; Yjs persistence — collaboration package.

### Не создавать общий Contracts dump

Отдельный `vaulter-contracts` нужен только в том случае, если contracts действительно имеют независимых потребителей без установки core. Иначе он быстро превратится в свалку интерфейсов.

Лучшее правило уже частично сформулировано в ADR-0019:

- Контракт, вызываемый kernel, живёт в core.
- Контракт, вызываемый только модулем, живёт в модуле.
- Реализация зависит от контракта.
- Модуль не экспортирует repositories, Actions и Eloquent models.

### Добавить module manifest

Composer должен отвечать за обязательные зависимости. Внутренний registry — за capabilities, совместимость protocol/schema и fail-fast validation.

```php
interface VaulterModule
{
    public static function id(): string;

    /** @return array<string, string> module => supported version constraint */
    public static function requires(): array;

    /** @return list<string> */
    public static function provides(): array;

    public function register(VaulterRegistry $registry): void;

    public function validate(VaulterInstallation $installation): void;
}
```

Например:

```php
final class CollaborationModule implements VaulterModule
{
    public static function id(): string
    {
        return 'collaboration.hocuspocus';
    }

    public static function requires(): array
    {
        return [
            'core' => '^1.2',
            'documents' => '^1.1',
        ];
    }

    public static function provides(): array
    {
        return [
            CollaborationDriver::class,
            CollaborationPersistence::class,
        ];
    }
}
```

При boot система должна завершаться с понятной ошибкой, если:

- Обязательный capability отсутствует.
- Версия protocol не поддерживается.
- Миграции пакета старее требуемой версии.
- Активировано два взаимоисключающих driver.
- Private package несовместим с установленным core.

`class_exists()` и неявное «если пакет установлен — поведение меняется» следует использовать только для UI conveniences, но не для correctness-critical dependencies.

## Владение миграциями

Рекомендуемый вариант:

```text
core/database/migrations/
    v_installations
    v_idempotency_keys
    v_outbox

media/database/migrations/
    v_blobs
    v_blob_locations
    v_blob_gc_marks

drive/database/migrations/
    v_drives
    v_nodes
    v_files
    v_file_revisions
    v_upload_sessions
    v_upload_parts

documents/database/migrations/
    v_documents
    v_document_revisions
    v_node_comments

collaboration/database/migrations/
    v_collaboration_rooms
    v_collaboration_snapshots
    v_collaboration_updates
```

Cross-package foreign keys допустимы только в направлении обязательной Composer dependency. Например, `documents` может ссылаться на `drive.nodes`, потому что Documents требует Drive; обратная ссылка из Drive в Documents недопустима.

Для установки нужны:

- `vaulter:install --profile=community|pro|enterprise`.
- `vaulter:doctor`.
- `vaulter:modules`.
- `vaulter:migrations:status`.
- `vaulter:uninstall-module --dry-run`.
- Проверка schema version при boot worker и collab hub.
- Запрет удаления модуля при существующих принадлежащих ему данных без явного export/purge.

## Публичный PHP API

Решение из ADR-0026 — одно из лучших мест архитектуры: публичные операции принимают IDs или snapshots, возвращают immutable snapshots, а Eloquent остаётся internal.

Это защищает от:

- `$model->save()` в host application.
- Превращения каждой DB-колонки в публичный контракт.
- Обхода authorization, invariants и audit.
- N+1, если mappers действительно выполняют batch-loading.
- Случайной сериализации внутренних полей.

### Сохранить единый вход

Все клиенты должны проходить один путь:

```text
REST ──────────┐
Filament ──────┼─> Authorized Operations ─> Actions/Queries ─> Repositories
Host PHP code ─┤
Jobs/Console ──┘
```

Текущий ADR уже требует, чтобы HTTP, Filament и host code использовали Operations, а Actions не выполняли авторизацию. Это правильный инвариант; его надо закрепить package-level arch tests во всех модулях.

### Усилить входные объекты

Публичные write methods лучше стандартизировать:

```php
final readonly class MoveNode
{
    public function __construct(
        public string $nodeId,
        public string $destinationId,
        public ConflictPolicy $conflictPolicy,
        public ?int $expectedNodeVersion,
        public ?int $expectedDestinationVersion,
        public string $requestId,
    ) {}
}
```

Каждая write operation должна иметь:

- `requestId`.
- Expected aggregate version или ETag.
- Conflict policy.
- Correlation ID.
- Actor из immutable context.
- Explicit system reason для system path.
- Stable result snapshot.

## Идемпотентность API

Это наиболее важное недостающее reliability-свойство.

AWS рекомендует caller-provided request ID и возврат семантически эквивалентного результата при повторе; операции с side effects без идемпотентности небезопасно автоматически повторять. Google AIP-155 аналогично требует, чтобы повторный запрос с тем же `request_id` не создавал повторных эффектов и возвращал результат предыдущего успешного вызова. [aws.amazon](https://aws.amazon.com/builders-library/making-retries-safe-with-idempotent-APIs/)

### Какие операции защищать

- Создание drive/node/folder.
- Инициализация upload.
- Commit upload.
- Restore revision.
- Создание grant/share.
- Отзыв share.
- Force delete.
- Export job.
- Collaboration seed/store.
- Webhook administration.
- Batch operations.

### Таблица ключей

```text
v_idempotency_keys
- scope
- actor_type
- actor_id
- operation
- request_id
- request_hash
- status
- response_code
- response_body
- resource_type
- resource_id
- started_at
- completed_at
- expires_at
```

Алгоритм:

1. Нормализовать command и вычислить `request_hash`.
2. Захватить запись по `(scope, actor, operation, request_id)`.
3. Если запрос уже завершён и hash совпадает — вернуть прежний response.
4. Если ключ тот же, но hash другой — `409 idempotency_key_reused`.
5. Если операция ещё выполняется — вернуть `409/425 operation_in_progress` или дождаться bounded deadline.
6. Выполнить mutation и сохранить response в одной транзакции.
7. Документировать TTL ключа.

Важно: ключ должен быть scoped минимум по tenant/drive, actor и operation. Глобальный UUID без scope создаёт риск конфликтов и утечки существования чужих операций.

## Optimistic concurrency

Идемпотентность защищает от повтора одного запроса, но не от двух разных конкурентных команд.

Нужны:

- `version` на mutable aggregates.
- `ETag` в REST representation.
- `If-Match` для PATCH/DELETE/MOVE.
- Expected version в PHP command.
- `409 stale_write` или `412 precondition_failed`.
- Атомарное `UPDATE ... WHERE id = ? AND version = ?`.
- Новая snapshot с увеличенной версией в response.

Для move нужно проверять не только source node, но и destination parent, потому что quota, unique sibling names и permissions могли измениться между чтением и записью.

## События и outbox

Dropbox Async Task Framework гарантирует at-least-once execution и прямо требует идемпотентности пользовательских callbacks, поскольку task может выполняться повторно и на другом host. Такой контракт реалистичнее обещания exactly-once. [dropbox](https://dropbox.tech/infrastructure/asynchronous-task-scheduling-at-dropbox)

Рекомендуемый event envelope:

```json
{
  "event_id": "evt_...",
  "type": "vaulter.node.moved",
  "schema_version": 1,
  "occurred_at": "2026-10-13T07:00:00.000000Z",
  "tenant_id": "tenant_...",
  "drive_id": "drive_...",
  "aggregate_type": "node",
  "aggregate_id": "node_...",
  "aggregate_version": 14,
  "actor": {
    "type": "user",
    "id": "user_..."
  },
  "correlation_id": "corr_...",
  "causation_id": "cmd_...",
  "payload": {}
}
```

Обязательные инварианты:

- Domain mutation и outbox insert — одна DB transaction.
- Event ID уникален.
- Delivery — at least once.
- Consumer хранит processed event IDs или использует natural idempotency.
- Event payload никогда не содержит Eloquent serialization.
- У каждого event type есть schema version.
- Старые consumers проверяются consumer-driven contract tests.
- Poison events переходят в DLQ после bounded retries.
- Outbox age и delivery lag являются метриками и SLI.

## Collaboration: сильные стороны

Текущий Hocuspocus runtime уже хорошо защищён:

- Exact Origin allowlist.
- Server-side read-only.
- Token expiry timer и проверка expiry перед сообщениями.
- Epoch fencing после restore.
- Seed conflict handling.
- Проверка потери TipTap attributes при преобразовании JSON в Y.Doc.
- WebSocket frame limit.
- Отключённый `perMessageDeflate`.
- Optional Redis для нескольких instances.
- HMAC-подпись backend calls.
- Backend webhook timeout.
- Отдельный health endpoint.
- Terminal handling для epoch mismatch и удалённого документа.

Это реализовано непосредственно в [Hocuspocus runtime](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/hocuspocus-server/src/index.js), [webhook client](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/hocuspocus-server/src/webhook.js) и строгой [config validation](https://github.com/axiomasoft/vaulter/blob/861e571377268300d7fb38818c5e83701dfe3fa6/hocuspocus-server/src/config.js).

Выбор Hocuspocus default вместо Reverb также обоснован: Reverb не даёт полноценного server-side Yjs backend с надёжным read-only и persistence lifecycle.

## Collaboration: критические риски

### Dirty state после сбоя store

Сейчас store выполняет несколько retries с линейной задержкой. После исчерпания retries ошибка пробрасывается, документ остаётся в памяти и будет сохранён снова при следующем изменении.

Опасный сценарий:

1. Пользователь внёс изменение.
2. Backend недоступен.
3. Все retries исчерпаны.
4. Пользователь больше ничего не меняет.
5. Hocuspocus process завершается или node эвакуируется.
6. Последнее состояние существовало только в памяти.

Это потенциальный data-loss window.

Нужен один из двух явно документированных durability modes:

| Режим | Гарантия |
|---|---|
| `buffered` | Update подтверждается быстро; RPO ограничен периодом durable checkpoint |
| `durable` | Update не считается принятым, пока не попал в durable update log |

Для Pro-режима предпочтительно хранить append-only Yjs updates:

```text
(room_id, epoch, sequence, update_id, update_hash, payload, created_at)
```

Периодически updates компактируются в snapshot. Redis Pub/Sub можно использовать для fanout, но не как единственный источник истины.

### Multi-hub stale overwrite

Обычный store передаёт полный Yjs state и JSON, но `state_version` используется только во время seed. При Redis partition или временной рассинхронизации два hub instances теоретически могут отправить разные full-state snapshots, и последний backend write способен затереть состояние другого instance.

Для каждого store нужны:

- `base_state_version`.
- Atomic compare-and-swap на backend.
- При conflict — загрузить latest state.
- Применить локальный Yjs update к latest Y.Doc.
- Повторить CAS с bounded retry budget.
- Логировать merge conflict и persistence lag.

Ещё лучше отправлять не полный state как mutation, а immutable update с уникальным `update_id`; backend может дедуплицировать и сливать Yjs updates независимо от порядка доставки.

### Revocation во время сессии

Сейчас права определяются при authenticate; до следующего backend store локально проверяется прежде всего expiry токена. Если право edit отозвано, пользователь может продолжать отправлять updates до store refusal. Изменение уже способно попасть другим участникам комнаты, даже если backend впоследствии отвергнет persistence.

Варианты исправления:

- Короткий TTL collaboration session.
- `permissions_version` в JWT.
- Active revocation через Redis control channel.
- Session lease, обновляемый backend.
- Проверка ACL generation перед принятием каждого update batch.
- Закрытие всех соединений actor/node при revoke.
- Отделение end-user session authentication от service authentication при persistence.

### Retry policy

Store retries сейчас используют линейное ожидание `retryDelayMs * attempt` без jitter. AWS и Google рекомендуют bounded exponential backoff с jitter, поскольку синхронные retries усиливают перегрузку и cascading failure. [aws.amazon](https://aws.amazon.com/builders-library/timeouts-retries-and-backoff-with-jitter/?did=ba_card&trk=ba_card)

Пример full-jitter:

```js
async function retry(operation, {
  attempts = 5,
  baseMs = 200,
  capMs = 5000,
  deadlineMs = 15000,
} = {}) {
  const startedAt = Date.now()

  for (let attempt = 0; ; attempt++) {
    try {
      return await operation()
    } catch (error) {
      if (!isTransient(error) || attempt >= attempts - 1) {
        throw error
      }

      const remaining = deadlineMs - (Date.now() - startedAt)

      if (remaining <= 0) {
        throw error
      }

      const ceiling = Math.min(capMs, baseMs * (2 ** attempt), remaining)
      const delay = Math.floor(Math.random() * ceiling)

      await sleep(delay)
    }
  }
}
```

Нужны отдельные policies для authenticate, load, store и Redis reconnect. Не все операции должны повторяться одинаково.

### Полный state в webhook

При каждом store отправляются:

- Полный binary Yjs state в base64.
- Полный JSON projection.
- JSON envelope.

Это создаёт значительный write amplification. Base64 дополнительно увеличивает размер примерно на треть, а полная JSON projection повторяет часть информации.

Нужно рассмотреть:

- Incremental Yjs updates.
- Snapshot compaction по размеру/числу updates.
- Compression на application level после оценки CPU.
- Streaming или object-storage staging для больших snapshots.
- Отдельные limits: WebSocket frame, document size, update size и backend request size.
- Backpressure при большой очереди dirty rooms.

### Readiness и shutdown

Текущий `/health` всегда возвращает `200`, а `/stats` показывает только число rooms и connections. Это liveness, но не полноценная readiness.

Рекомендуется:

- `/livez` — process event loop работает.
- `/readyz` — hub слушает WS, backend reachable, Redis ready при обязательном Redis mode, schema/protocol совместимы.
- При SIGTERM сначала readiness становится false.
- Новые подключения прекращаются.
- Dirty rooms flush выполняется с bounded deadline.
- Только затем закрываются sockets и process.
- Dirty room count при завершении логируется как severity error.

Stats port лучше по умолчанию bind на `127.0.0.1` или отдельный management interface, а не на все интерфейсы.

## CAS и файловое хранение

Текущая архитектура с SHA-256 CAS, race-free commit и two-phase GC хорошо соответствует подходу Dropbox: Dropbox отделяет metadata от file content, хранит immutable blocks по content hash и проверяет hash для обнаружения повреждения данных. Dropbox также использует abstraction layer над различными storage backends, чтобы перемещать объекты между S3, internal storage и будущими backend без изменения клиентского API. [dropbox](https://dropbox.tech/infrastructure/-broccoli--syncing-faster-by-syncing-less)

### Инварианты blob layer

- Blob immutable после commit.
- Storage path не является публичным ID.
- В DB хранится opaque `blob_id`, а не публичный URL.
- Hash вычисляется во время streaming upload.
- Size проверяется независимо от client metadata.
- MIME определяется server-side и не считается security boundary.
- Commit атомарно создаёт blob reference и file revision.
- Повторный commit того же upload идемпотентен.
- Object-store ETag не используется как универсальный content checksum.
- Download может опционально проверять content hash.
- Corrupted blob переходит в quarantine и repair queue.

### Integrity scrub

Нужна periodic scrub subsystem:

```text
Blob → sample/read → hash verification → healthy | suspect | corrupt
```

Метрики:

- `blob_integrity_checks_total`.
- `blob_integrity_failures_total`.
- `blob_repair_queue_depth`.
- `blob_last_verified_age_seconds`.
- `blob_location_missing_total`.

Для дорогих S3 reads можно использовать tiered policy: недавно записанные blobs проверяются чаще, старые — случайной выборкой.

### Безопасный GC

Two-phase GC — правильная база, но критерии должны быть формализованы:

1. Candidate обнаружен без ссылок.
2. Candidate помечается `retired_at`.
3. Проходит grace period.
4. Под lock повторно проверяются все references.
5. Проверяются active upload leases, legal holds и pending outbox operations.
6. Удаляется physical object.
7. Фиксируется tombstone.
8. Metadata удаляется или архивируется.

Критическая race:

```text
GC проверил refcount=0
                    ↘ новый revision добавил reference
GC удалил blob
```

Она должна быть закрыта либо advisory lock по blob hash, либо row lock плюс повторной проверкой, либо generation/lease protocol. Наличие PostgreSQL concurrency lane показывает, что проект уже движется в правильном направлении.

### Deduplication scope

Deduplication рекомендуется scope-ить как минимум по security namespace или tenant, если глобальная дедупликация позволяет обнаруживать наличие чужого content по timing/response differences.

Допустимые режимы:

```text
none
drive
tenant
global
```

`global` следует делать сознательной настройкой с отдельной threat model.

## Дерево и metadata

Для `v_nodes` особенно важны DB-level и operation-level invariants:

- Node не может быть собственным parent.
- Node нельзя переместить в собственного descendant.
- Parent принадлежит тому же drive.
- Тип parent допускает children.
- Sibling name uniqueness учитывает normalization и trash policy.
- Root имеет специальный инвариант и не удаляется обычной операцией.
- Move блокирует source и relevant destination scope.
- Recursive delete/restore имеют bounded batch size.
- Path cache, если появится, является производным и восстанавливаемым.
- Search index не является источником истины.

Dropbox сохраняет metadata отдельно от immutable blocks и требует read-after-write consistency для metadata cache. Для Vaulter это означает: content storage может быть eventually repaired, но rename/move/grant после успешного response должны сразу читаться в новом состоянии из primary API. [dropbox](https://dropbox.tech/infrastructure/meet-chrono-our-scalable-consistent-metadata-caching-solution)

## Авторизация и tenancy

ADR-0020 правильно требует:

- Actor в handle.
- Явный `asSystem(reason)`.
- Повторное чтение node внутри drive.
- 404 для невидимых ресурсов.
- 403 только если View есть, но отсутствует конкретная ability.
- Единый authorization path для HTTP, Filament и host code.

Дополнительно нужно закрепить:

- Любой repository method принимает `driveId` или tenant scope.
- Ни один `find($id)` без scope не допускается в module code.
- Unique indexes включают tenant/drive, где это логически необходимо.
- Queue payload содержит tenant и actor context явно.
- Jobs не восстанавливают actor из «текущего guard».
- `asSystem()` не обходит tenancy, retention и data invariants.
- Raw SQL разрешён только в явно отмеченных internal services.
- Все list/search queries применяют ACL narrowing до pagination, а не после неё.

### Security test matrix

Каждая operation должна проверяться минимум для:

| Сценарий | Ожидание |
|---|---|
| Actor отсутствует | 401 |
| Другой tenant | 404 |
| Нет View | 404 |
| View есть, Write нет | 403 |
| Истёкшая share link | 404 или стабильная public error |
| System без reason | Запрет |
| Read-only collaboration actor пишет update | Socket closed/refused |
| Permission revoked mid-session | Session invalidated |
| Node ID правильный, drive handle чужой | 404 |
| Hidden destination folder | 404 |
| Move без Create на destination | 403 |

## REST API

Поскольку API позиционируется как долгоживущий, до `v1` следует зафиксировать:

- Resource naming.
- Cursor format как opaque string.
- Stable problem codes.
- Pagination semantics.
- Sorting stability.
- Nullability.
- ID representation как string.
- Timestamp precision и UTC.
- Unknown-field policy.
- Deprecation headers.
- Request size limits.
- Idempotency semantics.
- ETag/If-Match semantics.
- Retryability каждого error code.

Google Cloud APIs используют resource-oriented design, чтобы обеспечивать единообразный developer experience. Для Vaulter это означает, что custom action endpoints должны обозначать реальные команды, а не заменять обычный CRUD без необходимости. [docs.cloud.google](https://docs.cloud.google.com/apis/design)

Примеры:

```text
POST   /v1/drives/{drive}/nodes
GET    /v1/drives/{drive}/nodes/{node}
PATCH  /v1/drives/{drive}/nodes/{node}
POST   /v1/drives/{drive}/nodes/{node}:move
POST   /v1/drives/{drive}/nodes/{node}:trash
POST   /v1/drives/{drive}/nodes/{node}:restore
POST   /v1/uploads
POST   /v1/uploads/{upload}:commit
```

Для mutating commands:

```http
Idempotency-Key: 2b5e...
If-Match: "node:abc:14"
X-Correlation-ID: ...
```

Response:

```http
ETag: "node:abc:15"
X-Request-ID: ...
X-Correlation-ID: ...
```

## Ошибки и retries

Нужна машиночитаемая классификация:

| Code | HTTP | Retry |
|---|---:|---|
| `validation_failed` | 422 | Нет |
| `node_not_found` | 404 | Нет |
| `permission_denied` | 403 | Нет |
| `idempotency_key_reused` | 409 | Нет |
| `stale_write` | 409/412 | После повторного чтения |
| `operation_in_progress` | 409/425 | Да, с backoff |
| `rate_limited` | 429 | Да, учитывать Retry-After |
| `dependency_unavailable` | 503 | Да |
| `deadline_exceeded` | 504 | Возможно |
| `quota_exceeded` | 409/429 | Обычно нет |
| `collaboration_epoch_mismatch` | 409 | Получить новую session |
| `payload_too_large` | 413 | Нет |

AWS рекомендует устанавливать timeout на каждый remote/process call, ограничивать число retries и использовать exponential backoff с jitter. Retry budget должен быть единым на всю операцию, иначе вложенные retries в HTTP client, job worker и SDK способны умножить нагрузку. [docs.aws.amazon](https://docs.aws.amazon.com/wellarchitected/latest/reliability-pillar/rel_mitigate_interaction_failure_limit_retries.md)

## Frontend packages

Backend уже разделён, но frontend должен отражать те же capability boundaries.

Рекомендуемая структура:

```text
@vaulter/core
@vaulter/vue
@vaulter/vue-drive
@vaulter/vue-documents
@vaulter/vue-collaboration
@vaulter/vue-pro
```

`@vaulter/core`:

- API client.
- Types generated from OpenAPI.
- Error decoder.
- Retry/idempotency support.
- Capability discovery.
- No Vue dependency.

`@vaulter/vue`:

- Injection keys.
- Basic components.
- Headless composables.
- Theme tokens.

Collaboration UI не должен попадать в community bundle через dead code. Private npm package должен зависеть только от публичных frontend contracts.

## Package publication

В manifests Drive и Media тесты импортируют `Vaulter\Tests` через соседний путь `../core/tests/`. После реального split это создаёт скрытую зависимость от структуры монорепозитория.

Нужно:

- Либо вынести test fixtures в `axioma-studio/vaulter-testing` как dev-only package.
- Либо дать каждому пакету собственный минимальный TestCase.
- Не экспортировать production consumers тестовую инфраструктуру.
- Проверять каждый split package после копирования в пустой temp directory.
- Выполнять `composer install --no-dev` на release artifact.
- Запускать smoke application только против dist packages.

Также package manifests содержат path repositories, `minimum-stability: dev` и `self.version`. Для стабильного release train лучше:

- Удалять path repositories из публикуемых manifests.
- Убрать `minimum-stability: dev`.
- Использовать стабильные version constraints.
- В metapackage допустим lockstep `self.version`.
- Private extensions должны использовать диапазон совместимости вроде `^1.2`, а не требовать идентичный patch release.

## CI и release engineering

CI уже содержит отдельные workflows для core, Drive, Media, Documents, frontend, Hocuspocus image, OpenAPI, load, mutation и monorepo split. Основной workflow тестирует Laravel 12/13, type coverage 100%, PostgreSQL 16, разные ID strategies и forked concurrency harness.

Это сильная база. Следующие улучшения дадут больше пользы, чем повышение обычного line coverage.

### Release-isolation lane

Для каждого package:

1. Собрать Git archive/tarball.
2. Скопировать в пустой directory.
3. Убедиться, что нет ссылок на `../core`, root fixtures и unpublished paths.
4. Установить только declared dependencies.
5. Запустить package tests.
6. Создать минимальный Laravel app.
7. Установить package из artifact.
8. Выполнить migrate.
9. Запустить smoke API.
10. Выполнить `composer install --no-dev --prefer-dist`.

### Upgrade lane

Нужны тесты:

```text
previous stable schema
    → install previous package
    → generate representative data
    → upgrade code
    → run migrations
    → validate snapshots and invariants
    → downgrade is explicitly refused or supported
```

Особенно важны:

- Node tree.
- Blob references.
- Upload sessions.
- Document revisions.
- Share tokens.
- Outbox backlog.
- Yjs snapshots.

### Fault injection lane

- Kill process после DB commit, но до HTTP response.
- Kill upload worker между object write и DB commit.
- Object storage timeout.
- Redis partition.
- Backend 500/429/timeout.
- PostgreSQL deadlock/serialization failure.
- Duplicate queue delivery.
- Restore одновременно с collab store.
- Permission revoke во время editing.
- GC одновременно с добавлением blob reference.
- Webhook receiver отвечает медленно.
- Clock skew для signed webhooks и JWT.

### Supply chain

Добавить:

- Pin GitHub Actions по commit SHA.
- Dependency review.
- CodeQL.
- Secret scanning.
- Composer/npm audit.
- SBOM для Composer, npm и OCI image.
- Container image provenance.
- Signed Git tags/releases.
- Signed OCI images.
- Проверку license inventory.
- Dependabot/Renovate policy с grouped security updates.

## Нагрузочная защита

Google SRE рекомендует не только масштабировать service, но и уметь отклонять лишнюю нагрузку до исчерпания CPU, памяти и queues; load shedding и graceful degradation предотвращают cascading failures. [sre](https://sre.google/sre-book/addressing-cascading-failures/)

Для Vaulter нужны лимиты не только по IP:

- Tenant concurrent requests.
- Actor concurrent uploads.
- Drive mutation rate.
- Bytes uploaded per minute.
- Active collaboration rooms per tenant.
- Connections per room.
- Updates per second per room.
- Pending exports/previews.
- Outbox backlog.
- Search cost.
- Tree operation batch size.

Priority classes:

```text
P0: authentication, reads of existing metadata, collaboration persistence
P1: normal mutations and downloads
P2: previews, exports, indexing
P3: GC, scrub, analytics
```

При перегрузке сначала останавливаются P3/P2 jobs, затем expensive list/search, но не persistence уже принятых пользовательских изменений.

## Observability

### Общий telemetry context

Во всех PHP, queue и Hocuspocus paths:

- `request_id`.
- `correlation_id`.
- `causation_id`.
- `tenant_id`.
- `drive_id`.
- `node_id`.
- `actor_type`.
- `operation`.
- `module`.
- `package_version`.
- `schema_version`.
- `collaboration_epoch`.

Sensitive tokens, filenames и content в logs по умолчанию не выводятся.

### Метрики API

- Request rate по operation/status.
- p50/p95/p99 latency.
- DB transaction duration.
- Lock wait.
- Deadlock/serialization retry count.
- Idempotency replay/conflict count.
- Stale write count.
- Rate-limited requests.
- Outbox age.
- Queue age.
- Permission check latency.

### Метрики collaboration

- Active rooms/connections.
- Authenticate/load/store latency.
- Dirty rooms.
- Oldest dirty room age.
- Store retry count.
- Store failure count.
- Epoch mismatch count.
- Merge/CAS conflicts.
- Update log size.
- Snapshot compaction duration.
- Redis reconnects.
- Revoked sessions still connected.
- Graceful shutdown flush failures.

### Метрики storage

- Upload throughput.
- Commit latency.
- Multipart retries.
- Dedup hit ratio.
- Blob reference count anomalies.
- GC candidate/sweep count.
- Physical deletion failures.
- Integrity failures.
- Repair backlog.
- Storage backend error rate.

## SLO и error budget

Google SRE использует SLO и error budget как механизм управления скоростью релизов: при исчерпании бюджета изменения останавливаются, кроме критических исправлений. Burn-rate alerts позволяют реагировать до полного исчерпания бюджета. [sre](https://sre.google/sre-book/embracing-risk/)

Стартовый набор SLI:

| Сервис | SLI |
|---|---|
| Metadata API | Доля valid requests, завершившихся корректным non-5xx |
| Upload | Доля начатых commit, завершившихся без потери/дублирования |
| Collaboration | Доля accepted updates, достигших durable persistence в пределах RPO |
| Downloads | Доля успешных reads с корректным checksum |
| Outbox | Доля событий, доставленных в установленный deadline |
| GC | Доля удалений, не затронувших referenced blobs |

Значения SLO следует устанавливать после baseline measurement, а не выбирать только маркетингово. В policy необходимо формально определить:

- Кто останавливает releases.
- При каком burn rate page.
- При каком burn rate создаётся ticket.
- Когда обязателен postmortem.
- Какой incident требует reliability milestone.
- Какие изменения разрешены при исчерпанном бюджете.

## Disaster recovery

Dropbox отдельно проверял disaster readiness реальным отключением data center; это выявляет проблемы, которые не видны в документации и unit tests. Для Vaulter не требуется сразу строить active-active продукт, но нужны проверяемые процедуры. [dropbox](https://dropbox.tech/infrastructure/disaster-readiness-test-failover-blackhole-sjc)

Минимальный DR contract:

- PostgreSQL backup + PITR.
- Object storage versioning/replication по желанию host.
- Проверяемая связь DB snapshot и blob namespace.
- Restore tool с dry run.
- Reconcile DB references против storage.
- Rebuild search/previews из canonical data.
- Rebuild Yjs state из snapshot/update log.
- Rotation webhook/collaboration secrets.
- Документированные RPO/RTO.
- Регулярный restore drill в отдельное окружение.
- Отчёт о потерянных/лишних blobs после restore.

## Что не стоит делать

- Не разбивать каждый domain service в отдельный Composer package.
- Не создавать `Contracts` package без независимого installation use case.
- Не делать plugin system через произвольный dynamic PHP loading.
- Не разрешать premium package обращаться к internal Eloquent models Community-пакета.
- Не строить correctness на Laravel model events.
- Не обещать exactly-once delivery.
- Не использовать Redis Pub/Sub как durable collaboration storage.
- Не считать health endpoint доказательством readiness.
- Не считать line coverage доказательством отсутствия race conditions.
- Не хранить публичные storage URLs в domain model.
- Не валидировать коммерческую лицензию удалённым запросом в каждом write operation.
- Не блокировать чтение и export пользовательских данных при истечении Pro-лицензии.

## Коммерческая лицензия

Проверка premium license не должна становиться single point of failure.

Рекомендуемая модель:

- Подписанный offline entitlement document.
- Локальная cryptographic verification.
- Периодическое обновление entitlement.
- Grace period при недоступности licensing service.
- Core API продолжает работать без сети.
- При expiry запрещается создание новых premium resources.
- Чтение, export и удаление существующих данных сохраняются.
- License state попадает в health/doctor, но без самого ключа.
- Remote revocation применяется с документированным delay.
- Community code не содержит private implementation.

## Приоритетный план

### P0: до публичного 1.0

1. Зафиксировать Community/Pro/Enterprise feature map.
2. Принять новый ADR о product/package boundary.
3. Перенести schema ownership в соответствующие модули.
4. Выделить Hocuspocus implementation и server в самостоятельный collaboration package.
5. Добавить request idempotency для всех mutating operations.
6. Добавить aggregate versions и conditional writes.
7. Закрыть dirty-state data-loss window в collaboration.
8. Добавить CAS/version conflict для multi-hub persistence.
9. Добавить active permission revocation strategy.
10. Сделать release-isolation tests для каждого split package.
11. Удалить dev path assumptions из публикуемых manifests.
12. Зафиксировать API/schema/protocol compatibility policy.

### P1: стабильность production

1. Durable Yjs update log и snapshot compaction.
2. Liveness/readiness/drain для Hocuspocus.
3. OpenTelemetry traces, structured logs и Prometheus metrics.
4. Outbox retry/DLQ/idempotent consumer contract.
5. Integrity scrub и blob repair.
6. Upgrade tests с предыдущего release.
7. Fault-injection suite.
8. Quotas, load shedding и per-tenant backpressure.
9. Backup/restore/reconcile commands.
10. Security threat models для upload, sharing и collaboration.

### P2: коммерческая платформа

1. Private Composer/npm/OCI distribution.
2. Signed offline entitlements.
3. Capability discovery endpoint.
4. Pro compatibility test kit.
5. Legal hold и retention.
6. KMS/antivirus/DLP/search adapters.
7. Enterprise audit export.
8. Managed collaboration deployment profile.

## Acceptance gates

Версию `1.0` разумно выпускать только при выполнении следующих проверяемых условий:

- Ни один публичный API не возвращает Eloquent model.
- Ни один controller/Filament action не импортирует internal Action/Repository.
- Все write endpoints имеют документированную retry/idempotency policy.
- Все mutable aggregates имеют concurrency policy.
- Каждый package устанавливается и тестируется вне монорепозитория.
- Community profile не создаёт таблицы Pro-модулей.
- Pro package не импортирует internal namespace Community package.
- Collaboration не теряет подтверждённые updates при process kill в пределах заявленного RPO.
- Redis partition не приводит к silent last-write overwrite.
- Permission revoke завершает активную write session в документированный срок.
- GC не удаляет referenced blob в race tests.
- Upgrade с предыдущей стабильной версии проверяется на PostgreSQL.
- Backup restore drill воспроизводится автоматически.
- SLO, error budget policy и operational runbooks находятся в репозитории.