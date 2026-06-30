# Vaulter — Техническое задание на разработку пакета

> **Статус:** Draft v0.1  
> **Проект:** `vaulter` (open-source Laravel/Vue package)  
> **Назначение документа:** Полное описание задач для передачи в Lovable / low-code-агент

---

## 1. Обзор проекта

**Vaulter** — открытый Laravel-пакет для встраивания полноценной системы управления документами в существующие приложения. Идея возникла из модуля совместного редактирования во FlexCRM, где используется TipTap-редактор с облачной синхронизацией, серверным хабом и JSON-хранением версий в БД. Цель пакета — вынести эту инфраструктуру в переиспользуемый, максимально гибкий компонент, пригодный для встраивания в любой Laravel-проект с произвольной системой ролей.

### Ключевые концепции

- **Document** — единица контента (текст, таблица, презентация и др.), хранится в формате TipTap JSON
- **Version** — снапшот документа в момент сохранения; цепочка версий образует историю
- **Folder** — иерархическое дерево папок (аналог Google Drive / Яндекс Диска)
- **Workspace** — изолированное пространство документов, привязанное к сущности хост-приложения (организация, проект, пользователь)
- **Permission Context** — адаптер, через который Vaulter обращается к системе ролей хост-приложения

---

## 2. Цели и ограничения

### Цели

- Предоставить готовую инфраструктуру для документооборота (файловый менеджер + редактор + версионность)
- Обеспечить максимально простую интеграцию в любой Laravel-проект за 1 рабочий день
- Поддерживать несколько типов документов (rich text, таблица, markdown, шаблон)
- Обеспечить совместное редактирование в реальном времени (collaborative editing) через Y.js + Hocuspocus
- Предоставить набор готовых Vue-компонентов для встраивания в страницы хост-приложения

### Ограничения

- Пакет **не** реализует собственную систему аутентификации — только интеграция через адаптер
- Пакет **не** хранит бинарные файлы внутри себя — только JSON-контент; для бинарных вложений предоставляется интерфейс Storage Adapter
- Минимальная версия: **Laravel 11**, **PHP 8.2**, **Vue 3**

---

## 3. Архитектура пакета

### 3.1 Структура репозитория

```
vaulter/
├── src/
│   ├── VaulterServiceProvider.php
│   ├── Models/
│   │   ├── Document.php
│   │   ├── DocumentVersion.php
│   │   ├── DocumentFolder.php
│   │   └── DocumentPermission.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── DocumentController.php
│   │   │   ├── VersionController.php
│   │   │   ├── FolderController.php
│   │   │   └── CollaborationController.php
│   │   └── Middleware/
│   │       └── VaulterAuth.php
│   ├── Contracts/
│   │   ├── PermissionResolver.php
│   │   └── StorageAdapter.php
│   ├── Support/
│   │   ├── PermissionContext.php
│   │   └── DocumentTypeRegistry.php
│   └── Hocuspocus/
│       └── HocuspocusExtension.php
├── resources/
│   └── js/
│       ├── components/
│       │   ├── VaulterFileManager.vue
│       │   ├── VaulterEditor.vue
│       │   ├── VaulterVersionHistory.vue
│       │   ├── VaulterBreadcrumb.vue
│       │   └── VaulterPermissionsPanel.vue
│       └── index.js
├── database/
│   └── migrations/
│       ├── create_v_documents_table.php
│       ├── create_v_document_versions_table.php
│       ├── create_v_document_folders_table.php
│       └── create_v_document_permissions_table.php
├── config/
│   └── vaulter.php
├── routes/
│   └── api.php
└── tests/
```

### 3.2 Слои системы

```
┌─────────────────────────────────────────────┐
│            Vue-компоненты (frontend)         │
│  FileManager │ Editor │ VersionHistory │ ... │
└───────────────────────┬─────────────────────┘
                        │ HTTP / WebSocket
┌───────────────────────▼─────────────────────┐
│              Laravel API (Vaulter)          │
│  DocumentController │ CollaborationController│
│  Middleware: VaulterAuth                   │
└───────────────────────┬─────────────────────┘
                        │
        ┌───────────────┼──────────────┐
        │               │              │
┌───────▼──────┐ ┌──────▼──────┐ ┌────▼────────┐
│  Permission  │ │  Storage    │ │ Hocuspocus  │
│  Resolver    │ │  Adapter    │ │  Server     │
│  (адаптер)  │ │  (адаптер)  │ │  (облако)  │
└──────────────┘ └─────────────┘ └─────────────┘
        │
┌───────▼──────────────────────────────────────┐
│            База данных (host app)             │
│  v_documents │ v_versions │ v_folders     │
└──────────────────────────────────────────────┘
```

