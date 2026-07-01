# Поиск и корзина

## Поиск

Дерево узлов storage принимает терм `q`, который сопоставляется (без учёта регистра) с именем
узла, ограничен рамками storage и отфильтрован по праву (ability) `view`:

```
GET /api/vaulter/storages/{storage}/search?q=roadmap
```

```ts
import { useVaulterClient } from '@vaulter/vue'

const client = useVaulterClient()
const page = await client.nodes.search(storageId, 'roadmap')
```

::: tip Масштабирование поиска
Поиск по умолчанию использует скан `LIKE` — этого достаточно для типичных storage. Для больших
объёмов данных подключите настоящий поисковый индекс (например, Laravel Scout / Meilisearch),
указав в `config('vaulter.models.node')` модель с поддержкой поиска и переопределив
read-репозиторий.
:::

## Корзина

Удаление узла переводит его (и его поддерево) в мягкое удаление (soft-delete). Корзину можно
просмотреть, восстановить из неё узел или окончательно вычистить.

| Метод | URL | Право (ability) |
|---|---|---|
| GET | `/storages/{storage}/trash` | `view` |
| DELETE | `/nodes/{node}` | `edit` |
| POST | `/nodes/{node}/restore` | `edit` |
| DELETE | `/nodes/{node}/force` | `manage` |

```ts
const client = useVaulterClient()

const trashed = await client.nodes.trashed(storageId)
await client.nodes.trash(nodeId)        // обратимое мягкое удаление
await client.nodes.restore(nodeId)      // вернуть обратно
await client.nodes.forceDelete(nodeId)  // окончательно
```

И обратимое удаление, и окончательная вычистка записываются в
[ленту активности](./activity.md), поэтому ваше приложение может по-разному учитывать жёсткие
удаления в журналах аудита.

## Перемещение и копирование

| Метод | URL | Право (ability) |
|---|---|---|
| POST | `/nodes/{node}/move` | `edit` |
| POST | `/nodes/{node}/copy` | `edit` (источник) + `create` (назначение) |

`move` и `copy` принимают `{ parent_id: id \| null }` (null — корень storage). Перемещение узла в
собственное поддерево отклоняется с `422`; `copy` клонирует узел (а для папки — её поддерево) в
назначение.

```ts
const client = useVaulterClient()

await client.nodes.move(nodeId, folderId)   // или move(nodeId, null), чтобы вынести из папки
const copy = await client.nodes.copy(nodeId, folderId)
```

Для листинга и операций с папками на реактивном состоянии используйте
[composable `useNodes`](./vue.md#drive-composables).
