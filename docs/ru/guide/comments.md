# Комментарии

Документы поддерживают ветвящиеся, при желании привязанные к тексту комментарии. Чтобы оставить
комментарий, нужно право (ability) **`comment`** из вашего `PermissionResolver`; для
разрешения комментария требуется `comment`; для удаления — `manage`. Новые комментарии
транслируются в приватный канал документа (`comment.added`), поэтому открытые редакторы
показывают их вживую.

## API

| Метод | URL | Право (ability) |
|---|---|---|
| GET | `/documents/{id}/comments` | `view` |
| POST | `/documents/{id}/comments` | `comment` |
| POST | `/documents/{id}/comments/{commentId}/resolve` | `comment` |
| DELETE | `/documents/{id}/comments/{commentId}` | `manage` |

Комментарий несёт `author` (аутентифицированный пользователь, полиморфно), `body`,
опциональный `anchor` (JSON с описанием диапазона текста, к которому он привязан),
`parent_id` для веток и метку времени `resolved_at`.

## Фронтенд

```ts
import { useComments } from '@vaulter/vue'

const { comments, list, add, resolve } = useComments(documentId)

await list()
await add({ body: 'Needs a citation', anchor: { from: 10, to: 24 } })
```

Или подключите готовую панель со слотами:

```vue
<VaulterCommentsPanel :document-id="documentId" @added="scrollToBottom">
  <template #item="{ comment, resolve }">
    <CommentBubble :comment="comment" @resolve="resolve" />
  </template>
</VaulterCommentsPanel>
```

## События

| Событие | Тип | Когда |
|---|---|---|
| `Vaulter\Events\CommentAdded` | broadcast (`comment.added`) | комментарий опубликован |
| `Vaulter\Events\CommentResolved` | domain (после коммита) | комментарий разрешён |

Слушайте эти события в своём приложении, чтобы отправлять уведомления или отражать активность.
