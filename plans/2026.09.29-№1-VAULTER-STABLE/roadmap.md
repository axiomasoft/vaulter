# Roadmap — 2026.09.29-№1-VAULTER-STABLE

**Обновлён:** 2026-09-29 · **Соответствует plan.md:** P0 детализирована (P0.1–P0.4), finish записан, ждёт design audit; P1–P8 —
skeleton в `backlog/` (катящийся дизайн, D03)

## Жизненный цикл плана (D03)

Для каждой фазы по порядку: `task:plan-design 2026.09.29-№1-VAULTER-STABLE Pn` (`backlog/Pn.md` → `phases/Pn/`) →
`task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` → `task:plan-audit 2026.09.29-№1-VAULTER-STABLE design` (независимая модель)
→ исполнение строк execution-sheet → `task:plan-audit 2026.09.29-№1-VAULTER-STABLE Pn` (фазовый аудит, B5) →
`task:plan-close 2026.09.29-№1-VAULTER-STABLE Pn` → дизайн следующей фазы по фактическому коду. Дизайн вперёд не делается.
Дизайн фазы обязательно производит `artifacts/coverage/ledger-Pn.tsv` и `artifacts/Pn-design/<Pn.m>-opus.md` для каждого
item; `opus-coverage.py check` exit 0 — условие finish, фазового аудита и закрытия (D06).

## Карта фаз

| Фаза | Суть | Маршрут (детали — execution-sheet) | Зависимости | Owner gate | Релиз |
|:--|:--|:--|:--|:--|:--|
| P0 | окружение, baseline, харнесс конкурентности, фикстура 0.2, ADR 0019–0024 | impl/medium, fr/medium | — | O-1 роль PG, O-2 MySQL | — |
| P1 | исправления T0 на текущих именах | fr/high, impl/low–high | P0 | O-3 push/PR | 0.3.0 (D02) |
| P2 | канон имён, миграции, конфиг | impl/low–high (xhigh для переноса — при дизайне) | P1 | O-3 | 0.3.0 |
| P3 | авторизованный публичный API, профили, ingest, tenancy | fr/high, impl/medium–high | P2 | O-3 | 0.3.0 |
| P4 | агрегат Document, реестр типов, коллаборация, удаление, sequence | fr/high, impl/low–high | P3 | Docker для хаба (P4.5) | 0.3.0 |
| P5 | конвейер событий | fr/high, impl/medium | P4 | O-3 | 0.3.0 |
| P6 | REST v1, RFC 9457, OpenAPI, Vue SDK, хаб, Filament | impl/medium–high | P5 | O-3 | 0.3.0 |
| P7 | doctor, install, генераторы, тест-кит, metapackage, docs | impl/low–high, eco/medium | P6 | O-4 тег AzGuard | 0.3.x |
| P8 | release-гейты, upgrade, rc → 1.0.0 | impl/medium–high | P7 | публикация, данные 0.2 владельца | 1.0.0-rc.1 → 1.0.0 |

## Owner gates

| Gate | Что | Когда | Команда / действие |
|:--|:--|:--|:--|
| O-1 | роль PG `vaulter` + БД `vaulter_test` | до P0.1 PG-лейнов | D04 п.2 |
| O-2 | пользователь MySQL `vaulter` + БД `vaulter_test` | до P0.3 | D04 п.3 |
| O-3 | push ветки фазы, PR, merge | после фазового аудита каждой фазы | владелец |
| O-4 | тегированный релиз AzGuard | до P7.5 | владелец (D02, Q9) |
| — | ADR 0019–0024 `proposed` → `accepted` | после P0.4 | владелец |

## Критический путь и риски

| Риск | Где | Митигация |
|:--|:--|:--|
| Нет ролей БД на хосте | P0+ | O-1/O-2; preflight останавливает item, SQLite не подменяет PG |
| Ложно-зелёные гонки (SQLite, нет pcntl в CI) | P0.2, P1 | харнесс ForkRace с негативным контролем; `pcntl, posix` в PG-лейнах |
| Потеря данных при upgrade 0.2 → 0.3 | P1.2, P1.5, P2.1, P4.1 | фикстура P0.3, preflight-миграция, идемпотентные шаги, `repair-scopes --dry-run` |
| Массовое переименование ломает поведение | P2 | сценарии P1 как сетка, карта + инструмент, сухой прогон, golden API |
| Граница безопасности публичного API | P3 | frontier-контракты, full-ревью, security-review, V04/V27/V31 |