---

## 4. База данных

### 4.1 Таблица `v_documents`

| Колонка | Тип | Описание |
|---|---|---|
| `id` | bigint, PK | — |
| `workspace_type` | string | Polymorphic: тип владельца (App\Models\Project) |
| `workspace_id` | bigint | Polymorphic: id владельца |
| `folder_id` | bigint, nullable | FK → v_document_folders |
| `type` | string | Тип: `richtext`, `table`, `markdown`, `template` |
| `title` | string | Заголовок документа |
| `content` | longtext JSON | TipTap JSON (текущая версия) |
| `meta` | json, nullable | Произвольные метаданные |
| `created_by` | bigint | FK → users хост-приложения |
| `updated_by` | bigint | FK → users хост-приложения |
| `deleted_at` | timestamp, nullable | Soft delete |
| `created_at` / `updated_at` | timestamps | — |

### 4.2 Таблица `v_document_versions`

| Колонка | Тип | Описание |
|---|---|---|
| `id` | bigint, PK | — |
| `document_id` | bigint | FK → v_documents |
| `content` | longtext JSON | Снапшот TipTap JSON |
| `snapshot_label` | string, nullable | Метка версии (напр. «v1.2 — правки клиента») |
| `created_by` | bigint | FK → users |
| `created_at` | timestamp | — |

### 4.3 Таблица `v_document_folders`

| Колонка | Тип | Описание |
|---|---|---|
| `id` | bigint, PK | — |
| `workspace_type` | string | Polymorphic |
| `workspace_id` | bigint | Polymorphic |
| `parent_id` | bigint, nullable | Самореференс (дерево папок) |
| `name` | string | Название папки |
| `order` | integer | Порядок отображения |
| `created_at` / `updated_at` | timestamps | — |

### 4.4 Таблица `v_document_permissions`

| Колонка | Тип | Описание |
|---|---|---|
| `id` | bigint, PK | — |
| `document_id` | bigint | FK → v_documents |
| `subject_type` | string | Polymorphic: user / role / team |
| `subject_id` | bigint | Polymorphic |
| `permission` | string | `view`, `edit`, `comment`, `manage` |
| `granted_by` | bigint | FK → users |
| `created_at` / `updated_at` | timestamps | — |

---

## 5. Конфигурация (`config/vaulter.php`)

```php
return [
    // Адаптер для проверки прав — реализуется в хост-приложении
    'permission_resolver' => \App\Vaulter\PermissionResolver::class,

    // Адаптер для хранения бинарных вложений
    'storage_adapter' => \Docvault\Support\DefaultStorageAdapter::class,

    // Префикс всех маршрутов API
    'route_prefix' => 'api/vaulter',

    // Мидлварь для API-маршрутов
    'middleware' => ['api', 'auth:sanctum'],

    // Максимальное количество версий на документ (0 = без ограничений)
    'max_versions' => 50,

    // Разрешённые типы документов
    'document_types' => ['richtext', 'table', 'markdown', 'template'],

    // Hocuspocus-сервер для совместного редактирования
    'collaboration' => [
        'enabled' => true,
        'server_url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        'app_id' => env('VAULTER_COLLAB_APP_ID'),
        'secret' => env('VAULTER_COLLAB_SECRET'),
    ],

    // Лимит размера документа в KB
    'max_document_size_kb' => 5120,
];
```

---

## 6. Контракты (адаптеры)

### 6.1 `PermissionResolver`

Хост-приложение обязано реализовать этот интерфейс, чтобы Vaulter мог делегировать проверку прав существующей системе ролей:

```php
namespace Docvault\Contracts;

interface PermissionResolver
{
    /**
     * Может ли пользователь выполнить действие над документом?
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  string  $action  view|edit|comment|manage|delete
     * @param  \Docvault\Models\Document  $document
     * @return bool
     */
    public function can($user, string $action, $document): bool;

    /**
     * Может ли пользователь создавать документы в данном workspace?
     */
    public function canCreate($user, string $workspaceType, int $workspaceId): bool;
}
```

### 6.2 `StorageAdapter`

```php
namespace Docvault\Contracts;

interface StorageAdapter
{
    public function store(string $filename, $contents): string; // возвращает URL
    public function delete(string $url): void;
    public function url(string $path): string;
}
```

---

## 7. API-маршруты

Все маршруты монтируются под префиксом из конфига (`api/vaulter` по умолчанию).

### Документы

