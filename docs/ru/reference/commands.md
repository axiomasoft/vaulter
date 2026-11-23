---
title: Artisan-команды
description: Все Artisan-команды Vaulter — сигнатуры, опции и что они делают.
outline: deep
---

# Artisan-команды

Команды Vaulter называются `vaulter:<область>:<действие>`; исключения — точки
входа `vaulter:install` и `vaulter:doctor`. Команды обслуживания (сборка мусора, очистка корзины, чистка загрузок,
релей outbox, повтор и очистка вебхуков, scan reconcile) пакеты сами ставят в планировщик
хоста — см. [Планировщик](#планировщик).

| Команда | Назначение |
|---|---|
| `vaulter:install` | Опубликовать конфиги установленных пакетов, вывести настройки хоста, при желании выполнить миграции |
| `vaulter:modules` | Показать установленные модули, их активацию, схему и вклады |
| `vaulter:module:uninstall` | Спланировать (dry run) или выполнить удаление схемы модуля |
| `vaulter:doctor` | Read-only диагностика настройки Vaulter на хосте; `--production` поднимает часть предупреждений до ошибок |
| `vaulter:scan:reconcile` | Восстановить scan-намерения, backfill и очистку закоммиченных загрузок (с лимитами) |
| `vaulter:scan:rescan` | Поставить в очередь повторное сканирование ревизий файлов по tenant, drive или набору ревизий |
| `vaulter:drives:purge` | Окончательно удалить помеченные на удаление drive |
| `vaulter:drives:recount` | Показать (или исправить) расхождение размера и числа узлов drive |
| `vaulter:make:profile` | Сгенерировать класс-провайдер Storage Panel |
| `vaulter:blobs:gc` | Двухфазная, устойчивая к гонкам сборка мусора блобов |
| `vaulter:blobs:reconcile` | Пересчитать `ref_count` блобов из пинов и сообщить о висячих строках |
| `vaulter:blobs:scrub` | Сверить байты блобов с их sha256 в пределах выборки и бюджета ([руководство](/ru/guides/blob-integrity)) |
| `vaulter:operations` | Напечатать каталог операций ([справочник](/ru/reference/operations)) |
| `vaulter:trash:purge` | Окончательно удалить узлы из корзины по истечении срока хранения |
| `vaulter:revisions:prune` | Удалить нетекущие ревизии файлов сверх политики ретеншна профиля |
| `vaulter:uploads:sweep` | Прервать и подчистить протухшие сессии резюмируемой загрузки |
| `vaulter:relay` | Передать outbox его consumer'ам: регистрация, сверка, доставка, удержание |
| `vaulter:relay:replay` | Переотправить диапазон outbox одному consumer'у или подтвердить его разрыв |
| `vaulter:outbox:prune` | Удалить события и доставки outbox старше срока хранения, не теряя недоставленные |
| `vaulter:webhooks:retry` | Повторно отправить доставки вебхуков, у которых истекла пауза повтора |
| `vaulter:webhooks:prune` | Удалить терминальные доставки вебхуков старше срока хранения |
| `vaulter:webhooks:redeliver` | Повторно отправить завершённые доставки вебхуков с тем же `webhook-id` |
| `vaulter:events:reference` | Перегенерировать (или проверить `--check`) таблицу каталога событий в справке |
| `vaulter:errors:reference` | Перегенерировать (или проверить `--check`) справку по кодам проблем в документации |
| `vaulter:changes:prune` | Удалить записи журнала изменений drive старше `vaulter-drive.changes.retention_days` |
| `vaulter:changes:reset` | Сделать недействительными все курсоры изменений drive — клиенты пересинхронизируются |

---

## `vaulter:install`

Публикует конфиг каждого установленного пакета Vaulter (`vaulter-config`,
`vaulter-media-config`, `vaulter-drive-config`, `vaulter-documents-config`,
`vaulter-webhooks-config`, `vaulter-filament-config`, `vaulter-azguard-config`), выводит настройки хоста, которые команда
**не** редактирует, и при желании выполняет миграции. Повторный запуск безопасен: уже
опубликованный файл пропускается (`--force` перезаписывает его), а `config/*.php` и `.env`
никогда не переписываются.

```bash
php artisan vaulter:install
```

| Опция | Смысл |
|---|---|
| `--middleware=` | Auth-middleware для authenticated-стека маршрутов (по умолчанию `auth:sanctum`) |
| `--migrate` | Выполнить `php artisan migrate` без вопроса |
| `--force` | Перезаписать уже опубликованные конфиги |

Интерактивно команда спрашивает middleware, нужно ли мигрировать (по умолчанию нет) и ставить ли
звезду; без терминала берутся опции или значения по умолчанию, а миграции не выполняются без
`--migrate`. Она печатает строку для применения (запись `routes.middleware.authenticated`) и завершается
отчётом
[`vaulter:doctor`](#vaulter-doctor). Установка не является барьером: код выхода `0`, что бы ни нашёл
doctor. Драйвер прав — понятие drive: выбирайте его через `vaulter-drive.access.permission_driver`
(env `VAULTER_PERMISSION_DRIVER`); `vaulter:doctor` его проверяет.

## `vaulter:modules`

```bash
php artisan vaulter:modules [--json]
```

Показывает каждый установленный (предложенный) модуль: пакет, протокол, политику активации
(`auto` / `opt-in`), активен ли он и почему (`default` или `override` из `vaulter.modules`),
миграции схемы, ещё не выполненные базой, и capabilities и слоты, которые модуль определяет
или в которые вносит вклад. Код `0` при валидном графе модулей, `1` при невалидном — причины
печатаются, ни один модуль не активен. Команда регистрируется при любом графе. См.
[Модули](/ru/concepts/modules).

## `vaulter:module:uninstall`

```bash
php artisan vaulter:module:uninstall <module> [--force] [--purge]
```

По умолчанию — dry run: план удаления схемы модуля — его миграции и таблицы, строки в них, установленные
модули, которые его требуют, внешние ключи других владельцев на его таблицы и media-пины, которые он держит
(или которые держатся на его данных), — и причины отказа. Выключение модуля никогда не удаляет его схему.

| Опция | Смысл |
|---|---|
| `--force` | Откатить миграции собственных schema-путей модуля, сначала новые. Отказ, пока в его таблицах есть строки или он держит пины, пока его требует установленный модуль, пока другой владелец держит внешний ключ на его таблицы, и для `core` |
| `--purge` | С `--force`: сначала удалить строки модуля и снять его **собственные** media-пины, затем откатить. Без `--force` — ошибка использования |

| Выход | Смысл |
|---|---|
| `0` | Спланировано (dry run, ничто не отказывает) или выполнено |
| `1` | Отказ или сбой; ничего не изменено |
| `2` | Использование: неизвестный модуль, `--purge` без `--force` |
| `3` | Применено частично: на драйвере, где DDL коммитится (MySQL), purge или часть откатов выполнены, а последующий не удался — повторите `--force` |

Команда никогда не дропает, не удаляет и не снимает ничего у другого владельца. Никакой lock не удерживает
писателей, поэтому сначала выключите модуль и остановите его трафик; то, что прочитал dry run, перечитывается
внутри транзакции purge (SQLite и PostgreSQL выполняют purge и откаты одной транзакцией) и перед каждым откатом,
а появившаяся строка или пин останавливает удаление. Байты media удалённого модуля остаются в хранилище до
следующей сборки мусора. См. [Модули](/ru/concepts/modules#удаление-модуля).

## `vaulter:doctor`

Read-only диагностика настройки Vaulter на хосте. Каждый установленный пакет добавляет свои
проверки; проверка сообщает находки `ok`, `warning` или `error` — со стабильным `code`, текстом и,
если проблему устраняет команда, подсказкой. Doctor ничего не чинит.

```bash
php artisan vaulter:doctor                          # все проверки, читаемый вывод
php artisan vaulter:doctor --production             # профиль production
php artisan vaulter:doctor --json                   # один документ vaulter.doctor/v1 в stdout
php artisan vaulter:doctor --check=drive.scan       # только проверки под префиксом ключа
php artisan vaulter:doctor --probe-storage          # дополнительно записать тестовый объект на каждый диск
```

| Опция | Смысл |
|---|---|
| `--production` | Проверка по профилю production: отмеченные ниже *warning → error* становятся ошибками, диски проверяются записью |
| `--json` | Напечатать один документ `vaulter.doctor/v1` и больше ничего в stdout (ошибки использования — в stderr) |
| `--check=` | Запустить только проверки, ключ которых равен префиксу или начинается с него и точки; можно повторять. Префикс без совпадений — код `2` |
| `--sample=1000` | Лимит строк выборочных проверок (целостность, scope сканирования, использование drive) |
| `--probe-storage` | Разрешить `media.disk.probe` записать, прочитать и удалить `.vaulter-doctor/<ulid>` на каждом диске. Без флага (и без `--production`) проверка сообщает `not evaluated; pass --probe-storage` |

Код выхода: `1`, если любая находка — `error`; `2` при неверной опции; иначе `0`. Предупреждения
запуск не проваливают. Проверка, бросившая исключение, превращается в ошибку `doctor.check_failed` с именем
класса исключения; с `-v` сообщение печатается в читаемом выводе (в нём может быть строка подключения; в JSON оно не попадает). Evidence содержит только
счётчики, имена и флаги — никогда секреты.

JSON-документ описан схемой `packages/core/resources/schemas/doctor-v1.schema.json`:

```json
{"schema":"vaulter.doctor/v1","production":false,"generated_at":"2026-10-11T12:00:00Z",
 "summary":{"ok":14,"warning":2,"error":0},
 "checks":[{"key":"core.queue","title":"Queue connection","findings":[
   {"severity":"warning","code":"core.queue.synchronous","message":"…","hint":"…","evidence":{"connection":"sync","driver":"sync"}}]}],
 "exit_code":0}
```

| Ключ | Что проверяет | Не production → production |
|---|---|---|
| `core.database.connection` | Соединение Vaulter отвечает; драйвер — PostgreSQL, MySQL или MariaDB | SQLite: warning → error |
| `core.schema.tables` | Все таблицы Vaulter существуют; нет неприменённых миграций Vaulter | error → error |
| `core.schema.key_types` | Типы ключевых колонок совпадают с `ids.strategy` / `ids.host_keys` (PostgreSQL, MySQL, MariaDB) | error → error |
| `core.schema.integrity` | Один корень на drive; kind узла ⇄ строка file/document; head-ревизия принадлежит своему агрегату (выборочно) | warning → warning |
| `core.config.valid` | Boot-валидация каждого установленного пакета, сводным списком | error → error |
| `core.routes.auth` | В `routes.middleware.authenticated` есть auth-middleware, guard которого существует. Вносит `vaulter-drive` | error → error |
| `core.access.driver` | Драйвер прав (`vaulter-drive.access.permission_driver`) настоящий и разрешается; тестовый драйвер в production отклоняется. Вносит `vaulter-drive` | error → error |
| `core.tenancy` | При включённой tenancy класс resolver разрешается (значение известно только в запросе) | error → error |
| `core.queue` | Очередь по умолчанию не `sync`/`null` | warning → warning |
| `core.scheduler` | `vaulter.schedule.enabled` или задачи `vaulter:*` в планировщике | warning → warning |
| `core.cache` | Кэш по умолчанию общий (не `array`/`file`) и поддерживает атомарные блокировки | warning → warning |
| `core.outbox.lag` | Самая старая готовая доставка outbox каждого consumer'а ждала менее 10 минут | warning → warning |
| `core.outbox.leases` | Нет доставки outbox с арендой релея, истёкшей более 5 минут назад | warning → error |
| `core.outbox.consumer_gaps` | У отсоединённых consumer'ов нет неподтверждённых разрывов (error); приостановленные consumer'ы выдаются как warning | error → error |
| `core.outbox.settle_window` | `outbox.settle_window_seconds` больше лимитов транзакций базы | warning → warning |
| `core.webhooks.deliveries` | Число endpoint, отключённых из-за сбоев, и доставок, исчерпавших повторы (`vaulter:webhooks:redeliver`). Вносит `vaulter-webhooks` | warning → warning |
| `media.disk.probe` | Запись, чтение и удаление тестового объекта на каждом диске (только с `--probe-storage` или `--production`) | error → error |
| `media.blobs.pins` | Пины по типам владельцев; пины выключенного или не установленного модуля-владельца держат свои блобы от сборки мусора, пока владелец их не снимет | warning → warning |
| `media.dedup.scope` | Дедупликация по tenant при профилях на разных дисках | warning → warning |
| `drive.uploads.tmp` | Временный каталог доступен на запись и больше `max_file_size` | error → error |
| `drive.intl` | Загружен `ext-intl` (имена в NFC) | warning → warning |
| `drive.scan.scanner` | У включённой политики сканирования есть сканер, который разрешается и отвечает заданным key и version | error → error |
| `drive.scan.policy` | Каждый drive работает по настроенной политике (`vaulter:scan:rescan --activate-policy`); нет backfill без прогресса 24 ч | warning → error |
| `drive.scan.obligations` | Истёкшие аренды и работа с опозданием 30 минут (`vaulter:scan:reconcile`); работа, завершённая без вердикта, по причинам | warning → error для зависшей, warning → warning для непросканированной |
| `drive.scan.scope` | Строки сканирования с отсутствующим drive или drive другого tenant; недоступный scope контента при политике «сканировать всё» | error → error |
| `drive.drives.deleting` | Drive в статусе `deleting` дольше 24 ч (`vaulter:drives:purge`) | warning → warning |
| `drive.drives.usage` | Сохранённый `used_bytes` против формулы квоты, тот же калькулятор, что у `vaulter:drives:recount`, без блокировки | warning → warning |
| `documents.types` | Тип по умолчанию зарегистрирован; документы незарегистрированных типов, счётчик | warning → warning |
| `azguard.dependency` | AzGuard установлен и guard моста разрешается | error → error |

Проверки `drive.scan.*` выполняются независимо от `vaulter.modules.webhooks`: сканирование — обязательная
работа по безопасности контента, а не вебхук. `media.disk.probe` — единственная проверка, которая пишет;
свой объект она удаляет в любом случае.

## Планировщик

При `vaulter.schedule.enabled` (env `VAULTER_SCHEDULE_ENABLED`, по умолчанию `true`) пакеты
сами регистрируют команды обслуживания в планировщике Laravel, каждую с `withoutOverlapping()`
и `onOneServer()`, поэтому строки `Schedule::command(...)` в хосте не нужны (существующие
уберите, чтобы не было двойных запусков). Хост по-прежнему должен запускать `schedule:run`
каждую минуту, а `onOneServer()` требует кэш-хранилища, общего для всех инстансов.

| Ключ конфига | Команда | По умолчанию |
|---|---|---|
| `vaulter.schedule.outbox_relay` | `vaulter:relay` | `everyMinute` |
| `vaulter.schedule.outbox_prune` | `vaulter:outbox:prune` | `hourly` |
| `vaulter-webhooks.schedule.webhooks_retry` | `vaulter:webhooks:retry` (нужен `vaulter.modules.webhooks`) | `everyMinute` |
| `vaulter-webhooks.schedule.webhooks_prune` | `vaulter:webhooks:prune` (нужен `vaulter.modules.webhooks`) | `dailyAt:03:10` |
| `vaulter-drive.schedule.changes_prune` | `vaulter:changes:prune` (нужен `vaulter-drive.features.changes`) | `dailyAt:03:20` |
| `vaulter-drive.schedule.uploads_sweep` | `vaulter:uploads:sweep` | `hourlyAt:15` |
| `vaulter-drive.schedule.trash_purge` | `vaulter:trash:purge` | `dailyAt:03:30` |
| `vaulter-drive.schedule.drives_purge` | `vaulter:drives:purge` | `hourlyAt:25` |
| `vaulter-drive.schedule.revisions_prune` | `vaulter:revisions:prune` | `dailyAt:03:40` |
| `vaulter-drive.schedule.scan_reconcile` | `vaulter:scan:reconcile --all-tenants --reason=scheduled --limit=100 --max-seconds=30` (нужен режим scan ≠ `off`, не зависит от вебхуков) | `everyFiveMinutes` |
| `vaulter-drive.schedule.drives_recount` | `vaulter:drives:recount --dry-run` | `null` (выключено) |
| `vaulter-media.schedule.blobs_gc` | `vaulter:blobs:gc` | `dailyAt:04:00` |
| `vaulter-media.schedule.blobs_reconcile` | `vaulter:blobs:reconcile` | `weeklyOn:0,04:30` |

Значение — `null` (задача выключена), имя частоты без аргументов (`everyMinute`,
`everyFiveMinutes`, `hourly`, `daily`, `weekly`, …), `dailyAt:HH:MM`, `hourlyAt:M`,
`weeklyOn:D,HH:MM` (0 — воскресенье) или cron-выражение из пяти полей. Иное значение
останавливает boot с `InvalidConfigurationException`.

См. [Руководство → Установка](/ru/getting-started/installation).

## `vaulter:make:profile`

Сгенерировать в `app/Vaulter/Profiles` класс `ProfileProvider`, принадлежащий
приложению, из stub-заготовки. Она **не** трогает опубликованный конфиг и **не**
генерирует миграции — панель по умолчанию представляет собой конфиг-конверт без
миграций поверх общей схемы `v_*`. Регистрация FQCN провайдера в
`config('vaulter-drive.profiles.providers')` — это осознанный следующий шаг, который команда выводит на экран.

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

См. [Концепции → Storage Panels](/ru/concepts/drives-and-profiles).


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

См. [Рецепты → Сборка мусора блобов](/ru/guides/recipes).

## `vaulter:blobs:reconcile`

Пересчитать `ref_count` блобов из пинов в `v_blob_references` (собственная таблица media) и сообщить о
висячих строках. Истина о живости — пины, а не `ref_count`; эта команда чинит согласованный кэш. Таблицы
других пакетов она не читает.

```
vaulter:blobs:reconcile
```

```bash
php artisan vaulter:blobs:reconcile
```

## `vaulter:trash:purge`

Окончательно вычистить узлы из корзины, у которых истекло окно хранения. Находит **корни**
корзины, чей `trashed_at` старше `now() - retention_days`, и принудительно удаляет каждое
поддерево — так связи ревизия↔блоб удаляются, а их пины снимаются (байты становятся доступны для GC), а квота
освобождается. Подходят только корни (`trash_root_id = id`): чистка корня удаляет всё его
поддерево, включая ранее удалённых отдельно потомков, поэтому ничего не чистится дважды.

```
vaulter:trash:purge
    {--days=} Переопределить окно хранения (конфиг vaulter-drive.trash.retention_days)
```

```bash
php artisan vaulter:trash:purge           # использует хранение из конфига (по умолчанию 30 дней)
php artisan vaulter:trash:purge --days=7  # своё окно хранения
```

См. [Использование → Поиск и корзина](/ru/guides/trash).

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
менее `minNoncurrentAgeDays` назад. Каждое удаление снимает пин блоба (байты становятся
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

См. [Использование → Загрузки → Ретеншн ревизий](/ru/guides/uploads#квота-и-ретеншн-ревизий).

## `vaulter:uploads:sweep`

Прервать и подчистить протухшие сессии резюмируемой загрузки, у которых истёк TTL
(`config('vaulter-drive.uploads.resumable.session_ttl_hours')`). Временный staged-файл и любое состояние
S3 multipart высвобождаются, а статус сессии переключается на `expired`.

```
vaulter:uploads:sweep
```

```bash
php artisan vaulter:uploads:sweep
```

::: tip Совет: свяжите с lifecycle-правилом S3
Sweeper прерывает те незавершённые multipart-загрузки, о которых знает. Добавьте
lifecycle-правило `AbortIncompleteMultipartUpload` как страховку для тех, о которых он не знает.
:::

См. [Использование → Загрузки](/ru/guides/uploads).

## `vaulter:relay`

Relay outbox (D29). Один проход регистрирует consumer'ов outbox из графа модулей
(приостанавливая тех, кого граф больше не содержит), сверяет строки доставки, пропущенные
процессом со старым графом, — только для событий старше `outbox.settle_window_seconds`, —
доставляет готовые строки под ограждённой арендой с повторами full jitter и состоянием DLQ и
отсоединяет consumer'ов, приостановленных дольше `outbox.max_consumer_hold_seconds`, с явным
разрывом. Без `--once` повторяет проходы, пока есть работа, но не дольше `--max-seconds`.
Несколько relay могут работать одновременно. См. [Outbox](/ru/concepts/events-outbox).

```
vaulter:relay
    {--consumer=}       Только этот consumer outbox (регистрация и удержание — для всех)
    {--limit=}          Сколько доставок брать на consumer за проход (по умолчанию outbox.batch)
    {--once}            Один проход
    {--max-seconds=50}  Не начинать новые проходы позже этого числа секунд
```

```bash
php artisan vaulter:relay
php artisan vaulter:relay --consumer=webhooks.fanout --once
```

## `vaulter:relay:replay`

Закрыть разрыв consumer'а outbox: события диапазона `[--from, --to]`, ещё лежащие в outbox,
снова становятся ожидающими для consumer'а (включая строки done и dead, с новым бюджетом
попыток), а покрытые диапазоном разрывы подтверждаются. Код выхода 1, если часть диапазона
уже не в outbox, — команда печатает покрытый диапазон. `--ack-only` подтверждает потерю без
повтора.

```
vaulter:relay:replay
    {--consumer=}  Id consumer'а outbox (webhooks.fanout, …)
    {--from=}      Первый seq outbox
    {--to=}        Последний seq outbox
    {--ack-only}   Подтвердить разрывы диапазона без повтора
```

## `vaulter:outbox:prune`

Хранение outbox: удаляет события старше `outbox.retention_seconds`, у которых нет открытых
доставок и дальше которых сверился каждый неотсоединённый durable consumer, строки done
удалённых событий и строки dead старше `outbox.dead_retention_seconds`. Запускается ежечасно.

```
vaulter:outbox:prune
```

## `vaulter:webhooks:retry`

Повторно отправить доставки вебхуков, у которых истекло окно backoff, и ожидающие
доставки, чей первый job потерян (никем не взяты через `vaulter-webhooks.retry_claim_seconds`
после срока). Управляется флагом `vaulter.modules.webhooks`.

```
vaulter:webhooks:retry
```

```bash
php artisan vaulter:webhooks:retry
```

## `vaulter:webhooks:prune`

Удалить ТЕРМИНАЛЬНЫЕ доставки вебхуков (`delivered`/`dead`, старше срока хранения).
Доставка `pending`/`failed` не удаляется никогда, сколько бы ей ни было. У outbox своё
хранение — `vaulter:outbox:prune`. Управляется флагом `vaulter.modules.webhooks`. Срок берётся из
`config('vaulter-webhooks.retention_days')` (по умолчанию 30), если не задан `--days`.
Удалённые строки нельзя восстановить или переиграть.

```
vaulter:webhooks:prune
    {--days=}    Переопределить срок хранения в днях
    {--dry-run}  Показать, что будет удалено, ничего не удаляя
```


## `vaulter:webhooks:redeliver`

Отправляет завершённые доставки заново: одну по id или все доставки статуса (по умолчанию `dead`)
с фильтром по эндпоинту и возрасту. Каждая идёт через `RedeliverWebhookAction`: та же строка
возвращается в `pending` с `attempts = 0`, поэтому получатель видит **тот же `webhook-id`**;
доставка неактивного эндпоинта или всё ещё `pending`/`failed` пропускается с сообщением. Доступны
только строки в пределах `vaulter-webhooks.retention_days`. Код выхода не нулевой, если не ушло ни одной.
Управляется флагом `vaulter.modules.webhooks`.

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
`EventSpec` каждого типа каталога событий. С `--check` ничего не пишет, а код выхода 1 означает,
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

Удаляет записи [журнала изменений](/concepts/events) старше срока хранения — drive за drive, пачками
по 1000. Каждая пачка держит голову журнала drive `FOR UPDATE` и поднимает `pruned_through` в той
же транзакции, что и `DELETE`, поэтому клиент, чей курсор отстал от удалённых записей, получает
`410 cursor_expired`, а не тихий пропуск; мутации drive ждут не больше одной пачки. Срок берётся из
`config('vaulter-drive.changes.retention_days')` (по умолчанию 30), если не задан `--days`. Запускайте
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
базы из бэкапа или когда журнал больше не соответствует drive (например, `vaulter-drive.features.changes` был
какое-то время выключен).

```bash
php artisan vaulter:changes:reset 42
```

---

## Планирование по расписанию

Команды обслуживания дружелюбны к расписанию. Показательный график:

Эти строки нужны, только если `vaulter.schedule.enabled` равен `false`; иначе пакеты уже планируют эти команды, и сниппет запустит каждую дважды.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay')->everyMinute()->withoutOverlapping();
Schedule::command('vaulter:outbox:prune')->hourly();
Schedule::command('vaulter:webhooks:retry')->everyFiveMinutes();
Schedule::command('vaulter:uploads:sweep')->hourly();
Schedule::command('vaulter:blobs:gc')->daily();
Schedule::command('vaulter:trash:purge')->daily();
Schedule::command('vaulter:blobs:reconcile')->weekly();
Schedule::command('vaulter:changes:prune')->daily();
```

## См. также

- [Справочник по конфигурации](/ru/reference/config) — `vaulter-media.gc.*`, `vaulter-drive.trash.*`, `vaulter-drive.uploads.*`, `vaulter-webhooks.*`, `vaulter.modules.webhooks`
- [Справочник по событиям](/ru/reference/events) — что разворачивает релей
- [Фасад](/ru/reference/php-api) — рантайм-API

## Все команды (генерируется)

<!-- vaulter:commands-reference:start -->
| Command | Description | Synopsis |
|---|---|---|
| `vaulter:blobs:gc` | Garbage-collect unreferenced Vaulter blobs (two-phase, race-safe — H.3) | `[--grace [GRACE]] [--pending]` |
| `vaulter:blobs:reconcile` | Rebuild blob ref_count from the pins and report dangling rows; optionally disk-walk for orphan objects (H.3) | `[--orphans] [--disk [DISK]] [--orphan-grace [ORPHAN-GRACE]] [--prune]` |
| `vaulter:blobs:scrub` | Verify the size and sha256 of a sample of stored blobs and mark the damaged ones | `[--limit [LIMIT]] [--max-bytes [MAX-BYTES]] [--max-seconds [MAX-SECONDS]] [--scope [SCOPE]] [--dry-run]` |
| `vaulter:changes:prune` | Delete drive change-journal entries past the retention window. | `[--days [DAYS]] [--drive [DRIVE]]` |
| `vaulter:changes:reset` | Expire every change-journal cursor of a drive, forcing its clients to resynchronize. | `<drive>` |
| `vaulter:commands:reference` | Generate the command table in the reference docs. | `[--check] [--path [PATH]]` |
| `vaulter:config:reference` | Generate the config key table in the reference docs. | `[--check] [--path [PATH]]` |
| `vaulter:doctor` | Diagnose the Vaulter setup of this host (read-only) | `[--production] [--json] [--check [CHECK]] [--sample [SAMPLE]] [--probe-storage]` |
| `vaulter:drives:purge` | Purge drives with status deleting: nodes, revisions and blob references, then the drive row. | `[--drive [DRIVE]] [--dry-run] [--sync]` |
| `vaulter:drives:recount` | Recount the used bytes of drives from their retained file revisions. | `[--drive [DRIVE]] [--dry-run]` |
| `vaulter:errors:reference` | Generate the problem-code reference in the docs. | `[--openapi] [--check] [--path [PATH]]` |
| `vaulter:events:reference` | Generate the event catalogue table in the reference docs. | `[--check] [--path [PATH]]` |
| `vaulter:idempotency:prune` | Compact expired idempotency receipts and remove elapsed tombstones. | `[--limit [LIMIT]]` |
| `vaulter:install` | Publish Vaulter config, show the host settings to apply, optionally migrate and run the doctor | `[--middleware [MIDDLEWARE]] [--migrate] [--force]` |
| `vaulter:make:document-type` | Scaffold a Vaulter document type | `[--force] [--] <name>` |
| `vaulter:make:exporter` | Scaffold a Vaulter document exporter | `[--force] [--] <name>` |
| `vaulter:make:file-handler` | Scaffold a Vaulter file handler with a contract test | `[--force] [--] <name>` |
| `vaulter:make:permission-driver` | Scaffold a Vaulter permission driver with a contract test | `[--force] [--] <name>` |
| `vaulter:make:profile` | Scaffold a Vaulter profile provider (zero-migration config envelope) | `[--host [HOST]] [--no-grants] [--force] [--] <name>` |
| `vaulter:module:uninstall` | Plan (dry run) or perform the removal of a Vaulter module's schema | `[--force] [--purge] [--] <module>` |
| `vaulter:modules` | List the Vaulter modules, their activation and what they contribute | `[--json]` |
| `vaulter:operations` | List the mutating operations with their concurrency and idempotency policies. | `[--json]` |
| `vaulter:outbox:prune` | Delete outbox events and deliveries past their retention without losing undelivered ones. | — |
| `vaulter:relay` | Relay the outbox to its consumers: register, reconcile, deliver, hold. | `[--consumer [CONSUMER]] [--limit [LIMIT]] [--once] [--max-seconds [MAX-SECONDS]]` |
| `vaulter:relay:replay` | Replay a range of the outbox to one consumer, or acknowledge its gap. | `[--consumer [CONSUMER]] [--from [FROM]] [--to [TO]] [--ack-only]` |
| `vaulter:revisions:prune` | Delete non-current file revisions past their profile retention policy. | `[--drive [DRIVE]] [--dry-run]` |
| `vaulter:scan:reconcile` | Recover bounded scan intents, backfill discovery and committed upload cleanup. | `[--tenant [TENANT]] [--drive [DRIVE]] [--all-tenants] [--reason [REASON]] [--limit [LIMIT]] [--max-seconds [MAX-SECONDS]] [--dry-run] [--json]` |
| `vaulter:scan:rescan` | Activate scan policy or record bounded durable scan requests. | `[--tenant [TENANT]] [--drive [DRIVE]] [--all-tenants] [--reason [REASON]] [--revision [REVISION]] [--after [AFTER]] [--limit [LIMIT]] [--request-id [REQUEST-ID]] [--rescan] [--retry-failed] [--activate-policy] [--dry-run] [--json]` |
| `vaulter:trash:purge` | Permanently delete trashed nodes whose retention window has elapsed. | `[--days [DAYS]]` |
| `vaulter:uploads:sweep` | Abort and clean up stale resumable-upload sessions past their TTL (§2.8). | — |
| `vaulter:webhooks:prune` | Delete terminal webhook deliveries past the retention window. | `[--days [DAYS]] [--dry-run]` |
| `vaulter:webhooks:redeliver` | Send finished webhook deliveries again (same webhook-id). | `[--endpoint [ENDPOINT]] [--since [SINCE]] [--status [STATUS]] [--] [<delivery>]` |
| `vaulter:webhooks:retry` | Re-dispatch webhook deliveries whose backoff window has elapsed (§2.11). | — |
<!-- vaulter:commands-reference:end -->