<!-- execution-sheet/v1 -->

Эта таблица — **единственное конкретное per-admission execution-расписание**. Finish 2026-09-29 подтвердил три запуска
P0 (зависимости P0.1 → B0-A; P0.4 независим) и записал fingerprint дизайна. P0 допускается только после GREEN design audit текущего fingerprint.
Строка 1 — первая (окружение, O-1 первым шагом); строки 2 и 3 независимы друг от друга; строка 2 требует GREEN P0.1 и O-2
до P0.3.

| Admission | Command | Route | Review | Executor (provider/model + effort) | Independent Reviewer + effort or self-check | Auto-fix executor + effort/limit | Checkpoint | Order / short risk reason |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| 1 · solo P0.1 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.1` | `implementation/medium` | `light` | `codex/gpt-6-sol/medium` | self-check + affected checks | `codex/gpt-6-sol/medium`, ≤2 rounds | design audit GREEN; O-1 preflight | Первым; окружение и baseline — предусловие P0.2/P0.3 |
| 2 · B0-A | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.2 P0.3` | `implementation/medium` | `full` | `codex/gpt-6-sol/medium` | `cursor/grok-4.7/high`, fresh | `codex/gpt-6-sol/medium`, ≤2 rounds | P0.1 GREEN; O-2 до P0.3 | P0.2 → P0.3; харнесс и фикстура — доказательная база T0 и upgrade |
| 3 · solo P0.4 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.4` | `frontier/medium` | `light` | `claude-code/claude-opus-5-5/medium` | self-check + docs build | `claude-code/claude-opus-5-5/medium`, ≤2 rounds | design audit GREEN | Независим; перенос AD в ADR без переосмысления |

## P0 — последовательность команд

Все команды фазы по порядку; строки 3–5 — те же запуски, что строки 1–3 execution-sheet. Носители плана сводит автор плана.

| # | Шаг | Команда | Кто (provider/model + effort) | Native launch | Условие старта |
|:--|:--|:--|:--|:--|:--|
| 0 | Дизайн фазы (написан) | `task:plan-design 2026.09.29-№1-VAULTER-STABLE P0` | `claude-code/claude-opus-5-5/high` (автор плана) | `/task:plan-design 2026.09.29-№1-VAULTER-STABLE P0` | — ; владелец вызывает для `finalize-design` |
| 1 | Finish дизайна | `task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` | `claude-code/claude-opus-5-5/high` | `/task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` | шаг 0; `plan-lint` OK |
| 2 | Независимый аудит дизайна | `task:plan-audit 2026.09.29-№1-VAULTER-STABLE design` | `codex/gpt-6-sol/high` (не автор) | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-audit 2026.09.29-№1-VAULTER-STABLE design'` | finish записан |
| 3 | P0.1 (sheet 1) | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.1` | `codex/gpt-6-sol/medium` | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6-sol -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.1'` | design audit GREEN; O-1 |
| 4 | P0.2, P0.3 (sheet 2) | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.2 P0.3` | `codex/gpt-6-sol/medium`; review `cursor/grok-4.7/high` | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6-sol -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.2 P0.3'` | P0.1 GREEN; O-2 |
| 5 | P0.4 (sheet 3) | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.4` | `claude-code/claude-opus-5-5/medium` | `claude --model opus` → `/task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.4` | design audit GREEN |
| 6 | Фазовый аудит | `task:plan-audit 2026.09.29-№1-VAULTER-STABLE P0` | `codex/gpt-6-sol/high` (не исполнитель по сессии) | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-audit 2026.09.29-№1-VAULTER-STABLE P0'` | P0.1–P0.4 GREEN |
| 7 | Закрытие фазы | `task:plan-close 2026.09.29-№1-VAULTER-STABLE P0` | `claude-code/claude-opus-5-5/high` | `/task:plan-close 2026.09.29-№1-VAULTER-STABLE P0` | фазовый аудит GREEN |
| 8 | Дизайн P1 | `task:plan-design 2026.09.29-№1-VAULTER-STABLE P1` | `claude-code/claude-opus-5-5/high` | `/task:plan-design 2026.09.29-№1-VAULTER-STABLE P1` | P0 закрыта |
