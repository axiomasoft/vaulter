---
title: Установка
description: Установка vaulter-core через Composer, публикация конфига и миграций, их запуск, затем фронтенд-пакет @vaulter/vue.
outline: deep
---

# Установка

У Vaulter две устанавливаемые части: **бэкенд** — Laravel-пакет, и **фронтенд** — Vue-пакет.
Опциональное совместное редактирование использует небольшой Node-сервис. Сначала ставим
бэкенд.

::: tip Требования
PHP `^8.4`, Laravel `^12.0 || ^13.0` и (для UI) Vue `^3.4` / Node `>=20`. Полная матрица —
в разделе требований.
:::

## 1. Бэкенд — Laravel-пакет

Для полного продукта поставьте метапакет. В нём нет собственного кода: он требует
`axioma-studio/vaulter-core`, `-media`, `-drive` и `-documents` ровно своей версии:

<!-- snippet: shell -->

```bash
composer require axioma-studio/vaulter
```

Сам по себе `axioma-studio/vaulter-core` не даёт ни Drive, ни функций редактирования
документов — это ядро (модульная система, операции, события с outbox, идемпотентность, ошибки, каркасы HTTP и
конфига), и доменных таблиц у него нет. Чтобы взять только часть продукта, подключите именно те пакеты, которые
нужны вашему приложению:

<!-- snippet: shell -->

```bash
# Drive (папки/файлы/загрузки) — автоматически подтягивает axioma-studio/vaulter-media (CAS)
composer require axioma-studio/vaulter-drive

# Документные узлы (редактирование TipTap/Yjs, версии, комментарии) — требует vaulter-drive
composer require axioma-studio/vaulter-documents
```

Любая из команд также подтянет `axioma-studio/vaulter-core` как зависимость, поэтому
установка одного `axioma-studio/vaulter-core` имеет смысл только если вы строите собственные модули
поверх ядра.

Четыре пакета не входят в метапакет и ставятся по необходимости. Каждый требует `vaulter-drive` (коллаборация ещё и
`vaulter-documents`):

<!-- snippet: shell -->

```bash
# Исходящие вебхуки (opt-in модуль, см. ниже)
composer require axioma-studio/vaulter-webhooks

# Админ-панель для Filament 5
composer require axioma-studio/vaulter-filament

# Драйвер прав AzGuard
composer require axioma-studio/vaulter-azgard

```

`vaulter-webhooks` открывает исходящий сетевой трафик, поэтому его модуль выключен, пока вы не включите его в
`config/vaulter.php` (`'modules' => ['webhooks' => true]`, см. [Модули](/ru/concepts/modules)); таблицы создаёт
`php artisan migrate` в любом случае. Совместное редактирование доступно как коммерческое дополнение.

Защищённые HTTP-маршруты по умолчанию требуют аутентификации через `auth:sanctum`. Установите и настройте
Laravel Sanctum в хосте (`composer require laravel/sanctum`) или задайте
`vaulter.routes.middleware.authenticated` для guard вашего приложения. Анонимные защищённые запросы получают 401;
публичные share-ссылки и подписанные callback-запросы хаба используют независимые стеки `public` и `hub` и не
требуют токена Sanctum. Метрики запросов добавляются во все три стека автоматически. См.
[Конфигурацию](/ru/guides/configuration#routes).

Затем выполните встроенную команду установки — она публикует конфиг каждого установленного
пакета Vaulter и предлагает запустить миграции:

<!-- snippet: shell -->

```bash
php artisan vaulter:install
```

::: details Что делает `vaulter:install`
- публикует конфиг каждого установленного пакета (`vaulter-config`, `vaulter-media-config`,
  `vaulter-drive-config`, …); существующий файл пропускается без `--force`,
- спрашивает auth-middleware (или берёт `--middleware`) и печатает строку для конфига — `config/*.php` и
  `.env` она никогда не редактирует; драйвер прав выбирайте через `vaulter-drive.access.permission_driver`
  (`VAULTER_PERMISSION_DRIVER`),
- предлагает выполнить `php artisan migrate` (или выполняет с `--migrate`),
- предлагает поставить звезду репозиторию на GitHub.

Без терминала берутся опции или значения по умолчанию, миграции без `--migrate` не
выполняются. Повторный запуск безопасен. См. [Команды → `vaulter:install`](/ru/reference/commands#vaulter-install).
:::

### Установка вручную

Предпочитаете по шагам? Команда установки эквивалентна следующему:

::: code-group

<!-- snippet: shell -->

```bash [публикация + миграции]
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
```

<!-- snippet: shell -->

```bash [только конфиг]
# Миграции Vaulter запускаются из vendor/ и никогда не публикуются,
# поэтому минимум — это только файл конфигурации:
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
```

:::

::: info Миграции не публикуются
Каждый пакет запускает миграции своих таблиц прямо из `vendor/`: `vaulter-core` — outbox и ключи
идемпотентности, `vaulter-media` — блобы и их пины, `vaulter-drive` — таблицы диска, `vaulter-documents` —
таблицы документов, `vaulter-webhooks` — эндпоинты и доставки. Тега публикации для них нет, поэтому опубликованных копий, которые расходятся с пакетом, не
бывает, а таблица пакета, который вы не ставили, не создаётся. Опубликованный конфиг ложится в
`config/vaulter.php`.
:::

## 2. Фронтенд — Vue-пакет

Поставьте компоненты, composable-функции и API-клиент:

<!-- snippet: shell -->

```bash
npm install @vaulter/vue
```

Зарегистрируйте плагин и укажите ему префикс API (по умолчанию `api/vaulter`):

<!-- snippet: host -->

```js
// resources/js/app.js
import { VaulterPlugin } from '@vaulter/vue'

app.use(VaulterPlugin, { baseUrl: '/api/vaulter' })
```

Это глобально регистрирует Drive-компоненты (`VaulterDrive`, `VaulterUploader`,
`VaulterCommentsPanel`, …), а также передаёт API-клиент в каждую composable-функцию.

::: tip Сборка ассетов
`@vaulter/vue` поставляется собранными ESM/UMD-бандлами и типами TypeScript — отдельного шага
сборки сверх вашего сборщика (Vite и т.п.) не требуется. Vue `^3.4` — peer-зависимость.
:::

## 3. Совместное редактирование (опционально)

Эксплуатация коммерческого дополнения описана в его приватном руководстве.

## Проверка установки

Быстрая проверка из `tinker`:

<!-- snippet: host -->

```php
use Vaulter\Drive\Facades\Vaulter;

$drive = Vaulter::drive($user);   // любая Eloquent-модель
$drive->root();                 // автоматически созданная корневая папка (Node)
```

Если вернулся `Node` — бэкенд подключён правильно.

## Дальше

- [**Быстрый старт**](/ru/getting-started/quick-start) — создать папку, загрузить файл, получить список.
- [**Конфигурация**](/ru/guides/configuration) — привязать драйвер прав и настроить пакет.
