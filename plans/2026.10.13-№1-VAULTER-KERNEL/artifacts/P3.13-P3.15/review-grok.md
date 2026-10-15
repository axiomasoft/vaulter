# Ревью P3.13 + P3.14 + P3.15 — grok-4.7 (high), read-only, 3 дорожки

Маршрут: `SKM_ORCH_ROOT=…/skm-orch-dev/packages/orch plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high`.
Потоки — `review-{E,O,R}.jsonl`, итоги — `review-{E,O,R}.md`, брифы — `briefs/`.

| Дорожка | Вердикт |
|---|---|
| E (P3.13) | approve, 1 low |
| O (P3.14) | approve, находок нет |
| R (P3.15) | changes |

## Находки и решения

| # | Sev | Находка | Решение |
|---|---|---|---|
| R1 | critical | События за время `detached` не попадают в gap после re-register (`OutboxStore`) | Подтверждена чтением. Исправлено: detach двигает `reconciled_through_seq`, `reattach()` пишет gap по пропущенным durable-событиям. Тест `records the events committed while a consumer is detached…` |
| R2 | high | Replay подтверждает gap при пустом диапазоне/обрезанном голове | Исправлено: ack только если в диапазоне есть живые события и `first <= from`; иначе — только `--ack-only` |
| R3 | high | Дыра в середине/хвосте диапазона даёт exit 0 | Отклонено: у outbox_seq бывают легитимные дыры (rollback), плотность диапазона не проверяема; потеря головы ловится |
| R4 | medium | Гонка detach/acknowledge теряет запись `gaps` | Не исправлено (read-modify-write без блокировки acknowledge); вынесено владельцу |
| R5 | medium | `OutboxSettleWindowCheck`: 0 (без лимита) = ok | Не исправлено: решение D29 о семантике «нет лимита»; вынесено владельцу |
| R6 | low | docs architecture: устаревшие классы | Исправлено (en/ru) |
| E1 | low | int-enum → TypeError в `ModuleCompiler` | Исправлено: приведение к string → reason «malformed» |

## Прогоны

Все в `runs/`: static core/drive/documents, inventory, repo/core/drive/documents/filament sqlite, pgsql
Outbox/Webhooks/Events/Changes + serial-гонки + Schema, mysql Outbox/Webhooks, baseline, doc-links,
schema-snapshot sqlite/pgsql/mysql — зелёные. Побочно починены устаревшие ожидания: `KeyColumnInspector`
(outbox.drive_id/profile), `KeyColumnTypesTest` (46→44), `OutboxStagingTest` (порядок ключей JSON на MySQL).
