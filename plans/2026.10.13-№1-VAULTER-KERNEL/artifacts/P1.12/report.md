# P1.12 — хвосты оптимизации и падения

Замеры на загруженной машине (load average 6–17): порядок величин.

## Ускорено
- BlobGcBatchTest (media, pgsql): массовый посев `CasTestHelpers::seedBlobRows` — 61,7 с → 47,8 с; остальное — сам GC и
  fake-диск. Строки сериализует модель (касты и id-стратегия сохранены); sqlite: 11 passed.
- Быстрая линия `scripts/t sqlite` (все пакеты): 275 с на загруженной машине (исходного замера нет — только ориентир).

## Тесты, исправленные (причина — допущение теста про SQLite)
| Тест | Причина | Решение |
|---|---|---|
| HostKeyColumnsSchemaTest «preserves nullable…» | `DB::connection()` без schema grammar на pgsql | `Schema::getConnection()` |
| TreeInvariantsTest ×2 (lock order) | pgsql берёт tree lock advisory, не строкой drives | `treeLockStatement()` по драйверу |
| CoreDoctorChecksTest ×3 | ждёт sqlite-предупреждение / key_types_skipped / FK-нарушение, которое сервер не допускает | `skip` не-sqlite, причина в сообщении |

## Падения, оставленные (продуктовый код/ожидания; на base c64bba25 проверено там, где указано)
| Тест | Движки | Статус |
|---|---|---|
| Feature/EventTypeTest (2 теста) | sqlite, mysql | **на base падает так же** — не P1 |
| Operations/ProblemMappingTest (4) | sqlite, mysql | **на base падает так же** (`render()` не определён) |
| Changes/PruneChangesCommandTest «unknown drive» | pgsql | `ResetChangesCommand` не изменялся с c64bba25 (diff пуст): не-числовой id в bigint-запросе, SQLSTATE 22P02 |
| Events/EventRecorderTest «thin envelope» | mysql | на base на sqlite зелёный; mysql сортирует ключи JSON — различие движка, не P1 |
| ObservabilityDocuments/P83DocumentsMetricsTest | sqlite+RefreshDatabase, mysql | на base вне раннера не воспроизводится (нет Testbench-конфига) — **не доказано** |
| azgard RealAzgard* | sqlite, pgsql | нет реального AzGuard в окружении (InvalidConfigurationException) |
| media ColdMutationSurvivorTest | pgsql | одиночно зелёный; падал только в параллельном прогоне под нагрузкой |

Продуктовый код (`packages/*/src`) не менялся — вне scope; решение по этим дефектам за владельцем.

## Не сделано
Накладные расходы пустых партиций (по ~1 с на партицию) — не трогал: выигрыш десятки секунд, риск для инвентаря.
documents sqlite: партиция `CollaborationEnabledTestCase+DatabaseMigrations` «ZERO TESTS» в быстрой линии — не разбиралась.

## Дополнение: падения исправлены (по решению владельца)
| Падение | Исправление |
|---|---|
| Feature/EventTypeTest | ожидания синхронизированы с enum (+node.updated, access.inheritance_changed, share.updated, share.rotated; 29 webhook / 31 activity) |
| Operations/ProblemMappingTest | `exception->render()` удалён в P6.2 → тест рендерит через `ProblemRenderer` на версионном пути; имя теста не менялось (baseline) |
| ObservabilityDocuments/P83DocumentsMetricsTest | актор 1 искал таблицу users, которой нет в партиции → `actorId: null` (метрика от актора не зависит) |
| Events/EventRecorderTest (mysql) | порядок ключей JSON-колонки не контракт → `toEqualCanonicalizing` |
| Console/ResetChangesCommand (**product src**) | нечисловой id для int-ключа → «not found» вместо SQLSTATE 22P02 на pgsql; 1 файл, pgsql/sqlite зелёные |
| documents `CollaborationEnabledTestCase+DatabaseMigrations` ZERO TESTS | партиция целиком serial: `empty_passes: ["parallel","serial"]`, раннер учитывает `parallel` для single/parallel проходов |

Остаётся: azgard RealAzgard* — внешний репозиторий azguard (`/home/vostrikov/projects/packages/azguard`) отвергает неизвестный ключ конфигурации,
который передаёт vaulter; несовпадение версий двух проектов, вне этого репозитория.
