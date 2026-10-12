# Комментарии

Узлы-документы поддерживают ветвящиеся, при желании привязанные к тексту комментарии. Для чтения
нужно право **`view`**; чтобы оставить комментарий — **`comment`**; для разрешения — **`comment`**
и быть **автором** комментария либо иметь **`edit`**; для удаления — быть **автором** либо иметь
**`manage`** на узле. Узел, который пользователь не видит, отвечает `404 node_not_found`. В PHP —
`Documents::comments($drive)` (`list`, `add`, `resolve`, `delete`), см.
[Узлы-документы](./documents.md#php-api). Комментарии по умолчанию включены в `vaulter-documents.comments.enabled`
и транслируются, поэтому открытые редакторы показывают их вживую.

## API

| Метод | URL | Право (ability) |
|---|---|---|
| GET | `/v1/nodes/{node}/comments` | `view` |
| POST | `/v1/nodes/{node}/comments` | `comment` |
| POST | `/v1/nodes/{node}/comments/{comment}/resolve` | `comment`, и автор или `edit` |
| DELETE | `/v1/nodes/{node}/comments/{comment}` | автор или `manage` |

Маршруты зарегистрированы всегда, переключатель проверяют операции. При выключенных комментариях
(глобально или в профиле диска) видимый узел отвечает `403 profile_restriction`, невидимый — как
обычно `404 node_not_found`. Список — курсорная страница (`?cursor=&per_page=`, сначала старые),
`POST` отвечает `201`, а `DELETE` — `204`; комментарий другого узла — `404 comment_not_found`.

Комментарий несёт `author` (аутентифицированный пользователь, полиморфно), `body`,
опциональный `anchor` (JSON с описанием диапазона текста, к которому он привязан),
`parent_id` для веток и метку времени `resolved_at`.

## Фронтенд

<!-- snippet: host -->

```ts
import { useComments } from '@vaulter/vue'

const { comments, list, add, resolve } = useComments(nodeId)

await list()
await add({ body: 'Needs a citation', anchor: { from: 10, to: 24 } })
```

Или подключите готовую панель со слотами:

<!-- snippet: host -->

```vue
<VaulterCommentsPanel :node-id="nodeId" @added="scrollToBottom">
  <template #item="{ comment, resolve }">
    <CommentBubble :comment="comment" @resolve="resolve" />
  </template>
</VaulterCommentsPanel>
```

## События

| Событие | Тип | Когда |
|---|---|---|
| `Vaulter\Documents\Events\Comments\CommentAdded` | broadcast | комментарий опубликован |
| `Vaulter\Documents\Events\Comments\CommentResolved` | domain (после коммита) | комментарий разрешён |
| `Vaulter\Documents\Events\Comments\CommentDeleted` | domain (после коммита) | комментарий удалён |

Слушайте эти события в своём приложении, чтобы отправлять уведомления или отражать активность.
