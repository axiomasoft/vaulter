# Roadmap — 2026.09.29-№1-VAULTER-STABLE

**Обновлён:** 2026-10-02 · **Соответствует plan.md:** P0 детализирована (P0.1–P0.4), завершение исправлений — bounded recheck по D08;
P1 детализирована до закрытия P0 (D12; исполнение — после `task:plan-close … P0`); P2–P8 — skeleton в `backlog/` (катящийся дизайн, D03)

## Жизненный цикл плана (D03, уточнения P0 — D07/D08)

Для каждой фазы по порядку: `task:plan-design 2026.09.29-№1-VAULTER-STABLE Pn` (`backlog/Pn.md` → `phases/Pn/`) →
`task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` → `task:plan-audit 2026.09.29-№1-VAULTER-STABLE design` (независимая модель)
→ исполнение строк execution-sheet → O-3-CI/hosted CI итогового candidate (P0, D07) →
`task:plan-audit 2026.09.29-№1-VAULTER-STABLE Pn` (фазовый аудит, B5; candidate/run-bound consent D03) →
`task:plan-close 2026.09.29-№1-VAULTER-STABLE Pn` → дизайн следующей фазы по фактическому коду. Дизайн вперёд не делается.
Дизайн фазы обязательно производит `artifacts/coverage/ledger-Pn.tsv` и `artifacts/Pn-design/<Pn.m>-opus.md` для каждого
item; `opus-coverage.py check` exit 0 — условие finish, фазового аудита и закрытия (D06).

Для текущего дизайна P0 после независимого RED и исправлений D07 повторную проверку
до GREEN проводит gpt-6-sol/high в той же сессии по D08. Ещё один полный независимый
design audit не требуется; фазовая проверка реализации остаётся обязательной.

## Карта фаз

| Фаза | Суть | Маршрут (детали — execution-sheet) | Зависимости | Owner gate | Релиз |
|:--|:--|:--|:--|:--|:--|
| P0 | окружение, baseline, харнесс конкурентности, фикстура 0.2, ADR 0019–0024 | impl/medium, fr/medium | — | O-1 роль PG, O-2 MySQL | — |
| P1 | исправления T0 на текущих именах (детализирована, D12) | fr/high, impl/low–high, eco/low | P0 закрыта (D12) | O-3-CI, O-3-PR | 0.3.0 (D02) |
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
| O-3-CI | push итогового candidate plan2/P0 и запуск tests.yml на этом ref | после локальной Validation/review всех items, **до** фазового аудита P0 | отдельный явный запрос владельца; `git push -u origin plan2/P0`, затем `gh workflow run tests.yml --ref plan2/P0` (D07) |
| O-3-PR | PR и merge ветки фазы | после GREEN фазового аудита | отдельный явный запрос владельца; для P1–P8 порядок push уточняется при дизайне |
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
P0 (зависимости P0.1 → B0-A и P0.1 → P0.4) и записал fingerprint дизайна. Исправления D07 требуют повторного finish; перед исполнением нужен GREEN design audit текущего fingerprint.
Строка 1 — первая (bootstrap скриптов, затем --check/O-1); строки 2 и 3 после P0.1 независимы друг от друга; строка 2 требует GREEN P0.1 и O-2
до P0.3. Строки 4–12 (P1) допускаются только после `task:plan-close … P0`, `task:plan-design … finish` и GREEN
`task:plan-audit … design` для P1 (D03, D12); порядок 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12.

