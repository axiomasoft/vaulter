# Remaining Work — единый исполнительный лист

**Единственный актуальный документ для работы — этот файл.** Здесь порядок, статусы, остаток, результаты и следующая команда. Старые `remaining-evidence/`, VLT-LOAD и архивные планы — доказательства/история; их launch/next-блоки не задают текущую очередь. Дублирующие START-HERE, ACTIVE и RUNBOOK удалены. Для обычных шагов новые status/handoff/carrier-файлы не создавать.

**Обновлено:** 2026-09-28. Владелец отложил тяжёлые cold-mutation прогоны до фиксации API перед версией 1.0. Последний счёт drive остаётся **86.20%** (1549 killed, 0 timeout, 248 escaped / 1797), gate 95 exit 1. Код drive не менялся. Шаг 13 (65.38%) и промежуточные 79.51%, 84.25%, 84.70% и 86.20% не переписаны. До фиксации API эти прогоны и тесты ради мутантов не запускать.

**Готово:** R25, шаг 28 — offline-классификация каждой сохранённой P6 recovery RED cell: original P6 36/108, R09 9/25, R09 follow-up 8/24, R10 9/26 (cells/attempts). Старый capture исправлен в R09; recovery outcomes и SLO-61 остаются RED. Новый package defect не локализован, corrective строки не добавлены. Полные архивы проверены по прежним digests; **35 offline P6 tests / exit 0**. [Классификация и raw checks](#r25--результат-шага-28-2026-09-28-offline-classification). Ранее R24 (27) исправил active concurrent duplicate: полные suites **SQLite 582 passed / 1895 assertions**, **PostgreSQL 581 passed / 1927 assertions**, final focused PG **81 passed / 298 assertions**, review без findings; [результат](#r24--результат-шага-27-2026-09-28-codepackage-tests). Pint/PHPStan прошли; прежние Rector/type coverage RED сохранены. R21 (24), [результат](#r21--результат-шага-24-2026-09-28-codepackage-tests), R04 (17, `326f69b`), R03 harness (16a), R27 (16b, `0160ce3`) и R03 memory-admission fix (`15170d5`) также готовы только в указанном scope. Live P6/SLO и mutation в R25 не запускались.

**Решение владельца, 2026-09-28:** **живого стенда до конца этого плана не будет.** Не ждать lab, не запрашивать возобновление live и не поднимать стенд для закрытия листа. Закончить доступные code/test, offline review/readback, синхронизацию и итоговый handoff. Новые live/load/chaos/SLO измерения исключены из текущего исполнения; обычные изолированные package tests и offline controls разрешены. Cold mutation и тесты ради мутантов по-прежнему отложены до фиксации API перед 1.0.

**Остаток:** **8 активных шагов**, **23 выполненных**, **12 отложенных** (14, 16c, 17a, 20, 21, 22, 23, 25, 26, 30, 31, 34), ещё 1 условная строка repairs вне активного счёта. Решение владельца исключило ещё шесть live-строк: активный остаток **14 → 8**, отложенные **6 → 12**, выполненные **23 → 23**. Это перенос scope, не получение product proof. Новых задач не добавлено. Остаток — шаги **18 → 19 → 32 → 33 → 35 → 36 → 37 → 38**; новые конкретные code/test findings получают отдельный corrective R перед зависимым шагом, без стенда и mutation. Это число шагов, не процент готовности продукта и не оценка времени.

Новые задачи нельзя добавлять молча: для каждой вставки нужны finding, причина, scope и изменение счётчика «было → стало». Повтор неуспешного шага остаётся в той же строке с последним результатом и причиной повтора. Если новый дефект не обнаружен, очередь не расширять.

**Следующий шаг — 18 / B07-R: независимый offline-переаудит доступного code/evidence scope.** Проверить B07-01/02 по исправленному R05 и сохранённому valid run `adcdcdb7…`, disposition B07-04 по R06/R08, а также код/gates R03/R04 и их offline checks. B07-03 current-source R03/R04 proof остаётся RED/REJECTED/unevaluated; B07-05 mutation отложен владельцем. Их отсутствие не препятствует завершению ограниченного аудита и handoff, но **не выдаёт product GREEN или live/B08 admission**. R24/R25 готовы; новых repairs для строки 29 не найдено. Следующий шаг выполнить в новой сессии; текущая правка листа аудит не запускает.

```text
$ task:review plans/REMAINING-WORK.md B07-R
```

Cold mutation (`>=95`, последовательный прогон на часы, тесты ради выживших мутантов) исключена из текущей очереди. Вернуться к ней после фиксации API, перед версией 1.0: к тому моменту имена и контракты перестанут ехать, и набор не придётся писать заново. До этого шаги 14, 22, 23 и 25 не запускать. Номера R — идентификаторы задач, а не порядок запуска. **Порядок запуска задаёт только таблица ниже, сверху вниз.** Выбирать строку СЛЕДУЮЩИЙ, пропуская все ОТЛОЖЕНО. Offline audits и итоговый handoff выполняются по доступным evidence без ожидания исключённых live-строк; missing product proof фиксируется как retained RED/unevaluated. Возврат исключённых измерений требует отдельной задачи владельца вне текущего плана. Справочник findings ниже не является очередью.

Статусы: **ГОТОВО** — выполнен именно указанный шаг; **СЛЕДУЮЩИЙ** — единственный разрешённый сейчас запуск; **ОЖИДАЕТ** — шаг ещё не выполнен; **УСЛОВНО** — нужен только при указанном результате предыдущего шага; **ОТЛОЖЕНО** — исключено из текущей очереди решением владельца: cold mutation ждёт фиксации API перед 1.0, все новые live-измерения исключены до конца плана. ОТЛОЖЕНО не означает выполненное измерение, product GREEN или принятие риска. ГОТОВО у измерения с RED означает, что измерение выполнено, а дефект остаётся в своём corrective route. ГОТОВО у кода не означает, что live-доказательство получено.

## Исполнительная таблица

В каждой строке один запуск и один результат. После запуска исполнитель отмечает эту строку ГОТОВО, добавляет ссылку/commit и переводит первую допустимую следующую строку в СЛЕДУЮЩИЙ. Не пропускать незавершённые зависимости. Если проверка выявила дефект, вставить corrective строку перед зависимым шагом; до исправления не двигать указатель дальше. Никакая строка ниже не разрешает автоматический запуск следующей задачи в том же запросе.

| Шаг | Статус | Работа / фаза | Исполнитель | Команда или действие | Результат / условие перехода |
|:--|:--|:--|:--|:--|:--|
| 01 | ГОТОВО | R01 — scoped retry implementation/proof | — | Не запускать повторно | [R01](remaining-evidence/R01.md), accepted bounded proof в R09; старый R01 run rejected |
| 02 | ГОТОВО | R02 — baseline inventory | — | Не запускать повторно | [R02](remaining-evidence/R02.md); cold baseline, не текущий >=95 proof |
| 03 | ГОТОВО | R09 и B02 — scoped runtime/verifier | — | Не запускать повторно | [R09](remaining-evidence/R09.md), [B02](remaining-evidence/B02-audit.md); SLO-61 RED сохранён |
| 04 | ГОТОВО | R10 — scoped endpoint evidence | — | Не запускать повторно | [R10](remaining-evidence/R10.md); M/L/24-hour остаются unevaluated |
| 05 | ГОТОВО | R14 — GC/CAS измерение | — | Не запускать старый R14 повторно | [R14](remaining-evidence/R14.md); throughput и batch cap RED → шаги R21/R22/R23 |
| 06 | ГОТОВО | R18 — media mutation до R21 | — | Не запускать повторно | [R18](remaining-evidence/R18.md); 95.33% PG+SQLite. Повторный прогон R22 отложен до фиксации API перед 1.0 и из этой строки не запускается |
| 07 | ГОТОВО | R19 — filament mutation | — | Не запускать повторно | [R19](remaining-evidence/R19.md); 95.51% SQLite |
| 08 | ГОТОВО | B07 — предыдущий аудит | — | Сохранить результат | [B07](remaining-evidence/B07-audit.md): FAILED, B08 не допущен; это выполненный аудит с открытыми findings |
| 09 | ГОТОВО | R05 — исправление кода B07-01/02 | GPT-6 Sol | Не повторять code lane | [R05](remaining-evidence/R05.md), `d042695`; 30 offline tests, live pending |
| 10 | ГОТОВО | R06 — dirty-20k измерение причины | Grok 4.7 | Не запускать повторно | [run 03f5d89d](VLT-LOAD/artifacts/R06/03f5d89d-e882-41e9-86e0-43eb0f93cd2e); класс: harness argv `artisan`. Drive не менялся. SLO-70 не переизмерен |
| 11 | ГОТОВО | R08 — решение по коду drive | Grok 4.7 | Не запускать повторно | Код не менялся. Exit 1 из R06 был вызовом стенда, не ошибкой `SweepUploadsCommand` |
| 12 | ГОТОВО | R08 — измерение SLO-70 на неизменённом коде | Grok 4.7 | Не запускать повторно | [run 8a9c70cf](VLT-LOAD/artifacts/R08/8a9c70cf-c531-48ac-bdce-d566726aed20): **RED**. 20 000 за 80.501 с, один батч 20 000, PHP peak 132644864. Живой PATCH пропущен, повтор 204 |
| 13 | ГОТОВО | R07 — cold drive mutation и gate | Grok 4.7 | Не запускать этот прогон повторно. Шаг 14 тоже не запускать: он ОТЛОЖЕНО | 65.38% sequential PG, gate 95 exit 1. [cold-pg-20260927](remaining-evidence/R07-checks/cold-pg-20260927/summary.json). Исторические 48.90% не переписаны |
| 14 | ОТЛОЖЕНО | R07 — тесты на 591 escaped и повтор cold PG | — | Не запускать до фиксации API перед 1.0 | Последний счёт **86.20%** (1549/1797), gate 95 exit 1. [cold-pg-step14-r07d](remaining-evidence/R07-checks/cold-pg-step14-r07d-20260928/summary.json). Исторические 65.38%, 79.51%, 84.25%, 84.70% не переписаны. Дожим до 95% отложен: API ещё меняется |
| 15 | ГОТОВО | R05 — affected live P5 cells | Grok 4.7 | Не запускать этот прогон повторно | [run 4e5b1c3e](VLT-LOAD/artifacts/R05/4e5b1c3e-03ae-4d2e-8a29-460df7f50a3b): 39 valid / 17 rejected, verify exit 1. Участие actors полное на valid cells. Overlap и быстрые primitive rejected → 15a |
| 15a | ГОТОВО | R05 — corrective capture controls | Grok 4.7 | Не повторять code lane | Commit `56015c8`; argv без `artisan`; `sweepable` читает RETURNING, не тег `UPDATE 1`; during-sample, начатый при живом акторе, сохраняется. 16 offline tests, exit 0. Drive не менялся |
| 15b | ГОТОВО | R05 — повтор rejected live cells | Grok 4.7 | Не запускать этот прогон повторно | Commit `ea77e00`; [run adcdcdb7](VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe): 56/56 valid, verify exit 0, digest `a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074`. R08 SLO-70 не переписан |
| 16 | ГОТОВО | R03 — full-window throughput / attribution | GPT-6 Sol | Не повторять этот run | Commit `ff0d0d3`; [run 4f565edf](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json): S0/S по 180s полностью; k6 exit 99, audits exit 2. Qualified proof **REJECTED**, class B unevaluated; observed throughput RED и причины сохранены. Historical RED не переписан → 16a–16c |
| 16a | ГОТОВО | R03 — corrective harness H01…H05 | GPT-6 | Не повторять code lane | Исходная revision `52e0e63`; [offline checks/source digest](remaining-evidence/R03-checks/16a-20260928-final/checks.json): 16 Python + 7 JS + 1 PHP, все exit 0. Distinct OP-06/07, accepted-byte/duration tags, primary blob FK, mandatory discovery, nonempty/sample/lab/source admission. Все SLO floors сохранены; live и mutation не запускались → 16b |
| 16b | ГОТОВО | R27 — package-core PostgreSQL bind-limit corrective | GPT-6 | Не повторять code lane | [Результат и команды](#r27--результат-шага-16b): большие ID sets добавляют 0 bindings; PG regression 65 540 descendants bigint/ULID, HTTP, access/deny/cursors; SQLite core 574 tests, PG Access+Schema 169 passed/4 skipped, PG ULID 5 passed, PHPStan/Pint exit 0. Mutation/live не запускались → 16c |
| 16c | ОТЛОЖЕНО | R03 — qualified full-window remeasurement | GPT-6 Sol | Не запускать до конца плана: стенда не будет | [Preflight](#r03--preflight-шага-16c-2026-09-28-live-отложен-владельцем) rejected: L-1/L-3/L-5 и storage admission не готовы; live не начат. R03-H06 исправлен inline, 18 Python + 7 JS tests exit 0. Qualified full-window proof RED/REJECTED/unevaluated сохраняется; будущий пересъём — отдельная задача вне плана |
| 17 | ГОТОВО | R04 — код capture/gates и offline tests | GPT-6 Sol | Не повторять code lane | Commit `326f69b`; [результат](#r04--код-и-offline-tests-2026-09-28-live-отложен): 29 Python + 7 PHP / 35 assertions; writers joined, errors propagated, validated fresh publication, actual-plan/trace identity gates; independent review без blockers |
| 17a | ОТЛОЖЕНО | R04 — live P3 trace/ANALYZE capture | GPT-6 Sol | Не запускать до конца плана: стенда не будет | Код/gates шага 17 готовы; current-source trace/actual ANALYZE/top-20/N+1 и OP↔SQL proof остаются unevaluated. Пересъём — отдельная задача вне плана |
| **18** | **СЛЕДУЮЩИЙ** | B07-R — независимый offline-переаудит | GPT-6 Astra | `$ task:review plans/REMAINING-WORK.md B07-R` | Read-only: B07-01/02 по corrected R05 + stored `adcdcdb7…`; B07-04 по R06/R08; R03/R04 code/gates/offline checks. Записать только B07-audit.md, сохранив прежний отчёт как историю. Live/mutation не запускать; missing current product proof оставить RED/REJECTED/unevaluated. Findings → named corrective R; product/live/B08 admission не выдавать |
| 19 | ОЖИДАЕТ | B07-R — синхронизация листа | Ответственный исполнитель | `task:dev plans/REMAINING-WORK.md B07-R-sync` | Прочитать новый offline-аудит, обновить только затронутые статусы/маршруты. После устранения actionable code/evidence findings следующий шаг 32/B14; 20–21 остаются ОТЛОЖЕНО. Аудитор сам свои R-статусы не меняет |
| 20 | ОТЛОЖЕНО | R13 — 1,000-anchor/tree measurement | Grok 4.7 | Не запускать до конца плана: стенда не будет | Реальные SQL/CTE/tree timings не получены; historical cost findings остаются unconfirmed/unevaluated. Измерение — отдельная задача вне плана |
| 21 | ОТЛОЖЕНО | R15 — реальная advisory collision | Grok 4.7 | Не запускать до конца плана: стенда не будет | Collision остаётся unconfirmed; 429-only её не доказывает. Подтверждение/опровержение — отдельная задача вне плана |
| 22 | ОТЛОЖЕНО | R16 — cold core mutation | — | Не запускать до фиксации API перед 1.0 | Sequential PG+SQLite `>=95` вернётся вместе с остальными cold-mutation строками, когда API зафиксирован |
| 23 | ОТЛОЖЕНО | R17 — cold documents mutation | — | Не запускать до фиксации API перед 1.0 | Sequential PG `>=95` вернётся вместе с остальными cold-mutation строками, когда API зафиксирован |
| 24 | ГОТОВО | R21 — media GC исправление | GPT-6 Sol | Не повторять code lane | [Результат и raw checks](#r21--результат-шага-24-2026-09-28-codepackage-tests): cap 1000, keyset/high-water progress, batch mark reads/locks; SQLite 127 passed, PG 132 passed, Pint/PHPStan/Rector/type coverage exit 0; review без blockers. Только `packages/media/`, без live/mutation/SLO claim. R14 RED сохранён до R23; R22 отложен |
| 25 | ОТЛОЖЕНО | R22 — cold media requalification | — | Не запускать до фиксации API перед 1.0 | Пересъём `>=95` после R21 отложен вместе с остальными cold-mutation строками. R21 и R23 его не ждут |
| 26 | ОТЛОЖЕНО | R23 — GC/CAS повторное измерение | Grok 4.7 | Не запускать до конца плана: стенда не будет | R21 code/package tests готовы; current-source SLO proof отсутствует. Historical R14 throughput/batch-cap RED сохранён. Измерение — отдельная задача вне плана |
| 27 | ГОТОВО | R24 — core duplicate delivery | GPT-6 Sol | Не повторять code lane | [Результат и raw checks](#r24--результат-шага-27-2026-09-28-codepackage-tests): DB lease + fenced settlement; SQLite 582 passed, PG 581 passed, final focused PG 81 passed; independent review без findings. Pint/PHPStan exit 0; прежние Rector/type coverage RED сохранены. Только `packages/core/`, без live/mutation/SLO claim |
| 28 | ГОТОВО | R25 — P6 recovery RED classification | GPT-6 Sol | Не повторять classification без нового evidence | [Результат и raw checks](#r25--результат-шага-28-2026-09-28-offline-classification): все RED cells сопоставлены; original capture → completed R09, остальные recovery/SLO-61 → retained RED, новых package repairs нет. 35 offline tests exit 0; historical failed-job identities неизвестны |
| 29 | УСЛОВНО | Новые repairs/requalification из R24/R25 | По назначенному scope | Вставить отдельные строки с новым R только при конкретном новом дефекте. Cold mutation сюда не возвращать | R25 новых repairs не выявил; сейчас строка не активируется и не блокирует R26. Новый reproduced defect → named corrective R перед измерением |
| 30 | ОТЛОЖЕНО | R26 — frozen-source full P6 matrix | Grok 4.7 | Не запускать до конца плана: стенда не будет | R24 code/tests и R25 classification готовы; current full P6 verdict отсутствует. Historical duplicate/recovery/SLO-61 RED, failed-job identity gap и M/L/24-hour unevaluated сохранены для R20 |
| 31 | ОТЛОЖЕНО | R11 — P7 live baseline | GPT-6 Sol | Не запускать до конца плана: стенда не будет | Код подготовлен, новый baseline `/ready`/named operations/metrics/seeded joins не измерен; readiness/recovery product proof остаётся unevaluated |
| 32 | ОЖИДАЕТ | B14 — независимый offline-аудит P7/code/evidence | GPT-6 Astra | `task:review plans/REMAINING-WORK.md B14` | После sync 19 и необходимых code/test repairs: prepared R11/P7 harness, fault/revert/reconcile gates, R24/R25 paths и сохранённые evidence. Записать только B14-audit.md; без live/chaos/mutation. Отсутствующие R11/R12 baseline/recovery и current mutation proofs сохранить как отложенные gaps, не выдавать product GREEN |
| 33 | ОЖИДАЕТ | B14 — синхронизация листа | Ответственный исполнитель | `task:dev plans/REMAINING-WORK.md B14-sync` | Перенести результаты offline-аудита, назначить/выполнить необходимые named code/test repairs, затем R20. R12 остаётся ОТЛОЖЕНО |
| 34 | ОТЛОЖЕНО | R12 — full tier-S P7 recovery matrix | Grok 4.7 | Не запускать до конца плана: стенда не будет | Новые first/retry attempts, DB↔object joins и full per-cell recovery verdicts не получены; historical RED/unevaluated сохранить для R20 |
| 35 | ОЖИДАЕТ | R20 — текущие факты и owner handoff без стенда | Grok 4.6 | `task:dev plans/REMAINING-WORK.md R20` | После offline audits/sync/repairs: SLO-by-tier с отдельными historical results/current-source gaps, CI/release/scheduler facts, все отложенные строки и решения владельца. Не ждать R23/R26/R11/R12 и не принимать риск/публиковать |
| 36 | ОЖИДАЕТ | B17 — итоговый offline-аудит handoff | GPT-6 Astra | `task:review plans/REMAINING-WORK.md B17` | Проверить факты R20, source/evidence identity, полноту deferred/RED/unevaluated и dispositions; отсутствие live не blocker завершения offline scope. Findings → named corrective rows; product GREEN и close/archive VLT-LOAD не выдавать |
| 37 | ОЖИДАЕТ | B17 — синхронизация листа | Ответственный исполнитель | `task:dev plans/REMAINING-WORK.md B17-sync` | Полный список code/tests, historical verdicts, current proof gaps, отложенных измерений и решений владельца; runnable хвостов кроме owner decision нет |
| 38 | ОЖИДАЕТ | Решение владельца | Владелец | Рассмотреть R20/B17 и каждую оставшуюся deviation | Только владелец принимает риск, решает публикацию/deployment и close/archive VLT-LOAD |

### R25 — результат шага 28 (2026-09-28; offline classification)

Выполнена только классификация сохранённого evidence, база **`6f477ef`**. Package/harness source, SLO-пороги и исторические verdicts не менялись. [readback.py](remaining-evidence/R25-checks/readback.py) читает committed full archives во временном каталоге: большие primary lifecycle/sink ledgers отсутствуют в некоторых browsable trees. Проверены прежние raw-tree digests до/после, archive SHA-256, run/cell/attempt identity, все product UUID enqueue/processing/completion counts и p95, sink multiplicity, primary Redis samples, failed-job deltas и RED aggregation. [Raw observations](remaining-evidence/R25-checks/readback.json) явно перечисляют **каждую RED cell и все её попытки**, включая rejected R01; [checks.json](remaining-evidence/R25-checks/checks.json) содержит команды/exits и input/log digests. Это readback исторических измерений, без нового run ID или stand admission.

| Сохранённый run | RED cells / attempts | Классификация и нынешний gate |
|:--|:--:|:--|
| P6 `59dfb7e1-bd32-4c87-ab6f-eb8f409607af` | **36 / 108** | Все `product-realistic.relay.i{20,100,200}.f{0,1,all}.{2xx,500,slow-under-timeout,over-timeout}`: **harness correction R09 выполнен**, historical RED сохранён. Sticky `failed_jobs=2`, отсутствующие dispatch UUIDs, fan-out/fixture contamination и короткое drain observation не дают package diagnosis. V1 verifier exit **0**, digest `d48c8ffd07fb11847cff3a5f80d4c56cfe9b2e3459ed78ce251ab9a9b66f3766`; это прежний evidence gate, не product GREEN |
| R01 `721dd40b-ce8e-410a-8ecf-58873644f038` | **19 / 55** | **Rejected family**, не accepted product evidence: invalid concurrent duplicate control; current verifier exit **1**. Capture/fixture route уже выполнен в R09; четыре ModelNotFoundException этого R01 не идентифицируют исходные два P6 jobs. Digest `48f8d50e7f6a7e4910da909518cb3be7884507e75c7166ebd54e2c5a7c7b50b6` |
| R09 `3df3b9e1-32b8-4e15-8b8f-c4efffe28f52` | **9 / 25** | **Retained RED → R26**; current verifier exit **1**, отсутствует run-scoped exposition. Digest `7bac6725d3af18ac57789e7f70f1fdc3d78b4f28fdc051ff7de2dda39124c270` |
| R09 follow-up `bc199ced-5b0f-4e77-b5ca-1a73055c0bfa` | **8 / 24** | **Retained RED → R26**; current verifier exit **1**, retry-command memory gauge = 0. Digest `bbfa77d90edc2664a158b37aa7cab0a8a936c7af8bfe90cefac3e745a184a0e6` |
| R10 `56823709-fe47-4173-b965-dc51ea523bcb` | **9 / 26** | **Retained RED → R26**; current verifier exit **1**, отсутствует run-scoped exposition. Digest `100d368f90e4c985a40fb4bb624128bda8ee6e483727dc4a7d4db302c9a2dad7` |

Telemetry gaps исправлены и независимо закрыты в **последнем разделе B02 от 2026-09-27** на scoped runtime `52cc42c8…`; это не новый полный P6 run и не разрешение overlay в старые raw files. Отдельный corrective R для уже исправленного capture не добавлен. Записанные SLO-58/64 GREEN и SLO-61 RED остаются историческими результатами своих измерений; полный R26 обязан пройти усиленный gate на frozen source без splice/overlay.

Все recovery RED последующих полных runs имеют **`failed_jobs_delta=[]`, `duplicate_http=0`, `backlog_slope=null`, `recovered=false`**. Ниже исчерпывающий список union из девяти cells; префикс каждой — `product-realistic.relay.`. Цифры в трёх run-колонках — номера RED-попыток; `—` означает отсутствие RED в этом run, а не product GREEN. R09 follow-up имеет восемь RED cells, поэтому число девять из первого R09 на него не переносится.

| Cell suffix | R09 `3df3…` | R09 `bc199…` | R10 `5682…` | Классификация / наблюдение |
|:--|:--|:--|:--|:--|
| `i20.f1.over-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 20 jobs; final pending/reserved **10/2**, processed **8/20** |
| `i20.fall.over-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 400 jobs; **390/2**, processed **8/400** |
| `i100.f1.over-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 100 jobs; **90/2**, processed **8/100** |
| `i100.fall.over-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 2000 jobs; **1990/2**, processed **8/2000** |
| `i200.f1.over-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 200 jobs; **190/2**, processed **8/200** |
| `i200.fall.over-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 4000 jobs; pending **3988–3990**, reserved **2**, processed **8–10/4000** |
| `i100.fall.slow-under-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 2000 jobs; pending **1531–1568**, reserved **2**, processed **436–474/2000** |
| `i200.fall.slow-under-timeout` | 1,2,3 | 1,2,3 | 1,2,3 | **Retained RED → R26**: 4000 jobs; pending **3487–3528**, reserved **2**, processed **476–518/4000** |
| `i20.fall.slow-under-timeout` | 1 | — | 1,2 | **Retained RED → R26**: predicate вернул timeout, последующий snapshot уже **400/400**, **400 HTTP**, обе очереди пусты. Нет первичного timestamp успешного drain внутри окна; поздний snapshot не меняет RED и не доказывает false timeout |

`run_burst()` наблюдает пустоту Redis до **20 s** после relay (steady — 60 s), затем отдельно читает Redis, DB и ledgers. `recovery_seconds` включает время подготовки/relay; это не точное время drain. В RED-попытках fault profile **не возвращался в 2xx**, successful package retry после fault revert не измерялся. Cleanup паузит воркеры, ждёт reserved/sink settling и удаляет pending fixtures; он не доказывает recovery оставшихся jobs. Для hang (8 s у serial sink, HTTP timeout 5 s) `business_failed` — ожидаемый результат HTTP failure, не Laravel failed job и не DLQ. На этих данных package defect не локализован: новая package R не назначена. Если R26 воспроизведёт дефект, его raw evidence должно дать отдельный corrective R с причиной и package scope.

**SLO-61 — retained RED → R26/R20.** В девяти 200-event / one-endpoint / 50-ms попытках R09/R09 follow-up/R10 recomputed jobs/s **16.1525–17.6772**, queue p95 **9.284–10.128 s**, process p95 **0.101–0.104 s**. Сохраняются пороги ≥50 jobs/s, queue p95 ≤5 s, process p95 ≤2 s. `php -S` sink последовательно обслуживает запросы: его идеальный 50-ms потолок — 20 HTTP/s; два синхронных product workers даже с независимым sink теоретически дают не более 40 HTTP/s до накладных расходов. Это объясняет ограничение атрибуции, **не новый measurement, не основание ослабить SLO или обещать GREEN после sink repair**. R26 сохраняет фактический RED; R20 передаёт владельцу явно названное отклонение. M/L и daily failed-job budget остаются **unevaluated**.

**Исторические failed jobs:** original P6 сохранил только sticky count 2; R01 удалил эти строки до R09. UUID, payload hash, job/exception classes исходных двух jobs из доступного evidence неизвестны. Нулевые deltas новых runs, R01 ModelNotFoundException и R24 passing tests не восстанавливают их и не закрывают historical RED. Concurrent 2-dispatch/2-HTTP residual остаётся измеренным историческим фактом; R24 исправил active overlap в code/tests, его current product verdict — отдельная задача R26.

| Проверка R25 | Exit | Фактический результат |
|:--|:--:|:--|
| `PYTHONDONTWRITEBYTECODE=1 python3 -B plans/remaining-evidence/R25-checks/readback.py` | **0** | [Readback](remaining-evidence/R25-checks/readback.json): все RED cells классифицированы, первичные joins/counts/p95 и archive/tree digests проверены; actual verifier subprocess commands/exits сохранены. Expected historical verifier exits **0/1/1/1/1** не объявлены общим PASS |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p6*.py'` | **0** | **35 tests**, [лог](remaining-evidence/R25-checks/python.log); printed integrity defects — ожидаемый negative control |
| `git diff --check` | **0** | Scoped docs/evidence diff без whitespace errors |

Package suites, live P6/SLO, chaos и mutation в R25 не выполнялись. **Результат очереди:** шаг 28 ГОТОВО; **15 → 14** активных, **22 → 23** выполненных; новых строк нет, строка 29 не активирована. **Рекомендация следующего шага:** R26 после явного возобновления live владельцем и готового lab; до этого он ОЖИДАЕТ и runnable строки СЛЕДУЮЩИЙ нет. R25 не запускает его автоматически.

### R24 — результат шага 27 (2026-09-28; code/package tests)

Выполнен разрешённый `packages/core/` scope. База — `c6b43c00f8558e13932c323b301a9314f313d241`; итоговый source rollup **`8968089f8db89fa943b7147e79620bf9b3280e110eb0a1fbf24cc029cb638ba0`** — SHA-256 канонического JSON map `path → file SHA-256` (sorted keys, compact separators). [checks.json](remaining-evidence/R24-checks/checks.json) хранит файловые digests, команды, exits, окружение и hashes raw logs. Live run/stand digest к обычным тестам не применяются.

- `DeliverWebhook` атомарно claim'ит delivery через nullable `claim_token` / `claim_until`, с CAS по attempts и проверкой состояния. Занятый claim пропускается без POST, attempts и delivery metrics. HTTP идёт **вне транзакции**; результат и снятие claim фиксируются вместе на соединении delivery model и только при совпадении токена. Старый воркер не перезаписывает результат нового. Lease — `max(retry_claim_seconds, webhookTimeout + 30)` секунд; crash/rollback оставляет recoverable claim, active lease исключён из `dueForRetry`, expired lease снова доступен.
- Новая anonymous upgrade-миграция `2026_09_28_000001_add_webhook_delivery_claims.php` добавляет nullable columns к существующим default/registered-panel таблицам на `vaulter.connection`; отсутствующие таблицы пропускает, partial upgrade и повторные up/down поддержаны. Fresh schema builder также содержит columns. **При обновлении установленного пакета нужна эта миграция до запуска новых воркеров.** Host-owned таблицы вне Config/panel mapping обновляет host.
- Red-before: nested overlapping jobs дают **2 POST вместо 1** и для 200, и для 500 ([лог](remaining-evidence/R24-checks/baseline-overlap.log.gz), exit 1). Настоящий PostgreSQL two-process regression с исходным `DeliverWebhook` из base revision падает во всех **3** outcomes: 200, 500, transport exception ([лог](remaining-evidence/R24-checks/baseline-concurrent.log.gz), exit 1). HTTP barrier удерживает первого воркера до завершения второго, соединения независимы. На исправлении второй воркер не отправляет HTTP, результат имеет attempts 1.
- Проверены также active-claim retry exclusion, expired-claim recovery, stale-owner fencing, configured connection без HTTP-транзакции, throwing custom-model settlement с rollback, сохранение существующей delivery при upgrade и default/panel/missing/partial schemas. [Независимый read-only review](remaining-evidence/R24-checks/independent-review.txt) — без Blocker/Major/Minor; reviewer выполнил diff/syntax checks, runtime suites выполнены основным агентом.

| Проверка | Exit | Фактический результат |
|:--|:--:|:--|
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: composer test -- packages/core/tests --no-coverage --compact` | 0 | **582 passed / 1895 assertions**, 3 PG-only skipped, 17 прежних risky — [лог](remaining-evidence/R24-checks/sqlite.log.gz) |
| Та же полная suite на `pgsql`, host `127.0.0.1:55432`, отдельная `vaulter_r24_core_test`, user `vaulter` | 0 | **581 passed / 1927 assertions**, 4 skipped, 17 прежних risky — [лог](remaining-evidence/R24-checks/pgsql.log.gz) |
| Final focused PG: `composer test -- packages/core/tests/Webhooks --no-coverage --compact` | 0 | **81 passed / 298 assertions**, включая все 3 two-process cases — [лог](remaining-evidence/R24-checks/final-focused-pgsql.log.gz). Полные suites предшествовали только косметической rename/concat/spacing правке; final focused проверяет итоговый webhook source |
| Pint на 8 изменённых/новых PHP files; PHPStan core src/database | 0 / 0 | [Pint](remaining-evidence/R24-checks/pint-final.log.gz), [PHPStan](remaining-evidence/R24-checks/phpstan-final.log.gz) |
| Rector dry-run на тех же 8 files | 2 | Прежние style suggestions в builder и двух существующих tests; [candidate](remaining-evidence/R24-checks/rector-final.log.gz) vs [HEAD baseline](remaining-evidence/R24-checks/rector-baseline.log.gz): **0 новых suggestions**, [comparison](remaining-evidence/R24-checks/rector-comparison.json). Новые файлы и job/model без suggestions; exit 2 не объявлен успехом |
| `composer test:types -- --memory-limit=512M --compact` (эквивалентный memory overlay в candidate) | 1 | Package **99,8%**, прежние untyped constants `TracingSpanNames` (file 75%); [baseline](remaining-evidence/R24-checks/types-baseline.log.gz) и [candidate](remaining-evidence/R24-checks/types-512m.log.gz) совпадают, source [byte-identical](remaining-evidence/R24-checks/type-baseline.json). Initial 128M fatal исправлен memory retry; min 100 не снижался |

Первоначальный PG запуск двух suites на одной task-owned DB пересёк `RefreshDatabase` и был rejected; final PG suites выполнялись последовательно. Fork failure cleanup исправлен до чистого red-before. После final readback `v_webhook_deliveries=0`, `v_webhook_endpoints=0` в отдельной `_test` DB. Общий live-стенд не использовался.

**Граница результата:** active concurrent duplicate исправлен кодом и обычными тестами. Lease expiry или crash после remote acceptance по-прежнему может привести к повторному POST: **at-least-once**, не exactly-once. Historical P6 concurrent 2-dispatch/2-HTTP, recovery RED и SLO-61 RED не переписаны; новый product verdict остаётся за R26. Mutation отложена до фиксации API перед 1.0. Следующий шаг — **28/R25**, без автоматического запуска из R24.

### R21 — результат шага 24 (2026-09-28; code/package tests)

Выполнен только разрешённый `packages/media/` code/test scope. База — revision `bce93930fc074d523e485d9b9eceb77f201bc47d`; итоговый source rollup **`5db6c3bf7f6fa5cf101c9c0702dcb710d330ccd869369b0f4667d2d64f4f18b9`**. Файловые SHA-256, рецепт rollup, точные команды/окружение/exits — [checks.json](remaining-evidence/R21-checks/checks.json). Live run ID и stand digest к обычным package tests не применяются.

- Все три прохода GC читают keyset-батчи **до 1000** с верхним ключом, зафиксированным в начале фазы. Изменение статуса не сдвигает offset; skipped-строки продвигают курсор, новые ключи выше границы ждут следующего прохода. Поддержаны bigint, sparse string и native PG UUID ключи, включая настроенные модели и нестандартное имя PK.
- Mark по умолчанию использует одну транзакцию на батч: PG advisory locks взяты первым statement в порядке уникальных `hashtext(scope|sha256)`, затем свежие blob rows и distinct live IDs читаются пакетно. На 2105 кандидатах с живыми ссылками тест доказывает **[1000, 1000, 105]** и **менее 20 SELECT**, вместо per-row state/edge reads. Переходы остаются через repository/model save. Custom models и Blob observers сохраняют прежние per-row locked rechecks, чтобы observer одной строки не сделал следующую строку устаревшей.
- Sweep/pending сохраняют отдельные locked SERIALIZABLE units и retry-on-40001. Grace, resurrection и живые ссылки проверяются после чтения батча под блокировкой. `vaulter_gc_batch_rows` теперь относится к реальным чекпоинтам; mark scan и sweep delete counters растут на завершённых батчах. При rollback следующего optimized mark батча остаются только успешно завершённые чекпоинты. Exact metric tests адаптированы к emission до завершения phase timer; checks не ослаблены.
- Добавлены 12 обычных регрессий: cap/progress и skipped границы, high-water inserts, observer liveness, смена state/edge/grace после дешёвого scan, rollback/checkpoints, configured string/UUID keys и PG lock namespace через независимое соединение. Независимый read-only review выявил и проверил исправления distinct live IDs и observer fallback; итог — без blockers.

| Проверка | Exit | Фактический результат / raw log |
|:--|--:|:--|
| SQLite: `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: composer test -- --no-coverage --compact` из `packages/media/` | 0 | **127 passed, 9 skipped, 4 прежних risky; 447 assertions** — [sqlite.log.gz](remaining-evidence/R21-checks/sqlite.log.gz) |
| PostgreSQL: тот же `composer test -- --no-coverage --compact`, `DB_CONNECTION=pgsql`, host `127.0.0.1:55432`, отдельная `vaulter_r21_media_test`, user `vaulter` | 0 | **132 passed, 4 skipped, 4 прежних risky; 459 assertions** — [pgsql.log.gz](remaining-evidence/R21-checks/pgsql.log.gz) |
| `composer lint:check`; `composer analyse` из `packages/media/` | 0 / 0 | Pint passed / PHPStan без ошибок — [pint](remaining-evidence/R21-checks/pint.log.gz), [phpstan](remaining-evidence/R21-checks/phpstan.log.gz) |
| `vendor/bin/rector process packages/media/src/Cas/BlobGarbageCollector.php --dry-run --no-progress-bar` из корня | 0 | No remaining changes — [rector](remaining-evidence/R21-checks/rector.log.gz); первый format-only exit 2 сохранён отдельно |
| `composer test:types -- --compact --memory-limit=512M` из `packages/media/` | 0 | **100.0%**, min 100 сохранён — [type coverage](remaining-evidence/R21-checks/type-coverage.log.gz). Первый запуск с default 128 MiB завершился memory exhaustion / exit 1; [его log](remaining-evidence/R21-checks/type-coverage-128M.log.gz) не объявлен успешным |
| `git diff --check` | 0 | Без whitespace errors — [diff](remaining-evidence/R21-checks/diff.log.gz) |

Обычные suites запускались последовательно: общий `Storage::fake` не изолирует одновременно запущенные SQLite/PG процессы. Первоначальный параллельный focused запуск столкнулся на fake-файлах; повторные последовательные focused и полные suites прошли. Тестовый PG preflight подтвердил ready на host endpoint и отдельную `_test` DB; миграции выполнял только пакетный RefreshDatabase. Четыре прежних risky — неизменённые observability tests с recorder assertions, которые PHPUnit не считает assertions; skipped не объявлены пройденными.

**Граница результата:** cap/progress/query-cost regression proof получен. Throughput SLO-76…78, RSS и lock health на live-стенде **не переизмерены**; historical R14 scan **747.90 rows/s RED** и batch **4000 RED** сохранены. CAS GREEN и GC delete/RSS GREEN из R14 также не переписаны. R23 ждёт решения владельца/готового lab; R22 не запускался и остаётся отложенным до фиксации API перед 1.0. Historical R18 **95.33%** относится к прежнему source, нового mutation claim нет. Следующий разрешённый code/test шаг — **27/R24**, без автоматического запуска из R21.

### R07 — результат шага 13

Запись прошедшего прогона, не команда. Повторять её и запускать шаг 14 запрещено до фиксации API перед 1.0.

Cold sequential PostgreSQL, без `--parallel`, с `--clear-cache`. База `vaulter_r02_drive_test` на `127.0.0.1:55432`. PHP 8.4.26 с pcov. Source `55d10b6afd864b2820061c31cc5dc84ec54d714c`, blob `SweepUploadsCommand.php` `6213ce898b9edc4787bc14110a852c1b3b7c2f78` тот же, что в шаге 11. Команда: `/usr/bin/php8.4 vendor/bin/pest --mutate --path=src --covered-only --clear-cache`. Pest exit 0, потому что `--min` в прогоне не стоял и знаменатель досчитался. Отдельный gate 95 на этом счёте дал exit 1. Фикстура того же gate: 94.99 → fail, 95.0 → pass.

Score **65.38%** = (1091 killed + 25 timeout) / 1707. Escaped 591, uncovered 0. Wall clock **3:50:24**. Поле Duration в логе (60.8 с) — артефакт последнего тика, не время прогона. Крупные группы выживших: TusUploadController 53, S3MultipartHandler 44, StagedUpload 43, UploadFileAction 34, NodeController 33. Raw: `plans/remaining-evidence/R07-checks/cold-pg-20260927/`. Исторические 48.90% не переписаны. Workflow `--min=43` не поднимался и не опускался.

### R07 — результат отложенного шага 14

Тот же cold sequential PostgreSQL, без `--parallel`, с `--clear-cache`. База `vaulter_r02_drive_test` на `127.0.0.1:55432`. Source `55d10b6afd864b2820061c31cc5dc84ec54d714c`, blob `SweepUploadsCommand.php` `6213ce898b9edc4787bc14110a852c1b3b7c2f78`. Команда та же. Pest exit 0, `--min` в прогоне не стоял. Отдельный gate 95 на последнем счёте 86.20 даёт exit 1.

Промежуточный повтор после первой пачки тестов: **79.51%** = 1420 / 1786. Escaped 366, timeout 0. Wall clock **3:12:40**. Raw: `plans/remaining-evidence/R07-checks/cold-pg-step14-reboot-20260927/`.

Полный повтор после первой пачки тестов: **84.25%** = 1514 / 1797. Escaped 283, timeout 0. Pest Duration **3:47:09**, wall clock **3:52:15**. Raw: `plans/remaining-evidence/R07-checks/cold-pg-step14-r07b-20260927/`.

Повтор после тестов с запрещающим драйвером прав: **84.70%** = 1522 / 1797. Escaped 275, timeout 0. Знаменатель тот же, 1797. Убито на 8 больше, чем в 84.25%. Pest Duration **3:17:48**, wall clock **3:20:10**. Pest exit 0, `--min` не стоял. Отдельный gate 95 на 84.70 даёт exit 1. Крупные остатки: StagedUpload 30, S3MultipartHandler 24, TusUploadController 23, NodeController 21, CopyNodeAction 19. Raw: `plans/remaining-evidence/R07-checks/cold-pg-step14-r07c-20260927/`.

Повтор после тестов на наблюдаемые мутанты: **86.20%** = 1549 / 1797. Escaped 248, timeout 0. Знаменатель тот же, 1797. Убито на 27 больше, чем в 84.70%. Pest Duration **3:13:17**, wall clock **3:15:42**. Pest exit 0, `--min` не стоял. Отдельный gate 95 на 86.20 даёт exit 1. Крупные остатки: StagedUpload 28, TusUploadController 23, S3MultipartHandler 21, CopyNodeAction 19, UploadFileAction 16. Raw: `plans/remaining-evidence/R07-checks/cold-pg-step14-r07d-20260928/`. Первый запуск шага (3 PostgreSQL-падения) и прерванный после перезагрузки прогон в зачёт не входят. Шаг 13, 79.51%, 84.25% и 84.70% не переписаны.

2026-09-28 владелец отложил строку 14 и остальные ещё не сделанные cold-mutation прогоны (шаги 22, 23, 25) до фиксации API перед версией 1.0. Дожим 86.20% → 95% и новые тесты ради мутантов до этого не делать: API ещё меняется, и набор пришлось бы писать заново. Счёт 86.20% остаётся последним измерением.

### R03 — preflight шага 16 (2026-09-28; live не выполнен)

[Raw preflight `b2e80447-c8b4-48ad-967f-a9728470b805`](VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/run-manifest.json), [commands/exits](VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/commands.json), [source archive](VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/source.tar.gz), [checksums](VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/sha256.json). Это инвентаризация условий, не новый product run. Source revision `55d10b6afd864b2820061c31cc5dc84ec54d714c`; digest фактических package/load исходников, включая dirty harness, `48d2dc9cbdc52b719b3486b140dfa684c5e9304e716dd8c36f498cf23d9838da`; stand-config digest `caa156087ae948fd53cd0aae645b2f8b8b14b43b5341b17800a89eda0bfc8fdd`.

На диске 9,8 GiB свободно; штатный hot-set S — 20 000 × ≈1 MiB (≈20 GiB), дополнительно нужны PG/WAL и запас под нагрузку. `load/seed/out/` отсутствует; staged manifest — historical S0 (400 hot objects), не доказанный полный S. Существующий остановленный стенд содержит 15,2 GiB MinIO-объектов и 1,3 GiB PG; эти данные не удалялись и не пересоздавались. Условия `reference-env.md` §5 сейчас не выполнены: governor `powersave`, `intel_pstate/no_turbo=0`, включённый swap с ≈5,4 GiB занятых страниц, сторонние Docker-стеки работают. Историческая оговорка P2.8 о S0-объёме на S-железе не превращается в доказательство полного tier-S baseline.

Focused `test_r03_summary.py`: 3/3, exit 0; `sh -n load/scripts/run.sh`: exit 0; `maind health --cwd --json`: connected, exit 0. Аудит сохранённого старого summary при declared 180s: exit 2, `full_window=false`, `proof_ready=false`; это ожидаемый отказ принимать historical ≈32s run. Throughput SLO-40/43/49/52 остаётся historical RED, новая атрибуция/class-B/full-window — unevaluated. Package/harness-код не менялся, load/mutation/chaos не запускались; read-only helper завершён, исходное состояние стенда сохранено.

Маршрут остаётся R03: подготовить lab-условия и достаточный диск (либо предоставить другой соответствующий стенд), затем новый immutable full-window run с S0/lab comparison, tagged metrics, sample counts и measured attribution. Удаление существующих данных требует явного запроса владельца по `AGENTS.md`; остановка сторонних стеков требует определения владельцем затрагиваемого scope. Шаг 16 не закрыт, R04 не допущен и не запускался; счётчик остаётся 19, новые задачи не добавлены.

### R03 — результат шага 16 (2026-09-28; qualified proof REJECTED)

[Immutable campaign `4f565edf-58ca-47a0-869f-b9148ebcd2b6`](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json), [commands/exits](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/commands.jsonl), [findings](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/harness-findings.json), [checksums](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/sha256.json). Этот результат заменяет preflight как текущий статус R03; preflight выше сохранён исторически. Source revision `55d10b6afd864b2820061c31cc5dc84ec54d714c`, фактический source digest `48d2dc9cbdc52b719b3486b140dfa684c5e9304e716dd8c36f498cf23d9838da`; архив не дублировался, все 1609 файлов совпали после работы. Package/harness-код и чужие изменения не менялись.

Стенд — новые scoped DB/buckets `vaulter_r03_4f565edf_s[0]` / `vaulter-r03-4f565edf-s[0]`, отдельный compose project, существующие cached images. Стандартные S0/S CPU/memory/VU limits сохранены; single-shot 5 MiB, TUS 8-MiB чанки, S3 64-MiB части. Seed S: 100000 tree nodes + документы, фактически nodes 105002, blobs 72099, hot objects 20000 (20965187300 bytes), activity 2000001, uploads 50000, outbox 10001. Первый seed S завершился PHP512M OOM, exit 255: rejected preparation. Повтор exit 0, 1210.230 с, seed-only PHP2G/cgroup3G, metrics/tracing отключены только в seed; SUT app остался PHP512M/2G. Сохранён сжатый baseline DB 58 MiB и object catalog. Cache seed-warm, cache drop не делался.

| Cell / evidence | Declared window | Summary duration | Exits | Результат |
|:--|:--|:--|:--|:--|
| [S0 `ab5edbe8`](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/s0-mixed-ab5edbe8-analysis.json) | 180 с | 190.345 с | k6 99; writer 0; audit 2 | Full window; TUS med/min 29.969/1.729, S3 putPart 64.975/13.901, single 49.505/10.707 MiB/s; download n0 |
| [S `740fb7e0`](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/s-mixed-740fb7e0-analysis.json) | 180 с | 202.539 с | k6 99; writer 0; audit 2 | Full window; qualified proof rejected, 0/15 class-B keys ≥100 samples; download/docs не участвовали |
| [S offset diagnostic `87b1f166`](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/s-tus-offset-87b1f166-analysis.json) | 3 × 160 MiB, 20 чанков по 8 MiB | wall 73.310 с | k6 0 | 57×204 + 3×201, 66/66 checks; три завершённых файла скачались HTTP 200. Bounded attribution, не mixed SLO proof |

У обоих mixed runs ноль interrupted iterations; раннего abort по threshold нет. Первое S0 окно остановилось около43 с; точный exit исходного k6 не был сохранён, stale historical summary явно rejected. Повтор использовал Go memory tuning внутри неизменённого k6 cgroup и сохранил exit/container inspect. Другой S0 pre-run отказал из-за отсутствующего pg_stat_statements; workload там не начинался. Эти попытки не входят в proof.

| SLO, tier S | Наблюдение полного S | Интерпретация |
|:--|:--|:--|
| SLO-40 / OP-02 | n95; med 17.021, min 1.660 MiB/s; floors 25/15 не пройдены | Observed RED; tagged data_sent=0 делает агрегатный byte-rate недостоверным |
| SLO-43 / OP-06 | n49, 49 accepted201; med 27.004, min 11.012 MiB/s | min ниже15; materialized gate ошибочно med>100 от complete. Это не отдельный valid verdict SLO-43/46 |
| SLO-49 / OP-17 | n5 accepted201; med 11.765, min 6.545 MiB/s | Observed ниже25/15; выборка sparse |
| SLO-52 / OP-08…10 | n0; data_received tag0 | unevaluated: пустой download pool, не product throughput RED |
| Class B | OP-02 n99, OP-06 n49, OP-01 n50; все15 ключей <100; docs keys n0 | unevaluated. Powersave/turbo/swap/сторонние стеки также исключают lab admission; header profile=lab сам по себе допуска не даёт |

Measured attribution: в mixed S app использовал в среднем1.885 из2 cores (p95≈1.999), PG0.077 и MinIO0.125 core; PG WAL sync 12.589 с за окно. В отдельном offset diagnostic accepted PATCH median вырос с97.542 мс при offset0 до1514.632–1861.755 мс в конце prefix, final commit3948.553 мс; Pearson r(nonfinal offset,duration)=0.921. При503316480 uploaded bytes MinIO получил5.819GB и отправил6.302GB; PG WAL sync лишь0.258 с. `StagedUpload::append()` читает накопленный prefix и переписывает объект на каждом чанке: измерения поддерживают rewrite amplification как одну причину TUS degradation. PHP fsync отдельно не измерен, единственная причина не объявлена. Server `single` counters включают финализацию TUS/S3 через общий UploadFileAction и не изолируют OP-17. S0/S имеют разные dataset/CPU envelopes, поэтому причинное сравнение улучшения не заявляется.

Новые строки добавлены явно: **19 → 18 → 21**. **16a / R03-H01…H05:** threshold collision OP-06/07, нулевые tagged byte counters, отсутствующий primary blob FK, silent empty/sparse pools и throughput до проверки HTTP acceptance; scope только harness/seed/gates. **16b / R03-P01:** [HTTP500 + исходный метод](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/product-bind-limit-finding.json), `packages/core/.../NodeReadRepository.php` OR-ит группы по900 IDs в один statement; общий bind count всё равно >65535. Owner root listing падает, хотя documents folder и5000 документов есть: это дополнительная причина нулевого docs participation. Отдельный package-core corrective **R27** обязателен; соседние пакеты в нём не менять. **16c:** после обоих исправлений нужен новый valid source/window run, поскольку текущий full window имеет потерянные/отсутствующие обязательные серии. Увеличение duration само эти defects не исправит.

Проверки: source verification1609/1609; прежние focused R03 tests3/3, shell syntax и coverage96/96 остаются применимы к неизменённому коду. Новые S0/S summary audits exit2 ожидаемо отвергли proof. [Cleanup readback](VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/cleanup-readback.json): удалены только105 добавленных этим workload объектов (6636437504 bytes); все20000 baseline keys/ETags/sizes сохранены. Сгенерированный S0 bucket очищен после readback; owner buckets/volumes и контейнеры не удалялись. Наши сервисы остановлены; foreign stacks/host settings не менялись. Перед новым прогоном восстановить scoped baseline DB и проверить timestamps/фикстуры: текущие post-workload DB rows сохранены для evidence, не являются clean baseline после cleanup объектов. На завершении свободно28488388608 bytes. Historical RED сохранён; R04 и corrective-код следующих строк не запускались; mutation не запускалась. Единственный NEXT —16a.

Фиксация результатов завершена: `56015c8` — corrective capture R05; `4d09915` — подготовленные тесты и historical R07 evidence; `ea77e00` — live evidence R05/R06/R08; `ff0d0d3` — R03 evidence и исполнительная очередь. Все четыре коммита отправлены в `origin/remediation/audit-vlt`, удалённый SHA проверен. Перед commit/push прошли 16 offline tests R05, 3 R03 summary tests, PHP syntax 15/15 и staged checksums R03 236/236. Runtime R07 и mutation не запускались. Mutation cache исключён из Git и сохранён локально; raw terminal captures/SQL dumps сохранены побайтно. Остаток **21**, единственный следующий шаг **16a**; code lane этого шага ещё не выполнен.

### R08 — результат шагов 11 и 12

Шаг 11 — решение, менять ли `SweepUploadsCommand`. Не менять. R06 показал, что historical exit 1 возникает из-за слова `artisan` в команде стенда: testbench не запускает свип. Это не основание править drive.

Шаг 12 — измерение того же файла на стенде tier S. Source `55d10b6afd864b2820061c31cc5dc84ec54d714c`, blob `SweepUploadsCommand.php` `6213ce898b9edc4787bc14110a852c1b3b7c2f78` совпал с HEAD. Команда без слова `artisan`: `php -d auto_prepend_file=/app/load/scripts/p5-php-peak.php vendor/bin/testbench vaulter:sweep-uploads --no-interaction`.

| Проверка SLO-70 | Порог | Измерено | Вердикт |
|:--|:--|:--|:--|
| Один тик | ≤ 5 с | 80.501 с, exit 0, stdout `Swept 20000 stale upload sessions.` | RED |
| Скорость | ≥ 500 сессий/с | 20 000 / 80.501 с = 248.4 сессии/с | RED |
| Батч за тик | ≤ 1 000 | одна команда сняла 20 000; `/metrics` ответил 404, histogram с CLI туда не попал | RED |
| Память команды | < 256 MiB | PHP peak `memory_get_peak_usage(true)` = 132644864 (126.5 MiB). Это не RSS. Gauge на `/metrics` не наблюдался | под потолком по снятому peak |

Итог SLO-70 этого прогона: **RED**. Старый historical RED не переписан. После прогона строк `p5-dirty` нет.

Гонка и повтор, одна сессия `2638fe76-847d-4c86-bff2-7579cf8e723e`: PATCH держал advisory lock, свип ответил `Swept 0 stale upload sessions (1 skipped — a PATCH is in flight)`, stderr `refused`, exit 0. Первый PATCH 204, повторный PATCH 204, статус `uploading`, `received_bytes` 4 и после повтора. Это одна сессия, не матрица N. Сессия после readback удалена.

### R06 — результат шага 10

Run `03f5d89d-e882-41e9-86e0-43eb0f93cd2e`. Source `55d10b6afd864b2820061c31cc5dc84ec54d714c`, worktree clean на preflight, source digest `fa6ad3768e6334e50025d2963b5e2774530208cf18d2b30ee6153134da692897`. Стенд tier S: app `sha256:d8714c578c47299f4a07604fe2cd8bb31e4fa47d46343dc281d7cfb48f16c166`, postgres `sha256:5f196f3b1342203facb25a787e4a0046c90d94600d511097e5b62e8f9551c255`, pgbouncer `edoburu/pgbouncer` `sha256:5e25bffdcb60de22f691800553669bd6b8976bcb549a1293f35de4ecdad38e0a`, pool 8/200/120. Перед прогоном БД была пустой; storage `1` создан штатным `GET /load/storage` с `X-Load-Actor: 1`.

Команда probe, три попытки: `php -d auto_prepend_file=/app/load/scripts/p5-php-peak.php vendor/bin/testbench artisan vaulter:sweep-uploads --no-interaction`. Все три: exit 1, seeded/remaining 20000/20000, processed 0, elapsed 0.324 / 0.234 / 0.17 с, PHP peak `memory_get_peak_usage(true)` = 36175872, batch histogram null. Stdout: `Command "artisan" is not defined.` Stderr: только строка peak. Это тот же argv, что у historical P5 (`c8bedfe`): testbench получает имя команды `artisan` и завершается до `vaulter:sweep-uploads`.

Контроль после cleanup, ноль due-строк `p5-dirty`, тот же prepend, без токена `artisan`: exit 0, stdout `Swept 0 stale upload sessions.`, peak 38273024. Это не измерение 20k и не SLO-70. Исторический SLO-70 RED не переписан. Cleanup readback: `p5-dirty` = 0; counts storages 1, nodes 1, uploads 0, blobs 0. Raw: `plans/VLT-LOAD/artifacts/R06/03f5d89d-e882-41e9-86e0-43eb0f93cd2e/`.

### R03 — результат шага 16a (2026-09-28; corrective harness)

R03-H01…H05 исправлены в scope load/gates, load/k6, load/scripts/run.sh и load/app/Console/SeedLoadDatasetCommand.php. Исходная revision `52e0e63d8b566aa7d2d1585fc8288873beac06e9` (точный SHA — в checks.json); source digest package/load файлов с фактическими правками `782651cb3db6fe18422c7af59f207318510481a5c1b4f626e1ce508580504535`. [Raw commands/exits/logs](remaining-evidence/R03-checks/16a-20260928-final/checks.json), validation run ID `4a7af276-09f0-410d-a821-a44701ee4dc5`. Это offline validation кода, не product run; stand digest отсутствует, стенд не менялся.

OP-06 putPart и OP-07 complete имеют разные tagged trend selectors с прежними floors 25/15 и 100 MiB/s на S; все исходные floors S0/S/M/L сверены между JS builder и Python reader. Скорость, payload-byte counters и accepted duration пишутся только после принятого ответа; отдельно сохраняются accepted/rejected counts. TUS учитывает оба half-chunks при resume. Complete учитывает processed bytes отдельно, без повторного sent-byte count. Download с discarded body учитывает Content-Length только при HTTP 200 и сохраняет OP-08/09/10 participation. Server `protocol=single` по-прежнему включает TUS/S3 finalization; client OP-17 изолирован, product counters не менялись.

Hot и metadata-only file versions получают primary `blob_id`, совпадающий с GC data-edge; seed version обновлена, старый manifest не переиспользуется как исправленный seed. SQLite in-memory regression проверила обе связи и чтение/hash горячего объекта. Discovery теперь явно отказывает при listing HTTP500, отсутствии documents folder, пустом docs pool и отсутствии реально скачиваемых файлов; увеличение duration не скрывает R03-P01.

Evidence admission требует непустые class-A серии, ≥100 samples для всех 15 class-B ключей с неизменёнными thresholds, participation OP-08/09/10, окно ≥180s, source/run/dataset/stand identity и отдельные raw lab checks L-1…L-7. `run.sh` требует run-bound `LAB_FACTS`; read-only helper заново измеряет governor/boost, физическое разделение cpuset, foreign Docker stacks и swap/memory limits. Host jobs/reserved cores, storage layout, warm/cold cache и reset outputs требуют raw evidence от исполнителя будущего прогона; profile=lab не заменяет эти факты. Неизвестные условия не допускаются; настройки хоста helper не меняет. Точный k6 exit сохраняется отдельно; stale summary и изменение исходников за окно отвергаются. Valid measured RED остаётся fail, отсутствие/недостаток evidence — unevaluated.

Проверки, все exit 0: `python3 -B -m unittest discover -s load/gates -p 'test_r03_*.py'` — 16 tests; `node --experimental-vm-modules --test load/gates/tests/r03-k6.test.mjs` — 7 tests; `vendor/bin/pest load/tests/Feature/R03SeedFileVersionsTest.php --no-coverage` — 1 test/10 assertions; focused Pint, PHP syntax, `sh -n load/scripts/run.sh`, `git diff --check`. JS HTTP и runner Docker — offline fakes; PHP — только SQLite in-memory и fake storage. Эти проверки не доказывают tier-S throughput, PostgreSQL listing или current-source mutation. Live, mutation, R27 и R04 не запускались; historical raw runs и SLO RED/REJECTED не переписаны. Остаток **21 → 20**, единственный NEXT — **16b / R27**, затем pending 16c.

### R27 — результат шага 16b

Исходная revision `531393a`; scope кода/тестов только `packages/core`. На отдельной PostgreSQL 16 regression воспроизвела исходную ошибку: 65 545 bindings в одном statement, `number of parameters must be between 0 and 65535`, exit 2. OR-группы не ограничивали общий bind count.

`NodeReadRepository` теперь сохраняет bound `whereIn` для ≤900 IDs; большие sets используют внутренний `EscapedKeySet` (`Expression`). Grammar оборачивает имя столбца, а `escape()` делегирует цитирование текущему PDO при компиляции запроса. ID set добавляет 0 bind-параметров; int/string не кастуются. Общий helper применяется к listing, shared candidates и subtree anchors. Один AND-предикат сохраняет base filters, soft-delete scopes, SQL ordering и cursor pagination; candidate-superset и exact per-action deny не изменены. SHA-256: `NodeReadRepository.php` — `e66a20839b59fa9b3712411ead88193c93690c8e5bb09011baf3b31540e78595`; `EscapedKeySet.php` — `f136ec57213a38b6a074b7234ac609b002e7c64215f4a8b61976fe59f8b1430a`.

Добавлены 6 regression cases: реальные 65 540 descendants для bigint (ключи >2^53) и ULID; HTTP listing без 500; cursor вперёд/назад при одинаковой position; чужой storage и ungranted sibling исключены; exact deny возвращает 403, другой actor получает пустой set. Общий predicate отдельно проверен на строковом bigint, ULID, кавычках/backslash/SQL payload, base filter, soft deletes и границах 0/900/901. Independent read-only review окончательного diff: blockers не найдены. Wide integration tests для sharedWith/множества subtree anchors отдельно не выполнялись; их общий predicate проверен напрямую.

Проверки на окончательном коде (все exit 0):

- `DB_CONNECTION=sqlite DB_DATABASE=:memory: composer test -- packages/core/tests --no-coverage --log-junit /tmp/vaulter-r27-core.xml` — 574 passed, 1849 assertions; Pest также сообщает 17 risky в существующих metrics tests с 0 PHPUnit assertions, это не скрыто как отсутствие issues.
- PG env: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55437 DB_DATABASE=vaulter_r27_test DB_USERNAME=vaulter DB_PASSWORD=r27-test-only`. `composer test -- packages/core/tests/Access packages/core/tests/Schema --no-coverage` — 169 passed, 4 skipped, 344 assertions. Skipped не объявлены проверенными.
- В том же PG env `composer test -- packages/core/tests/IdStrategy/Ulid --no-coverage` — 5 passed, 36 assertions, включая wide ULID regression.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` — 0 errors.
- `vendor/bin/pint --test packages/core/src/Repositories/Drive/NodeReadRepository.php packages/core/src/Repositories/Drive/EscapedKeySet.php packages/core/tests/Access/WideNodeListingTest.php packages/core/tests/Concerns/ExercisesWideNodeListing.php packages/core/tests/IdStrategy/Ulid/WideNodeListingTest.php packages/core/tests/Schema/NodeKeyNarrowingTest.php` — passed; `git diff --check` — exit 0.

PG lane использовал отдельный ephemeral container `vaulter-r27-postgres`, DB `vaulter_r27_test`, tmpfs без persistent volume; image `sha256:de3a4eab8fdfa507ea92aac488b916b08089e515db49b055fe71dfa271ba3a28`. До тестов readiness и имя/пустая public schema проверены. После проверок container остановлен. Общий load stand не использовался. R03 live/16c, R04 и mutation не запускались; это corrective proof, не измерение SLO/throughput. Исторические RED/REJECTED сохранены. Остаток **20 → 19**, единственный NEXT — **16c / R03**.

### R03 — preflight шага 16c (2026-09-28; live отложен владельцем)

Это preflight и исправление обнаруженной ошибки допуска, не full-window measurement и не закрытие 16c. Владелец ответил: **«Сохранить preflight и отложить live R03»**. Остаток активной очереди **19 → 18** только за счёт отложения 16c; 19 выполненных строк не изменились. Новая задача/строка не добавлялась; дальнейшая очередь ждёт R03, R04 не допущен.

[Первый preflight `af3c88b5`](VLT-LOAD/artifacts/R03/af3c88b5-ea6d-42f8-8cb2-f6ec466895c8/run-manifest.json), [Compose resource facts](VLT-LOAD/artifacts/R03/af3c88b5-ea6d-42f8-8cb2-f6ec466895c8/stand-resources.json) и [helper error](VLT-LOAD/artifacts/R03/af3c88b5-ea6d-42f8-8cb2-f6ec466895c8/lab-helper-error.txt) воспроизвели **R03-H06**: `docker compose config --format json` выдаёт `mem_limit`/`memswap_limit` как decimal byte strings (`"2147483648"`), а `r03_lab.observe_host` сравнивал строку с числом и падал с TypeError. Source `0160ce3e38d6d2cd5242732931cfc391aa21afe9`, digest `60b88bef9a0c9d61a3b51100cdbbdd7c52050dfd4344686cbf24eec87501168e`; исходные байты 1618 файлов сохранены в source archive/checksums.

Bounded inline repair в рамках R03: только `load/gates/r03_lab.py` и `test_r03_lab.py`. Memory admission теперь принимает integer/decimal byte string, сравнивает численные байты и отвергает отсутствующие/невалидные значения, bool/float и различающиеся memory/swap limits. Новые регрессии воспроизвели TypeError и ошибочный допуск bool/float (до исправления exit 1), после исправления весь focused Python suite — 18 tests exit 0; JS suite — 7 tests exit 0; runner shell syntax — exit 0. Пороговые значения/SLO и package-код не менялись. Это завершённый inline repair до окна; measurement остаётся невыполненным.

[Повторный preflight `0ff73501`](VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/run-manifest.json), [commands/exits](VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/commands.json), [host facts](VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/host-observations.json), [checksums](VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/sha256.json). Source revision та же, с фактическим inline repair: digest `94f282f338ac7f200ea98486be6e5642dcf6b80cab3d930d2bb0cb962ea869a9`; отдельный архив фиксирует эти байты. Resource-only stand digest `6a81abf7ad983ebf7bd2e46d83189f57bc906d22fbc69e3d4d7cb5d01fe67875`; credentials из compose/inspect не сохранялись.

Host admission не готов: governor `powersave`, `intel_pstate/no_turbo=0`; 19 foreign containers; swap включён, занято ≈5.8 GiB. Load SUT остановлен, поэтому actual cpuset/memory limits пока не подтверждены (L-2/L-5); это не product failure. На NVMe свободно ≈27 GiB, вторичный диск — <1 GiB. Существующий PG volume — 5 377 328 KiB; отдельное PG storage или доказанно помещающийся объявленный tmpfs пока не подготовлены (L-4). Warm/cold declaration и run-bound reset outputs L-6/L-7 отсутствуют. Noninteractive sudo требует пароль. [Конкретный scope подготовки](VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/host-preparation-proposal.json) сохранён как owner-deferred, действия не выполнялись.

Live/load/mutation/chaos не запускались, owner data/volumes не удалялись, foreign containers и настройки хоста не менялись. Preflight raw outputs/source archives/checksums сохранены; historical RED/REJECTED шага 16 не переписаны. Для возобновления нужны явное решение владельца и lab L-1…L-7; затем новый immutable source/stand/dataset freeze и qualified S0/S окна. Сейчас runnable NEXT отсутствует, **16c/R03 отложен**, R04 и зависимые шаги ждут.

### R04 — код и offline tests (2026-09-28; live отложен)

Владелец уточнил scope: **«живого стенда пока не будет, делаем по максимуму код и тесты»**. Это разрешение на отдельный code/test срез R04 без valid live R03. Код шага 17 выполнен, commit `326f69b`. Измерение вынесено в 17a и не выполнено: live R04 отложен, qualified capture по-прежнему требует готового lab и valid R03. Остаток **18 → 17** за счёт отложения live, на момент выполнения число полностью завершённых строк оставалось 19; новая задача не добавлялась. При текущей синхронизации готовый код выделен в строку 17, live — в 17a: активный остаток 17 → 17, выполненные строки 19 → 20, scope не расширен.

Исходная revision `15170d5c02cb4bfe49fe587cb00db49faada5a50`. [Readiness и offline checks](VLT-LOAD/artifacts/R04/95affec7-a862-417f-8f53-e6e78282f495/run-manifest.json) сохраняют exact commands/exits, initial readiness, regression-before и хеши итогового load scope. Старый P3 trace остаётся пустым; его verify/report ожидаемо отвергаются. Исторические manifest/top-20/trace хеши до и после совпадают.

В capture исправлены перекрытие warm/measured writers, detached exec без exit/flush и проигнорированные ошибки. Каждый writer получает длительность своей фазы и явный tier; capture ждёт его завершения перед reset/publish, сохраняет log и отвергает failed writer/k6. Четыре maintenance-команды вызываются напрямую через Testbench без ошибочного `artisan`; их ошибки тоже блокируют proof. Старый P3 больше не перезаписывается: свежий каталог run ID, проверенный private snapshot, serialized publication, отказ при existing destination (включая symlink). EXPLAIN default теперь находится в выбранном run, README обновлён.

Валидаторы требуют обязательные manifest/trace metadata и явные совпадающие run/dataset IDs. Пустой/дублированный/некорректный trace не допускается. `ok` план засчитывается как analyzed только с actual execution metrics; planner-only, pending и чужой dataset отвергаются. Rejected сохраняется как attempted; повтор SQL внутри invocation остаётся кандидатом для review, не автоматическим N+1 вердиктом.

Проверки: **15 R04 + 14 P3 Python tests**, **7 PHP tests / 35 assertions**, Pint и shell syntax exit 0. Реальный capture-скрипт исполняется с управляемыми внешними фейками: writer/k6/maintenance failures, warm trace exclusion, incomplete artifacts, destination conflict; публикация проверена при двух конкурирующих вызовах. PHP HTTP termination, Artisan success/failure, queue success/failure, transaction state и privacy — только SQLite `:memory:`. Шесть выбранных регрессий на исходной revision дали 18 assertion failures (expected), на исправленном коде проходят. Независимый review: два замечания исправлены и покрыты тестами, блокирующих замечаний нет.

Live/Docker workload, PostgreSQL ANALYZE, lab/SLO measurement, mutation и DDL не запускались. OP↔SQL полнота, top-20 actual plans и N+1 product proof остаются **unevaluated** до свежего live capture; прежние RED/REJECTED не переписаны. Code/test запрос R04 выполнен и отмечен ГОТОВО (17); live R04 отмечен ОТЛОЖЕНО (17a). Следом выполнен независимый code/test R21 (24); текущая следующая команда указана вверху листа. Live-очередь ждёт решения владельца по стенду.

### R05 — результат шага 15

Запись прошедшего прогона, не команда. Повторять этот run и считать его допуском B07-R нельзя: verifier exit 1.

Tier S, run `4e5b1c3e-03ae-4d2e-8a29-460df7f50a3b`. Команда: `TIER=S python3 -u load/gates/p5_live_matrix.py run --run-id 4e5b1c3e-03ae-4d2e-8a29-460df7f50a3b --out plans/VLT-LOAD/artifacts/R05/4e5b1c3e-03ae-4d2e-8a29-460df7f50a3b`. Run exit 0, wall 609.4 с. Verify: `python3 load/gates/p5_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/R05/4e5b1c3e-03ae-4d2e-8a29-460df7f50a3b` exit 1, первая причина `product-realistic.sweeper-overlap.live-patch-overlap.n1 evidence is rejected`. Исторический каталог `artifacts/P5/` не заменялся.

Source `55d10b6afd864b2820061c31cc5dc84ec54d714c`, worktree dirty (лист уже был изменён). Source digest шести harness-файлов `fa6ad3768e6334e50025d2963b5e2774530208cf18d2b30ee6153134da692897`. Образы: app `sha256:d8714c578c47299f4a07604fe2cd8bb31e4fa47d46343dc281d7cfb48f16c166`, postgres `sha256:5f196f3b1342203facb25a787e4a0046c90d94600d511097e5b62e8f9551c255`, pgbouncer `sha256:5e25bffdcb60de22f691800553669bd6b8976bcb549a1293f35de4ecdad38e0a`. Pool 8/200/120, transaction. `capture_contract=r05-b07/v2`. Integrity before = after: nodes 1039, uploads 343, blobs 32, storages 1. Readback `p5-dirty` = 0. Стенд после readback остановлен. `minio/mc:latest` на Docker Hub отсутствует; для minio-init локально помечен уже лежащий `minio/minio:latest`, в нём есть `mc`. Репозиторий из-за этого не менялся.

56 ячеек: 39 evidence valid, 17 rejected. Machine SLO по `cell-verdicts.json`: green 14, red 10, not-a-5pct-budget 8, unevaluated 4, not-a-product-slo 3, not-evaluated 17. Machine green означает, что среди повторов был хотя бы один operation 2xx; отдельные попытки при этом могут быть целиком 429. HTTP 5xx на valid cells нет.

Участие: на каждой valid product-попытке observed actors = N, iterations = N, aborted пуст. Это не закрывает B07-02: verify не прошёл, Astra re-review не было.

Overlap, все 9 попыток n1/n8/n32: advisory lock наблюдался до и после sweep, final `uploading` / `received_bytes` 4, retry PATCH 204. Sweep argv содержал `artisan`, exit 1, stdout `Command "artisan" is not defined.`, `sweepable=false`, `target_refused=false`. Ячейки rejected. Final state не доказывает безопасность sweep: команда продукта не запускалась.

Быстрые primitive (14 ячеек, все кроме pool-timeout): rejected, `no completed lock/pool sample during actor process`. Pool-timeout n200 valid: elapsed 134.66 с, 320 samples, `cl_waiting` max 190, `sv_active` max 10, `maxwait` max 120. На subtree-move n200 в 2 из 9 postgres-samples список locks непустой, ungranted 0.

Dirty внутри этой матрицы повторил известный argv: dirty-20k трижды exit 1, seeded/remaining 20000/20000, elapsed 0.212 / 0.23 / 0.188 с, PHP peak 36175872; dirty-1m exit 1, 1000000/1000000, elapsed 0.208 с, тот же peak. Это не новое SLO-70 и не замена R08.

Сырые статусы valid product cells, попытка 1 если не сказано иное. Retry-After пишется только у 429.

| Ячейка | Machine SLO | Факт попыток |
|:--|:--|:--|
| cas normal-key n1/n8 | green | n1: 2xx и один operation 422; n8: 2xx и operation 422 |
| cas normal-key n32/n200 | green | n32 a1: 39×2xx + 32×429 Retry-After 24; a2/a3 целиком 429. n200 a1/a2 целиком 429 (Retry-After 10/11 и 5); a3: 120×2xx + 200×429 |
| upload tus different n1 | green | 2xx и operation 422 |
| upload tus different n8/n32/n200 | red | все попытки целиком 429, Retry-After есть, actors = N |
| upload s3 different n1 | green | 2xx |
| upload s3 different n8/n32 | red | все попытки целиком 429 |
| upload s3 different n200 | green | a1/a3 целиком 429; a2: 123×2xx + 200×429 |
| collab n1/n8/n32/n200 | red | все ответы operation **403**, Retry-After нет, actors = N |
| subtree disjoint и overlapping, все N | green | все ответы 2xx, actors = N |
| same-resource tus | not-a-5pct-budget | n8: 1×2xx + 7×409; n32: 16×2xx + 16×409; n200: 39×2xx + 41×409 + 120×429. Это не setup-only 429 |
| same-resource s3 | not-a-5pct-budget | ответы 429, actors = N |
| int4-collision | unevaluated | n1 и n200 целиком 429; n8: 16×2xx + 8×other; n32: 88×2xx + 8×other. Collision **unconfirmed**. 429-only её не доказывает |

### R05 — результат шагов 15a и 15b

Запись прошедших шагов, не команда. Прогон 15 не переписывается. R08 SLO-70 не переписывается. Исторический каталог `artifacts/P5/` не заменялся, его run id остаётся `2aeca12f-6180-4595-8466-31397c4ed15d`.

Шаг 15a — harness, пакет drive не менялся. Три правки: sweep argv больше не содержит `artisan`; `sweepable` истинно, когда RETURNING-строка равна upload id, а не когда весь stdout равен ему (`psql -At` дописывает `UPDATE 1`); снимок lock/pool, начатый пока актор ещё шёл, сохраняется, даже если docker exec вернулся после выхода актора. Снимок, начатый уже после актора, по-прежнему отвергается. Проверка: `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest load/gates/test_r05_capture.py load/gates/test_r05_r06_capture.py`, 16 tests, exit 0.

Шаг 15b — tier S, run `adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe`. Команда: `TIER=S python3 -u load/gates/p5_live_matrix.py run --run-id adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe --out plans/VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe`. Run exit 0, wall 815.8 с. Verify exit 0, digest `a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074`. 56/56 evidence valid. Source `55d10b6afd864b2820061c31cc5dc84ec54d714c`, worktree dirty. Source digest шести harness-файлов `667a9345f2c5644e82a63a14961a34b00c4b4e82bc31f008fa8c24bfb44c407f`. Integrity before = after: nodes 1037, uploads 263, blobs 32, storages 1. Readback `p5-dirty` = 0. Стенд после readback остановлен.

Machine SLO: green 17, red 10, not-a-5pct-budget 8, unevaluated 4, not-a-product-slo 17. HTTP 5xx нет. Machine green по-прежнему значит «хотя бы один operation 2xx среди повторов», кроме overlap: там green ещё требует final `received_bytes` 4, нетерминальный статус и retry 2xx.

Overlap, все 9 попыток n1/n8/n32: `sweepable=true`, sweep exit 0, `target_refused=true`, advisory lock до и после sweep, final `uploading` / `received_bytes` 4, retry 204. Stdout: `Swept 0 stale upload sessions (1 skipped — a PATCH is in flight).` Это instrumented contention, не частота гонки и не SLO-70.

Все 14 коротких primitive имеют during-sample. Pool-timeout n200: elapsed 134.947 с, 330 samples, `cl_waiting` max 190, `sv_active` max 10, `maxwait` max 120.

Dirty-20k в этой матрице, три попытки: exit 0, 20000/20000 сняты, elapsed 75.438 / 72.796 / 72.026 с, PHP peak 132644864. Это не новый вердикт SLO-70. Авторитетным остаётся R08 RED (80.501 с, 248.4/с, batch 20000, тот же peak). Dirty-1m: exit 255, seeded/remaining 1000000/1000000, processed 0, elapsed 1.297 с, PHP peak 534777856, stdout пуст, stderr только строка peak. Команда продукта запустилась и не сняла строки. Это не замена R08 и не повод менять drive в этом шаге.

Collision int4 остаётся unconfirmed. Закрытие B07-01/02 по-прежнему требует Astra re-review; этот прогон даёт raw evidence, а не допуск B08.

**Актуальный execution scope после решения владельца:** живого стенда до конца плана не будет. Live R03/R04/R13/R15/R23/R26/R11/R12 и cold mutation исключены из текущей очереди; их proof gaps сохраняются, а ожидание lab не назначается следующим шагом. Code/test R21/R24 и offline classification R25 готовы. Доступный хвост: B07-R offline review → sync → B14 offline review → sync → R20 → B17 offline review → sync → решение владельца. Конкретные findings исправлять обычным code/test scope с проверками затронутого пакета; не создавать SLO proof из unit suites. При отдельном будущем заказе измерений понадобятся новый run ID, source/stand digest, exact commands/exits, raw evidence и cleanup/readback. Исторические блоки результатов выше, включая Next после R25, описывают прежний scope; актуальную очередь задаёт только текущая таблица.

## Справочник: правила доказательств

Read the applicable finding, SLO contract, gate contract, raw run and evidence carrier before a row. The latest conclusion for an audit replaces an older conclusion in that audit; it does not rewrite a product verdict outside its stated scope.

- VLT-LOAD is terminal `Done with deviations`; its P8 receipt is lifecycle evidence, not a product-wide GREEN. Do not execute its old `Pn.m` items. AUDIT-VLT and VLT-HSEAM are archived/superseded; their live Layout-v2 previews are quarantined and are not launch queues.
- B02's last section closes **B02-01/02 in its scoped runtime/verifier scope**. It does not create a new full P6 runtime or an all-SLO PASS. R09's stored P6 evidence remains the authority for the measured SLO-58/64 GREEN and SLO-61 RED; the historical failed-job identities remain unrecoverable.
- B07 is the latest audit of R02–R08: it failed. B08 has no admission. B07-01 and B07-02 remain audit-open until Astra re-review. Step 15's verifier rejected the first live matrix. Steps 15a and 15b corrected the harness and reran it: 56/56 valid, verify exit 0, overlap shows a real sweep refusal while the PATCH lock is held. That is raw evidence, not B07-R admission and not a drive change. B07-04 stays classified by step 10; the step 15b dirty-20k sweeps do not replace the R08 SLO-70 RED. B07-03's P5 missing-evidence gap is filled by run `adcdcdb7`; 429-only collision remains unconfirmed. B07-R now reviews available code/evidence offline; missing qualified R03/R04 measurements remain deferred proof gaps, without blocking this bounded review/handoff or granting live/B08 admission. B07-05 (cold drive mutation `>=95`) is deferred by the owner until the API is frozen before 1.0; it is not an active blocker.
- R14 is a completed measurement, not an unfinished measurement: CAS integrity/memory and GC delete/RSS were GREEN; **GC scan throughput and the 1,000-row batch cap were RED**. R21 code/package tests are complete; R23 is deferred outside current execution because no live stand will exist through the end of this plan. Its unchanged thresholds and current-source gap go to R20. R22 is ОТЛОЖЕНО and must not be started. Do not call R14 unperformed or replace its RED with ordinary test results.
- R18 (media mutation 95.33% PG+SQLite union) and R19 (filament mutation 95.51% SQLite) are complete on their frozen sources. A later media source change does not erase R18 and does not authorize starting R22 before the API is frozen for 1.0.
- P4 L-01's measured streaming bytes/memory result remains historical evidence. Its separate D11 mutation claim is unproved because it used `--parallel`. Do not rerun that mutation. The package rows that would requalify it (R07 step 14, R16, R17, R22) are ОТЛОЖЕНО.
- No smoke, S0 probe, one-row cardinality diagnostic, terminal task receipt, passing unit suite, offline control, or test-only overlay is an authoritative tier-S/M/L product result. A completed measurement may be RED; record that result and follow its route.
- Mutation proof, when it returns, uses a cold cache, the declared database drivers, real skip/timeout denominator and no `--parallel`. The target is >=95; no configured minimum may be lowered to make a gate pass. Owner decision 2026-09-28: do not run that proof and do not write mutant-chasing tests until the API is frozen before 1.0. Steps 14, 22, 23 and 25 stay ОТЛОЖЕНО. Recorded scores stay as they are.

## Справочник: findings и критерии закрытия (не очередь)

Соответствие findings задачам и критерии доказательства. Статусы и действия берутся только из исполнительной таблицы выше.

| Finding | R | Критерий / evidence |
|:--|:--|:--|
| L-04 core retry | R01 | no relaunch; R26 only supplies any later full-P6 re-verdict |
| P4 L-01 streaming / D11 | R02, R07, R16, R17, R22 | no aggregate/parallel mutation claim. The >=95 rows are ОТЛОЖЕНО until the API is frozen before 1.0; do not launch them from this cell |
| F-P2.8-1/2/3; SLO-40/43/49/52 | R03 | 16a harness corrective и 16b R27 готовы; product proof шага 16 REJECTED сохранён. 16c исключён до конца плана; current qualified full-window/attribution gaps → R20. Offline review не заменяет tagged metrics/sample/lab proof |
| L-P3-01, L-P3-03 | R04 | Код/gates готовы: шаг 17, `326f69b`. Live proof 17a исключён до конца плана; trace/actual ANALYZE/top-20/N+1 gaps → R20. No claim from zero rows |
| L-P3-04/L-05 endpoint scan | R26 | R26 отложен до конца плана; historical tier-S facts и explicit unevaluated M/L/24-hour/current-source gaps → R20 |
| L-P5-01/02/04; **B07-01** | R05 | step 15b: all 9 overlap attempts sweep exit 0, `sweepable`, lock held, target refused, final uploading/4, retry 204. Close only after Astra re-review |
| L-P5-01/02/04; **B07-02** | R05 | step 15b verify recomputed participation for every product cell. Collab `other` remains HTTP 403 from step 15. Close only after Astra re-review |
| B07-03 P5 portion | R05 | run `adcdcdb7` is 56/56 valid with actors/status/Retry-After/lock/pool/overlap. 429-only collision remains unconfirmed |
| L-P5-03, L-02; B07-04 | R06 | classified: harness argv `artisan`, exit 1, remaining 20000, PHP peak 36175872; product sweep not run; no guessed drive fix |
| drive L-02, SLO-70/72 | R08 | step 11 kept drive source; step 12 SLO-70 RED (80.501 s, 248.4/s, batch 20000, PHP peak 132644864). Cold R07 `>=95` больше не блокирует правки drive: отложен до фиксации API перед 1.0 |
| drive D11 / VLT-MUT P2; B07-05 | R07 | step 13 measured 65.38% (1091+25)/1707. Step 14 measured 79.51% (1420/1786), 84.25% (1514/1797), 84.70% (1522/1797), then 86.20% (1549/1797). Gate 95 exit 1. Owner deferred the chase until the API is frozen before 1.0. Historical scores stay |
| P6 L-P6-01…04; **B02-01/02** | R09 | no re-execution without a source-changing reason |
| P6 concurrent duplicate: 2 dispatch / 2 HTTP | R24 | Code/test выполнен: source digest, red-before и green concurrent regression, независимый review без findings; [результат](#r24--результат-шага-27-2026-09-28-codepackage-tests). Historical verdict неизменён; R26 отложен, current product proof gap → R20 |
| P6 recovery RED, sticky historical failed jobs, SLO-61 RED | R25 | [Classification выполнена](#r25--результат-шага-28-2026-09-28-offline-classification): original capture → completed R09; последующие recovery/SLO-61 → retained RED, без локализованного package defect. Каждая RED cell и attempt перечислены в raw readback; historical job identities неизвестны. R26 отложен, residuals → R20 |
| P6 current full verdict; SLO-58…64 and endpoint M/L | R26 | R24/R25 готовы; R26 исключён до конца плана. Historical RED/unevaluated и current-source duplicate/recovery/failed-job proof gaps → R20; unit suites/overlay не дают full verdict |
| P7 L-P7-01…05 | R11 | live baseline исключён до конца плана; подготовленный code/gates scope проверяется offline в B14. Missing baseline/actual fault proof → R20 |
| P7 authoritative recovery | R12 | live matrix исключена до конца плана; missing first/retry/DB↔object/full per-cell proof → R20 |
| L-06; L-P3-02/05/06/07 | R13 | 1,000-anchor SQL/CTE/tree timings не получены; live measurement исключён до конца плана, gaps → R20 |
| L-03, L-P4-01; SLO-76…78 | R21 | Code/package tests выполнены: cap 1000, keyset/high-water progress, batch query-cost, lock/liveness/grace rechecks; [результат](#r21--результат-шага-24-2026-09-28-codepackage-tests). No SLO claim; R23 отложен, historical R14 throughput/batch RED → R20 |
| R21 source change / media D11 | R22 | отложено до фиксации API перед 1.0; R21 и R23 этот >=95 не ждут |
| R14 RED cells after R21 | R23 | live remeasurement исключён до конца плана; historical scan throughput/batch-cap RED и отсутствие current-source proof → R20 |
| L-07 | R15 | live measurement исключён до конца плана; explicitly unconfirmed по имеющемуся lock/HTTP evidence → R20 |
| core D11 / VLT-MUT P0.2/P1.1/P3 | R16 | отложено до фиксации API перед 1.0; до этого cold core mutation не запускать |
| documents D11 / VLT-MUT P0.2/P1.1/P4 | R17 | отложено до фиксации API перед 1.0; до этого cold documents mutation не запускать |
| media D11 / VLT-MUT P5 | R22 if R21 changes source | R18 remains the recorded result. Do not start R22 until the API is frozen before 1.0 |
| filament D11 / VLT-MUT P6 | complete | [R19](remaining-evidence/R19.md) |
| L-08 scheduler; archived release/CI residuals | R20 | current fact table plus explicit owner decisions; no publish, billing or archive mutation |

## Справочник: архивные обязательства и решения владельца

These are deliberately not reopened as code work. R20 must report their current factual state and give the owner the indicated decision; it must not make the decision.

| Source | Current disposition | Owner decision required |
|:--|:--|:--|
| VLT-REMED handoff: 78.6% < 80% release-readiness | historical RED; current value must be rechecked in R20 | fund/authorize a coverage improvement or explicitly accept the current residual |
| VLT-CANON handoff: private/free GitHub cannot enforce required checks | external constraint, not a repository fix | choose GitHub Team/Pro or make the repository public, or accept non-enforcement |
| VLT-REL D9: split repos, token and Packagist registration absent | release tag exists; publication is deferred | provision split/Packagist and explicitly authorize publication/retry, or keep deferred |
| VLT-CANON ADR-0018 mixed `format` values | explicitly accepted limitation | no action unless owner opens a separately scoped migration decision |
| VLT-CANON docs/reactivity/README drift and VLT-REL D12 examples | bounded historical documentation debt | choose a separate docs task if desired; it is outside product proof completion |
| L-08 host scheduler | host ownership is documented, host execution is not proved | provide deployment evidence or explicitly accept the absence of host-scheduler proof |
| remaining product RED/unevaluated SLOs, including SLO-61 | no one may accept them through R20/B17; R23/R26/R11/R12 и прочие live proofs excluded through plan end | по R20/B17 рассмотреть каждый named residual/current proof gap отдельно; принять риск либо заказать отдельные repair/measurement задачи вне текущего плана |

## Правило обновления после каждого шага

After every implementation or measurement slice, its executor must immediately:

1. record the result in this file’s executed row: revision/commit, commands/exits, run ID, source/stand digest, raw links, GREEN/RED/unevaluated and limitations; use a short result block below the table when the row is insufficient. Existing `remaining-evidence/RNN.md` files are historical supporting reports, not another current status register. Do not create a new plan, status file, handoff or carrier for ordinary step completion; store bulky raw logs in an artifact directory and link them here;
2. mark the exact executed row in the execution table ГОТОВО with its result/carrier/commit; update affected findings; insert any concrete corrective/requalification rows; mark exactly one next admitted row СЛЕДУЮЩИЙ, skipping every ОТЛОЖЕНО row, replace the command at the top and recalculate the remaining-step counter; if no row is admitted, retain ОЖИДАЕТ and state its exact dependency/owner admission instead of inventing permission; never leave completed code and pending measurement in one ambiguous status; never promote an ОТЛОЖЕНО row and never start `pest --mutate` for it;
3. preserve raw evidence, SLO thresholds and historical verdicts. A correction never overwrites a rejected or RED artifact.

For an independent audit, the auditor writes only its `Bxx-audit.md` report (the explicit exception for review separation); the subsequent map-sync is performed by the responsible executor. For a failed verification, return to the named corrective R in this map. Do not leave a generic `blocked` status when a code, measurement, re-audit, or owner-decision route is known.

## Критерии завершения

Vaulter is ready for an owner close decision only when the following are evidenced, not merely discussed:

- every ledger row is complete, explicitly retained RED/unevaluated with an owner disposition, or an accepted archival limitation;
- B07-R and B14/B17 finish their bounded offline scopes; every actionable code/evidence/reporting finding is repaired and rechecked or has an explicit owner disposition. Missing excluded live/mutation proof is reported separately and does not demand a stand before offline completion;
- current-source mutation proof >=95 for drive, core, documents and media is deferred until the API is frozen before 1.0 and is not required to finish the rest of this ledger; R18/R19 remain valid for their sources;
- the R21 media code/test result is reported with historical R14 throughput/batch-cap RED retained; R23 is explicitly deferred, without a current-source SLO claim;
- R24/R25 code/test/classification results are reported with historical P6 duplicate/recovery/SLO-61 verdicts and unknown original failed-job identities preserved; missing current R26 matrix and R11/R12 P7 baseline/recovery remain explicit deferred gaps;
- R20 publishes the SLO-by-tier table separating historical measurements from current-source gaps, current CI/release/host facts, all 12 deferred rows and the owner-decision list. M/L or 24-hour gaps remain `unevaluated` unless actually measured. No new live stand/run is required to finish the authorized offline scope;
- the owner, not an executor or audit, decides whether any remaining RED, unevaluated target, deployment constraint, required-check limitation, or deferred publication is acceptable and whether to close/archive VLT-LOAD.
