# Поиск и корзина

## Поиск

Список документов принимает терм `q`, который сопоставляется (без учёта регистра) с заголовком **и**
сырым содержимым, ограничен рамками workspace и отфильтрован по праву (ability) `view`:

```
GET /api/vaulter/documents?workspace_type=App\Models\Project&workspace_id=1&q=roadmap
```

```ts
import { useDocuments } from '@vaulter/vue'

const { documents, list } = useDocuments()
await list({ workspace_type: 'App\\Models\\Project', workspace_id: 1, q: 'roadmap' })
```

::: tip Масштабирование поиска
Поиск по умолчанию использует скан `LIKE` — этого достаточно для типичных workspace. Для больших
объёмов данных подключите настоящий поисковый индекс (например, Laravel Scout / Meilisearch),
указав в `config('vaulter.models.document')` модель с поддержкой поиска и переопределив
read-репозиторий.
:::

## Корзина

Удаление документа переводит его в мягкое удаление (soft-delete). Корзину можно просмотреть,
восстановить из неё документ или окончательно вычистить.

| Метод | URL | Право (ability) |
|---|---|---|
| GET | `/documents/trash` | `view` |
| POST | `/documents/{id}/restore` | `manage` |
| DELETE | `/documents/{id}/force` | `manage` |

```ts
const { trashed, listTrashed, forceDelete } = useDocuments()

await listTrashed({ workspace_type: 'App\\Models\\Project', workspace_id: 1 })
await forceDelete(documentId) // окончательно — диспатчит DocumentPurged
```

Окончательное удаление диспатчит `Vaulter\Events\DocumentPurged` (отличается от обратимого
`DocumentDeleted`), поэтому ваше приложение может по-разному учитывать жёсткие удаления в журналах
аудита.

## Перемещение и дублирование

| Метод | URL | Право (ability) |
|---|---|---|
| PATCH | `/documents/{id}/move` | `edit` |
| POST | `/documents/{id}/duplicate` | `view` + `create` |

`move` принимает `{ folder_id: id \| null }` (null перемещает в корень); перемещение в папку из
другого workspace отклоняется с `422`, а успешное перемещение диспатчит `DocumentMoved`.
`duplicate` клонирует документ (заголовок с префиксом `Copy of `) в тот же workspace/папку и
диспатчит `DocumentCreated`.

```ts
const { move, duplicate } = useDocuments()

await move(documentId, folderId)   // или move(documentId, null), чтобы вынести из папки
const copy = await duplicate(documentId)
```

## Массовые операции

`POST /documents/bulk` применяет одно действие к множеству документов, авторизуя каждый по
отдельности и сообщая, какие были обработаны, а какие пропущены (отказано / отсутствует):

```
POST /api/vaulter/documents/bulk
{ "action": "move", "ids": [1, 2, 3], "folder_id": 9 }
→ { "data": { "action": "move", "processed": [1, 3], "skipped": [2] } }
```

Действия: `delete`, `restore`, `move` (с `folder_id`), `force-delete` — каждое ограничено тем же
правом (ability), что и его одиночный аналог (`delete` / `manage` / `edit` / `manage`).

```ts
const { bulk } = useDocuments()
await bulk({ action: 'delete', ids: selectedIds })
```