| Admission | Command | Route | Review | Executor (provider/model + effort) | Independent Reviewer + effort or self-check | Auto-fix executor + effort/limit | Checkpoint | Order / short risk reason |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| 1 · solo P0.1 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.1` | `implementation/medium` | `light` | `codex/gpt-6-sol/medium` | self-check + affected checks | `codex/gpt-6-sol/medium`, ≤2 rounds | design audit GREEN; bootstrap, затем O-1 preflight | Первым; окружение и baseline — предусловие P0.2/P0.3/P0.4 |
| 2 · B0-A | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.2 P0.3` | `implementation/medium` | `full` | `codex/gpt-6-sol/medium` | `cursor/grok-4.7/high`, fresh | `codex/gpt-6-sol/medium`, ≤2 rounds | P0.1 GREEN; O-2 до P0.3 | P0.2 → P0.3; харнесс и фикстура — доказательная база T0 и upgrade |
| 3 · solo P0.4 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.4` | `frontier/medium` | `light` | `claude-code/claude-opus-5-5/medium` | self-check + docs build | `claude-code/claude-opus-5-5/medium`, ≤2 rounds | design audit GREEN; P0.1 GREEN и baseline | После P0.1 независим от B0-A; перенос AD в ADR без переосмысления |
| 4 · solo P1.1 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.1` | `implementation/medium` | `full` | `codex/gpt-6-sol/medium` | `cursor/grok-4.7/high`, fresh | `codex/gpt-6-sol/medium`, ≤2 rounds | P0 закрыта (D12); P1 finish + design audit GREEN | Первым в P1: `VaulterDatabase` нужен P1.2/P1.5/P1.6 |
| 5 · solo P1.8 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.8` | `implementation/low` | `light` | `codex/gpt-6-sol/low` | self-check + affected checks | `codex/gpt-6-sol/low`, ≤2 rounds | P1.1 GREEN | Контракт исключений до P1.2/P1.9 |
| 6 · solo P1.4 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.4` | `implementation/medium` | `light` | `codex/gpt-6-sol/medium` | self-check + affected checks | `codex/gpt-6-sol/medium`, ≤2 rounds | P1.8 GREEN | `eventId`; независим от остальных |
| 7 · B1-B | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.9 P1.10 P1.11` | `implementation/medium` | `full` | `codex/gpt-6-sol/medium` | `cursor/grok-4.7/high`, fresh | `codex/gpt-6-sol/medium`, ≤2 rounds | P1.8 GREEN | P1.9 → P1.10 → P1.11; граница аутентификации и авторизация Filament |
| 8 · solo P1.7 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.7` | `implementation/high` | `full` | `codex/gpt-6-sol/high` | `cursor/grok-4.7/high`, fresh | `codex/gpt-6-sol/high`, ≤2 rounds | P1.1 GREEN; O-2 (MySQL) | До P1.2/P1.5: `PanelTablesBuilder`, хелпер фикстуры, миграция №3 |
| 9 · solo P1.2 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.2` | `frontier/high` | `full` | `claude-code/claude-opus-5-5/high` | `cursor/grok-4.7/high`, fresh | `claude-code/claude-opus-5-5/high`, ≤2 rounds | P1.1, P1.7, P1.8 GREEN | Блокировки дерева, ремонт N04 |
| 10 · solo P1.3 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.3` | `frontier/high` | `full` | `claude-code/claude-opus-5-5/high` | `cursor/grok-4.7/high`, fresh | `claude-code/claude-opus-5-5/high`, ≤2 rounds | P1.1, P1.8 GREEN | Алгоритм доступа; отдельная сессия по объёму |
| 11 · solo P1.5 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.5` | `frontier/high` | `full` | `claude-code/claude-opus-5-5/high` | `cursor/grok-4.7/high`, fresh | `claude-code/claude-opus-5-5/high`, ≤2 rounds | P1.1, P1.7 GREEN | Миграция данных CAS, repair-scopes |
| 12 · solo P1.6 | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P1.6` | `implementation/high` | `full` | `codex/gpt-6-sol/high` | `cursor/grok-4.7/high`, fresh | `codex/gpt-6-sol/high`, ≤2 rounds | P1.2, P1.5 GREEN | Последним: порядок блокировок D21 |

## P0 — последовательность команд

Все команды фазы по порядку; строки 3–5 — те же запуски, что строки 1–3 execution-sheet. Носители плана сводит автор плана.

| # | Шаг | Команда | Кто (provider/model + effort) | Native launch | Условие старта |
|:--|:--|:--|:--|:--|:--|
| 0 | Дизайн фазы (написан) | `task:plan-design 2026.09.29-№1-VAULTER-STABLE P0` | `claude-code/claude-opus-5-5/high` (автор плана) | `/task:plan-design 2026.09.29-№1-VAULTER-STABLE P0` | — ; владелец вызывает для `finalize-design` |
| 1 | Finish дизайна | `task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` | `claude-code/claude-opus-5-5/high` | `/task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` | шаг 0; `plan-lint` OK |
| 2 | Перепроверка исправлений дизайна (D08) | `task:plan-audit 2026.09.29-№1-VAULTER-STABLE design` | `codex/gpt-6-sol/high`, same-author recheck | текущая сессия, отдельный запуск не требуется | новый finish; исходный независимый RED и исправления D07 |
| 3 | P0.1 (sheet 1) | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.1` | `codex/gpt-6-sol/medium` | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6-sol -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.1'` | design audit GREEN; O-1 |
| 4 | P0.2, P0.3 (sheet 2) | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.2 P0.3` | `codex/gpt-6-sol/medium`; review `cursor/grok-4.7/high` | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6-sol -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.2 P0.3'` | P0.1 GREEN; O-2 |
| 5 | P0.4 (sheet 3) | `task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.4` | `claude-code/claude-opus-5-5/medium` | `claude --model opus` → `/task:plan-run 2026.09.29-№1-VAULTER-STABLE P0.4` | design audit GREEN; P0.1 GREEN и baseline |
| 5a | Hosted CI итогового candidate | O-3-CI, подробности ниже | владелец/отдельно разрешённый агент | после явного разрешения: push и workflow_dispatch | локальная Validation/review всех items |
| 6 | Фазовый аудит | `task:plan-audit 2026.09.29-№1-VAULTER-STABLE P0` | `codex/gpt-6.1-sol/high` (D11; не исполнитель по сессии) | `codex -C "$(git rev-parse --show-toplevel)" -m gpt-6.1-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-audit 2026.09.29-№1-VAULTER-STABLE P0'` | P0.1–P0.4 GREEN; hosted харнесс итогового SHA GREEN, остальные результаты без baseline-регрессий; candidate/run-bound consent |
| 7 | Закрытие фазы | `task:plan-close 2026.09.29-№1-VAULTER-STABLE P0` | `claude-code/claude-opus-5-5/high` | `/task:plan-close 2026.09.29-№1-VAULTER-STABLE P0` | фазовый аудит GREEN |
| 8 | Дизайн P1 | `task:plan-design 2026.09.29-№1-VAULTER-STABLE P1` | `claude-code/claude-opus-5-5/high` | `/task:plan-design 2026.09.29-№1-VAULTER-STABLE P1` | P0 закрыта |

