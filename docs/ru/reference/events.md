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

`journal` — операция журнала изменений, которую производит событие. `data` webhook содержит только идентификаторы, enum и счётчики; имена, mime-типы и префиксы токенов остаются в контексте activity. Таблица ниже генерируется из атрибута `EventSpec` каждого `EventType` командой `php artisan vaulter:events:reference` — вручную не правьте. Ключ с `?` может отсутствовать.

<!-- events:start -->
| Type | Subject | Data | Activity | Webhook | Journal | Description |
|---|---|---|---|---|---|---|
| `drive.created` | drive | `root_node_id`: `string`, `profile`: `string` | ✓ | ✓ | — | A drive was created together with its root node. |
| `folder.created` | node | `parent_id`: `string\|null` | ✓ | ✓ | upsert | A folder was created. |
| `file.uploaded` | node | `parent_id`: `string\|null`, `revision_id`: `string`, `size`: `int` | ✓ | ✓ | upsert | A file was created with its first revision. |
| `file.revision_added` | node | `revision_id`: `string`, `number`: `int`, `size`: `int` | ✓ | ✓ | upsert | A new revision became the current content of the file. |
| `upload.initiated` | upload | `upload_id`: `string`, `size`: `int` | ✓ | — | — | A resumable upload session was opened (audited only). |
| `upload.aborted` | upload | `upload_id`: `string`, `size`: `int` | ✓ | — | — | An upload session was aborted (audited only). |
| `upload.expired` | upload | `upload_id`: `string`, `size`: `int` | ✓ | — | — | An upload session expired and was reclaimed (audited only). |
| `node.moved` | node | `from_parent_id`: `string\|null`, `to_parent_id`: `string\|null` | ✓ | ✓ | upsert | A node moved to another parent. |
| `node.renamed` | node | — | ✓ | ✓ | upsert | A node was renamed; the names stay in the activity context. |
| `node.copied` | node | `source_id`: `string`, `destination_id`: `string\|null` | ✓ | ✓ | upsert | A node was copied; the subject is the copy. |
| `node.updated` | node | `changed`: `list<string>` | ✓ | ✓ | upsert | The properties of a node changed. |
| `node.trashed` | node | — | ✓ | ✓ | remove | A node and its subtree were moved to the trash. |
| `node.restored` | node | `parent_id`: `string\|null` | ✓ | ✓ | upsert | A trashed node was restored. |
| `node.purged` | drive | `node_ids?`: `list<string>`, `node_count`: `int`, `freed_bytes`: `int` | ✓ | ✓ | — | Trashed nodes were permanently deleted; node_ids is omitted above 1000 nodes. |
| `grant.created` | node | `grant_id`: `string`, `subject`: `object`, `role`: `string`, `role_version`: `int`, `scope`: `string` | ✓ | ✓ | upsert | A role was granted on a node. |
| `grant.revoked` | node | `subject`: `object`, `revoked`: `int` | ✓ | ✓ | upsert | The grants of a subject on a node were revoked. |
| `access.inheritance_changed` | node | `inherits`: `bool` | ✓ | ✓ | upsert | A node started or stopped inheriting grants from above. |
| `share.created` | share | `node_id`: `string`, `role`: `string`, `role_version`: `int`, `audience`: `string`, `scope`: `string`, `expires_at`: `string\|null` | ✓ | ✓ | — | A share link was created. |
| `share.updated` | share | `node_id`: `string`, `changed`: `list<string>` | ✓ | ✓ | — | A live share link: audience, expiry, budget or password changed. |
| `share.rotated` | share | `node_id`: `string` | ✓ | ✓ | — | The token of a share link was replaced. |
| `share.revoked` | share | `node_id`: `string` | ✓ | ✓ | — | A share link was revoked. |
| `drive.archived` | drive | — | ✓ | ✓ | — | A drive became read-only. |
| `drive.unarchived` | drive | — | ✓ | ✓ | — | An archived drive became writable again. |
| `drive.deleted` | drive | — | ✓ | ✓ | — | A drive was deleted. |
| `file.revision_deleted` | node | `revision_id`: `string`, `number`: `int` | ✓ | ✓ | — | A non-current revision was deleted. |
| `document.created` | node | `type`: `string`, `revision_id`: `string`, `state_version`: `int`, `epoch`: `int` | ✓ | ✓ | upsert | A document was created. |
| `document.updated` | node | `revision_id?`: `string`, `checkpoint`: `bool`, `state_version`: `int`, `epoch`: `int` | — | ✓ | upsert | The content of a document was saved; webhooks receive only saves that took a checkpoint. |
| `document.revision_created` | node | `revision_id`: `string`, `state_version`: `int`, `epoch`: `int`, `restored`: `bool` | ✓ | ✓ | — | A named or restored document revision was created. |
| `document.restored` | node | `from_revision_id`: `string`, `revision_id`: `string`, `state_version`: `int`, `epoch`: `int` | ✓ | ✓ | upsert | A document was restored from an earlier revision. |
| `comment.added` | comment | `comment_id`: `string`, `node_id`: `string` | ✓ | ✓ | — | A comment was added. |
| `comment.resolved` | comment | `comment_id`: `string`, `node_id`: `string` | ✓ | ✓ | — | A comment was resolved. |
| `comment.deleted` | comment | `comment_id`: `string`, `node_id`: `string` | ✓ | ✓ | — | A comment was deleted. |
<!-- events:end -->

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
| `NodeShareCreated`, `NodeShareRevoked`, `NodeShareRotated` | `NodeShare $share` |
| `NodeShareUpdated` | `NodeShare $share, array $changed` |
| `NodeUpdated` | `Node $node` |
| `NodeInheritanceChanged` | `Node $node, bool $inherits` |

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
