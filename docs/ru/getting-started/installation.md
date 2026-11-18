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
PHP `^8.4`, Laravel `^13.0` и (для UI) Vue `^3.4` / Node `>=26`. Поддерживаемые версии и линии
релизов — в разделе [Совместимость](/ru/reference/compatibility).
:::

## 1. Бэкенд — Laravel-пакет

Для полного продукта поставьте метапакет. В нём нет собственного кода: он требует
`axiomasoft/vaulter-core`, `-media`, `-drive` и `-documents` ровно своей версии:

<!-- snippet: shell -->

```bash
composer require axiomasoft/vaulter
```

Сам по себе `axiomasoft/vaulter-core` не даёт ни Drive, ни функций редактирования
документов — это ядро (модульная система, операции, события с outbox, идемпотентность, ошибки, каркасы HTTP и
конфига), и доменных таблиц у него нет. Чтобы взять только часть продукта, подключите именно те пакеты, которые
нужны вашему приложению:

<!-- snippet: shell -->

```bash
# Drive (папки/файлы/загрузки) — автоматически подтягивает axiomasoft/vaulter-media (CAS)
composer require axiomasoft/vaulter-drive

# Документные узлы (редактирование TipTap/Yjs, версии, комментарии) — требует vaulter-drive
composer require axiomasoft/vaulter-documents
```

Любая из команд также подтянет `axiomasoft/vaulter-core` как зависимость, поэтому
установка одного `axiomasoft/vaulter-core` имеет смысл только если вы строите собственные модули
поверх ядра.

Три пакета не входят в метапакет и ставятся по необходимости. Каждый требует `vaulter-drive`:

<!-- snippet: shell -->

```bash
# Исходящие вебхуки (opt-in модуль, см. ниже)
composer require axiomasoft/vaulter-webhooks

# Админ-панель для Filament 5
composer require axiomasoft/vaulter-filament

# Драйвер прав AzGuard
composer require axiomasoft/vaulter-azguard
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
- запускает `php artisan vaulter:doctor` и показывает его находки (установку они не валят),
- предлагает ссылку, чтобы поставить звезду репозиторию на GitHub.

Без терминала берутся опции или значения по умолчанию, миграции без `--migrate` не
выполняются. Повторный запуск безопасен. См. [Команды → `vaulter:install`](/ru/reference/commands#vaulter-install).
:::

### Установка вручную

Предпочитаете по шагам? Опубликуйте конфиг каждого установленного пакета (по тегу на пакет), затем
выполните миграции и проверьте установку:

<!-- snippet: shell -->

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-media-config
php artisan vendor:publish --tag=vaulter-drive-config
php artisan vendor:publish --tag=vaulter-documents-config
php artisan migrate
php artisan vaulter:doctor
```

::: info Миграции не публикуются
Каждый пакет запускает миграции своих таблиц прямо из `vendor/`: `vaulter-core` — outbox и ключи
идемпотентности, `vaulter-media` — блобы и их пины, `vaulter-drive` — таблицы диска, `vaulter-documents` —
таблицы документов, `vaulter-webhooks` — эндпоинты и доставки. Тега публикации для них нет, поэтому опубликованных копий, которые расходятся с пакетом, не
бывает, а таблица пакета, который вы не ставили, не создаётся. Каждый пакет
публикует свой файл конфига: `config/vaulter.php` для ядра, `config/vaulter-media.php`,
`config/vaulter-drive.php`, `config/vaulter-documents.php` и так далее.
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

Быстрая проверка из `tinker`. Вошедшего пользователя там нет, поэтому вызов явно называет системного
актора (любой вызов Vaulter идёт от пользователя или от системы):

<!-- snippet: host -->

```php
use Vaulter\Drive\Facades\Vaulter;

$drive = Vaulter::drive($user);         // любая Eloquent-модель
$drive->asSystem('install check')->root(); // автоматически созданная корневая папка
```

Если вернулся `Vaulter\Drive\Data\Snapshots\NodeSnapshot` — бэкенд подключён правильно.

## Дальше

- [**Быстрый старт**](/ru/getting-started/quick-start) — создать папку, загрузить файл, получить список.
- [**Конфигурация**](/ru/guides/configuration) — привязать драйвер прав и настроить пакет.
