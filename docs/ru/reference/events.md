---
title: События
description: Каждое событие Vaulter по доменам — payload конструктора, ShouldBroadcast против ShouldDispatchAfterCommit.
outline: deep
---

# События

Vaulter диспетчеризует события Laravel по четырём доменам. Каждое событие — это класс
`final readonly` с публичными продвинутыми (promoted) свойствами конструктора: слушайте их в
своём `EventServiceProvider`, чтобы реагировать на активность в Drive, изменения ACL,
публикацию и правки документов.

В игре два контракта доставки:

- **`ShouldDispatchAfterCommit`** — событие срабатывает только после того, как окружающая
  транзакция БД зафиксирована (ни один слушатель не отработает над строкой, которую потом
  откатят). Это подавляющее большинство событий Vaulter.
- **`ShouldBroadcast`** — событие вдобавок транслируется в realtime-канал. Транслируются
  только события узловых комментариев (все в домене Documents): `CommentAdded`,
  `CommentResolved`, `CommentDeleted`.

```php
use Vaulter\Drive\Events\Files\FileUploaded;

Event::listen(function (FileUploaded $event): void {
    // $event->node, $event->file, $event->version, $event->actorId
});
```

::: info `actorId`
Почти каждое событие несёт `int|string|null $actorId` — id пользователя хоста, который его
запустил (таблицей пользователей владеет хост, поэтому это id, а не модель). Он равен
`null` для действий, инициированных системой (например, плановая очистка/GC).
:::

---

## Access — `Vaulter\Events\Access`

Все `ShouldDispatchAfterCommit`.

| Событие | Payload конструктора |
|---|---|
| `NodeGrantCreated` | `NodeGrant $grant, int\|string\|null $actorId = null` |
| `NodeGrantRevoked` | `Node $node, Subject $subject, int $revoked, int\|string\|null $actorId = null` |

- `NodeGrantCreated` / `NodeGrantRevoked` — гранты **Drive ACL** (`v_node_grants`).
  `$revoked` — число удалённых строк грантов.

## Documents — `Vaulter\Documents\Events` (требует `axioma-studio/vaulter-documents`)

| Событие | Транслируется? | Payload конструктора |
|---|---|---|
| `DocumentCreated` | После коммита | `Node $node, DocumentRevision $version, int\|string\|null $actorId = null` |
| `DocumentContentUpdated` | После коммита | `Node $node, DocumentRevision $version, bool $created, int\|string\|null $actorId = null` |
| `DocumentRevisionCreated` | После коммита | `Node $node, DocumentRevision $version, bool $restored = false, int\|string\|null $actorId = null` |
| `CommentAdded` | **Да** (`ShouldBroadcast`) | `NodeComment $comment` |
| `CommentResolved` | **Да** (`ShouldBroadcast`) | `NodeComment $comment, int\|string\|null $actorId = null` |
| `CommentDeleted` | **Да** (`ShouldBroadcast`) | `NodeComment $comment, int\|string\|null $actorId = null` |

::: info Путь, привязанный к узлу
`DocumentCreated`, `DocumentContentUpdated`, `DocumentRevisionCreated` работают на
**узловом** пути (`Node` + `DocumentRevision`, при установленном Documents). События
`NodeComment*` покрывают треды узловых комментариев (ADR-0014). У Vaulter больше нет
других доменов документных событий — прежние события DocVault, привязанные к
`document_id`, были полностью исключены (ADR-0014).
:::

### Детали трансляции

Все три события `NodeComment*` публикуют в `PrivateChannel` с именем
`{collaboration.channel_prefix}.{nodeId}` (префикс по умолчанию `vaulter.documents`) —
та же комната, в которую заходят открытые редакторы для совместного редактирования.

| Событие | `broadcastAs()` | Ключи `broadcastWith()` |
|---|---|---|
| `CommentAdded` | `node-comment.added` | `id`, `node_id`, `parent_id`, `body`, `created_at` |
| `CommentResolved` | `node-comment.resolved` | `id`, `node_id`, `resolved_at`, `resolved_by` |
| `CommentDeleted` | `node-comment.deleted` | `id`, `node_id` |

```php
// Слушатель на стороне Echo (фронтенд)
Echo.private(`vaulter.documents.${nodeId}`)
    .listen('.node-comment.added', (e) => { /* … */ })
    .listen('.node-comment.resolved', (e) => { /* … */ })
    .listen('.node-comment.deleted', (e) => { /* … */ })
```

## Drive — `Vaulter\Drive\Events` (требует `axioma-studio/vaulter-drive`)

Все `ShouldDispatchAfterCommit`.

| Событие | Payload конструктора |
|---|---|
| `Drives\DriveCreated` | `Drive $storage, Node $root, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\FolderCreated` | `Node $node, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Files\FileUploaded` | `Node $node, File $file, FileRevision $version, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Files\FileRevisionAdded` | `Node $node, File $file, FileRevision $version, int $versionNo, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeRenamed` | `Node $node, string $fromName, string $toName, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeMoved` | `Node $node, int\|string\|null $fromParentId, int\|string\|null $toParentId, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeCopied` | `Node $source, Node $copy, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeTrashed` | `Node $node, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeRestored` | `Node $node, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodePurged` | `int\|string $storageId, array $nodeIds, int $freedBytes, int\|string\|null $actorId = null, ?string $eventId = null` |

- `Drives\DriveCreated` — срабатывает, когда `Vaulter::for()` находит-или-создаёт строку
  `v_storages`; `$root` — автоматически подготовленный узел корневой папки.
- `Nodes\NodePurged` — обратите внимание, оно несёт **id**, а не модели (`$storageId`,
  `array $nodeIds`) плюс `$freedBytes`; оно испускается уже после того, как поддерево
  исчезло, так что его строк больше не существует. Слушатели могут запланировать GC и
  освободить квоту.

## Sharing — `Vaulter\Events\Sharing`

Все `ShouldDispatchAfterCommit`.

| Событие | Payload конструктора |
|---|---|
| `NodeShareCreated` | `NodeShare $share, int\|string\|null $actorId = null` |
| `NodeShareRevoked` | `NodeShare $share, int\|string\|null $actorId = null` |

- `NodeShare*` — канал **публичных ссылок (share) на узлы по bearer-токену** (`v_node_shares`);
  это единственный канал публикации, который Vaulter поставляет сегодня (см.
  [ADR-0013](/adr/0013-two-sharing-channels)).

См. [Использование → Публичные ссылки](/ru/guide/sharing).

---

## Заметка о надёжности

Когда включён `features.webhooks`, after-commit-подписчик (`OutboxRecorder`) складывает
доменные события в `v_outbox` в том же запросе, что и мутацию; затем команда
`vaulter:relay-outbox` разворачивает каждую строку в подписанные webhook-доставки.
Потребители обязаны **дедуплицировать по `event_id`** из payload — outbox идемпотентен по
`event_id`, но доставка может быть поставлена в очередь повторно. См.
[Рецепты → Надёжность и webhooks](/ru/guide/recipes).

## См. также

- [Справочник команд](/ru/reference/commands) — планировщики relay/retry/GC
- [Справочник конфигурации](/ru/reference/configuration) — `features.webhooks`, `vaulter-documents.collaboration.broadcast.channel_prefix`, `webhooks.*`
- [Справочник контрактов](/ru/reference/contracts) — модели, которые несут эти payload
