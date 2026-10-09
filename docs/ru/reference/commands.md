---
title: Artisan-команды
description: Все Artisan-команды Vaulter — сигнатуры, опции и что они делают.
outline: deep
---

# Artisan-команды

Vaulter регистрирует девять Artisan-команд: команду установки (из
spatie/laravel-package-tools) плюс восемь команд пакета, подключённых в
`VaulterServiceProvider`. Команды обслуживания (сборка мусора, очистка корзины, чистка
загрузок, релей outbox, повтор вебхуков) рассчитаны на запуск по расписанию.

| Команда | Назначение |
|---|---|
| `vaulter:install` | Опубликовать конфиг + миграции, выполнить их, предложить поставить репозиторию звезду |
| `vaulter:make:profile` | Сгенерировать класс-провайдер Storage Panel |
| `vaulter:blobs:gc` | Двухфазная, устойчивая к гонкам сборка мусора блобов |
| `vaulter:blobs:reconcile` | Пересчитать `ref_count` блобов и сообщить о висячих строках |
| `vaulter:purge-trash` | Окончательно удалить узлы из корзины по истечении срока хранения |
| `vaulter:revisions:prune` | Удалить нетекущие ревизии файлов сверх политики ретеншна профиля |
| `vaulter:sweep-uploads` | Прервать и подчистить протухшие сессии резюмируемой загрузки |
| `vaulter:relay-outbox` | Передать ожидающие события outbox на эндпоинты вебхуков |
| `vaulter:retry-webhooks` | Повторно отправить доставки вебхуков, у которых истёк backoff |

---

## `vaulter:install`

Предоставляется spatie/laravel-package-tools. Публикует файл конфига и миграции,
предлагает выполнить миграции и поставить репозиторию звезду на GitHub.

```bash
php artisan vaulter:install
```

Эквивалентные ручные шаги:

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-migrations
php artisan migrate
```

См. [Руководство → Установка](/ru/guide/installation).

## `vaulter:make:profile`

Сгенерировать в `app/Vaulter/Profiles` класс `ProfileProvider`, принадлежащий
приложению, из stub-заготовки. Она **не** трогает опубликованный конфиг и **не**
генерирует миграции — панель по умолчанию представляет собой конфиг-конверт без
миграций поверх общей схемы `v_*`. Регистрация FQCN провайдера в
`config('vaulter.profiles.providers')` — это осознанный следующий шаг, который команда выводит на экран.

```
vaulter:make:profile
    {name}        Идентификатор панели (snake/kebab), например project_drive
    {--host=}     FQCN хост-модели, в которую встраивается это хранилище
    {--no-grants} Сгенерировать панель без грантов на узлы
    {--force}     Перезаписать провайдер, если он уже существует
```

```bash
php artisan vaulter:make:profile project_drive --host="App\Models\Project"
```

См. [Концепции → Storage Panels](/ru/concepts/storage-panels).


## `vaulter:blobs:gc`

Двухфазная, устойчивая к гонкам сборка мусора блобов (без учёта ACL, в системном
контексте). Помечает нессылаемые зафиксированные блобы как tombstoned с крайним сроком
grace, затем сметает tombstoned-блобы, у которых grace истёк, в физическое удаление.
Окно grace берётся из `config('vaulter-media.gc.grace_hours')` (с нижней границей в 1 час),
если его не переопределяет `--grace`.

```
vaulter:blobs:gc
    {--grace=}  Переопределить окно grace для GC в часах (нижняя граница — 1 час)
    {--pending} Также вымести PENDING-блобы, зависшие на этапе staging после сбоя
```

```bash
php artisan vaulter:blobs:gc            # tombstone + sweep по истечении grace
php artisan vaulter:blobs:gc --grace=48 # своё окно grace
php artisan vaulter:blobs:gc --pending  # также очистить зависшие staging-строки
```

::: danger Sweep удаляет байты
Фаза sweep физически удаляет байты блоба. Держите `grace_hours` ≥ вашей худшей задержки
фиксации multipart, чтобы незавершённая загрузка успела воскресить tombstoned-блоб.
:::

См. [Рецепты → Сборка мусора блобов](/ru/guide/recipes).

## `vaulter:blobs:reconcile`

Пересчитать `ref_count` блобов из счётчиков рёбер в `v_file_version_blobs` и сообщить о
висячих строках. Именно ребро — а не `ref_count` — является истиной о живости; эта
команда чинит согласованный кэш.

```
vaulter:blobs:reconcile
```

```bash
php artisan vaulter:blobs:reconcile
```

## `vaulter:purge-trash`

Окончательно вычистить узлы из корзины, у которых истекло окно хранения. Находит **корни**
корзины, чей `trashed_at` старше `now() - retention_days`, и принудительно удаляет каждое
поддерево — так рёбра версия↔блоб отсоединяются (байты становятся доступны для GC), а квота
освобождается. Подходят только корни (`trash_root_id = id`): чистка корня удаляет всё его
поддерево, включая ранее удалённых отдельно потомков, поэтому ничего не чистится дважды.

```
vaulter:purge-trash
    {--days=} Переопределить окно хранения (конфиг vaulter-drive.trash.retention_days)
