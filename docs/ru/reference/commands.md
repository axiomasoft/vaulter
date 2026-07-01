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
| `vaulter:make-panel` | Сгенерировать класс-провайдер Storage Panel |
| `vaulter:panel:migrations` | Сгенерировать per-panel миграции для панели с собственными таблицами |
| `vaulter:gc-blobs` | Двухфазная, устойчивая к гонкам сборка мусора блобов |
| `vaulter:reconcile-blobs` | Пересчитать `ref_count` блобов и сообщить о висячих строках |
| `vaulter:purge-trash` | Окончательно удалить узлы из корзины по истечении срока хранения |
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

## `vaulter:make-panel`

Сгенерировать в `app/Vaulter/Panels` класс `StoragePanelProvider`, принадлежащий
приложению, из stub-заготовки. Она **не** трогает опубликованный конфиг и **не**
генерирует миграции — панель по умолчанию представляет собой конфиг-конверт без
миграций поверх общей схемы `v_*`. Регистрация FQCN провайдера в
`config('vaulter.panels')` — это осознанный следующий шаг, который команда выводит на экран.

```
vaulter:make-panel
    {name}        Идентификатор панели (snake/kebab), например project_drive
    {--host=}     FQCN хост-модели, в которую встраивается это хранилище
    {--prefix=}   Префикс имён таблиц — включает продвинутый режим собственных таблиц
    {--no-grants} Сгенерировать панель без грантов на узлы
    {--id=}       Стратегия первичного ключа для этой панели (ulid|uuid7|bigint) — только для собственных таблиц
    {--force}     Перезаписать провайдер, если он уже существует
```

```bash
php artisan vaulter:make-panel project_drive --host="App\Models\Project"
```

См. [Концепции → Storage Panels](/ru/concepts/storage-panels).

## `vaulter:panel:migrations`

Сгенерировать per-panel Drive-миграцию для панели, включившей продвинутый режим
собственных таблиц. Резолвит панель из реестра и передаёт её в
`PanelMigrationGenerator`. Панель, пишущая в общую схему `v_*`, отклоняется —
маршруту по умолчанию не нужен сгенерированный файл. Результат кладётся в
`config('vaulter.migrations.path')` (или в `database/migrations`, если значение null).

```
vaulter:panel:migrations
    {name}    Идентификатор панели, чьи per-panel таблицы нужно сгенерировать
    {--force} Перезаписать существующую миграцию для этой панели
```

```bash
php artisan vaulter:panel:migrations project_drive
```

## `vaulter:gc-blobs`

Двухфазная, устойчивая к гонкам сборка мусора блобов (без учёта ACL, в системном
контексте). Помечает нессылаемые зафиксированные блобы как tombstoned с крайним сроком
grace, затем сметает tombstoned-блобы, у которых grace истёк, в физическое удаление.
Окно grace берётся из `config('vaulter.gc.grace_hours')` (с нижней границей в 1 час),
если его не переопределяет `--grace`.

```
vaulter:gc-blobs
    {--grace=}  Переопределить окно grace для GC в часах (нижняя граница — 1 час)
    {--pending} Также вымести PENDING-блобы, зависшие на этапе staging после сбоя
```

```bash
php artisan vaulter:gc-blobs            # tombstone + sweep по истечении grace
php artisan vaulter:gc-blobs --grace=48 # своё окно grace
php artisan vaulter:gc-blobs --pending  # также очистить зависшие staging-строки
```

::: danger Sweep удаляет байты
Фаза sweep физически удаляет байты блоба. Держите `grace_hours` ≥ вашей худшей задержки
фиксации multipart, чтобы незавершённая загрузка успела воскресить tombstoned-блоб.
:::

См. [Рецепты → Сборка мусора блобов](/ru/guide/recipes).

## `vaulter:reconcile-blobs`

Пересчитать `ref_count` блобов из счётчиков рёбер в `v_file_version_blobs` и сообщить о
висячих строках. Именно ребро — а не `ref_count` — является истиной о живости; эта
команда чинит согласованный кэш.

```
vaulter:reconcile-blobs
```

```bash
php artisan vaulter:reconcile-blobs
```

## `vaulter:purge-trash`

Окончательно вычистить узлы из корзины, у которых истекло окно хранения. Находит **корни**
корзины, чей `trashed_at` старше `now() - retention_days`, и принудительно удаляет каждое
поддерево — так рёбра версия↔блоб отсоединяются (байты становятся доступны для GC), а квота
освобождается. Узел в корзине, чей родитель тоже в корзине, пропускается (без двойной чистки).

```
vaulter:purge-trash
    {--days=} Переопределить окно хранения (конфиг vaulter.trash.retention_days)
```

```bash
php artisan vaulter:purge-trash           # использует хранение из конфига (по умолчанию 30 дней)
php artisan vaulter:purge-trash --days=7  # своё окно хранения
```

См. [Использование → Поиск и корзина](/ru/guide/search-trash).

## `vaulter:sweep-uploads`

Прервать и подчистить протухшие сессии резюмируемой загрузки, у которых истёк TTL
(`config('vaulter.uploads.session_ttl_hours')`). Временный staged-файл и любое состояние
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
`features.reliability`.

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
`features.reliability`.

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
Schedule::command('vaulter:gc-blobs')->daily();
Schedule::command('vaulter:purge-trash')->daily();
Schedule::command('vaulter:reconcile-blobs')->weekly();
```

## См. также

- [Справочник по конфигурации](/ru/reference/configuration) — `gc.*`, `trash.*`, `uploads.*`, `webhooks.*`, `features.reliability`
- [Справочник по событиям](/ru/reference/events) — что разворачивает релей
- [Фасад и Manager](/ru/reference/facade) — рантайм-API
