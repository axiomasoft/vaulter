# P3.13 + P3.14 + P3.15 — ревью и прогоны (следующая сессия)

Код закоммичен, пункты `in_progress`, `finish` не вызывался:

| Коммит | Пункты | Что |
|---|---|---|
| `6c3977a4` | P3.13 | Каталог событий D38: `EventTypeContract`/`EventCatalog`/`EventSources`/`EventTypeCast`, `DriveEventType`, `DocumentEventType`, слот `EventSink` + `SinkWriter`/`SinkResult`, вклады `drive.activity`/`drive.changes`, `EventsReference` из каталога, ledger D38 |
| `d0d757cd` | P3.14 + P3.15 (единый, общие файлы) | Outbox D29: нейтральный `v_outbox` (`outbox_seq`, scope, type, external), `v_outbox_consumers`/`v_outbox_deliveries`, `OutboxWriter` (всегда), SPI `OutboxConsumer`/`DeliveryControl`/`ConsumeResult`, `OutboxStore`/`OutboxRelay`, `vaulter:relay`, `vaulter:relay:replay`, `vaulter:outbox:prune`, 4 doctor-проверки, `WebhookFanoutConsumer`, конфиг `vaulter.outbox.*`, доки |

Решения/отклонения/допущения — `plan.py events 2026.10.13-№1-VAULTER-KERNEL --item P3.13|P3.14|P3.15`.

Уже выполнено в сессии исполнителя на итоговом дереве (НЕ записано как `--check`, артефактов нет): php -l, pint,
PHPStan core/drive/documents/filament, `inventory --check`, `schema-snapshot --write` sqlite/pgsql/mysql, public-api и
service-locator снимки, `baseline --compare`, check-doc-links; smoke sqlite: core целиком, documents целиком, drive
(Events/Feature/Drive/Uploads/Arch), filament, repo; pgsql и mysql — только `tests/Outbox/OutboxWriteTest` +
`OutboxRelayTest`. Ни разу не запускались: pgsql serial-гонки (`Concurrency/Outbox*`, `EventRecorderRollbackPgTest`),
`schema-snapshot --compare`, pgsql `Webhooks`/`Events`/`Schema`.

## Часть А — ревью на grok 4.7 (read-only, без длинных прогонов)

Селектор `grok/grok-4.7/500k/high`, запуск headless через бридж
(`plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high`), бриф на stdin, поток — в
`artifacts/P3.13-P3.15/review-<lane>.jsonl`; маршрут подтверждать по сессии ревьюера (`contextWindowTokens` ≥ 500k).
Три дорожки параллельно (общий бриф + свой фокус):

| Lane | Диапазон | Фокус |
|---|---|---|
| E events (P3.13) | `git show 6c3977a4` | D38: значения 32 событий неизменны; `EventCatalog` (active vs `resolve()` по установленным, дубль значения → reason компилятора); `EventTypeCast` (читаемость строк выключенного модуля); семантика sinks: `SinkResult::Skipped` не решает replay, первый Stored/Replayed решает, частичная запись → LogicException; порядок (priority,id) и outbox последним; флаги activity/changes внутри drive-вкладов; `EventsReference` (колонка Module, порядок по id модуля); I1/I2 allowlists; ledger D38 |
| O outbox write + schema (P3.14, hard risk) | `git show d0d757cd -- packages/core/src/Events packages/core/src/Schema packages/core/database packages/core/config packages/core/src/Configuration packages/documents/src` | O1: событие + delivery-строки в транзакции операции, rollback без следов, replay ничего не пишет; `outbox_seq` bigIncrements вне ids strategy; DDL (PK, индексы, json default на mysql); тело вебхука байт-в-байт (`OutboxEnvelope::body` = старый `EventEnvelope::fromEvent`); per-occurrence `isExternal()` (DocumentContentUpdated); `OutboxConfig::validate` (hold ≥ settle); удалённые ключи конфига |
| R relay + consumer (P3.15, hard risk) | `git show d0d757cd -- packages/core/src/Events/Outbox packages/core/src/Webhooks packages/core/src/Console packages/core/src/Doctor packages/core/src/CoreModule.php docs` | O2 reconcile только до S (settle window), span-транзакции с fenced advance; O3 fenced complete по `lease_generation`, takeover; claim `FOR UPDATE SKIP LOCKED` + условный UPDATE; O4 pause/hold/detach + gap (`from_seq` = первый пропущенный seq), ephemeral без gap; O5 rolling activation; O6 consumer-lease; retention (повтор предиката при delete, hold по min reconciled не-detached durable); replay (`--ack-only`, exit 1 при потере); `WebhookFanoutConsumer` (`attempts:1` у чанка, `saveContinuation` до вставки доставок, eligibility); full jitter/budget/DLQ, метрики; doctor `OutboxSettleWindowCheck` (pg/mysql лимиты) |

Бриф обязателен: **никаких прогонов наборов** — запрещены `scripts/t`, `pest`, `phpstan`, `inventory`,
`schema-snapshot`, `npm`. Разрешены: чтение, `git show/diff/log`, `grep`, `php -l <файл>`. Каждая находка —
файл:строка, сценарий отказа (входы → неверный результат), предложенный минимальный репро (не запускать). Ревьюер
ничего не правит.

