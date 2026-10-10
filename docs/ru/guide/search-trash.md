# Поиск и корзина

## Поиск

Дерево узлов drive принимает терм `q`, который сопоставляется (без учёта регистра) с именем
узла, ограничен рамками drive и отфильтрован по праву (ability) `view`:

```
GET /api/vaulter/v1/drives/{drive}/search?q=roadmap
```

Страницы несут `meta.next_cursor` и `meta.per_page` (1–200, по умолчанию 50; вне диапазона —
`422`), без `total` и номеров страниц: передавайте `cursor` из предыдущего ответа, пока
`next_cursor` не станет `null`.

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

Перенос узла в корзину переводит его (и его поддерево) в мягкое удаление (soft-delete).
Корзину можно просмотреть, восстановить из неё узел или окончательно вычистить. Маршрутам с
пометкой If-Match нужен текущий `ETag` узла (`428` без него, `412` при устаревшем); для узла в
корзине это `etag` из списка корзины.

| Метод | URL (под `/api/vaulter/v1`) | Право (ability) | If-Match |
|---|---|---|---|
| GET | `/drives/{drive}/trash` | `view` | — |
| POST | `/nodes/{node}/trash` | `trash` | да |
| POST | `/nodes/{node}/restore` | `restore` | да |
| DELETE | `/nodes/{node}` | `delete` (только узлы в корзине) | да |
| DELETE | `/drives/{drive}/trash` | `delete` на каждом корне | — |

```ts
const client = useVaulterClient()

const trashed = await client.nodes.trashed(storageId)
await client.nodes.trash(nodeId)        // обратимое мягкое удаление
await client.nodes.restore(nodeId)      // вернуть обратно
await client.nodes.forceDelete(nodeId)  // окончательно
```

И обратимое удаление, и окончательная вычистка записываются в
[ленту активности](/ru/reference/rest-api#лента-активности), поэтому ваше приложение может по-разному учитывать жёсткие
удаления в журналах аудита.

## Перемещение и копирование

| Метод | URL (под `/api/vaulter/v1`) | Право (ability) | If-Match |
|---|---|---|---|
| POST | `/nodes/{node}/move` | `move` + `create` (назначение) | да |
| POST | `/nodes/{node}/copy` | `copy` (источник) + `create` (назначение) | — |

`move` и `copy` принимают `{ destination_id: id \| null }` (null — корень drive); `move` ещё
принимает `on_conflict` (`fail` или `rename`). Перемещение узла в собственное поддерево
отклоняется; `copy` клонирует узел (а для папки — её поддерево) в назначение и при совпадении
имени берёт свободное.

```ts
const client = useVaulterClient()

await client.nodes.move(nodeId, folderId)   // или move(nodeId, null), чтобы вынести из папки
const copy = await client.nodes.copy(nodeId, folderId)
```

Для листинга и операций с папками на реактивном состоянии используйте
[composable `useNodes`](./vue.md#drive-composables).