## Hosted CI P0 — O-3-CI (D07)

Это будущая внешняя операция; изменение дизайна не даёт разрешения на её выполнение.
После явного запроса владельца публикуется итоговый candidate plan2/P0; только push этой ветки tests.yml не запускает.
На опубликованном ref выполняется `gh workflow run tests.yml --ref plan2/P0` (workflow_dispatch уже объявлен).
`gh run list --workflow tests.yml --branch plan2/P0 --event workflow_dispatch --limit 20 --json databaseId,headSha,status,conclusion,url`
выбирает новый run; при нескольких подходящих run фиксируется один точный ID, чужой SHA не принимается.
`gh run watch <RUN-ID> --exit-status` → сохранить фактический exit (ненулевой требует разбора по baseline);
`gh run view <RUN-ID> --json headSha,event,status,conclusion,jobs,url` → headSha итогового candidate,
event workflow_dispatch, status completed. В job `PHP 8.4 / Postgres (full schema portability lane)`
требуется success шага `Postgres: concurrency harness` (`if: always()` из P0.2).
Failure workflow/job допустим только из-за тех же классифицированных failures baseline P0.1;
новая ошибка, skipped харнесс, cancelled run или ошибка инфраструктуры блокирует приёмку.
Шаги, пропущенные вследствие известных baseline failures, фиксируются как skipped, без утверждения passed.
`gh run view <RUN-ID> --log` сохраняется как evidence: в этом шаге `4 passed`, `0 skipped`.
URL, run ID, SHA, JSON и лог передаются фазовому reviewer. Другой SHA, skipped/failure харнесса или отсутствующий шаг
не допускают audit GREEN/phase close; отказ разрешения — точное ожидание O-3-CI. Новый product commit требует новый run.

## P1 — последовательность команд (D12)

| # | Шаг | Команда | Кто (provider/model + effort) | Условие старта |
|:--|:--|:--|:--|:--|
| 0 | Дизайн фазы (записан 2026-10-02) | `task:plan-design 2026.09.29-№1-VAULTER-STABLE P1` | `claude-code/claude-opus-5-5/high` | — (D12) |
| 1 | Закрытие P0 | `task:plan-run … P0.2` (ревью PA01) → `task:plan-close 2026.09.29-№1-VAULTER-STABLE P0` | по `handoff.md` | P0.2 GREEN |
| 2 | Finish дизайна | `task:plan-design 2026.09.29-№1-VAULTER-STABLE finish` | `claude-code/claude-opus-5-5/high` | P0 закрыта; delta assessment при изменении продукта (D12 п.2) |
| 3 | Design audit | `task:plan-audit 2026.09.29-№1-VAULTER-STABLE design` | `codex/gpt-6.1-sol/high` (не автор дизайна) | finish записан |
| 4 | Исполнение | строки 4–12 execution-sheet | по строкам | design audit GREEN |
| 5 | Hosted CI | O-3-CI: `git push -u origin plan2/P1`, `gh workflow run <tests|drive|media|documents|filament>.yml --ref plan2/P1` | владелец/разрешённый агент | локальная Validation всех items; отдельный явный запрос владельца |
| 6 | Фазовый аудит | `task:plan-audit 2026.09.29-№1-VAULTER-STABLE P1` | не исполнитель P1 | `audit-admission/v2` consent |
| 7 | Закрытие | `task:plan-close 2026.09.29-№1-VAULTER-STABLE P1` | `claude-code/claude-opus-5-5/high` | аудит GREEN |