| Метод | URL | Действие |
|---|---|---|
| GET | `/documents` | Список документов (с фильтрацией по workspace, folder, type) |
| POST | `/documents` | Создать документ |
| GET | `/documents/{id}` | Получить документ |
| PATCH | `/documents/{id}` | Обновить мета/контент |
| DELETE | `/documents/{id}` | Soft delete |
| POST | `/documents/{id}/restore` | Восстановить из trash |
| GET | `/documents/{id}/versions` | История версий |
| POST | `/documents/{id}/versions` | Создать именованный снапшот |
| GET | `/documents/{id}/versions/{versionId}` | Получить конкретную версию |
| POST | `/documents/{id}/versions/{versionId}/restore` | Откатиться к версии |

### Папки

| Метод | URL | Действие |
|---|---|---|
| GET | `/folders` | Дерево папок для workspace |
| POST | `/folders` | Создать папку |
| PATCH | `/folders/{id}` | Переименовать / переместить |
| DELETE | `/folders/{id}` | Удалить (с переносом документов) |

### Права доступа

| Метод | URL | Действие |
|---|---|---|
| GET | `/documents/{id}/permissions` | Список прав на документ |
| POST | `/documents/{id}/permissions` | Выдать право субъекту |
| DELETE | `/documents/{id}/permissions/{permId}` | Отозвать право |

### Совместное редактирование

| Метод | URL | Действие |
|---|---|---|
| POST | `/documents/{id}/collab/token` | Получить JWT-токен для Hocuspocus |

---

## 8. Vue-компоненты

Пакет предоставляет готовые Vue 3 компоненты, регистрируемые глобально или импортируемые по-отдельности.

### 8.1 `VaulterFileManager`

Файловый менеджер — главная точка входа для пользователя. Аналог Google Drive.

**Props:**

| Prop | Тип | Описание |
|---|---|---|
| `workspaceType` | String | Тип workspace (напр. `App\\Models\\Project`) |
| `workspaceId` | Number | ID workspace |
| `baseUrl` | String | Базовый URL API (по умолчанию `/api/vaulter`) |
| `viewMode` | String | `grid` \| `list` (по умолчанию `list`) |
| `allowedTypes` | Array | Разрешённые типы для создания |

**Events:**

- `@open-document(document)` — пользователь открыл документ
- `@create-document(type, folderId)` — запрос на создание
- `@folder-changed(folder)` — смена активной папки

**Функциональность:**
- Отображение дерева папок в сайдбаре
- Список/сетка документов с иконками типов
- Drag & drop переноса в папки
- Контекстное меню: переименовать, переместить, история версий, удалить
- Строка поиска по названию

### 8.2 `VaulterEditor`

Обёртка над TipTap с поддержкой совместного редактирования.

**Props:**

| Prop | Тип | Описание |
|---|---|---|
| `documentId` | Number | ID документа |
| `collaborative` | Boolean | Включить Y.js коллаб (default: true) |
| `readonly` | Boolean | Режим просмотра |
| `toolbar` | Array | Список кнопок тулбара |
| `baseUrl` | String | Базовый URL API |

**Events:**

- `@save(content)` — контент сохранён
- `@collaborators-changed(list)` — изменился список активных редакторов
- `@version-created(version)` — создан снапшот

**Функциональность:**
- Полный TipTap toolbar (H1–H6, bold, italic, список, цитата, код, таблица, изображение)
- Аватарки активных соавторов в хедере
- Автосохранение с debounce 2 сек
- Кнопка «Сохранить версию» с диалогом метки
- Счётчик несохранённых изменений

### 8.3 `VaulterVersionHistory`

Боковая панель с историей версий документа.

**Props:** `documentId`, `baseUrl`

**Events:** `@restore(version)`, `@preview(version)`

**Функциональность:**
- Список версий с датой, автором, меткой
- Предпросмотр версии в режиме diff (подсветка изменений)
- Кнопка «Восстановить»

### 8.4 `VaulterPermissionsPanel`

Управление доступом к документу.

**Props:** `documentId`, `baseUrl`, `resolveSubject` (callback для поиска пользователей/ролей)

**Функциональность:**
- Таблица субъектов с уровнем доступа
- Select: view / comment / edit / manage
- Добавление субъекта через autocomplete
- Отзыв доступа

### 8.5 `VaulterBreadcrumb`

Хлебные крошки навигации по папкам.

**Props:** `path` (массив папок), `current` (текущий документ/папка)

**Events:** `@navigate(folder)`

---

## 9. Интеграция с хост-приложением

### 9.1 Установка

```bash
composer require vaulter/vaulter
php artisan vendor:publish --tag=vaulter-migrations
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
npm install @vaulter/vue
```

### 9.2 Регистрация адаптера

