# 12 — Эксплуатация, DX и релиз

Решения: [D34](02-decisions.md#d34)–[D38](02-decisions.md#d38).

## 1. Команды artisan (канон `vaulter:<area>:<verb>`)

| Команда | Пакет | Было | Назначение |
|---|---|---|---|
| `vaulter:install` | core | `vaulter:install` (spatie) | Интерактивная установка: стек middleware, драйвер прав, публикация конфигов пакетов, миграции, итоговый doctor |
| `vaulter:doctor [--production] [--json]` | core | — | Диагностика (§2); код выхода 1 при error |
| `vaulter:upgrade:inspect` | core | — | Отчёт готовности данных 0.2 → 0.3 (то, что проверяет preflight-миграция) |
| `vaulter:make:profile` | core | `vaulter:make-panel` | Генератор профиля |
| `vaulter:make:file-handler` / `vaulter:make:permission-driver` | core | — | Генераторы |
| `vaulter:make:document-type` / `vaulter:make:exporter` | documents | — | Генераторы |
| `vaulter:webhooks:relay [--limit=]` | core | `vaulter:relay-outbox` | |
| `vaulter:webhooks:retry` | core | `vaulter:retry-webhooks` | |
| `vaulter:webhooks:prune` | core | `vaulter:prune-webhooks` | |
| `vaulter:uploads:sweep` | drive | `vaulter:sweep-uploads` | |
| `vaulter:trash:purge` | drive | `vaulter:purge-trash` | |
| `vaulter:drives:purge` | drive | — | Завершение удаления drives со статусом `deleting` |
| `vaulter:drives:recount [--drive=]` | drive | — | Пересчёт `used_bytes` |
| `vaulter:blobs:gc` | media | `vaulter:gc-blobs` | |
| `vaulter:blobs:reconcile` | media | `vaulter:reconcile-blobs` | |
| `vaulter:blobs:repair-scopes [--dry-run\|--execute]` | media | — | D20 |
| `vaulter:panel:migrations` | — | удалить | D05 |

Все команды, изменяющие данные, поддерживают `--dry-run`, работают батчами, пишут итог в лог и идемпотентны.

## 2. `vaulter:doctor`

Контракт проверки (core):

```php
namespace Vaulter\Contracts\Doctor;

interface DoctorCheck
{
    public function key(): string;                          // 'core.database.connection'
    public function title(): string;
    /** @return list<DoctorFinding> */
    public function run(DoctorContext $context): array;    // DoctorFinding{severity: ok|warning|error, message, hint}
}
```

`--production` повышает часть warning до error. Регистрация — тег `vaulter.doctor_checks`.

| Ключ | Пакет | Проверяет | Уровень в production |
|---|---|---|---|
| `core.database.connection` | core | соединение Vaulter доступно; драйвер поддерживается (SQLite → error) | error |
| `core.schema.tables` | core | все таблицы и индексы на месте; применены все миграции Vaulter | error |
| `core.schema.key_types` | core | фактические типы PK/HK-колонок совпадают с `ids.strategy`/`ids.host_keys` (N28) | error |
| `core.schema.integrity` | core | I1, I5, I6 (выборочно, лимит строк) | warning |
| `core.config.valid` | core | повтор boot-валидации всех пакетов без исключения, сводным списком | error |
| `core.config.deprecated_keys` | core | используются устаревшие ключи | warning |
| `core.routes.auth` | core | в `routes.middleware.authenticated` есть auth-middleware, guard существует | error |
| `core.access.driver` | core | драйвер прав не `null`; класс разрешается | error |
| `core.tenancy` | core | при включённой — resolver разрешается и возвращает значение в HTTP-контексте | error |
| `core.queue` | core | очередь не `sync` в production (webhooks, scan) | warning |
| `core.scheduler` | core | `schedule.enabled` или задачи найдены в `schedule:list` | warning |
| `core.cache` | core | драйвер кэша не `array`/`file` при нескольких инстансах (replay-кэш, rate limits) | warning |
| `core.webhooks.outbox_lag` | core | возраст самой старой pending-строки outbox < 10 мин | warning |
| `media.disk.probe` | media | запись/чтение/удаление тестового объекта на каждом диске профилей | error |
| `media.dedup.scope` | media | профили с разными дисками при `dedup.scope ≠ drive` | warning |
| `media.legacy_scopes` | media | есть blobs `legacy:snull|tnull` → предложить repair-scopes | warning |
| `drive.uploads.tmp` | drive | временный каталог доступен, свободное место > `max_file_size` | error |
| `drive.intl` | drive | `ext-intl` для NFC | warning |
| `drive.scan` | drive | `scan.required` без scanner | error |
| `documents.types` | documents | `default_type` зарегистрирован; документы неизвестных типов (счётчик) | warning |
| `documents.collaboration` | documents | секреты заданы и различны, `wss://`, `token_ttl ≤ 3600`, `firebase/php-jwt` установлен | error |
| `azgard.dependency` | azgard | AzGuard-пакет и guard разрешаются | error |

## 3. Планировщик

`Providers\SchedulingRegistrar` (core) и аналоги в пакетах регистрируют задачи через
`$this->callAfterResolving(Schedule::class, …)`, если `vaulter.schedule.enabled = true`:
`webhooks:relay` (everyMinute, withoutOverlapping), `webhooks:retry` (everyMinute), `webhooks:prune` (daily),
`uploads:sweep` (hourly), `trash:purge` (daily), `drives:purge` (hourly), `blobs:gc` (daily),
`blobs:reconcile` (weekly). Частоты — из `vaulter.schedule.*`. Хост отключает автоматику одним ключом.

## 4. Установка у хоста (целевой сценарий README)

```bash
composer require axioma-studio/vaulter          # core + media + drive + documents
php artisan vaulter:install                     # middleware, драйвер прав, конфиги, миграции, doctor
npm install @vaulter/vue
```

```php
// app/Models/Project.php
use Vaulter\Concerns\HasDrives;

final class Project extends Model
{
    use HasDrives;
}

// где угодно в HTTP-контексте
$project->drive()->files()->upload(UploadSource::fromUploadedFile($request->file('file')));
```

## 5. Набор для тестов хоста и расширений (D35)

| Компонент | Что даёт |
|---|---|
| `database/factories/*` (core, drive, documents) | `Drive::factory()->for($project)->create()`, `Node::factory()->folder()->in($drive)`, `->file(bytes: '…')`, `Document::factory()->richText()` |
| `Vaulter\Testing\InteractsWithVaulter` | `vaulterFakeStorage()` (in-memory диск), `vaulterActingAs($user)`, `assertVaulterEventRecorded(EventType $type, …)`, `assertNodeVisibleTo($user, $node)` |
| `Vaulter\Testing\Contracts\FileHandlerContractTests` | трейт/датасет Pest для автора обработчика |
| `Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests`, `ExporterContractTests` | то же для типов и экспортёров |
| `Vaulter\Testing\Contracts\PermissionDriverContractTests` | одинаковые решения на общем фикстуре |

## 6. Релиз и версии

| Пункт | Решение |
|---|---|
| Метапакет | `packages/vaulter/composer.json` (`type: metapackage`), split-репозиторий `axioma-studio/vaulter` |
| Версии split-пакетов | одна версия монорепо на все PHP-пакеты; `self.version` допустим, но release-гейт проверяет, что все split-репозитории получили один и тот же тег |
| `vaulter-azgard` | публикуется только при тегированной версии AzGuard (`^0.x`), `dev-main` запрещён в `require`; иначе исключается из релиза с пометкой в CHANGELOG |
| npm | `@vaulter/vue` — своя semver-линия; `peerDependencies`/`package.json` поле `"vaulter": { "api": "1" }` |
| Хаб | Docker-образ `ghcr.io/axiomasoft/vaulter-hocuspocus:<версия>`; совместимость — API v1 |
| Метаданные | homepage/support/issues/source → `https://github.com/axiomasoft/vaulter`; `VaulterException::docs()` и `askToStarRepoOnGitHub` — тоже; описание core: «Kernel of Vaulter: schema, access control, sharing, audit events and extension contracts. Install axioma-studio/vaulter for the full product.» |

Порядок версий: **0.3.0** — canon break (D01); **0.3.x** — исправления по результатам гейтов;
**1.0.0-rc.1** — когда все гейты §7 зелёные; **1.0.0** — после двух недель без изменений публичного API
(Roave BC check пуст относительно rc) и прогона upgrade на данных из 0.2.

## 7. Release-гейты

| Гейт | Матрица / содержание |
|---|---|
| Статика | Pint, PHPStan max, Rector dry-run, Pest arch — во всех пакетах |
| Unit/feature | PHP 8.4 × Laravel 12, 13 × (SQLite для быстрых, PostgreSQL для всех feature) |
| ID-стратегии | bigint, ulid, uuid7 × host_keys string/bigint/uuid — только suites CAS, access, provisioning |
| Конкурентность | PostgreSQL 15/17: квота, ревизии, move-циклы, restore-конфликты, CAS commit/GC, outbox claims, upload complete |
| MySQL smoke | MySQL 8.0: миграции, инварианты I1–I3, основные сценарии |
| Upgrade | дамп фикстуры 0.2.0 → миграции 0.3 → `vaulter:doctor --production` зелёный → сценарии V15 |
| Consumer | чистый Laravel-проект, установка **собранных** zip-артефактов split-пакетов (без path-репозиториев): metapackage; каждый пакет отдельно (core+media+drive; +documents; +filament; +azgard) |
| Config cache | тесты маршрутов и провайдеров под `config:cache` и `route:cache` |
| API | OpenAPI-валидация, snapshot маршрутов, `oasdiff breaking` против последнего тега |
| PHP BC | Roave BackwardCompatibilityCheck против последнего тега (после 1.0 — блокирующий) + обновлённые `PublicApiSnapshotTest` |
| Front | Node 20, 22: `vue-tsc`, vitest, build, проверка сгенерированных типов |
| Hub | Node 22: тесты, Docker build, healthcheck-контейнер |
| Безопасность | gitleaks, `composer audit`, `npm audit --omit=dev`, Trivy для образа хаба |
| Документация | рецепты (`tests/Recipes/*`) исполняются; ссылки в docs валидны |

## 8. Документация

```text
docs/
├── getting-started/ (installation, quick-start, upgrading-0.2-to-0.3)
├── concepts/        (glossary, drives-and-profiles, nodes-and-revisions, access-model, events, tenancy)
├── guides/          (uploads, documents, collaboration, sharing, webhooks, filament, vue, operations)
├── extending/       (file-handlers, document-types, exporters, permission-drivers, upload-inspectors, profiles)
├── reference/       (config, php-api, rest-api (из OpenAPI), events, errors, commands)
├── security/        (threat-model, hardening-checklist)
└── adr/             (+ 0019 kernel ownership, 0020 authorized public API, 0021 profiles, 0022 revisions & documents aggregate, 0023 event pipeline, 0024 REST v1)
```

- Каждый PHP/TS-фрагмент guides имеет рецепт-тест (`tests/Recipes/<Guide>Test.php`), фрагмент в markdown
  включается из рецепта (или сверяется скриптом `scripts/check-doc-snippets.php`).
- `reference/config.md` генерируется из `config/*.php` (комментарии), `reference/events.md` — из `EventType`,
  `reference/errors.md` — из таблицы кодов (единый источник — enum/константы исключений).
- Из `src` удаляются внутренние коды задач в docblock'ах (`P2 #5`, `F-P3.5-01`, `H.5`, `D10`, `§2.4`),
  ADR-ссылки остаются как `@see docs/adr/00NN-…`. Выполняется механическим скриптом с ревью.
- README переписывается с нуля по §4.