После ответа исполнитель подтверждает/отклоняет каждую находку целевым прогоном (часть Б), вердикт —
`artifacts/P3.13-P3.15/review-grok.md`, `plan.py log <item> --type note`, `plan.py commit <item> --role reviewer`.
Один раунд исправлений + одно ре-ревью (только по затронутому).

## Часть Б — прогоны (только изменённое) и фиксы

Каждая команда один раз, вывод в `artifacts/P3.13-P3.15/runs/*.txt`; общая команда засчитывается во все пункты.
Серверы БД — `scripts/db-test-env.sh` (теперь с watchdog/лимитом лога; см. CONTRIBUTING.md); один прогон на движок,
где код движко-зависим.

| # | Команда | Зачем | Чеки |
|---|---|---|---|
| 1 | `scripts/php84 php vendor/bin/pint --test packages/core && scripts/php84 php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | статика core | P3.13 V7; P3.14 V10; P3.15 V5 |
| 2 | то же в `packages/drive` и `packages/documents` | статика | P3.13 V8, V9 |
| 3 | `scripts/php84 php scripts/inventory/inventory.php --check` | инвентарь/ledger | P3.13 V10; P3.14 V11; P3.15 V6 |
| 4 | `scripts/t sqlite -p repo` | identities/zones/suites/target-map | P3.13 V11; P3.14 V12, V13; P3.15 V7 |
| 5 | `scripts/t sqlite -p core` | ядро целиком (покрывает Events/Activity/Changes/Outbox/Webhooks/Doctor/PruneWebhooks) | P3.13 V1, V3; P3.14 V1, V8; P3.15 V1, V4 |
| 6 | `scripts/t pgsql -p core packages/core/tests/Outbox packages/core/tests/Webhooks packages/core/tests/Events packages/core/tests/Changes` | SQL relay/OutboxStore (NOT EXISTS-вставки, SKIP LOCKED, json) на PG | P3.13 V2; P3.14 V2; P3.15 V2 |
| 7 | `scripts/t pgsql -p core --group=serial packages/core/tests/Concurrency/EventRecorderRollbackPgTest.php packages/core/tests/Concurrency/OutboxFanoutRaceTest.php packages/core/tests/Concurrency/OutboxLateCommitTest.php packages/core/tests/Concurrency/OutboxLeaseTakeoverTest.php` | гонки O2/O3/O6 (новые, ни разу не запускались) | P3.14 V3; P3.15 V3 |
| 8 | `scripts/t pgsql -p core --group=database packages/core/tests/Schema` | DDL на PG | P3.14 V9 |
| 9 | `scripts/t mysql -p core packages/core/tests/Outbox packages/core/tests/Webhooks` | отдельный путь MySQL (`insertOnce` ON DUPLICATE KEY, SKIP LOCKED, delete с подзапросом) — один прогон | доп. |
| 10 | `scripts/schema-snapshot.sh --driver=<sqlite\|pgsql\|mysql> --all-presets --compare=packages/core/tests/Fixtures/Schema/expected` + `git diff --quiet 83b7169e -- packages/core/tests/Fixtures/Schema/original` | DDL; mysql идёт ~20+ мин — запускать в фоне, при таймауте добить `--preset=<id>` | P3.14 V4–V7 |
| 11 | `scripts/t sqlite -p drive` | события drive, outbox-колонки в тестах | P3.13 V4 |
| 12 | `scripts/t sqlite -p documents` | DocumentEventType, external document.updated | P3.13 V5 |
| 13 | `scripts/t sqlite -p filament` | ActivityResource на каталоге | P3.13 V6 |
| 14 | `scripts/t baseline --compare` | 59 записей CHANGES.json | P3.13 V12; P3.14 V14; P3.15 V8 |
| 15 | `scripts/php84 php scripts/check-doc-links.php docs README.md && scripts/php84 php scripts/check-doc-links.php --mirror docs` | доки EN/RU | P3.13 V13; P3.15 V9 |

Не запускать: azgard, media, полный pgsql/mysql матрицей, mutation, load, coverage (квалификация фазы — P3.19).
Известное вне диффа: azgard RealAzgard* (дрейф ../azguard); drive `TrashRootsTest` «empty purges every root» — флак
при равных `trashed_at` (orderBy без tie-break); load/ (QueueProbeCommand, SeedLoadDatasetCommand, LoadStateMetrics,
gates) ещё ссылается на старые колонки outbox и `vaulter:webhooks:relay` — задача владельца.

Фиксы: правка в зоне пункта-владельца, перепрогон затронутых строк таблицы, `plan.py commit <item> --role fix`.
Затем `plan.py finish 2026.10.13-№1-VAULTER-KERNEL P3.13|P3.14|P3.15 --status done|done_with_deviations --check V…=0 …`
(P3.14 и P3.15 — `--deviation` про единый коммит), коммит закрытия и `plan.py next 2026.10.13-№1-VAULTER-KERNEL`.
