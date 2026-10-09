---
title: События
description: Каждое событие Vaulter по доменам — как оно записывается, его конструктор, строки activity и webhook.
outline: deep
---

# События

Каждое событие Vaulter наследует `Vaulter\Events\DomainEvent`: это `readonly`-класс,
который обозначает одно наступление (occurrence) изменения. Слушайте события в своём
`EventServiceProvider`, чтобы реагировать на активность в Drive, изменения ACL,
публикацию, документы и комментарии.

```php
use Vaulter\Drive\Events\Files\FileUploaded;

Event::listen(function (FileUploaded $event): void {
    // $event->node, $event->file, $event->version
    // $event->eventId, $event->occurredAt, $event->actor, $event->correlationId
});
```

Каждое событие несёт:

| Свойство | Тип | Смысл |
|---|---|---|
| `eventId` | `string` (ULID) | Идентификатор наступления. Его разделяют строка activity, строка outbox и `id` webhook. |
| `occurredAt` | `CarbonImmutable` (UTC) | Когда произошло изменение. |
| `actor` | `ActorRef` | Кто его вызвал: `type` + `id` пользователя хоста или системный актёр (`type = vaulter:system`, `id = null`, `reason`). |
| `correlationId` | `string` | Идентификатор корреляции операции; системные пути выпускают свой. |

и отвечает на `type()` (свой `EventType`), `scope()` (тенант, drive, профиль),
`subject()` (о чём оно), `sequence()` (sequence узла после изменения или `null`) и
`data()` (данные webhook).

## Как записываются события

Actions Vaulter передают каждое событие в `Vaulter\Events\EventRecorder` **последним
шагом своей транзакции** на соединении Vaulter. Рекордер:

1. пишет строку activity (если включён `features.activity` и тип аудируется);
2. пишет строку outbox с конвертом webhook (если включён `features.webhooks` и тип —
   webhook-тип);
3. отдаёт Laravel-событие вашим слушателям **после фиксации транзакции**.

Обе строки фиксируются или откатываются вместе с самим изменением. Если строку записать
не удалось, исключение выходит из транзакции и изменение откатывается — у
зафиксированного изменения всегда есть его строки activity и outbox. Упавший слушатель
изменение не отменяет: он работает после фиксации, его исключение передаётся в
`report()` и учитывается в `vaulter_event_listener_failures_total{type}`, а операция
возвращает результат как обычно.

Повторная запись того же наступления (событие, собранное заново с тем же `eventId`) —
no-op и повторно не отдаётся слушателям. Две одинаковые команды — два наступления:
переименование A→B→A→B даёт три события `node.renamed`.

::: warning Не диспетчеризуйте события Vaulter сами
`event(new NodeRenamed(...))` дойдёт до слушателей, но не запишет ни строку activity,
ни строку outbox. События больше не реализуют `ShouldDispatchAfterCommit`; доставку
после фиксации даёт рекордер.
:::

События комментариев вдобавок реализуют `ShouldBroadcast`; их трансляция ставится в
очередь той же диспетчеризацией после фиксации.

## Каталог

`A` — пишется в ленту активности; `W` — ставится в outbox для webhooks. `data` webhook
содержит только идентификаторы, перечисления и счётчики; имена, mime-типы и префиксы
токенов остаются в контексте activity.

