---
title: Корзина и восстановление
description: "Жизненный цикл корзины Vaulter — мягкое удаление, восстановление, безвозвратное удаление, окна хранения и команда vaulter:trash:purge."
outline: deep
---

# Корзина и восстановление

Удаление узла в Drive по умолчанию **обратимо**: `trash()` мягко удаляет
узел (и его поддерево), который затем можно вывести списком, восстановить или —
когда истечёт окно хранения — безвозвратно вычистить. Эта страница описывает
такой жизненный цикл и команду `vaulter:trash:purge`, которая применяет политику хранения.

::: tip Два вида удаления
`trash()` обратимо (выставляет `trashed_at`). `forceDelete()` безвозвратно и
отвязывает байты. В коде приложения везде предпочитайте `trash()` и позвольте
плановой чистке применить вашу политику хранения — см. [Drive · forceDelete](/ru/concepts/nodes-and-revisions#forcedelete).
:::

## Жизненный цикл

```
живой узел ──trash()──▶ в корзине (trashed_at выставлен) ──restore()──▶ живой узел
                              │
                              └── истекло хранение ──▶ vaulter:trash:purge ──▶ удалён
```

Узел в корзине сохраняет свою строку (и поддерево) до тех пор, пока его либо не
восстановят, либо не истечёт окно хранения и команда чистки не удалит его
безвозвратно.

### Корни корзины

У каждого удалённого узла есть `trash_root_id` — узел, с которого началось удаление. Корень
указывает на себя, удалённые вместе с ним — на корень. Корзина показывает **только корни**, срок
хранения считается от корня, а восстановление возвращает **только узлы, удалённые вместе с этим
корнем**: потомок, который раньше был удалён отдельно, остаётся в корзине со своим корнем.
Чистка корня удаляет всё его поддерево, включая такие ранее удалённые потомки, лежащие внутри
(как безвозвратное удаление папки в Drive или Box).

### Отправка в корзину

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($project);

$drive->trash($node);          // мягкое удаление — диспатчит NodeTrashed
```

Отправка папки в корзину отправляет туда всё её поддерево. Узел исчезает из `list()`
и поиска, но остаётся в представлении корзины.

### Восстановление

```php
$drive->restore($node);        // обратно в живые — диспатчит NodeRestored
```

Узел возвращается в папку, из которой был удалён. Если её больше нет или она сама в корзине,
восстановление **не переносит узел молча**, а бросает `RestoreDestinationRequiredException`
(409 `restore_destination_required`). Передайте назначение явно — оно проверяется как создание
(`Create` на папке, активная папка диска):

```php
$drive->trash()->restore($node, new RestoreOptions(destination: $folder));
```

Восстановить можно только корень корзины: узел, удалённый вместе с папкой, даёт
`NodeTrashedByAncestorException` (409 `node_trashed_by_ancestor`, `context.trash_root_id`) —
восстановите корень. При конфликте имени решает `OnNameConflict` (по умолчанию `Rename`).

### Безвозвратное удаление

```php
$drive->forceDelete($node);    // безвозвратно — диспатчит NodePurged
```

Безвозвратное удаление отвязывает каждое ребро версия↔блоб в поддереве (байты
становятся кандидатами на [GC](/ru/guides/recipes)) и освобождает квоту хранилища.

::: danger Необратимо
`forceDelete()` нельзя отменить. «Восстановления после чистки» не существует.
:::

## Хранение

Сколько узел в корзине живёт, прежде чем станет кандидатом на чистку, задаётся
параметром `config('vaulter-drive.trash.retention_days')`:

```php
// config/vaulter-drive.php
'trash' => [
    'retention_days' => 30,   // по умолчанию 30; 0 = чистить сразу после отправки в корзину
],
```

- **По умолчанию:** 30 дней.
- **Нижняя граница:** 0. `0` означает, что узел в корзине становится кандидатом на чистку немедленно.

Значение читается через `Vaulter\Drive\Configuration\DriveConfig::trashRetentionDays()`.

## Плановая чистка

Безвозвратно удалить узлы в корзине, у которых истекло окно хранения, можно
Artisan-командой **`vaulter:trash:purge`**:

```bash
php artisan vaulter:trash:purge
```

Она находит **корни** корзины, чей `trashed_at` старше
`now() - retention_days`, и безвозвратно удаляет каждое поддерево.

::: info Обрабатываются только корни корзины
Узел в корзине, чей родитель *тоже* в корзине, пропускается — чистка поддерева
предка уже покрывает его (без двойной чистки).
:::

### Переопределение окна на конкретный запуск

Опция `--days` переопределяет настроенное хранение для одного запуска (с нижней
границей 0):

```bash
# Вычистить всё, что попало в корзину более 7 дней назад, игнорируя значение из конфига.
php artisan vaulter:trash:purge --days=7

# Вычистить всё, что сейчас в корзине.
php artisan vaulter:trash:purge --days=0
```

### Планирование

Запускайте чистку по расписанию, чтобы хранение применялось автоматически:

::: code-group
```php [routes/console.php]
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:trash:purge')->daily();
```

```php [Console Kernel]
protected function schedule(Schedule $schedule): void
{
    $schedule->command('vaulter:trash:purge')->daily();
}
```
:::

::: warning Чистка освобождает байты, GC их возвращает
`vaulter:trash:purge` отвязывает рёбра версия↔блоб, но сами CAS-объекты
возвращает отдельный проход **blob GC**. Планируйте оба — см.
[Blob GC](/ru/guides/recipes).
:::

## Представление корзины (REST)

Корни корзины drive выводятся списком (курсорная пагинация) через REST v1 в пределах видимых
вызывающему. `etag` каждого элемента — тег, который ждут восстановление и окончательное
удаление: удалённый в корзину узел не читается через `GET v1/nodes/{node}`:

```http
GET /api/vaulter/v1/drives/{drive}/trash
POST /api/vaulter/v1/nodes/{node}/restore      If-Match: <etag из списка>
DELETE /api/vaulter/v1/nodes/{node}            If-Match: <etag из списка>
```

Восстановление или окончательное удаление с устаревшим тегом отвечает
`412 node_sequence_mismatch` и ничего не меняет — окончательное удаление, в частности, не
удаляет ни строк, ни квоты, ни сохранённых байтов. То же ожидание есть в PHP:
`$drive->trash()->purge($node, expectedSequence: $snapshot->sequence)` и
`new RestoreOptions(expectedSequence: $snapshot->sequence)`.

В браузере `useNodes` даёт представление корзины и восстановление:

```ts
import { useNodes } from '@vaulter/vue'

const { trashed, restore } = useNodes(storageId)

await trashed()             // листинг корзины
await restore(nodeId)       // вернуть узел обратно
```

Полный контракт маршрута — параметры, форма ответа и права-гейты `Restore`/`Delete` —
в [справочнике REST API](/ru/reference/rest-api#корзина).

## См. также

- [Drive](/ru/concepts/nodes-and-revisions) — `trash()` / `restore()` / `forceDelete()` на хэндле
- [Справочник REST API](/ru/reference/rest-api#drive) — маршруты корзины и восстановления
- [Blob GC](/ru/guides/recipes) — возврат байтов, которые отвязывает чистка

## Поиск и корзина

### Поиск

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

### Корзина

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
[ленту активности](/ru/reference/rest-api#активность), поэтому ваше приложение может по-разному учитывать жёсткие
удаления в журналах аудита.

### Перемещение и копирование

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
[composable `useNodes`](./vue.md#composable-функции).
