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
| `vaulter:retry-webhooks` | Повторно отправить доставки вебхуков, у которых истекла пауза повтора |
| `vaulter:prune-webhooks` | Удалить завершённые строки outbox и терминальные доставки старше срока хранения |
| `vaulter:webhooks:legacy-fanout` | Показать или переиграть строки outbox до 1.0, у которых раскрытие нельзя доказать |
| `vaulter:webhooks:redeliver` | Повторно отправить завершённые доставки вебхуков с тем же `webhook-id` |
| `vaulter:events:reference` | Перегенерировать (или проверить `--check`) таблицу каталога событий в справке |
| `vaulter:errors:reference` | Перегенерировать (или проверить `--check`) справку по кодам проблем в документации |
| `vaulter:changes:prune` | Удалить записи журнала изменений drive старше `changes.retention_days` |
| `vaulter:changes:reset` | Сделать недействительными все курсоры изменений drive — клиенты пересинхронизируются |

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
повторы. Каждая ожидающая строка `v_outbox` разворачивается в одну строку
`v_webhook_deliveries` на каждый подходящий эндпоинт, и HTTP POST ставится в очередь
после коммита этих строк. Relay берёт строку в аренду на `webhooks.relay.claim_seconds`
и пишет по `webhooks.relay.fanout_chunk` эндпоинтов за транзакцию; строка становится
`completed` вместе с последними доставками, поэтому упавший relay продолжает следующий
запуск, когда аренда истечёт. Несколько relay могут работать одновременно. Гарантия
at-least-once с экспоненциальным backoff + DLQ. Управляется флагом `features.webhooks`.
См. [Webhooks → Delivery guarantees](/guide/webhooks#delivery-guarantees).

```
vaulter:relay-outbox
    {--limit=} Максимум строк outbox, которые нужно слить за этот тик
```

```bash
php artisan vaulter:relay-outbox --limit=500
```

См. [Рецепты → Надёжность и вебхуки](/ru/guide/recipes).

## `vaulter:retry-webhooks`

Повторно отправить доставки вебхуков, у которых истекло окно backoff, и ожидающие
доставки, чей первый job потерян (никем не взяты через `webhooks.relay.claim_seconds`
после срока). Управляется флагом `features.webhooks`.

```
vaulter:retry-webhooks
```

```bash
php artisan vaulter:retry-webhooks
```

## `vaulter:prune-webhooks`

Удалить ЗАВЕРШЁННЫЕ строки outbox (`completed`, `fanned_out_at` старше срока хранения) и
ТЕРМИНАЛЬНЫЕ доставки (`delivered`/`dead`, старше срока хранения). Строка outbox в
`pending` — даже раскрытая наполовину — и доставка `pending`/`failed` не удаляются никогда,
сколько бы им ни было. Управляется флагом `features.webhooks`. Срок берётся из
`config('vaulter.webhooks.retention_days')` (по умолчанию 30), если не задан `--days`.
Удалённые строки нельзя восстановить или переиграть.

```
vaulter:prune-webhooks
    {--days=}    Переопределить срок хранения в днях
    {--dry-run}  Показать, что будет удалено, ничего не удаляя
```

## `vaulter:webhooks:legacy-fanout`

Relay до 1.0 помечал строку outbox `dispatched` до записи доставок, и падение могло их
потерять. Апгрейд превращает такие строки в `completed` с `legacy_fanout = true`. Legacy-
строка без единой доставки неоднозначна: подписчиков не было или раскрытие потеряно —
по данным это не различить. Команда печатает такие строки (`event_id`, `event`,
`created_at`, `drive_id`); с `--replay` возвращает их relay, который раскрывает их на
эндпоинты, подходящие **сейчас** (эндпоинт, неактивный в момент события, может его
получить; эндпоинт, у которого доставка уже есть, второй не получит). Строки, удалённые
prune, не восстанавливаются. Управляется флагом `features.webhooks`.

```
vaulter:webhooks:legacy-fanout
    {--since=}   Только строки, созданные не раньше этой даты/времени
    {--until=}   Только строки, созданные не позже этой даты/времени
    {--replay}   Вернуть показанные строки в очередь relay
    {--dry-run}  С --replay — показать, что будет переиграно, ничего не меняя
```

```bash
php artisan vaulter:webhooks:legacy-fanout --since=2026-09-01 --replay --dry-run
php artisan vaulter:webhooks:legacy-fanout --since=2026-09-01 --replay
```

---

## `vaulter:webhooks:redeliver`

Отправляет завершённые доставки заново: одну по id или все доставки статуса (по умолчанию `dead`)
с фильтром по эндпоинту и возрасту. Каждая идёт через `RedeliverWebhookAction`: та же строка
возвращается в `pending` с `attempts = 0`, поэтому получатель видит **тот же `webhook-id`**;
доставка неактивного эндпоинта или всё ещё `pending`/`failed` пропускается с сообщением. Доступны
только строки в пределах `webhooks.retention_days`. Код выхода не нулевой, если не ушло ни одной.
Управляется флагом `features.webhooks`.

```
vaulter:webhooks:redeliver
    {delivery?}    Id одной доставки
    {--endpoint=}  Только доставки этого эндпоинта
    {--since=}     Только доставки, созданные не раньше этой даты/времени
    {--status=dead} delivered или dead
```

```bash
php artisan vaulter:webhooks:redeliver 1042
php artisan vaulter:webhooks:redeliver --endpoint=7 --since=2026-10-01
```

---

## `vaulter:events:reference`

Перегенерирует таблицу каталога событий между маркерами `<!-- events:start -->` и
`<!-- events:end -->` в `docs/reference/events.md` и `docs/ru/reference/events.md` из
`EventSpec` каждого `EventType`. С `--check` ничего не пишет, а код выхода 1 означает,
что страница расходится с кодом (для CI). Это служебная команда репозитория Vaulter, а
не приложений-хостов.

```bash
php artisan vaulter:events:reference
php artisan vaulter:events:reference --check
```

---

## `vaulter:errors:reference`

Перегенерирует справку по кодам проблем между маркерами `<!-- vaulter:errors:start -->` и
`<!-- vaulter:errors:end -->` в `docs/reference/errors.md` и `docs/ru/reference/errors.md` из
каталога кодов. С `--check` ничего не пишет, а код выхода 1 означает, что страница расходится с
кодом (для CI). Это служебная команда репозитория Vaulter, а не приложений-хостов.

```bash
php artisan vaulter:errors:reference
php artisan vaulter:errors:reference --check
```

---

## `vaulter:changes:prune`

Удаляет записи [журнала изменений](/guide/changes) старше срока хранения — drive за drive, пачками
по 1000. Каждая пачка держит голову журнала drive `FOR UPDATE` и поднимает `pruned_through` в той
же транзакции, что и `DELETE`, поэтому клиент, чей курсор отстал от удалённых записей, получает
`410 cursor_expired`, а не тихий пропуск; мутации drive ждут не больше одной пачки. Срок берётся из
`config('vaulter.changes.retention_days')` (по умолчанию 30), если не задан `--days`. Запускайте
ежедневно.

```
vaulter:changes:prune
    {--days=}     Переопределить срок хранения в днях (положительное целое)
    {--drive=*}   Только эти id drive
```

```bash
php artisan vaulter:changes:prune            # ежедневно по расписанию
php artisan vaulter:changes:prune --days=7 --drive=42
```

---

## `vaulter:changes:reset`

Начинает новую эпоху журнала изменений одного drive: все выданные для него курсоры истекают
(`410 cursor_expired`), и клиенты пересинхронизируются полным листингом. Нужна после восстановления
базы из бэкапа или когда журнал больше не соответствует drive (например, `features.changes` был
какое-то время выключен).

```bash
php artisan vaulter:changes:reset 42
```

---

## Планирование по расписанию

Команды обслуживания дружелюбны к расписанию. Показательный график:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay-outbox')->everyMinute();
Schedule::command('vaulter:retry-webhooks')->everyFiveMinutes();
Schedule::command('vaulter:sweep-uploads')->hourly();
Schedule::command('vaulter:blobs:gc')->daily();
Schedule::command('vaulter:purge-trash')->daily();
Schedule::command('vaulter:blobs:reconcile')->weekly();
Schedule::command('vaulter:changes:prune')->daily();
```

## См. также

- [Справочник по конфигурации](/ru/reference/configuration) — `vaulter-media.gc.*`, `vaulter-drive.trash.*`, `vaulter-drive.uploads.*`, `webhooks.*`, `features.webhooks`
- [Справочник по событиям](/ru/reference/events) — что разворачивает релей
- [Фасад и Manager](/ru/reference/facade) — рантайм-API