```

```bash
php artisan vaulter:purge-trash           # использует хранение из конфига (по умолчанию 30 дней)
php artisan vaulter:purge-trash --days=7  # своё окно хранения
```

См. [Использование → Поиск и корзина](/ru/guide/search-trash).

## `vaulter:drives:purge`

Запустить или продолжить чистку дисков со статусом `deleting` (PLAN2.D27). Чистка идемпотентна:
повторный запуск после сбоя доделывает работу — прерывает открытые загрузки, чистит детей корня
пачками по 100 через `PurgeNodeAction` от system-актора `drive-purge`, затем удаляет корень и
строку диска. Activity и outbox остаются.

```
vaulter:drives:purge
    {--drive=*}   Только эти id дисков
    {--dry-run}   Напечатать число узлов по дискам и ничего не менять
    {--sync}      Чистить в этом процессе, а не ставить PurgeDriveJob в очередь
```

## `vaulter:drives:recount`

Пересчитать `used_bytes` дисков по сохранённым ревизиям файлов, включая корзину (PLAN2.D48), под
эксклюзивным tree-lock каждого диска, чтобы параллельная загрузка или удаление ревизии не были
затёрты устаревшей суммой. Сообщает и логирует найденное расхождение; повторный запуск ничего не
меняет. Работает как обслуживание вне tenant, только по названным дискам.

```
vaulter:drives:recount
    {--drive=*}   Только эти id дисков
    {--dry-run}   Показать расхождение, не исправляя
```

## `vaulter:revisions:prune`

Применить `RevisionRetentionPolicy` профиля каждого диска (PLAN2.D48): удалить нетекущие ревизии
каждого файла — в том числе файлов в корзине — сверх `maxNoncurrentRevisions` или старше
`maxNoncurrentAgeDays`, но никогда не голову, не закреплённую ревизию и не ревизию, замещённую
менее `minNoncurrentAgeDays` назад. Каждое удаление отцепляет рёбра блобов (байты становятся
доступны для GC) и освобождает квоту. Диск, на котором произошла ошибка, пишется в лог, остальные
обрабатываются.

```
vaulter:revisions:prune
    {--drive=*}  Только эти id дисков
    {--dry-run}  Показать, что было бы удалено
```

```bash
php artisan vaulter:revisions:prune --dry-run
php artisan vaulter:revisions:prune          # планируйте ежедневно
```

См. [Использование → Загрузки → Ретеншн ревизий](/ru/guide/uploads#квота-и-ретеншн-ревизий).

## `vaulter:sweep-uploads`

Прервать и подчистить протухшие сессии резюмируемой загрузки, у которых истёк TTL
(`config('vaulter-drive.uploads.resumable.session_ttl_hours')`). Временный staged-файл и любое состояние
S3 multipart высвобождаются, а статус сессии переключается на `expired`.

```
vaulter:sweep-uploads
```

```bash
php artisan vaulter:sweep-uploads
```

::: tip Совет: свяжите с lifecycle-правилом S3
Sweeper прерывает те незавершённые multipart-загрузки, о которых знает. Добавьте
lifecycle-правило `AbortIncompleteMultipartUpload` как страховку для тех, о которых он не знает.
:::

См. [Использование → Загрузки](/ru/guide/uploads).

## `vaulter:relay-outbox`

Передать ожидающие события outbox на эндпоинты вебхуков и повторно отправить назревшие
повторы. Каждая ожидающая строка `v_outbox` разворачивается в одну подписанную строку
`v_webhook_deliveries` на каждый подписанный активный эндпоинт, и HTTP POST ставится в
очередь. Гарантия at-least-once с экспоненциальным backoff + DLQ. Управляется флагом
`features.webhooks`.

```
vaulter:relay-outbox
    {--limit=} Максимум строк outbox, которые нужно слить за этот тик
```

```bash
php artisan vaulter:relay-outbox --limit=500
```

См. [Рецепты → Надёжность и вебхуки](/ru/guide/recipes).

## `vaulter:retry-webhooks`

Повторно отправить доставки вебхуков, у которых истекло окно backoff. Управляется флагом
`features.webhooks`.

```
vaulter:retry-webhooks
```

```bash
php artisan vaulter:retry-webhooks
```

---

## Планирование по расписанию

Пять команд обслуживания дружелюбны к расписанию. Показательный график:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay-outbox')->everyMinute();
Schedule::command('vaulter:retry-webhooks')->everyFiveMinutes();
Schedule::command('vaulter:sweep-uploads')->hourly();
Schedule::command('vaulter:blobs:gc')->daily();
Schedule::command('vaulter:purge-trash')->daily();
Schedule::command('vaulter:blobs:reconcile')->weekly();
```

## См. также

- [Справочник по конфигурации](/ru/reference/configuration) — `vaulter-media.gc.*`, `vaulter-drive.trash.*`, `vaulter-drive.uploads.*`, `webhooks.*`, `features.webhooks`
- [Справочник по событиям](/ru/reference/events) — что разворачивает релей
- [Фасад и Manager](/ru/reference/facade) — рантайм-API