```php
// app/Providers/AppServiceProvider.php
use Docvault\Facades\Vaulter;

Vaulter::resolvePermissionsWith(new \App\Vaulter\PermissionResolver());
```

### 9.3 Использование Vue-компонентов

```js
// resources/js/app.js
import { VaulterPlugin } from '@vaulter/vue'

app.use(VaulterPlugin, {
    baseUrl: '/api/vaulter',
})
```

```vue
<!-- Внутри любой страницы хост-приложения -->
<VaulterFileManager
    workspace-type="App\Models\Project"
    :workspace-id="project.id"
    @open-document="openEditor"
/>
```

---

## 10. Совместное редактирование (Hocuspocus)

### Схема работы

1. Frontend запрашивает `POST /api/vaulter/documents/{id}/collab/token` — сервер выдаёт JWT
2. `VaulterEditor` подключается к Hocuspocus WebSocket-серверу с этим JWT
3. Hocuspocus-сервер валидирует JWT через webhook на Laravel-бэкенд
4. Y.js синхронизирует изменения между всеми подключёнными клиентами в реальном времени
5. По событию `save` (debounce или ручному) содержимое пишется в `v_documents.content` и при необходимости в `v_document_versions`

### Переменные окружения

```env
VAULTER_COLLAB_URL=ws://your-hocuspocus-server:1234
VAULTER_COLLAB_APP_ID=your-app-id
VAULTER_COLLAB_SECRET=your-secret-key
```

### Деплой Hocuspocus-сервера

Пакет включает готовый `hocuspocus-server/` Node.js-сервис с `Dockerfile` и примером `docker-compose.yml` для self-hosted деплоя.

---

## 11. Типы документов

Реестр типов расширяем из хост-приложения:

```php
Vaulter::registerDocumentType('spreadsheet', [
    'label' => 'Таблица',
    'icon' => 'table-cells',
    'editor_component' => 'VaulterSpreadsheetEditor',
    'tiptap_extensions' => [\Tiptap\Extensions\Table::class],
]);
```

Встроенные типы:

| Тип | Описание | TipTap-расширения |
|---|---|---|
| `richtext` | Полноформатный текстовый документ | StarterKit, Image, Table, Link |
| `markdown` | Markdown с превью | StarterKit, CodeBlock |
| `template` | Шаблон с переменными `{{placeholder}}` | StarterKit, Placeholder |

---

## 12. Тестирование

### Unit-тесты (PHPUnit)

- `DocumentService`: CRUD, версионность, восстановление
- `PermissionContext`: делегирование к адаптеру
- `HocuspocusTokenService`: генерация и валидация JWT

### Feature-тесты

- Полный CRUD-цикл через API с аутентификацией
- Проверка запрета доступа при недостаточных правах (403)
- Создание версии при сохранении
- Откат к предыдущей версии

### Компонентные тесты (Vitest)

- `VaulterFileManager`: рендер, поиск, навигация по папкам
- `VaulterEditor`: автосохранение, эмит событий
- `VaulterVersionHistory`: превью и восстановление

---

## 13. Дорожная карта (Milestones)

| Этап | Содержание | Приоритет |
|---|---|---|
| **M1 — Core** | Миграции, модели, CRUD API, `PermissionResolver` интерфейс | 🔴 Критично |
| **M2 — Frontend** | `FileManager`, `Editor` (без коллаб), `VersionHistory` | 🔴 Критично |
| **M3 — Collaboration** | Hocuspocus-интеграция, JWT, Y.js в Editor | 🟡 Важно |
| **M4 — Permissions UI** | `PermissionsPanel`, granular access | 🟡 Важно |
| **M5 — Extensions** | `DocumentTypeRegistry`, spreadsheet тип, шаблоны | 🟢 Желательно |
| **M6 — DevX** | Artisan-команды, Stubs, примеры интеграции с Spatie/AzGuard | 🟢 Желательно |

---

## 14. Открытые вопросы

- [ ] Нужен ли встроенный File Browser для вложений (изображения в документах), или делегируем через `StorageAdapter`?
- [ ] Поддержка offline-редактирования (Y.js IndexedDB persistence)?
- [ ] Экспорт документов в PDF / DOCX — в scope M1 или отдельный пакет?
- [ ] Нужна ли поддержка комментариев к фрагментам текста (TipTap Comments extension)?
- [ ] Брендинг: `vaulter/vaulter` (Composer) + `@vaulter/vue` (npm) — подтвердить неймспейс

---

*Документ подготовлен для передачи в Lovable / low-code-агент. Все архитектурные решения подлежат финальному согласованию перед стартом разработки.*
