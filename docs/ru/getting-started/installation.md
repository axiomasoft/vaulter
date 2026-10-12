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

```bash
composer require axioma-studio/vaulter
```

Сам по себе `axioma-studio/vaulter-core` не даёт ни Drive, ни функций редактирования
документов — это общее ядро (авторизация, контракты CAS, sharing, панели). Чтобы взять только часть
продукта, подключите именно те пакеты, которые нужны вашему приложению:

```bash
# Drive (папки/файлы/загрузки) — автоматически подтягивает axioma-studio/vaulter-media (CAS)
composer require axioma-studio/vaulter-drive

# Документные узлы (редактирование TipTap/Yjs, версии, комментарии) — не зависит от Drive
composer require axioma-studio/vaulter-documents
```

Любая из команд также подтянет `axioma-studio/vaulter-core` как зависимость, поэтому
установка одного `axioma-studio/vaulter-core` имеет смысл только если вы планируете сами
реализовать все доменные контракты без Drive и Documents.

Затем выполните встроенную команду установки — она публикует конфиг каждого установленного
пакета Vaulter и предлагает запустить миграции:

```bash
php artisan vaulter:install
```

::: details Что делает `vaulter:install`
- публикует конфиг каждого установленного пакета (`vaulter-config`, `vaulter-media-config`,
  `vaulter-drive-config`, …); существующий файл пропускается без `--force`,
- спрашивает auth-middleware и драйвер прав (или берёт `--middleware` / `--driver`) и печатает
  строки для `.env` и конфига — `config/*.php` и `.env` она никогда не редактирует,
- предлагает выполнить `php artisan migrate` (или выполняет с `--migrate`),
- предлагает поставить звезду репозиторию на GitHub.

Без терминала берутся опции или значения по умолчанию, миграции без `--migrate` не
выполняются. Повторный запуск безопасен. См. [Команды → `vaulter:install`](/ru/reference/commands#vaulter-install).
:::

### Установка вручную

Предпочитаете по шагам? Команда установки эквивалентна следующему:

::: code-group

```bash [публикация + миграции]
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-migrations
php artisan migrate
```

```bash [только конфиг]
# Миграции Vaulter обнаруживаются и запускаются даже без публикации,
# поэтому минимум — это только файл конфигурации:
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
```

:::

::: info Миграции обнаруживаются автоматически
Сервис-провайдер вызывает `discoversMigrations()->runsMigrations()`, поэтому миграции пакета
запускаются прямо из `vendor/` без публикации. Публикуйте их тегом `--tag=vaulter-migrations`
только когда нужно их **редактировать** (например, подправить схему под собственную панель).
Опубликованный конфиг ложится в `config/vaulter.php`.
:::

## 2. Фронтенд — Vue-пакет

Поставьте компоненты, composable-функции и API-клиент:

```bash
npm install @vaulter/vue
```

Зарегистрируйте плагин и укажите ему префикс API (по умолчанию `api/vaulter`):

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

## 3. Сервер совместного редактирования (опционально)

Редактирование в реальном времени использует драйвер `hocuspocus`
и небольшой Node.js-хаб Hocuspocus с `Dockerfile` и `docker-compose.yml` в каталоге
`hocuspocus-server/`:

```bash
cp hocuspocus-server/.env.example hocuspocus-server/.env
docker compose -f hocuspocus-server/docker-compose.yml up -d
```

Хаб нужен при включённом совместном редактировании.

## Проверка установки

Быстрая проверка из `tinker`:

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($user);   // любая Eloquent-модель
$drive->root();                 // автоматически созданная корневая папка (Node)
```

Если вернулся `Node` — бэкенд подключён правильно.

## Дальше

- [**Быстрый старт**](/ru/getting-started/quick-start) — создать папку, загрузить файл, получить список.
- [**Конфигурация**](/ru/guides/configuration) — привязать драйвер прав и настроить пакет.
