# RC1 verdict (codex gpt-6.1-sol high, diff src+database, 2a657840..69216af0)

- **P1 — повторное применение переименованных миграций.** `packages/core/database/migrations/0001_01_01_000001_create_v_outbox_table.php:12`. Сценарий: БД уже мигрирована на `2a657840`, затем обновляется до RC1. Новые имена отсутствуют в таблице `migrations`, поэтому Laravel повторно вызывает `create('outbox')` и получает ошибку существующей таблицы. Аналогично для остальных переименований; переноса записей истории в diff нет.

- **P1 — core без drive не мигрируется на PostgreSQL/MySQL.** `packages/core/src/Webhooks/Schema/WebhooksSchema.php:85`. Сценарий: чистая БД, установлен только core. Его миграция `000401` создаёт FK `webhook_endpoints.drive_id → drives.id`, но создание `drives` перенесено в пакет drive. Сервер отклонит FK; SQLite допускает создание ссылки на отсутствующую таблицу и скрывает проблему.

- **P2 — фиксированный морф-алиас ломает чтение при карте хоста.** `packages/core/src/Support/Concerns/HasStableMorphAlias.php:24`. Сценарий: хост задаёт `Relation::enforceMorphMap(['node' => Node::class], false)` и сохраняет Node через `morphTo()->associate()`. Теперь записывается `vaulter.node`, отсутствующий в карте. При чтении Eloquent пытается создать класс `vaulter.node` и падает. Аналогично для Drive; раньше записывался зарегистрированный алиас хоста.

- **P1 — plausible: миграции перестают подключаться в production.** `packages/core/src/Modules/ModuleApplier.php:99`. Удалена публикация `schemaPaths`, а подключения этих путей к Laravel Migrator в diff нет; новый `migrationPaths()` используется диагностикой и тестовой инфраструктурой. Возможный сценарий: новый host без опубликованных миграций выполняет обычный `artisan migrate`, но таблицы пакетов не создаются. Для подтверждения нужен отсутствующий здесь код регистрации Migrator.
## Проверка
1 REJECTED (пакет не публиковался, D21; v0.2.0 без 2026_10_13_*). 2 CONFIRMED но временно до P4.13 (webhooks в core) → nits. 3 PARTIAL/BC: морф-алиасы не в Relation::morphMap → fix (morphMap merge в провайдере + test). 4 REJECTED (ModuleApplier::schema → migrator->path).