| `EventType` | Класс | Subject | `data` webhook | A | W |
|---|---|---|---|---|---|
| `drive.created` | `Vaulter\Drive\Events\Drives\DriveCreated` | drive | `root_node_id`, `profile` | ✓ | ✓ |
| `drive.archived` | `Vaulter\Drive\Events\Drives\DriveArchived` | drive | — | ✓ | ✓ |
| `drive.unarchived` | `Vaulter\Drive\Events\Drives\DriveUnarchived` | drive | — | ✓ | ✓ |
| `drive.deleted` | `Vaulter\Drive\Events\Drives\DriveDeleted` | drive | — | ✓ | ✓ |
| `folder.created` | `Vaulter\Drive\Events\Nodes\FolderCreated` | node | `parent_id` | ✓ | ✓ |
| `file.uploaded` | `Vaulter\Drive\Events\Files\FileUploaded` | node | `parent_id`, `revision_id`, `size` | ✓ | ✓ |
| `file.revision_added` | `Vaulter\Drive\Events\Files\FileRevisionAdded` | node | `revision_id`, `number`, `size` | ✓ | ✓ |
| `file.revision_deleted` | `Vaulter\Drive\Events\Files\FileRevisionDeleted` | node | `revision_id`, `number` | ✓ | ✓ |
| `node.renamed` | `Vaulter\Drive\Events\Nodes\NodeRenamed` | node | — | ✓ | ✓ |
| `node.moved` | `Vaulter\Drive\Events\Nodes\NodeMoved` | node | `from_parent_id`, `to_parent_id` | ✓ | ✓ |
| `node.copied` | `Vaulter\Drive\Events\Nodes\NodeCopied` | node (копия) | `source_id`, `destination_id` | ✓ | ✓ |
| `node.trashed` | `Vaulter\Drive\Events\Nodes\NodeTrashed` | node | — | ✓ | ✓ |
| `node.restored` | `Vaulter\Drive\Events\Nodes\NodeRestored` | node | `parent_id` | ✓ | ✓ |
| `node.purged` | `Vaulter\Drive\Events\Nodes\NodePurged` | drive | `node_ids` (до 1000), `node_count`, `freed_bytes` | ✓ | ✓ |
| `upload.initiated` / `upload.aborted` / `upload.expired` | `Vaulter\Drive\Events\Uploads\*` | upload | `upload_id`, `size` | ✓ | — |
| `document.created` | `Vaulter\Documents\Events\Documents\DocumentCreated` | node | `type`, `revision_id`, `state_version`, `epoch` | ✓ | ✓ |
| `document.updated` | `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | node | `revision_id` (только при checkpoint), `checkpoint`, `state_version`, `epoch` | — | только checkpoint |
| `document.revision_created` | `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` | node | `revision_id`, `state_version`, `epoch`, `restored` | ✓ | ✓ |
| `document.restored` | `Vaulter\Documents\Events\Revisions\DocumentRestored` | node | `from_revision_id`, `revision_id`, `state_version`, `epoch` | ✓ | ✓ |
| `comment.added` / `comment.resolved` / `comment.deleted` | `Vaulter\Documents\Events\Comments\*` | comment | `comment_id`, `node_id` | ✓ | ✓ |
| `grant.created` | `Vaulter\Events\Access\NodeGrantCreated` | node | `grant_id`, `subject {type, id}`, `role`, `role_version`, `scope` | ✓ | ✓ |
| `grant.revoked` | `Vaulter\Events\Access\NodeGrantRevoked` | node | `subject {type, id}`, `revoked` | ✓ | ✓ |
| `share.created` | `Vaulter\Events\Sharing\NodeShareCreated` | share | `node_id`, `role`, `role_version`, `audience`, `scope`, `expires_at` | ✓ | ✓ |
| `share.revoked` | `Vaulter\Events\Sharing\NodeShareRevoked` | share | `node_id` | ✓ | ✓ |

Каждое сохранение содержимого документа засорило бы ленту, поэтому `document.updated`
не аудируется; webhooks получают сохранения, которые сделали checkpoint, а ваши
слушатели — каждое сохранение (`$event->created` говорит, какое). Документные события
несут `state_version` и `epoch` документа после записи — из результата writer; их
`sequence` равен `null`, потому что содержимое не двигает sequence узла. См.
[Конкурентность](./concurrency.md).

## Конструкторы

Каждый конструктор заканчивается на `ActorRef $actor, string $correlationId, ?string $eventId = null, ?CarbonImmutable $occurredAt = null`; в таблице — параметры перед ними.

| Событие | Начальные параметры |
|---|---|
| `DriveCreated` | `Drive $storage, Node $root` |
| `DriveArchived`, `DriveUnarchived` | `Drive $drive` |
| `DriveDeleted` | `string $driveId, string $ownerType, string $ownerId, int\|string\|null $tenantId, string $profile` |
| `FolderCreated`, `NodeTrashed`, `NodeRestored` | `Node $node` |
| `FileUploaded` | `Node $node, File $file, FileRevision $version` |
| `FileRevisionAdded` | `Node $node, File $file, FileRevision $version, int $versionNo` |
| `FileRevisionDeleted` | `Node $node, File $file, int\|string $revisionId, int $revisionNo, int $sizeBytes` |
| `NodeRenamed` | `Node $node, string $fromName, string $toName` |
| `NodeMoved` | `Node $node, int\|string\|null $fromParentId, int\|string\|null $toParentId` |
| `NodeCopied` | `Node $source, Node $copy` |
| `NodePurged` | `int\|string $storageId, array $nodeIds, int $freedBytes, int\|string\|null $tenantId, string $profile` |
| `UploadInitiated`, `UploadAborted`, `UploadExpired` | `UploadSession $session` |
| `DocumentCreated` | `Node $node, DocumentRevision $version, int $stateVersion, int $epoch` |
| `DocumentContentUpdated` | `Node $node, DocumentRevision $version, bool $created, int $stateVersion, int $epoch` |
| `DocumentRevisionCreated` | `Node $node, DocumentRevision $version, int $stateVersion, int $epoch, bool $restored` |
| `DocumentRestored` | `Node $node, DocumentRevision $version, DocumentRevision $restoredFrom, int $epoch, int $stateVersion` |
| `CommentAdded`, `CommentResolved`, `CommentDeleted` | `NodeComment $comment` |
| `NodeGrantCreated` | `NodeGrant $grant` |
| `NodeGrantRevoked` | `Node $node, Subject $subject, int $revoked` |
| `NodeShareCreated`, `NodeShareRevoked` | `NodeShare $share` |

- `DriveDeleted` и `NodePurged` несут идентификаторы, а не модели: их строк уже нет.
- `NodeShare*` — канал публичных ссылок на узел (`v_node_shares`); см.
  [ADR-0013](/adr/0013-two-sharing-channels) и [Использование → Публикация](/ru/guide/sharing).

## Трансляция комментариев

Три события комментариев публикуются в `PrivateChannel` с именем
`{collaboration.channel_prefix}.{nodeId}` (префикс по умолчанию `vaulter.documents`) —
ту же комнату, к которой подключаются открытые редакторы для совместной работы.

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

## См. также

- [Webhooks](/guide/webhooks) — конверт, доставка и дедупликация (на английском)
- [Справочник команд](/ru/reference/commands) — планируемые relay/retry/GC
- [Справочник конфигурации](/ru/reference/configuration) — `features.activity`, `features.webhooks`, `webhooks.*`
- [Справочник контрактов](/ru/reference/contracts) — модели, которые несут эти payload
