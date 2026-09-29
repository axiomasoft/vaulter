> **Архивировано 2026-09-29.** Offline-очередь закрыта: 0 активных шагов. Live и cold mutation residuals сохранены как отложенные факты, а не текущие задания.

# Remaining Work — единый исполнительный лист

**Это итоговый исторический реестр закрытой очереди.** Здесь сохранены порядок, статусы, остаток и результаты на момент архивации. `remaining-evidence/`, VLT-LOAD и остальные архивные планы — доказательства/история; их launch/next-блоки не задают текущую очередь. Дублирующие START-HERE, ACTIVE и RUNBOOK удалены.

Рабочие адреса исторических ссылок из сохранённых evidence-снимков: [архивная навигация REC-02](#архивная-навигация-rec-02-2026-09-29).

**Обновлено:** 2026-09-29. Владелец отложил тяжёлые cold-mutation прогоны до фиксации API перед версией 1.0. Последний счёт drive остаётся **86.20%** (1549 killed, 0 timeout, 248 escaped / 1797), gate 95 exit 1. Код drive не менялся. Шаг 13 (65.38%) и промежуточные 79.51%, 84.25%, 84.70% и 86.20% не переписаны. До фиксации API эти прогоны и тесты ради мутантов не запускать.

**Статус:** **Done with deviations — закрыт в согласованном offline scope (2026-09-29).** Последующее указание владельца — «доделывай все до закрытия плана» — выполнено: оба последних repairs и независимый focused recheck завершены. Все исключённые измерения и product residuals сохранены ниже; product readiness и принятие риска не заявляются.

**Готово:** R11-B17-02, шаг 37b — [payload/final CAS integrity](#r11-b17-02--результат-шага-37b-2026-09-29-offline-corrective), commit `42a6f3e`: **B17-02 CLOSED** в load-only offline scope, 185 P7 tests exit 0; independent focused recheck без findings. R11-B17-01, шаг 37a — [received-byte conservation](#r11-b17-01--результат-шага-37a-2026-09-29-offline-corrective), commit `719af61`: **B17-01 CLOSED** в load-only offline scope, 177 P7 tests exit 0; независимый focused recheck без findings. Оба B17 findings закрыты в code/offline scope. B17 и B17-sync, шаги 36/37 — [итоговый offline-аудит и синхронизация](#b17-sync--результат-шагов-3637-2026-09-29): **REPAIRS REQUIRED, 2 Major** на момент аудита; оба findings CLOSED по 37a/37b и независимому recheck. B14-01…06 остаются CLOSED в своих scopes; offline handoff завершён. R20, шаг 35 — [текущие факты и owner handoff без стенда](#r20--результат-шага-35-2026-09-29-offline-facts): committed source `9c8497a`; SLO-by-tier с раздельными historical results и current-source gaps; read-only CI/release/scheduler facts; все 12 отложенных строк и named owner decisions. Risk не принят, CI/release/deploy не запускались, product GREEN нет. R11-B14-06, шаг 33f — [immutable P7 publication](#r11-b14-06--результат-шага-33f-2026-09-29-offline-corrective): **B14-06 CLOSED** в load-only publisher/offline scope, commit `9c8497a`, 168 P7 tests (12 новых), exit 0; occupied destinations отвергаются атомарно, failed staging сохраняется, независимый focused review без findings. R11-B14-05, шаг 33e — [staging/part reconciliation capture](#r11-b14-05--результат-шага-33e-2026-09-29-offline-corrective): **B14-05 CLOSED** в load-only inventory/collector/reader offline scope, commit `cf967c7`, 156 P7 tests (31 новый), exit 0; scoped staged/part/final identities capture до cleanup, orphan/missing/collector-error controls отвергаются. R11-B14-04, шаг 33d — [matched-absent integrity contract](#r11-b14-04--результат-шага-33d-2026-09-29-offline-corrective): **B14-04 CLOSED** в load-only producer/reader offline scope, commit `2c1e70f`, 125 P7 tests (14 новых), exit 0; missing-required-object/orphan по-прежнему отвергаются. R11-B14-03, шаг 33c — [fault ACK и lock-holder barrier](#r11-b14-03--результат-шага-33c-2026-09-29-offline-corrective): **B14-03 CLOSED** в load-only code/controlled offline scope, commit `dd23b68`, 111 P7 tests (31 новых), exit 0. R11-B14-02, шаг 33b — [complete P7 evidence admission](#r11-b14-02--результат-шага-33b-2026-09-29-offline-corrective): **B14-02 CLOSED** в captured-pair verifier/offline scope, commit `85c0afd`, 80 P7 tests (32 новых), exit 0; пять audit controls отвергаются. R11-B14-01, шаг 33a — [безопасный P7 base-route restore](#r11-b14-01--результат-шага-33a-2026-09-28-offline-corrective), commit `a6c6b91`: **B14-01 CLOSED** в load-only code/offline scope, 48 P7 tests (16 новых sentinel/route controls), exit 0. R05-B07-02, шаг 19a — [offline corrective и независимый focused recheck](#r05-b07-02--результат-шага-19a-2026-09-28-offline-corrective). **B07-02 CLOSED** в persisted participation scope: structural/verdict/full verifier проверяют оба product plane; 110 отрицательных controls отвергаются. Read-only replay 56-cell run проходит с неизменным raw digest. Primary: 8 focused tests; независимый GPT-6 Astra: 7 focused tests и собственные controls, exit 0. B07-R (18), B07-R-sync (19), R25 (28), R24 (27), R21 (24), R04 (17), R03 harness (16a), R27 (16b), B14 (32) и B14-sync (33) также готовы в ранее записанном scope. Все шесть B14 findings B14-01…06 закрыты в указанном code/offline scope; actual live/product proof отсутствует. Historical RED/REJECTED/unevaluated, SLO-70 RED и deferred proof gaps сохранены; product/live/B08 admission не выдан.

**Решение владельца, 2026-09-28; подтверждено 2026-09-29:** **живого стенда не будет; этот лист завершаем без live.** Не ждать lab, не запрашивать возобновление live и не поднимать стенд для закрытия листа. Закончить доступные code/test, offline review/readback, синхронизацию и итоговый handoff. Новые live/load/chaos/SLO измерения исключены из текущего исполнения; обычные изолированные package tests и offline controls разрешены. Cold mutation и тесты ради мутантов по-прежнему отложены до фиксации API перед 1.0. Это решение о границах работы, а не принятие оставшихся product risks.

**Остаток:** **0 активных шагов**, **40 выполненных**, **12 отложенных** (14, 16c, 17a, 20, 21, 22, 23, 25, 26, 30, 31, 34), ещё 1 неактивированная условная строка. После 37b: active **2 → 1**, done **38 → 39**; после owner-requested offline closure 38: active **1 → 0**, done **39 → 40**. Deferred **12 → 12**, conditional **1 → 1**. Это завершение разрешённой работы, не процент product readiness.

**Что вынесено за рамки закрытого листа:** все **8 live gaps** (16c, 17a, 20, 21, 26, 30, 31, 34) и **4 cold-mutation gaps** (14, 22, 23, 25) остаются ОТЛОЖЕНО. Historical RED/REJECTED/unevaluated и current-source gaps не закрыты passing offline tests. Новые измерения, release/deploy и принятие product risks возможны только по отдельному решению владельца; в текущую очередь они не возвращаются.

Новые задачи нельзя добавлять молча: для каждой вставки нужны finding, причина, scope и изменение счётчика «было → стало». Повтор неуспешного шага остаётся в той же строке с последним результатом и причиной повтора. Если новый дефект не обнаружен, очередь не расширять.

**Следующего запуска в этом плане нет.** Runnable code/evidence/report repairs не осталось; [итог и dispositions](#закрытие--результат-шага-38-2026-09-29-offline-scope) записаны ниже. Закрытие текущего offline scope разрешено последующим указанием владельца; product GREEN/B08 admission, публикация и deployment не разрешены этим результатом.

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
| 10 | ГОТОВО | R06 — dirty-20k измерение причины | Grok 4.7 | Не запускать повторно | [run 03f5d89d](../VLT-LOAD/artifacts/R06/03f5d89d-e882-41e9-86e0-43eb0f93cd2e); класс: harness argv `artisan`. Drive не менялся. SLO-70 не переизмерен |
| 11 | ГОТОВО | R08 — решение по коду drive | Grok 4.7 | Не запускать повторно | Код не менялся. Exit 1 из R06 был вызовом стенда, не ошибкой `SweepUploadsCommand` |
| 12 | ГОТОВО | R08 — измерение SLO-70 на неизменённом коде | Grok 4.7 | Не запускать повторно | [run 8a9c70cf](../VLT-LOAD/artifacts/R08/8a9c70cf-c531-48ac-bdce-d566726aed20): **RED**. 20 000 за 80.501 с, batch 20 000 выведен из кода/cardinality, batch telemetry отсутствует (scrape 404), PHP peak 132644864 — не RSS. PATCH refusal/retry 204; R08 `sweepable=false`, усиленный overlap proof — R05 |
| 13 | ГОТОВО | R07 — cold drive mutation и gate | Grok 4.7 | Не запускать этот прогон повторно. Шаг 14 тоже не запускать: он ОТЛОЖЕНО | 65.38% sequential PG, gate 95 exit 1. [cold-pg-20260927](remaining-evidence/R07-checks/cold-pg-20260927/summary.json). Исторические 48.90% не переписаны |
| 14 | ОТЛОЖЕНО | R07 — тесты на 591 escaped и повтор cold PG | — | Не запускать до фиксации API перед 1.0 | Последний счёт **86.20%** (1549/1797), gate 95 exit 1. [cold-pg-step14-r07d](remaining-evidence/R07-checks/cold-pg-step14-r07d-20260928/summary.json). Исторические 65.38%, 79.51%, 84.25%, 84.70% не переписаны. Дожим до 95% отложен: API ещё меняется |
| 15 | ГОТОВО | R05 — affected live P5 cells | Grok 4.7 | Не запускать этот прогон повторно | [run 4e5b1c3e](../VLT-LOAD/artifacts/R05/4e5b1c3e-03ae-4d2e-8a29-460df7f50a3b): 39 valid / 17 rejected, verify exit 1. Участие actors полное на valid cells. Overlap и быстрые primitive rejected → 15a |
| 15a | ГОТОВО | R05 — corrective capture controls | Grok 4.7 | Не повторять code lane | Commit `56015c8`; argv без `artisan`; `sweepable` читает RETURNING, не тег `UPDATE 1`; during-sample, начатый при живом акторе, сохраняется. 16 offline tests, exit 0. Drive не менялся |
| 15b | ГОТОВО | R05 — повтор rejected live cells | Grok 4.7 | Не запускать этот прогон повторно | Commit `ea77e00`; [run adcdcdb7](../VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe): 56/56 valid, verify exit 0, digest `a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074`. R08 SLO-70 не переписан |
| 16 | ГОТОВО | R03 — full-window throughput / attribution | GPT-6 Sol | Не повторять этот run | Commit `ff0d0d3`; [run 4f565edf](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json): S0/S по 180s полностью; k6 exit 99, audits exit 2. Qualified proof **REJECTED**, class B unevaluated; observed throughput RED и причины сохранены. Historical RED не переписан → 16a–16c |
| 16a | ГОТОВО | R03 — corrective harness H01…H05 | GPT-6 | Не повторять code lane | Исходная revision `52e0e63`; [offline checks/source digest](remaining-evidence/R03-checks/16a-20260928-final/checks.json): 16 Python + 7 JS + 1 PHP, все exit 0. Distinct OP-06/07, accepted-byte/duration tags, primary blob FK, mandatory discovery, nonempty/sample/lab/source admission. Все SLO floors сохранены; live и mutation не запускались → 16b |
| 16b | ГОТОВО | R27 — package-core PostgreSQL bind-limit corrective | GPT-6 | Не повторять code lane | [Результат и команды](#r27--результат-шага-16b): большие ID sets добавляют 0 bindings; PG regression 65 540 descendants bigint/ULID, HTTP, access/deny/cursors; SQLite core 574 tests, PG Access+Schema 169 passed/4 skipped, PG ULID 5 passed, PHPStan/Pint exit 0. Mutation/live не запускались → 16c |
| 16c | ОТЛОЖЕНО | R03 — qualified full-window remeasurement | GPT-6 Sol | Не запускать до конца плана: стенда не будет | [Preflight](#r03--preflight-шага-16c-2026-09-28-live-отложен-владельцем) rejected: L-1/L-3/L-5 и storage admission не готовы; live не начат. R03-H06 исправлен inline, 18 Python + 7 JS tests exit 0. Qualified full-window proof RED/REJECTED/unevaluated сохраняется; будущий пересъём — отдельная задача вне плана |
| 17 | ГОТОВО | R04 — код capture/gates и offline tests | GPT-6 Sol | Не повторять code lane | Commit `326f69b`; [результат](#r04--код-и-offline-tests-2026-09-28-live-отложен): 29 Python + 7 PHP / 35 assertions; writers joined, errors propagated, validated fresh publication, actual-plan/trace identity gates; independent review без blockers |
| 17a | ОТЛОЖЕНО | R04 — live P3 trace/ANALYZE capture | GPT-6 Sol | Не запускать до конца плана: стенда не будет | Код/gates шага 17 готовы; current-source trace/actual ANALYZE/top-20/N+1 и OP↔SQL proof остаются unevaluated. Пересъём — отдельная задача вне плана |
| 18 | ГОТОВО | B07-R — независимый offline-переаудит | GPT-6 Astra | Не повторять полный аудит | [B07-R](remaining-evidence/B07-audit.md): bounded scope выполнен с одним Major; B07-01 CLOSED, B07-02 PARTIALLY FIXED/OPEN → 19a; B07-04 diagnostic aspect CLOSED, R08 RED сохранён. R03/R04 proof gaps и mutation отложены; product/live/B08 admission не выдан |
| 19 | ГОТОВО | B07-R — синхронизация листа | Ответственный исполнитель | Не повторять sync | [Результат](#b07-r-sync--результат-шага-19-2026-09-28): перенесены audit dispositions, уточнён R08 batch evidence; добавлен только 19a/R05-B07-02. Счётчик 8 → 7 активных, 23 → 25 выполненных; B14 ждёт corrective/recheck |
| **19a** | **ГОТОВО** | R05-B07-02 — persisted worst-case participation corrective | GPT-6 Sol | Не повторять corrective без нового defect | [Результат](#r05-b07-02--результат-шага-19a-2026-09-28-offline-corrective): admission обоих product plane; 8 primary / 7 независимых focused tests, 110 отрицательных controls, 56-cell replay с неизменным raw digest. Focused re-review GPT-6 Astra: B07-02 CLOSED; аудит и лист синхронизированы → 32/B14; без live/mutation и product GREEN |
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
| **32** | **ГОТОВО** | B14 — независимый offline-аудит P7/code/evidence | GPT-6 | Не повторять полный аудит без нового material risk | [B14-аудит](remaining-evidence/B14-audit.md): **REPAIRS REQUIRED**, 1 Blocker + 5 Major в prepared P7 harness. R24/R25 readback без новых findings; R11/R12 baseline/recovery и current mutation proofs сохранены deferred; live/chaos/mutation и product GREEN отсутствуют → 33 |
| **33** | **ГОТОВО** | B14 — синхронизация листа | Ответственный исполнитель | Не повторять sync без нового audit evidence | [Результат](#b14-sync--результат-шага-33-2026-09-28): B14-01…06 перенесены в шесть отдельных named corrective rows. Активные 6 → 10, done 26 → 28; R12 остаётся ОТЛОЖЕНО → 33a |
| **33a** | **ГОТОВО** | R11-B14-01 — безопасный base-route restore | GPT-6 Sol | Не повторять без нового route/restore risk | [Результат](#r11-b14-01--результат-шага-33a-2026-09-28-offline-corrective), `a6c6b91`: B14-01 CLOSED в load-only offline scope; explicit migration skip app/worker, isolated HA bootstrap, final readback после restore; 48 P7 tests, exit 0. Live/product proof не получен → 33b |
| **33b** | **ГОТОВО** | R11-B14-02 — complete P7 evidence admission | GPT-6 | Не повторять без нового admission risk | [Результат](#r11-b14-02--результат-шага-33b-2026-09-29-offline-corrective), `85c0afd`: B14-02 CLOSED в captured-pair offline scope; 80 tests, 5 audit controls rejected, expected absence сохранена; historical P7 rejected и byte-identical → 33c |
| **33c** | **ГОТОВО** | R11-B14-03 — fault ACK и lock-holder barrier | GPT-6 | Не повторять без нового ACK/protocol risk | [Результат](#r11-b14-03--результат-шага-33c-2026-09-29-offline-corrective), `dd23b68`: B14-03 CLOSED в load-only code/controlled offline scope; 111 tests, exact ACK, held operation до fault/promotion и joined outcome; historical P7 rejected и byte-identical; actual lock-loss/promotion proof отсутствует → 33d |
| **33d** | **ГОТОВО** | R11-B14-04 — `matched-absent` integrity contract | GPT-6 Sol | Не повторять без нового integrity contract risk | [Результат](#r11-b14-04--результат-шага-33d-2026-09-29-offline-corrective), `2c1e70f`: B14-04 CLOSED в load-only producer/reader offline scope; 125 P7 tests, 14 новых; full reader принимает legitimate absence и отвергает 6 forged absence controls; historical P7 rejected и byte-identical; actual recovery не измеряется → 33e |
| **33e** | **ГОТОВО** | R11-B14-05 — staging/part reconciliation capture | GPT-6 Sol | Не повторять без нового inventory/collector risk | [Результат](#r11-b14-05--результат-шага-33e-2026-09-29-offline-corrective), `cf967c7`: B14-05 CLOSED в load-only offline scope; captured expected/actual staged/part/final identities до cleanup, per-key joins, explicit observation errors и owned cleanup; 156 P7 tests (31 новый), exit 0; historical P7 rejected и byte-identical; actual DB↔object proof не получен → 33f |
| **33f** | **ГОТОВО** | R11-B14-06 — immutable P7 publication | GPT-6 Sol | Не повторять без нового publication risk | [Результат](#r11-b14-06--результат-шага-33f-2026-09-29-offline-corrective), `9c8497a`: B14-06 CLOSED в load-only offline scope; run-specific paths, atomic no-replace, occupied destinations отвергаются, failed staging сохраняется; 168 P7 tests (12 новых), independent review без findings, historical 176 files byte-identical и rejected; fake runner only → R20 |
| 34 | ОТЛОЖЕНО | R12 — full tier-S P7 recovery matrix | Grok 4.7 | Не запускать до конца плана: стенда не будет | Новые first/retry attempts, DB↔object joins и full per-cell recovery verdicts не получены; historical RED/unevaluated сохранить для R20 |
| **35** | **ГОТОВО** | R20 — текущие факты и owner handoff без стенда | Grok 4.6 | Не повторять без нового evidence | [Результат](#r20--результат-шага-35-2026-09-29-offline-facts), HEAD `9c8497a`: SLO-by-tier, CI/release/scheduler, 12 deferred rows и owner-decision list записаны; risk не принят; current coverage unevaluated; product GREEN нет → B17 |
| **36** | **ГОТОВО** | B17 — итоговый offline-аудит handoff | GPT-6 Astra | Не повторять полный аудит без нового material risk | [B17-аудит](remaining-evidence/B17-audit.md), source `9c8497a`: **REPAIRS REQUIRED, 2 Major** — B17-01/02 были OPEN на момент аудита, CLOSED по 37a/37b и focused recheck. По отчёту 168 P7 tests exit 0, 2 false admissions через actual producer/full reader, fingerprints/R20 readback подтверждены. B14 closures и historical/deferred verdicts сохранены; live/product GREEN нет → 37 |
| **37** | **ГОТОВО** | B17 — синхронизация листа | Ответственный исполнитель | Не повторять sync без нового audit evidence | [Результат](#b17-sync--результат-шагов-3637-2026-09-29): два новых findings перенесены в 37a/37b; active 3 → 3, done 35 → 37. Все 8 live и 4 mutation gaps, historical verdicts и owner decisions сохранены. На момент sync handoff ждал 37a/37b; теперь repairs/recheck завершены, итог → 38 |
| **37a** | **ГОТОВО** | R11-B17-01 — multipart received count и durable parts/bytes | GPT-6 Sol | Не повторять без нового integrity risk | [Результат](#r11-b17-01--результат-шага-37a-2026-09-29-offline-corrective), `719af61`: B17-01 CLOSED в load-only producer/full reader offline scope; unique part sizes sum = received для open unassembled session; 177 P7 tests (9 новых), independent focused recheck без findings; zero-byte/assembled/terminal cases сохранены; historical P7 byte-identical и rejected → 37b |
| **37b** | **ГОТОВО** | R11-B17-02 — final payload/CAS checksum invariant | GPT-6 Sol | Не повторять без нового integrity risk | [Результат](#r11-b17-02--результат-шага-37b-2026-09-29-offline-corrective), `42a6f3e`: B17-02 CLOSED в load-only offline scope; payload/blob/CAS key/observed checksum согласованы, size check сохранён. 185 P7 tests (8 новых), независимый focused recheck 47 tests без findings; оба исходных audit controls rejected. Все runnable repairs завершены → 38 |
| **38** | **ГОТОВО** | Решение владельца — закрытие текущего offline scope | Владелец / исполнитель фиксации | Последующее указание: «доделывай все до закрытия плана» | [Итог и named dispositions](#закрытие--результат-шага-38-2026-09-29-offline-scope): **Done with deviations**. Оба repairs и независимый recheck завершены; все 12 исключённых измерений и historical/current product gaps retained вне плана. Это owner-authorized closure offline работы; product risks не приняты, publish/deploy и product GREEN/B08 admission не выданы. Следующего запуска нет |

### Закрытие — результат шага 38 (2026-09-29, offline scope)

**Done with deviations.** Владелец после запуска 37a поручил завершить весь оставшийся объём «до закрытия плана». Исполнитель завершил 37a/37b, независимый focused recheck и запись результатов; это указание является основанием закрытия **текущего offline scope**. Прежние запреты live/cold mutation сохраняются. Закрытие листа не выдаёт product-wide acceptance и не принимает невыданный product risk за владельца. Отдельного release/deploy/archive внешних систем, CI dispatch или публикации не было; ledger остаётся здесь как итоговый register/evidence.

**Final source:** `42a6f3e` после scoped repairs `719af61` и `42a6f3e`. Финальный P7 suite — **185 tests**, exit **0**, из них 17 новых ordinary offline regressions в двух срезах. Independent focused recheck — 47 tests (8 final +9 multipart +30 inventory), exit **0**, оба исходных B17 controls producer-invalid/full-reader-rejected, findings нет. B14-01…06 и B17-01/02 CLOSED только в записанных code/offline scopes. Historical P7 176-file digest неизменён; actual reader exit **1**, expected historical rejection. Новый live run ID/stand digest, current-source coverage/mutation/SLO proof отсутствуют.

| Named residual / gap | Final disposition при закрытии текущего листа | Что потребует отдельного решения/задачи владельца |
|:--|:--|:--|
| R03/16c, R04/17a, R13/20, R15/21, R23/26, R26/30, R11/31, R12/34 | **ОТЛОЖЕНО, 8 live gaps**; стенд исключён до конца плана, не measured/GREEN | Отдельно заказать stand/run/source-qualified proof либо принять отсутствие proof перед соответствующим product решением |
| R07/14, R16/22, R17/23, R22/25 | **ОТЛОЖЕНО, 4 cold-mutation gaps** до API freeze перед 1.0; drive 86.20%, gate 95 exit 1; R18 95.33% и R19 95.51% только для frozen sources | После freeze отдельно заказать cold serial proof с прежним >=95 threshold; сейчас не запускать и не писать tests ради mutants |
| Historical R03 rejected/observed RED; R14 GC scan throughput/batch-cap RED; SLO-70 RED; P6 recovery/SLO-61 RED; historical P7 REJECTED | **Retained без изменения verdict/raw**; code/tests не являются повторным измерением | Рассмотреть каждый product residual отдельно перед product acceptance; repair/measurement только по новому scope |
| R24 current duplicate/recovery proof; R11/R12 current baseline/fault/recovery; M/L/24-hour; original failed-job identities; collision | **Current-source unevaluated / identities unknown / collision unconfirmed**, R20/R25 readback сохранены | Отдельные measurement/diagnostic задачи; passing offline suite не устанавливает эти facts |
| Coverage historical 78.6% <80%; later recorded `c502f5f` pass; final source `42a6f3e` | Historical facts сохранены; **current coverage unevaluated**, CI не запускался | Измерить final/release SHA либо отдельно принять gap; floor 80 не снижать |
| Required-check enforcement; release/split/Packagist provisioning/publication | **Retained R20 external constraints/deferred publication**; новых запросов/записей нет | Выбрать GitHub visibility/plan либо принять non-enforcement; provision и явно разрешить publication/retry |
| L-08 host scheduler | **Ownership documented, execution unproved** по R20 | Deployment evidence либо отдельное принятие host-scheduler proof gap |
| ADR-0018 mixed formats; optional docs/examples debt | **Прежняя accepted limitation / bounded optional debt сохранены** | ADR migration/docs task только по отдельному запросу |

Эти dispositions закрывают очередь **работы в этом листе**, сохраняя каждый residual для будущего product решения. Никакой deferred row не переведён в ГОТОВО и ни один RED не заменён GREEN. Owner-decision list R20 ниже остаётся применимым к product/release/deployment; решения о risk acceptance, publication или funding не сделаны исполнителем.

Final map checks: 53 rows = 40 ГОТОВО +12 ОТЛОЖЕНО +1 УСЛОВНО, active 0 и runnable NEXT отсутствует; ссылки, source/log fingerprints обоих repairs и сохранность baseline/audit/raw вне scoped edits проверены; `git diff --check` exit 0. Package/PHP/PostgreSQL/live/mutation suites не запускались. Существовавшие dirty plan/audit/R20 carriers сохранены; план дополнен поверх них и не включён в code commits.

### R11-B17-02 — результат шага 37b (2026-09-29, offline corrective)

**B17-02 CLOSED** в load-only integrity producer/full reader scope, commit `42a6f3e`. Final blob key вычисляется из attempt payload SHA-256 и CAS scope; blob checksum обязан совпасть с payload. Expected final checksum теперь берётся из payload, observed object должен совпасть с ним. Recorded/observed size check сохранён. Согласованно неверные DB/object checksums не заменяют сохранность исходного payload; full reader повторно выводит invariant из raw DB/payload state.

[Checks/source/log fingerprints](remaining-evidence/R11-B17-02-checks/checks.json): inventory на `719af61` ещё принимал B17-02; current producer и full reader отвергают **оба** исходных B17 controls, audit-wrapper exit **0**. Focused **8 tests**, full P7 **185 tests** (8 новых), exit **0**. Независимый `quality:code-review` focused recheck обоих repairs: **47 tests** (8 final +9 multipart +30 inventory), exit **0**, original controls exit 0, findings нет. Positive committed fixture с retained/deleted part rows проходит; forged consistent DB/object/expected metadata, foreign CAS key, malformed/missing checksum, wrong observed checksum и size отвергаются.

Dry-run: **51 cells**, exit 0; Python compile и `git diff --check` exit 0. Historical P7 **176 файлов**, SHA-256 `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62`, bytes неизменны; full verifier exit **1**, expected `recovered without dependency probe`. Package/PHP/PostgreSQL suites, real Docker/mc runtime, live/chaos/load/SLO и cold mutation не запускались. Historical RED/REJECTED/unevaluated, все 12 deferred gaps и owner decisions сохранены; actual product integrity/recovery не измерена и GREEN не выдан.

Счётчик после repair: active **2 → 1**, done **38 → 39**, deferred **12 → 12**, conditional **1 → 1**; новых строк нет. Единственная оставшаяся строка 38 закрывает текущую offline работу по последующему указанию владельца, с named retained dispositions выше.

### R11-B17-01 — результат шага 37a (2026-09-29, offline corrective)

**B17-01 CLOSED** в load-only integrity producer/full reader scope, commit `719af61`. Открытая unassembled multipart session требует равенства `received_bytes` сумме размеров уникальных durable part rows. Duplicate identities и malformed/negative/bool sizes отвергаются; каждый retained part по-прежнему требует matching object/checksum/size. Полная и частичная потеря rows вместе с objects не даёт legitimate absence. Unequal/nonconsecutive parts разрешены; zero-byte pre-write refusal, assembled staging и committed/aborted/expired cleanup проходят.

[Checks/source/log fingerprints](remaining-evidence/R11-B17-01-checks/checks.json): original committed inventory принимал оба B17 controls; current producer и full reader отвергают B17-01 (`multipart received bytes contradict durable parts`), B17-02 пока valid/accepted и остаётся OPEN. Focused **9 tests**, full P7 **177 tests** (9 новых), exit **0**. Независимый focused `quality:code-review` recheck: **9 + 30 + 14 tests**, exit **0**, исходный audit control повторён exit 0, новых findings нет. Forged success labels также отвергаются full reader. Проверки контролируют внешние peers, реальный продукт не подвергался fault.

Dry-run: **51 cells**, exit 0; Python compile и `git diff --check` exit 0. Historical P7 **176 файлов**, SHA-256 `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62`, bytes неизменны; full verifier exit **1**, expected `recovered without dependency probe`. Package/PHP/PostgreSQL suites, real Docker/mc runtime, live/chaos/load/SLO и cold mutation не запускались. Historical RED/REJECTED/unevaluated, все 12 deferred gaps и owner decisions сохранены; product GREEN не выдан.

Счётчик: active **3 → 2**, done **37 → 38**, deferred **12 → 12**, conditional **1 → 1**; новых строк нет. Следующий срез — **37b/R11-B17-02**, выполняется в рамках последующего указания владельца закончить доступный хвост. Прежние dirty plan/audit/R20 bytes сохранены; commit содержит только scoped load changes и новые raw checks.

### B17-sync — результат шагов 36/37 (2026-09-29)

[B17-аудит](remaining-evidence/B17-audit.md) завершён в final P7/R20/source/evidence offline scope на source `9c8497a03f0d8f7275796805ee2dea65e67040e0`: **REPAIRS REQUIRED — 2 Major, confidence high**. По отчёту все 168 P7 tests прошли, exit 0; два дополнительных controls вызывают actual `run_window()` с controlled external peers и полный reader в complete 51-cell/77-attempt fixture. Оба показывают `producer=valid`, `full_reader=accepted` при нарушении integrity contract. Это false evidence admission, не доказанная потеря/порча данных реальным продуктом. Fingerprints и R20/R25 readback подтверждены в аудите; эти проверки в sync не перезапускались.

**B17-01 OPEN → 37a/R11-B17-01.** Потеря одновременно part rows и objects при открытой multipart session с `received_bytes=16` трактуется как legitimate absence. Причина вставки — отсутствие связи authoritative received count с полным durable набором parts/bytes. Scope и acceptance: только load-only producer/full reader integrity contract и ordinary offline regressions; control должен отвергаться обоими, zero-byte pre-write refusal и корректные assembled/commit/abort/expiry cases должны сохраняться.

**B17-02 OPEN → 37b/R11-B17-02.** Final blob/object checksum `c×64` при payload checksum и CAS key `b×64` принимается при совпавшем размере. Причина вставки — отсутствие связи DB/object checksum с исходным payload/content address. Scope и acceptance: только load-only producer/full reader final-CAS invariant и ordinary offline regressions; связать все четыре checksum/key inputs, сохранить size check, отвергать control обоими и принимать корректный committed fixture. Для каждого repair предусмотрен независимый focused recheck новых и соседних valid/invalid cases с записью результата в этот лист; отдельные full-audit/sync строки не добавлены.

B14-01…06 остаются **CLOSED в своих записанных code/offline scopes**; новые findings уточняют пределы integrity admission и не отменяют route restore, fault ACK/holder barrier или atomic publication. Все **8 live gaps** (16c, 17a, 20, 21, 26, 30, 31, 34) и **4 cold-mutation gaps** (14, 22, 23, 25) остаются ОТЛОЖЕНО. Historical SLO-61/SLO-70/GC scan+batch RED, R03 и historical P7 REJECTED, M/L/24-hour и current-source unevaluated сохранены. Drive **86.20%**, gate 95 exit 1, frozen-source R18 **95.33%** и R19 **95.51%** не изменены. R24 code/tests не заменяют R26 recovery/duplicate proof, исходные failed-job identities неизвестны.

Owner-decision list R20 сохранён: current coverage gap, required-check enforcement, release/split/Packagist provisioning/publication, documented-but-unproved host scheduler, accepted ADR-0018 limitation, optional docs debt и каждый retained product residual. Risk не принят; product GREEN/B08 admission, publish/deploy и close/archive не выданы. **Offline handoff ещё не завершён:** шаг 38 ждёт закрытия обоих findings через repairs/focused recheck либо явного owner disposition; отсутствие исключённых измерений не является новым code blocker.

Синхронизация меняет только этот лист поверх существовавших правок. Audit reports, source/tests/raw, R20 carriers, пороги и historical verdicts сохранены. Проверки sync: структура/счётчики таблицы, единственный runnable NEXT, зависимости, новые ссылки/anchors, fingerprints входов и неизменность остальных repository bytes, `git diff --check`; package/P7/live/mutation suites и внешние CI/release facts не перезапускались. Исходный B17-аудит SHA-256: `6e6c761e262fa1f5d4237255919bcf43e982710b758cff58f5658a612664478d`; input листа до sync: `ba5e3a1660778d4a2e1fa0d5896ed11f6927ef8fec1bb4f614090caf1c643e46`. Запись остаётся в рабочем дереве вместе с прежними незакоммиченными изменениями.

Счётчик: active **3 → 4 → 3** (−1 B17 +2 repairs −1 sync), done **35 → 36 → 37**, deferred **12 → 12**, conditional **1 → 1**. Добавлены ровно две named corrective rows по двум воспроизведённым defects, иных строк нет. Единственный следующий запуск — **37a/R11-B17-01**; repairs этим запросом не выполнялись.

### R20 — результат шага 35 (2026-09-29, offline facts)

Собраны текущие факты по committed source и сохранённым evidence. **Risk не принят.** CI/release/deploy/live/mutation не запускались. Product GREEN, live/B08 admission и close/archive VLT-LOAD не выданы. Неизмеренное остаётся RED/REJECTED/unevaluated. Новых corrective rows нет.

**Source.** HEAD `9c8497a03f0d8f7275796805ee2dea65e67040e0` — `fix(load): publish P7 runs atomically without replacing evidence`. Ветка `remediation/audit-vlt`, ahead 17 от `origin/remediation/audit-vlt`. Рабочее дерево на момент записи: этот лист, untracked [B14-audit.md](remaining-evidence/B14-audit.md) и [R20-checks](remaining-evidence/R20-checks/). Package/load product bytes после `9c8497a` не менялись. Нового live run ID/stand digest нет.

[Readback](remaining-evidence/R20-checks/readback.json) подтвердил 12 строк ОТЛОЖЕНО, 16 repair commits, отсутствие `Schedule::` в `packages/`, coverage floor `--min=80` и наличие cited evidence. [GitHub/Packagist facts](remaining-evidence/R20-checks/github-facts.json) — read-only query, без dispatch.

#### Final repairs и offline checks

| R / finding | Commit | Offline proof | Current product proof |
|:--|:--|:--|:--|
| R05 overlap / B07-01 | `d042695`, `56015c8`, `ea77e00` | 16 capture tests; live `adcdcdb7` 56/56, digest `a248913b…` | Bounded P5 overlap CLOSED; SLO-70 не заменён |
| R05-B07-02 | `9bd90da` | 8+7 focused tests, 110 negative controls, raw digest unchanged | Participation CLOSED; collision unconfirmed |
| R03 H01…H05 | `531393a`, `15170d5` | 16 Python + 7 JS + 1 PHP, exit 0 | Qualified full-window **REJECTED**; 16c deferred |
| R27 bind-limit | `0160ce3` | SQLite 574; PG Access+Schema 169/4 skip; ULID 5 | Listing bind overflow исправлен в code/tests; live R03 не переснят |
| R04 capture/gates | `326f69b` | 29 Python + 7 PHP | Live P3 traces **unevaluated**; 17a deferred |
| R21 GC batch | `c6b43c0` | SQLite 127; PG 132; Pint/PHPStan/types 0 | SLO-76 scan/batch historical RED; R23 deferred |
| R24 webhook lease | `6f477ef` | SQLite 582; PG 581; focused PG 81 | Concurrent duplicate исправлен в code/tests; R26 deferred |
| R25 classification | `25eccfa` (docs) | 35 P6 tests, readback exit 0 | Recovery/SLO-61 retained RED; no new package R |
| R11-B14-01…06 | `a6c6b91`…`9c8497a` | 48→80→111→125→156→168 P7 tests, exit 0 | B14-01…06 CLOSED в load-only offline; R11/R12 live deferred |

R18 **95.33%** PG+SQLite и R19 **95.51%** SQLite остаются валидны для своих frozen sources. Drive cold mutation last score **86.20%** (1549/1797), gate 95 exit 1.

#### SLO-by-tier: historical result vs current-source gap

Пороги не менялись. M/L и 24-hour остаются `unevaluated`, кроме явно измеренных S-ячеек ниже. Offline tests не дают tier-S/M/L GREEN.

| SLO | Tier | Historical measured result | Current-source gap | Owner / deferred |
|:--|:--|:--|:--|:--|
| SLO-40 OP-02 | S | Observed RED (med 17.021, min 1.660 MiB/s); campaign **REJECTED** | none — 16a/16b offline only | 16c / R03 |
| SLO-43 OP-06 | S | Observed RED/invalid (med 27.004, min 11.012; gate collision) | none | 16c / R03 |
| SLO-49 OP-17 | S | Observed RED, sparse n5 (med 11.765) | none | 16c / R03 |
| SLO-52 OP-08…10 | S | **unevaluated** (download n0) | none | 16c / R03 |
| SLO-40/43/49/52 class B | S | **unevaluated** (<100 samples; lab admission false) | none | 16c / R03 |
| SLO-41/42, 44/45, 50/51, 53/54 | M/L | **unevaluated** | none | R03 |
| SLO-58 OP-40 | S | **GREEN** (275.39 rows/s; tick p95 0.227 s) — R09 `3df3b9e1`, R10 `56823709` | none — needs frozen-source R26 | R26 |
| SLO-61 OP-41 | S | **RED** (16.15–17.68 jobs/s; queue p95 9.28–10.13 s) | none; sink attribution caveat retained | R26 |
| SLO-64 OP-42 | S | **GREEN** (2887.84 rows/s; claims 1000×5; RSS 57.33 MiB) | none — needs R26 | R26 |
| SLO-59/60, 62/63, 65/66 | M/L | **unevaluated** | none | R26 |
| Daily failed_jobs | — | **unevaluated**; 2 sticky identities unrecoverable | none | R26 / owner |
| P6 recovery 9 cells | S | **RED** retained (R09/R10); classified R25 | none | R26 |
| SLO-70 OP-44 | S | **RED** (20k / 80.501 s = 248.4/s; batch 20000 source-derived; scrape 404) — R08 `8a9c70cf` | none; drive source unchanged | R08 residual |
| SLO-71/72 | M/L | **unevaluated** | none | R08 |
| SLO-76 OP-46 | S | Mixed: scan **747.90/s RED**, batch **4000 RED**, delete **570.38/s GREEN**, RSS GREEN — R14 `cd0813b9` | code/offline after R21; no live | R23 |
| SLO-77/78 | M/L | **unevaluated** | code/offline after R21 | R23 |
| SLO-46…48 OP-07 | S/M/L | Known L-01 RED by code/arithmetic; not an accepted live SLO GREEN | mutation ОТЛОЖЕНО | 14/22/23/25 |
| SLO-01…39, 55…57, 67…69, 73…75, 79…81 | S/M/L | **unevaluated** | none | no dedicated live row, or R04/R13 |
| L-06 tree/CTE | L | **unevaluated** | none | R13 |
| P3 traces/ANALYZE | S | **unevaluated** | gates ready `326f69b` | 17a / R04 |
| P7 baseline/recovery | S | historical P7 **rejected**; no current baseline | B14 offline only | R11 / R12 |

#### CI / release / scheduler (read-only)

| Fact | Current state | Decision still required |
|:--|:--|:--|
| Coverage floor | `.github/workflows/tests.yml` и `composer test:coverage` — `--min=80`. Порог не понижен | fund/accept residual |
| Historical coverage RED | `tests.yml` run [29203669361](https://github.com/axiomasoft/vaulter/actions/runs/29203669361), SHA `e79b175`, **Total 78.6% < 80%**; PHP lanes failed, Postgres success | same |
| Later main floor pass | run [29414127913](https://github.com/axiomasoft/vaulter/actions/runs/29414127913), SHA `c502f5f` (2026-07-15), all three jobs success including `--min=80`. Exact Total% из логов не восстановлен | **не current HEAD** |
| Current-source coverage | **unevaluated**: `9c8497a` не имеет `tests.yml` run; push filter — `main`/`feat/**`/`fix/**`/`hotfix/**`, не `remediation/**`. CI не диспатчился | owner: измерить на current SHA или принять gap |
| Required checks | repo `axiomasoft/vaulter` **private**; `branches/main/protection` HTTP **404** «Branch not protected»; rulesets `[]`. Required checks не enforced | Team/Pro, public, или принять non-enforcement |
| Mutation CI | advisory; latest success на `c502f5f` / main, не current HEAD; `pull_request` нет | not a required check |
| Release | единственный тег **`v0.2.0`**; [GitHub release](https://github.com/axiomasoft/vaulter/releases/tag/v0.2.0) 2026-07-15 | publication still deferred |
| Split / Packagist | `monorepo-split` run 29409528976 **failure**; 12 target repos `axioma-studio/vaulter-*` и `axiomasoft/vaulter-*` отсутствуют; Packagist `axioma-studio/vaulter-core` **404** | provision + authorize, или keep deferred |
| L-08 scheduler | пакет **не** регистрирует `Schedule::`. Host sample есть в `docs/reference/commands.md`. Host execution **не доказан** | deployment evidence или принять отсутствие |
| ADR-0018 `format` | explicitly accepted mixed value-space, no cast/backfill | no action unless new migration decision |
| Docs drift | bounded historical docs debt | optional separate docs task |

#### 12 отложенных строк

| Шаг | R | Класс | Что отсутствует |
|:--|:--|:--|:--|
| 14 | R07 | cold mutation | drive `>=95`; last 86.20% |
| 16c | R03 | live | qualified full-window / attribution |
| 17a | R04 | live | trace / ANALYZE / top-20 / N+1 |
| 20 | R13 | live | 1,000-anchor SQL/CTE/tree timings |
| 21 | R15 | live | advisory collision confirmation |
| 22 | R16 | cold mutation | core `>=95` |
| 23 | R17 | cold mutation | documents `>=95` |
| 25 | R22 | cold mutation | media requalification `>=95` |
| 26 | R23 | live | GC/CAS remeasure after R21 |
| 30 | R26 | live | frozen-source full P6 matrix |
| 31 | R11 | live | P7 baseline `/ready`/metrics/ops |
| 34 | R12 | live | P7 first/retry recovery + DB↔object |

8 live + 4 mutation. Они не блокируют B17 и не возвращаются в очередь автоматически.

#### Owner-decision list (не решения исполнителя)

| Item | Current factual state | Owner must |
|:--|:--|:--|
| Coverage residual | historical 78.6% RED; later main SHA passed `--min=80`; **current HEAD unevaluated** | fund/measure current SHA, or accept residual/gap |
| Required checks | private repo, main unprotected, empty rulesets | Team/Pro or public, or accept non-enforcement |
| Packagist / split | tag exists; split repos and Packagist absent | provision + authorize publication, or keep deferred |
| ADR-0018 | accepted limitation | no action unless new scoped decision |
| Docs/reactivity drift | historical docs debt | optional separate docs task |
| L-08 host scheduler | documented, not proved | deployment evidence or accept absence |
| Remaining product RED/unevaluated, including SLO-61 | nobody may accept them through R20/B17 | accept risk or order separate repair/measurement **outside this plan** |

| Проверка R20 | Exit | Смысл |
|:--|--:|:--|
| `python3 -B plans/remaining-evidence/R20-checks/readback.py` | 0 | [readback.json](remaining-evidence/R20-checks/readback.json): 12 deferred, 16 commits, no `Schedule::`, floor 80 |
| `python3 -B -c compile(readback.py)` | 0 | syntax |
| `git diff --check` | 0 | whitespace |
| `gh run/repo/release/api` + Packagist GET | 0 query / 404 expected | [github-facts.json](remaining-evidence/R20-checks/github-facts.json); CI не диспатчился |

Package/Pest/PHPStan/live/mutation suites в R20 не запускались. **Результат очереди:** шаг 35 ГОТОВО; активные **4 → 3**, выполненные **34 → 35**; deferred **12 → 12**. **Рекомендация следующего шага:** `task:review plans/REMAINING-WORK.md B17`. Этот запрос его не запускает.

### R11-B14-06 — результат шага 33f (2026-09-29, offline corrective)

**B14-06 CLOSED** в load-only publisher/controlled offline scope. Source base — `cf967c7`; scoped commit `9c8497a` содержит publisher, tests/docs и technical check outputs. Существовавшие до запроса правки листа и `B14-audit.md` сохранены; синхронизация листа остаётся в рабочем дереве. Package code, historical raw и product verdicts не менялись.

Оба shell publication paths используют private staging рядом с destination и общий Linux `renameat2(RENAME_NOREPLACE)` publisher. Matrix default — `P7/runs/<run_id>`, copied lane — `P7/lane-runs/<manifest.run_id>`; `P7_ARTIFACTS` задаёт exact unoccupied destination. `verify --run-id` читает новый matrix path. Existing file/directory/empty directory/symlink/dangling symlink отвергаются; late occupant не заменяется и не получает nested evidence. Unsafe run IDs и identity mismatch отвергаются. Нет cross-filesystem copy/replacing-rename fallback; unsupported API/filesystem fail closed. Run/copy/verify/publication failures сохраняют staging с сообщением о пути. Matrix interruption пытается scoped revert; copied lane не вызывает live revert. Verification завершена до atomic rename; post-publication write/verification не требуется, поскольку перемещается тот же snapshot.

| Проверка | Actual exit | Evidence / смысл |
|---|---:|---|
| Baseline на `cf967c7` | 0 | [156 passed](remaining-evidence/R11-B14-06-checks/baseline-p7-tests.log) |
| Original/current disposable control | 0 wrappers | [Original](remaining-evidence/R11-B14-06-checks/original-source-control.json): publisher exit 0 удаляет sentinel (expected defect); [current](remaining-evidence/R11-B14-06-checks/current-control.json): publisher exit 1 сохраняет historical bytes |
| Focused publication suite | 0 | [12 passed](remaining-evidence/R11-B14-06-checks/publication-tests.log): real shell/publisher/readers, fake matrix run, occupied/late/concurrent controls, default paths/readback, failed verify/run, syscall errors |
| Full P7 suite | 0 | [168 passed](remaining-evidence/R11-B14-06-checks/p7-tests.log), 12 новых; existing negative diagnostics сохранены |
| Dry-run | 0 | [51 cells](remaining-evidence/R11-B14-06-checks/dry-run.log), без DB/object/Docker runtime |
| Historical verifier | 1 expected | [Rejection](remaining-evidence/R11-B14-06-checks/historical-rejection.log): `recovered without dependency probe` |
| Historical fingerprint | 0 | [176 файлов byte-identical](remaining-evidence/R11-B14-06-checks/historical-preservation.log), [before map](remaining-evidence/R11-B14-06-checks/historical-before.json) |
| Shell/Python syntax и diff check | 0 | [Shell](remaining-evidence/R11-B14-06-checks/shell-syntax.log), [Python compile without bytecode](remaining-evidence/R11-B14-06-checks/python-syntax.log), [diff](remaining-evidence/R11-B14-06-checks/diff-check.log) |

[Source/log fingerprints и actual exits](remaining-evidence/R11-B14-06-checks/checks.json); [independent focused review](remaining-evidence/R11-B14-06-checks/independent-review.md) без findings, включая addendum по default paths/errno controls. Reviewer inspected source and passing logs; tests не перезапускал. Unsupported filesystem errors симулированы. Conflict/concurrency/default-path controls выполнялись только в disposable local directories; matrix runner заменён fake peer, оба readers настоящие. Original control воспроизводит дефект старого shell только в disposable path; это не PASS исходника.

Нового live run/stand digest нет. Package/PHP/Testbench/PostgreSQL suites, настоящий Docker runtime, chaos/load/SLO measurements, cold mutation и tests ради mutants не выполнялись. Historical RED/REJECTED/unevaluated, SLO-70 RED и R11/R12 deferred gaps сохранены; product/live/B08 admission не выдан.

Счётчик: active **5 → 4**, done **33 → 34**, deferred **12 → 12**, conditional **1 → 1**; новых rows нет. Следующий единственный запуск — **35/R20**; он не выполнялся в этом запросе.

### R11-B14-05 — результат шага 33e (2026-09-29, offline corrective)

**B14-05 CLOSED** в load-only attempt-owned object inventory / collector / full-reader и controlled offline scope. Source base — `2c1e70f`; scoped commit `cf967c7` содержит 10 load source/tests/docs и technical check outputs. Существовавшие до запроса правки листа и `B14-audit.md` сохранены; синхронизация листа остаётся в рабочем дереве. Package code, historical raw и product verdicts не менялись.

Каждый raw attempt сохраняет `p7-object-inventory/v1`, а `objects.jsonl` — `p7-object/v3` для точного staged key, всех DB/attempted/actually listed part keys под prefix данного upload и predicted final CAS key. Load storage seam передаёт configured CAS scope; final candidate должен быть observed absent до fault, иначе injection не начинается. Shared bucket/CAS walk отсутствует. Даже missing DB session сохраняет scoped actual extra keys; foreign listing identities не читаются и не становятся cleanup instructions.

Expected presence/absence, checksum, size и durable identity записываются до cleanup. Оба joins выдаются для каждого key; full reader пересчитывает полный набор по raw DB/payload/listing, сравнивает actual observations и требует exact joins. Terminal committed/aborted/expired sessions требуют отсутствия staged/part objects, в том числе при retained part rows. Extra part без row, leftover-stage/part, missing required part/final, checksum/size mismatch, observation error и listing↔exact-read race инвалидируют producer и reader. Legitimate pre-write refusal/abort/expiry contract шага 33d сохранён.

Collector использует exact-key `mc stat --no-list --json`: отсутствие допускается только для exact-target typed `ObjectMissing`/`NoSuchKey`. Ошибки доступа, timeout, malformed responses, failed cat и size race дают `observation-error`, `exists=null`. Bytes читаются через base64 и хэшируются без text decoding. Формат CLI проверен по primary MinIO sources, связанным в `load/README.md`; real mc/Docker не запускался. Cleanup вызывается после capture/reconcile и использует только captured owned keys; final удаляемым считается лишь после pre-fault absence и совпадения predicted identity/checksum. Publisher не менялся.

| Проверка | Actual exit | Evidence / смысл |
|---|---:|---|
| Baseline P7 suite на `2c1e70f` | 0 | [125 passed](remaining-evidence/R11-B14-05-checks/baseline-p7-tests.log) |
| `python3 -B plans/remaining-evidence/R11-B14-05-checks/readback.py --original-ref 2c1e70f` | 0 wrapper | [Expected original RED](remaining-evidence/R11-B14-05-checks/original-source-controls.json): producer принимает leftover-part, extra-part, leftover-stage и missing-second-part; nonzero collector превращает AccessDenied в absent. Это воспроизведение defects, не PASS исходника |
| `python3 -B plans/remaining-evidence/R11-B14-05-checks/readback.py` | 0 | [Current controls invalid](remaining-evidence/R11-B14-05-checks/current-controls.json): четыре admissions отвергаются; nonzero collector имеет `exists=null` |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7_object_inventory.py' -v` | 0 | [30 passed](remaining-evidence/R11-B14-05-checks/object-inventory-tests.log), fake producer/collectors, scoped cleanup и raw/full reader |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7_*.py' -v` | 0 | [156 passed](remaining-evidence/R11-B14-05-checks/p7-tests.log), 31 новый; existing negative diagnostics сохранены |
| `PYTHONDONTWRITEBYTECODE=1 TIER=S ./load/scripts/p7-chaos.sh dry-run` | 0 | [51 cells](remaining-evidence/R11-B14-05-checks/dry-run.log), без Docker runtime/DB writes |
| `python3 -B load/gates/p7_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P7` | 1 expected | [Historical rejection](remaining-evidence/R11-B14-05-checks/historical-rejection.log): `recovered without dependency probe` |
| PHP lint, Python `compile()` без bytecode, `sh -n` для трёх inline collector scripts, `git diff --check` | 0 | [Syntax outputs](remaining-evidence/R11-B14-05-checks/syntax.log), [diff check](remaining-evidence/R11-B14-05-checks/diff-check.log); source/staged diff прошли |
| Historical raw fingerprint comparison | 0 | [176 файлов byte-identical](remaining-evidence/R11-B14-05-checks/historical-preservation.log); before map сохранён |

Full verifier принимает actual producer output для clean committed multipart и active two-part upload в complete 51-cell/77-attempt fixture; read-only repeat сохраняет digest. Четыре controls с поддельными success labels (leftover-part, extra-part, missing-final, listing error) отвергаются. Другие regressions проверяют required absence при abort/expiry, missing DB session, collector errors/unknown observations, exact scope, listing races, checksum/size и отсутствие foreign reads/deletes.

[Source/log fingerprints и actual exits](remaining-evidence/R11-B14-05-checks/checks.json); source rollup SHA-256 (sorted path bytes + file bytes для 10 changed load files): `1947facad82421e6130505f1396aa8cef67a24edaa8e182e57c1f50ff9a41183`. Historical P7 tree SHA-256: `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62`. Trailing whitespace в новых text logs нормализован; diagnostics/actual exits сохранены. Focused self-review по `quality:code-review` без remaining findings; независимый review остаётся в B17. Package/PHP Testbench/PostgreSQL suites, настоящий mc/Docker runtime, chaos/load/SLO measurements, cold mutation и tests ради mutants не выполнялись.

Live package DB↔object joins, actual cleanup/recovery, R11/R12 baseline и SLO proof не получены. Historical RED/REJECTED/unevaluated, SLO-70 RED и deferred gaps сохранены; product/live/B08 admission не выдан.

Счётчик: active **6 → 5**, done **32 → 33**, deferred **12 → 12**, conditional **1 → 1**; новых rows нет. Следующий единственный запуск — **33f/R11-B14-06**; он не выполнялся в этом запросе.

### R11-B14-04 — результат шага 33d (2026-09-29, offline corrective)

**B14-04 CLOSED** в load-only captured-pair producer/reader contract и controlled offline scope. Source base — `dd23b68`; scoped commit `2c1e70f` содержит producer, 14 regressions и technical check outputs. Существовавшие до запроса правки листа и `B14-audit.md` сохранены; синхронизация листа остаётся в рабочем дереве. Package code, historical raw и product verdicts не менялись.

Producer `run_window()` использует общий reader contract `gate.RESOLVED`: legitimate pre-write refusal (`initiated`, 0 received bytes, absent object), корректно очищенный abort и expiry дают оба `matched-absent` joins и `evidence=valid` при успешных recovery probes. Missing required staging/part/blob, orphan и checksum mismatch остаются invalid; recovery и distinct retry gates сохранены. Reader не менялся: presence, key, identity, checksum и size по-прежнему пересчитываются по raw DB/object pair.

Новые tests вызывают настоящий `run_window()` с fake HTTP/DB/object boundaries; неожиданные subprocess/dependency calls запрещены. Producer output переносится в complete 51-cell/77-attempt fixture: unpatched full verifier принимает четыре legitimate absence cases (включая repeated attempt) и read-only повтор сохраняет digest. Шесть missing-required-object/orphan controls отвергаются даже после подделки producer `valid` и обоих `matched-absent` labels. Staging/part inventory expansion и collector observation errors остаются в 33e; immutable publisher — в 33f.

| Проверка | Actual exit | Evidence / смысл |
|---|---:|---|
| Baseline P7 suite на `dd23b68` | 0 | [111 passed](remaining-evidence/R11-B14-04-checks/baseline-p7-tests.log) |
| `python3 -B plans/remaining-evidence/R11-B14-04-checks/readback.py --original-ref dd23b68` | 1 expected | [Original producer RED](remaining-evidence/R11-B14-04-checks/original-source-tests.log): 14 tests, 5 failed assertions и 4 full-reader errors на legitimate absence; отрицательные controls проходят. Это воспроизведение дефекта, не PASS исходника |
| `python3 -B plans/remaining-evidence/R11-B14-04-checks/readback.py` | 0 | [14 passed](remaining-evidence/R11-B14-04-checks/integrity-contract-tests.log), producer + raw/full reader regressions |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7_*.py' -v` | 0 | [125 passed](remaining-evidence/R11-B14-04-checks/p7-tests.log), 14 новых; expected negative diagnostics сохранены |
| `PYTHONDONTWRITEBYTECODE=1 TIER=S ./load/scripts/p7-chaos.sh dry-run` | 0 | [51 cells](remaining-evidence/R11-B14-04-checks/dry-run.log), без Docker runtime/DB writes |
| `python3 -B load/gates/p7_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P7` | 1 expected | [Historical rejection](remaining-evidence/R11-B14-04-checks/historical-rejection.log): `recovered without dependency probe` |
| Python `compile()` без bytecode, `git diff --check` | 0 | [Syntax](remaining-evidence/R11-B14-04-checks/syntax.log), [diff check](remaining-evidence/R11-B14-04-checks/diff-check.log); source/staged diff прошли |
| Historical raw fingerprint comparison | 0 | [176 файлов byte-identical](remaining-evidence/R11-B14-04-checks/historical-preservation.log); before map сохранён |

[Source/log fingerprints и actual exits](remaining-evidence/R11-B14-04-checks/checks.json); source rollup SHA-256 (sorted path bytes + file bytes для двух changed load files): `a6a4fd8d368862fc8ede063fc8e9c1c940d636377a37e07deed76309cb8402a4`. Historical P7 tree SHA-256: `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62`. Trailing whitespace в новых text logs нормализован; diagnostics/actual exits сохранены. Нового live run/stand digest нет. Focused self-review по `quality:code-review` без findings; независимый review остаётся в B17. Package/PHP/PostgreSQL suites, настоящий Docker runtime, chaos/load/SLO measurements, cold mutation и tests ради mutants не выполнялись.

Historical RED/REJECTED/unevaluated, SLO-70 RED и R11/R12 deferred gaps сохранены; product/live/B08 admission не выдан.

Счётчик: active **7 → 6**, done **31 → 32**, deferred **12 → 12**, conditional **1 → 1**; новых rows нет. Следующий единственный запуск — **33e/R11-B14-05**; он не выполнялся в этом запросе.

### R11-B14-03 — результат шага 33c (2026-09-29, offline corrective)

**B14-03 CLOSED** в load-only exact ACK / held-lock protocol / controlled offline scope. Source base — `85c0afd`; scoped commit `dd23b68` содержит 11 load source/tests/docs и technical check outputs. Существовавшие до запроса правки листа и `B14-audit.md` сохранены; синхронизация листа остаётся в рабочем дереве. Package code, historical raw, thresholds и product verdicts не менялись.

Общий `fault_spec()` связывает injection, ACK, raw target и scoped revert. ACK требует exact S3 run/target/mode; Toxiproxy proxy route, enabled state и единственный exact run/cell toxic; tmpfs run/path/filesystem и measured fill; HA run/service identities, один writable standby, fenced primary и завершённый repoint. HTTP 200 без требуемого fault, stale toxic/409 и foreign state отвергаются. Lock-holder transport cells направлены в PgBouncer, а не MinIO. Оба barrier/injector streams сохраняют ACK proof; full verifier пересчитывает target/state по named cell.

Для lock-holder новый bounded subprocess получает PostgreSQL transaction lock и сообщает database/backend PID/event ID **до** injection/promotion. Scoped stdin release разрешает query на том же backend и mutation; terminal result связывается с original held boundary, measured bytes, error class и command exit. Missing readiness не запускает fault; bad ACK, timeout, cancel, foreign release и malformed terminal отменяют holder, rollback контролируется peer, fault revert выполняется в `finally`. Real held mode требует PostgreSQL; прежний non-held режим сохранён. HA shell сохраняет run identity; существующий promotion wrapper передаёт её и проверен через fake CLI.

**Controls:** [31 новый test](../../../load/gates/test_p7_fault_barriers.py) проверяет exact ACK, реальные stdin/stdout и PHP command с stubbed DB/outbox, настоящий promotion shell с fake Docker CLI, три lock-holder windows (disconnect/reset/fence-then-promote), cleanup и full evidence admission. Complete positive 51-cell/77-attempt synthetic fixture принимается неподменённым verifier; bad ACK и missing/foreign/unheld boundaries отвергаются даже при согласованных streams. Peer order — `transaction-open → lock-acquired → fault → ack → rollback → revert`; controlled connection loss возвращает measured `PDOException`, а successful release контролируется отдельно. Это protocol/order regressions с подставными внешними границами, не доказательство реального lock loss или failover.

| Проверка | Exit | Фактический результат |
|:--|--:|:--|
| Baseline P7 suite на `85c0afd` | 0 | [80 passed](remaining-evidence/R11-B14-03-checks/baseline-p7-tests.log) |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7_*.py' -v` | 0 | [111 passed](remaining-evidence/R11-B14-03-checks/p7-tests.log), 31 новый; expected negative diagnostics сохранены |
| `python3 -B plans/remaining-evidence/R11-B14-03-checks/readback.py` | 0 wrapper | [Original source expected RED](remaining-evidence/R11-B14-03-checks/original-source-controls.json): unfaulted HTTP 200 accepted, holder execute после fault/ACK, raw target `minio`; current unfaulted ACK REJECTED |
| `PYTHONDONTWRITEBYTECODE=1 TIER=S ./load/scripts/p7-chaos.sh dry-run` | 0 | [51 cells](remaining-evidence/R11-B14-03-checks/dry-run.log), без Docker runtime/DB writes |
| `python3 -B load/gates/p7_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P7` | 1 | [Expected historical rejection](remaining-evidence/R11-B14-03-checks/historical-rejection.log): `recovered without dependency probe` |
| PHP lint (command и peer), отдельный `sh -n` для пяти scripts, Python `compile()` без bytecode, `git diff --check` | 0 | [Syntax outputs](remaining-evidence/R11-B14-03-checks/syntax.json); все проверки прошли |
| Historical P7 digest before/after | 0 | Bytes неизменны: `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62` |

[Source/log fingerprints и actual exits](remaining-evidence/R11-B14-03-checks/checks.json); source rollup SHA-256 (sorted path bytes + file bytes для 11 changed load files): `1607d628f98f4b1c91c569d5fc7d40b230180c1b701f8505721aa9af54508578`. Нового live run/stand digest нет. Focused self-review по `quality:code-review`; независимый review остаётся в B17. PHP/package/PostgreSQL suites, настоящий Docker runtime, chaos/load/SLO measurements, cold mutation и tests ради mutants не выполнялись.

**Ограничения сохранены:** actual lock-loss/promotion/recovery proof отсутствует; B14-04 producer `matched-absent`, B14-05 staging/part inventory/collector errors и B14-06 immutable publication не исправлялись. Full live admission/product GREEN не выданы. Historical RED/REJECTED/unevaluated, SLO-70 RED и deferred R11/R12 baseline/recovery gaps сохранены.

Счётчик: active **8 → 7**, done **30 → 31**, deferred **12 → 12**, conditional **1 → 1**; новых rows нет. Следующий единственный запуск — **33d/R11-B14-04**; он не выполнялся в этом запросе.

### R11-B14-02 — результат шага 33b (2026-09-29, offline corrective)

**B14-02 CLOSED** в load-only captured-pair verifier/offline scope. Source base — `a6c6b91`; scoped commit `85c0afd` содержит только changed load source/tests/docs и technical check outputs. Существовавшие до запроса правки листа и `B14-audit.md` сохранены; синхронизация листа остаётся в рабочем дереве. Package code, historical raw trees, thresholds и product verdicts не менялись.

Full `verify_artifacts()` требует точные 51 cells / 77 attempts во всех десяти primary streams; проверяет run/cell/attempt/fault/config identity, все шесть ledger phases, baseline/recovery probes, timestamps и terminal order. Baseline другого attempt или той же cell не заменяет recovery. Attempt file identity связывается с filename и raw; HTTP/availability, durable state, baseline/recovery и retry/evidence admission пересчитываются и сверяются с attempt/cell summaries. Оба DB↔object join обязательны на каждый attempt; проверяются durable upload/part/blob IDs, storage keys, checksums и size. Legitimate pre-write refusal, abort/expiry `matched-absent` и `not-applicable` принимаются reader; missing-required-object/orphan, неверный join и checksum отвергаются. Producer defect B14-04 не исправлялся.

Prometheus reference связан с attempt filename; full reader требует successful instant vector `up{job="vaulter-sut",instance="app:8080"}`, green values и sample timestamps внутри recorded baseline→reconcile wall-time window. Runner записывает microsecond wall times и задаёт query `time` по recorded reconcile timestamp (с округлением вниз до millisecond). Payload checksum добавлен в primary attempt stream. `p7_injectors.py` и старый chaos test используют обычный import общего `p7_chaos`: прежняя повторная загрузка меняла identity `EvidenceInvalid` и ломала совместный запуск negative controls; до исправления full suite возвращал ошибки, итоговый suite ниже прошёл.

**Controls:** [32 новых tests](../../../load/gates/test_p7_evidence.py) выполняют неподменённый full verifier на complete synthetic matrix, затем независимо убирают attempts/streams/recovery/joins, меняют identities/status/checksums/size/phase order/Prometheus scope/window, проверяют malformed data, expected absence, exact blob/part joins и raw-derived verdict disagreements. Один control пропускает результат настоящего `run_window()` с fake external boundaries через полную matrix admission. `setUp` каждого теста сначала проверяет положительную fixture; full acceptance и повторный verify сохраняют bytes. Это обычные regression tests, не mutation testing и не product measurements.

| Проверка | Exit | Фактический результат |
|:--|--:|:--|
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7*.py' -v` | 0 | [80 passed](remaining-evidence/R11-B14-02-checks/p7-tests.log), из них 32 новых; existing negative guard diagnostics ожидаемы |
| `python3 -B plans/remaining-evidence/R11-B14-02-checks/readback.py --original-ref a6c6b91` | 0 wrapper | [Expected RED: 5 false admissions](remaining-evidence/R11-B14-02-checks/original-source-controls.json); original unpatched full verifier принимает positive fixture и все пять audit controls |
| `python3 -B plans/remaining-evidence/R11-B14-02-checks/readback.py` | 0 | [Все пять controls REJECTED](remaining-evidence/R11-B14-02-checks/current-controls.json); current unpatched full verifier принимает positive 51/77 fixture byte-stably |
| `PYTHONDONTWRITEBYTECODE=1 TIER=S ./load/scripts/p7-chaos.sh dry-run` | 0 | [51 cells](remaining-evidence/R11-B14-02-checks/dry-run.log), Docker runtime/DB/object writes отсутствуют |
| `python3 -B load/gates/p7_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P7` | 1 | [Expected historical rejection](remaining-evidence/R11-B14-02-checks/historical-rejection.log): `recovered without dependency probe`; не product GREEN |
| Shell syntax для пяти P7 scripts; Python `compile()` без bytecode; `git diff --check` | 0 | Syntax/diff checks |
| Historical P7 digest before/after | 0 | Bytes неизменны: `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62` |

[Source/log fingerprints и actual exits](remaining-evidence/R11-B14-02-checks/checks.json); source rollup SHA-256 (sorted path bytes + file bytes для шести changed load files): `8b7af0da8f97548bf2a1506cae874b6ac8decf2550dbcd60353f8ce850e4d854`. Synthetic run ID — `offline-evidence`; нового live run/stand digest нет. Focused self-review по `quality:code-review`; независимый агент/reviewer не запускался. PHP/package/PostgreSQL/live suites, новые chaos/load/SLO measurements, cold mutation и tests ради mutants не выполнялись: изменены только load admission/metadata/imports и offline tests.

**Ограничения сохранены:** verifier проверяет захваченный object pair; staging/part inventory и collector-error capture остаются B14-05, exact ACK/lock-holder order — B14-03, producer `matched-absent` — B14-04, immutable publication — B14-06. Full live admission всего harness и product GREEN не выданы. Historical RED/REJECTED/unevaluated, SLO-70 RED и deferred R11/R12 baseline/recovery gaps сохранены.

Счётчик: active **9 → 8**, done **29 → 30**, deferred **12 → 12**, conditional **1 → 1**; новых rows нет. Следующий единственный запуск — **33c/R11-B14-03**; он не выполнялся в этом запросе.

### R11-B14-01 — результат шага 33a (2026-09-28, offline corrective)

**B14-01 CLOSED** в load-only route/restore/entrypoint scope. Commit `a6c6b91` содержит только `load/` source/tests/docs и два raw test logs. Существовавшие до запроса незакоммиченные правки этого листа и `B14-audit.md` сохранены; текущая синхронизация листа остаётся в рабочем дереве. Package code, historical raw, thresholds и verdicts не менялись.

P7 chaos overlay задаёт `LOAD_SKIP_MIGRATIONS=1` для app и queue-worker без opt-out. `enable_chaos()` использует явный base route, `route_app()` запрещает migrations при обоих маршрутах; `restore_base()` передаёт skip через base Compose и пытается вернуть app/worker даже при failed isolated HA revert. Base worker всегда пропускает migrations; entrypoint по умолчанию skip и отвергает migration opt-in при P7 flags. Обычная non-P7 инициализация base app сохраняет прежний явный Compose bootstrap (`LOAD_SKIP_MIGRATIONS=0`); в P7 единственный bootstrap — explicit exec на `postgres-primary/vaulter_chaos`. `integrity-after.json` и `locks.json` читаются после actual restore в `finally`, включая exception cleanup. Terminal marker появляется только после успешного run, restore и обоих readbacks; ошибка любого из них не выдаёт success.

**Offline controls:** [16 новых тестов](../../../load/gates/test_p7_base_restore.py) используют real Docker CLI **только для Compose config**, fake Docker runtime и настоящий shell entrypoint с перенесёнными во временный каталог filesystem paths. Sentinel bytes защищены при setup, base↔HA routing, app/worker recreate, hostile migration environment, failed preflight/window/setup, proxy errors и failed HA revert; failed restore/readback/locks не создают terminal. Отдельный control меняет sentinel во время restore и подтверждает, что final snapshot видит это изменение. Explicit HA bootstrap меняет только isolated sentinel; сохранность реального product state эти controls не измеряют. Focused self-review по `quality:code-review` не выявил дополнительных defects в этом scope; независимый агент/reviewer не запускался.

| Проверка | Exit | Фактический результат |
|:--|--:|:--|
| `PYTHONDONTWRITEBYTECODE=1 python3 -B -m unittest discover -s load/gates -p 'test_p7*.py' -v` | 0 | [48 passed](remaining-evidence/R11-B14-01-checks/p7-tests.log), из них 16 новых; expected negative guard diagnostics сохранены |
| Два новых sentinel controls против original source `9bd90da` через `git show` во временном дереве | 0 wrapper | [Expected RED: 2 failures](remaining-evidence/R11-B14-01-checks/original-source-controls.log): direct default и exception/finally действительно превращают owner sentinel в `reset`; это воспроизведение дефекта, не PASS исходника |
| `sh -n load/docker/entrypoint.sh load/scripts/p7-chaos.sh load/chaos/pg-failover.sh` | 0 | Shell syntax |
| `PYTHONDONTWRITEBYTECODE=1 TIER=S ./load/scripts/p7-chaos.sh dry-run` | 0 | 51 cells; Docker runtime/DB/object writes отсутствуют |
| `PYTHONDONTWRITEBYTECODE=1 python3 -B load/gates/p7_live_matrix.py verify --artifacts plans/VLT-LOAD/artifacts/P7` | 1 | Expected historical rejection: `recovered without dependency probe`; не product GREEN |
| Historical P7 digest before/after + `git diff --check` | 0 | P7 bytes неизменны: `b51a143828512efb5bb85c5d4f05863677545ae183f2ee3b9f407e3100895a62` |

Source rollup SHA-256 (sorted path bytes + file bytes для `load/README.md`, обоих Compose files, `load/docker/entrypoint.sh`, `load/gates/p7_live_matrix.py`, `load/gates/test_p7_base_restore.py`): `7a1371863cb40adab1bab9a02759121f56a47b75ce51725e70d8cd96972684c2`. Synthetic run ID — `offline-sentinel`; нового live run ID/stand digest нет. PHP/package/PostgreSQL/live suites не запускались: изменён только load orchestration, проверенный shell/Compose/fake runtime. Live fault windows, chaos/load/SLO measurements, cold mutation и tests ради mutants не выполнялись. R11/R12 baseline/recovery остаются deferred, historical RED/REJECTED/unevaluated и findings B14-02…06 сохраняются.

Счётчик: active **10 → 9**, done **28 → 29**, deferred **12 → 12**, conditional **1 → 1**; новых rows нет. Следующий единственный запуск — **33b/R11-B14-02**; он не выполнялся в этом запросе.

### B14-sync — результат шага 33 (2026-09-28)

[B14-аудит](remaining-evidence/B14-audit.md) завершён как независимый offline read-only review: **REPAIRS REQUIRED**, один Blocker и пять Major в prepared R11/P7 harness. Проверены только исходники и сохранённый evidence; B14 не создавал нового live run и не менял historical raw trees. R24 claim/settlement/recovery и R25 readback новых findings не дали. Historical RED/REJECTED/unevaluated, R11/R12 baseline/recovery gaps и current mutation proof остались ровно теми же deferred gaps; product GREEN, live admission и close/archive VLT-LOAD не выданы.

В лист добавлены ровно шесть отдельных corrective rows перед R20, по одному на каждый воспроизведённый finding. Причина, scope и acceptance каждого зафиксированы в его строке: `R11-B14-01` — base route restore и миграционный safety (Blocker); `-02` — complete evidence admission; `-03` — fault ACK/lock-holder barrier; `-04` — `matched-absent` producer/reader contract; `-05` — staged/part object reconciliation; `-06` — immutable publication. Их порядок минимизирует повторные изменения: сначала безопасный restore, затем verifier, затем production/capture и publisher. Для каждого разрешены только focused offline controls; новые live/chaos/mutation/SLO runs исключены решением владельца.

Счётчик изменён прозрачно: active **6 → 10**, done **26 → 28**, deferred **12 → 12**, conditional **1 → 1**. Добавлены шесть rows, потому что audit воспроизвёл шесть разных defects с разными owning paths; иных findings и строк не добавлено. Следующий единственный разрешённый запуск — **33a / R11-B14-01**. Этот sync не запускает 33a автоматически.

### B07-R-sync — результат шага 19 (2026-09-28)

[Аудит B07-R](remaining-evidence/B07-audit.md) завершил шаг 18: **B07-01 CLOSED**, **B07-02 PARTIALLY FIXED / OPEN, Major**, B07-03 P5 missing-evidence portion устранён, B07-04 diagnostic aspect CLOSED. R03/R04 current-source proof gaps сохраняются RED/REJECTED/unevaluated и передаются в R20; mutation отложен владельцем. Product/live/B08 admission отсутствует. R08 SLO-70 RED сохранён: 80.501 s / 248.444 rows/s; batch 20000 — вывод из source/cardinality, оба scrape 404, наблюдённой batch series нет; PHP peak 126.5 MiB не является RSS. R08 `sweepable=false` не повышен до усиленного overlap proof; его предоставляет R05.

Синхронизация изменила только этот лист. Прежний отчёт B07 и новый B07-R не переписаны; raw/source/tests, thresholds и исторические результаты не изменены. Проверки самого аудита (54 Python + 7 JS, независимый parser/4 focused R05 tests) здесь **не перезапускались**. Для sync проверены структура таблицы, счётчики, единственный runnable NEXT, зависимости, ссылки, сохранность audit/raw/source и `git diff --check`.

**Причина вставки:** воспроизведённый B07-02 допускает persisted worst-case N=200 с одним actor/HTTP 200 и `participation=None`; `verdict_for` и полный verifier возвращают valid/GREEN. Real stored run содержит всех actors, поэтому raw verdict не переписывается. Добавлен только шаг **19a/R05-B07-02**, в него входят code/tests, независимый focused recheck и запись результата; отдельные повторные full-audit/sync строки не добавлены. Счётчики: active 8 → 6 после закрытия 18/19 → 7 после вставки 19a, done 23 → 25, deferred 12 → 12, conditional 1 → 1. Строка 29 не активирована: этот finding относится к R05, не R24/R25.

### R05-B07-02 — corrective шага 19a

**Вход:** текущий раздел B07-R / B07-02 в [аудите](remaining-evidence/B07-audit.md), его воспроизведение и stored run `adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe`. Это offline repair gate, не запуск старого VLT-LOAD.

**Scope:** `load/gates/p5_live_matrix.py` (`verdict_for`), `load/gates/p5_concurrency.py` (structural admission) и существующие затронутые offline tests. Для persisted evidence проверять participation обоих product plane; primitive/dirty-cardinality contracts и thresholds сохранять. Пакеты, live harness workload, raw files и старые source digests менять не требуется.

**Acceptance:** missing actor, missing/incomplete participation, aborted iteration, setup-only и успешный create без operation в worst-case отклоняются structural/verdict/full verifier; realistic protection сохраняется. Явные create failures остаются наблюдёнными outcomes по действующему capture contract. Валидные оба product plane проходят focused controls. Read-only replay 56-cell stored run проходит на исправленном gate; raw digest остаётся `a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074`. Вне scope целые suites или проверки ради мутантов не запускать.

**Завершение в этом же шаге:** независимый read-only focused re-review GPT-6 Astra проверяет конкретный B07-02 на final source/test snapshot и отрицательные controls. Записать его evidence/disposition в B07-audit.md, сохранив оба прежних отчёта как историю; primary executor переносит итог в этот лист. Только после исправления и recheck отметить 19a ГОТОВО и 32/B14 СЛЕДУЮЩИЙ. Не нужен повтор полного B07; при оставшемся defect продолжать 19a, не открывать новый повторный шаг. Нет product GREEN или live/B08 admission. Контракт шага выполнен; результат ниже. Текущую следующую команду задают Next и строка 32/B14.

### R05-B07-02 — результат шага 19a (2026-09-28; offline corrective)

**B07-02 CLOSED в bounded persisted participation scope.** В `p5_concurrency.py` structural admission v2 и в `p5_live_matrix.py::verdict_for` participation теперь проверяется для `product-realistic` и `product-worst-case`. Existing capture/overlap logic, primitive/dirty-cardinality contracts и thresholds сохранены. Изменены только эти два gate, существующий `test_r05_capture.py`, [аудит B07](remaining-evidence/B07-audit.md) и этот лист; пакеты и raw files не менялись.

Primary выполнил **8 focused offline tests, exit 0**. Новые controls: 11 вариантов × 2 product plane × structural/verdict/full-cell/full-attempt/full-both = **110 rejection checks**. Missing actor, absent/null/empty participation, incomplete started/completed/iteration count, aborted iteration, setup-only, successful create без operation и исходный one-actor B07-02 отвергаются. Explicit create failures остаются observed outcomes по прежнему контракту; valid обеих product plane принимается. До исправления два focused tests дали 22 ожидаемых worst-case failures; final snapshot проходит.

**Независимый read-only focused re-review GPT-6 Astra:** B07-02 CLOSED, findings в заказанном scope нет. **7 focused tests, exit 0**; отдельный независимый parser/controls, exit 0: 110 rejection checks, 18 valid create-failure admissions (403/429/503), 4 primitive negative checks, сверка всех 56 stored verdicts. Exact commands/exits и fingerprints записаны в новом верхнем разделе B07-audit.md; оба прежних отчёта сохранены побайтно ниже. Это focused recheck существующего finding, не повтор полного B07.

Read-only replay run `adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe`: **56/56 cells**, verifier exit **0** у primary и независимого reviewer. Raw tree до/после **`a248913b1c49555b4cd397b31df2044cd1a54585410c7362987cb2899dde2074`**. Stored source digest остаётся историческим; fingerprints исправленного source отдельно в аудите. Collab HTTP 403, collision unconfirmed, historical RED/REJECTED/unevaluated и deferred product proof не переписаны. Новые live/chaos/mutation/SLO measurements и product/live/B08 admission отсутствуют.

Лист синхронизирован после исправления/recheck: **19a ГОТОВО**, **32/B14 СЛЕДУЮЩИЙ**. Active **7 → 6**, done **25 → 26**, deferred **12 → 12**, conditional **1 → 1**. Новые шаги не добавлены; B14 здесь не запускается.

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

[Raw preflight `b2e80447-c8b4-48ad-967f-a9728470b805`](../VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/run-manifest.json), [commands/exits](../VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/commands.json), [source archive](../VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/source.tar.gz), [checksums](../VLT-LOAD/artifacts/R03/b2e80447-c8b4-48ad-967f-a9728470b805/sha256.json). Это инвентаризация условий, не новый product run. Source revision `55d10b6afd864b2820061c31cc5dc84ec54d714c`; digest фактических package/load исходников, включая dirty harness, `48d2dc9cbdc52b719b3486b140dfa684c5e9304e716dd8c36f498cf23d9838da`; stand-config digest `caa156087ae948fd53cd0aae645b2f8b8b14b43b5341b17800a89eda0bfc8fdd`.

На диске 9,8 GiB свободно; штатный hot-set S — 20 000 × ≈1 MiB (≈20 GiB), дополнительно нужны PG/WAL и запас под нагрузку. `load/seed/out/` отсутствует; staged manifest — historical S0 (400 hot objects), не доказанный полный S. Существующий остановленный стенд содержит 15,2 GiB MinIO-объектов и 1,3 GiB PG; эти данные не удалялись и не пересоздавались. Условия `reference-env.md` §5 сейчас не выполнены: governor `powersave`, `intel_pstate/no_turbo=0`, включённый swap с ≈5,4 GiB занятых страниц, сторонние Docker-стеки работают. Историческая оговорка P2.8 о S0-объёме на S-железе не превращается в доказательство полного tier-S baseline.

Focused `test_r03_summary.py`: 3/3, exit 0; `sh -n load/scripts/run.sh`: exit 0; `maind health --cwd --json`: connected, exit 0. Аудит сохранённого старого summary при declared 180s: exit 2, `full_window=false`, `proof_ready=false`; это ожидаемый отказ принимать historical ≈32s run. Throughput SLO-40/43/49/52 остаётся historical RED, новая атрибуция/class-B/full-window — unevaluated. Package/harness-код не менялся, load/mutation/chaos не запускались; read-only helper завершён, исходное состояние стенда сохранено.

Маршрут остаётся R03: подготовить lab-условия и достаточный диск (либо предоставить другой соответствующий стенд), затем новый immutable full-window run с S0/lab comparison, tagged metrics, sample counts и measured attribution. Удаление существующих данных требует явного запроса владельца по `AGENTS.md`; остановка сторонних стеков требует определения владельцем затрагиваемого scope. Шаг 16 не закрыт, R04 не допущен и не запускался; счётчик остаётся 19, новые задачи не добавлены.

### R03 — результат шага 16 (2026-09-28; qualified proof REJECTED)

[Immutable campaign `4f565edf-58ca-47a0-869f-b9148ebcd2b6`](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json), [commands/exits](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/commands.jsonl), [findings](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/harness-findings.json), [checksums](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/sha256.json). Этот результат заменяет preflight как текущий статус R03; preflight выше сохранён исторически. Source revision `55d10b6afd864b2820061c31cc5dc84ec54d714c`, фактический source digest `48d2dc9cbdc52b719b3486b140dfa684c5e9304e716dd8c36f498cf23d9838da`; архив не дублировался, все 1609 файлов совпали после работы. Package/harness-код и чужие изменения не менялись.

Стенд — новые scoped DB/buckets `vaulter_r03_4f565edf_s[0]` / `vaulter-r03-4f565edf-s[0]`, отдельный compose project, существующие cached images. Стандартные S0/S CPU/memory/VU limits сохранены; single-shot 5 MiB, TUS 8-MiB чанки, S3 64-MiB части. Seed S: 100000 tree nodes + документы, фактически nodes 105002, blobs 72099, hot objects 20000 (20965187300 bytes), activity 2000001, uploads 50000, outbox 10001. Первый seed S завершился PHP512M OOM, exit 255: rejected preparation. Повтор exit 0, 1210.230 с, seed-only PHP2G/cgroup3G, metrics/tracing отключены только в seed; SUT app остался PHP512M/2G. Сохранён сжатый baseline DB 58 MiB и object catalog. Cache seed-warm, cache drop не делался.

| Cell / evidence | Declared window | Summary duration | Exits | Результат |
|:--|:--|:--|:--|:--|
| [S0 `ab5edbe8`](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/s0-mixed-ab5edbe8-analysis.json) | 180 с | 190.345 с | k6 99; writer 0; audit 2 | Full window; TUS med/min 29.969/1.729, S3 putPart 64.975/13.901, single 49.505/10.707 MiB/s; download n0 |
| [S `740fb7e0`](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/s-mixed-740fb7e0-analysis.json) | 180 с | 202.539 с | k6 99; writer 0; audit 2 | Full window; qualified proof rejected, 0/15 class-B keys ≥100 samples; download/docs не участвовали |
| [S offset diagnostic `87b1f166`](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/s-tus-offset-87b1f166-analysis.json) | 3 × 160 MiB, 20 чанков по 8 MiB | wall 73.310 с | k6 0 | 57×204 + 3×201, 66/66 checks; три завершённых файла скачались HTTP 200. Bounded attribution, не mixed SLO proof |

У обоих mixed runs ноль interrupted iterations; раннего abort по threshold нет. Первое S0 окно остановилось около43 с; точный exit исходного k6 не был сохранён, stale historical summary явно rejected. Повтор использовал Go memory tuning внутри неизменённого k6 cgroup и сохранил exit/container inspect. Другой S0 pre-run отказал из-за отсутствующего pg_stat_statements; workload там не начинался. Эти попытки не входят в proof.

| SLO, tier S | Наблюдение полного S | Интерпретация |
|:--|:--|:--|
| SLO-40 / OP-02 | n95; med 17.021, min 1.660 MiB/s; floors 25/15 не пройдены | Observed RED; tagged data_sent=0 делает агрегатный byte-rate недостоверным |
| SLO-43 / OP-06 | n49, 49 accepted201; med 27.004, min 11.012 MiB/s | min ниже15; materialized gate ошибочно med>100 от complete. Это не отдельный valid verdict SLO-43/46 |
| SLO-49 / OP-17 | n5 accepted201; med 11.765, min 6.545 MiB/s | Observed ниже25/15; выборка sparse |
| SLO-52 / OP-08…10 | n0; data_received tag0 | unevaluated: пустой download pool, не product throughput RED |
| Class B | OP-02 n99, OP-06 n49, OP-01 n50; все15 ключей <100; docs keys n0 | unevaluated. Powersave/turbo/swap/сторонние стеки также исключают lab admission; header profile=lab сам по себе допуска не даёт |

Measured attribution: в mixed S app использовал в среднем1.885 из2 cores (p95≈1.999), PG0.077 и MinIO0.125 core; PG WAL sync 12.589 с за окно. В отдельном offset diagnostic accepted PATCH median вырос с97.542 мс при offset0 до1514.632–1861.755 мс в конце prefix, final commit3948.553 мс; Pearson r(nonfinal offset,duration)=0.921. При503316480 uploaded bytes MinIO получил5.819GB и отправил6.302GB; PG WAL sync лишь0.258 с. `StagedUpload::append()` читает накопленный prefix и переписывает объект на каждом чанке: измерения поддерживают rewrite amplification как одну причину TUS degradation. PHP fsync отдельно не измерен, единственная причина не объявлена. Server `single` counters включают финализацию TUS/S3 через общий UploadFileAction и не изолируют OP-17. S0/S имеют разные dataset/CPU envelopes, поэтому причинное сравнение улучшения не заявляется.

Новые строки добавлены явно: **19 → 18 → 21**. **16a / R03-H01…H05:** threshold collision OP-06/07, нулевые tagged byte counters, отсутствующий primary blob FK, silent empty/sparse pools и throughput до проверки HTTP acceptance; scope только harness/seed/gates. **16b / R03-P01:** [HTTP500 + исходный метод](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/product-bind-limit-finding.json), `packages/core/.../NodeReadRepository.php` OR-ит группы по900 IDs в один statement; общий bind count всё равно >65535. Owner root listing падает, хотя documents folder и5000 документов есть: это дополнительная причина нулевого docs participation. Отдельный package-core corrective **R27** обязателен; соседние пакеты в нём не менять. **16c:** после обоих исправлений нужен новый valid source/window run, поскольку текущий full window имеет потерянные/отсутствующие обязательные серии. Увеличение duration само эти defects не исправит.

Проверки: source verification1609/1609; прежние focused R03 tests3/3, shell syntax и coverage96/96 остаются применимы к неизменённому коду. Новые S0/S summary audits exit2 ожидаемо отвергли proof. [Cleanup readback](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/cleanup-readback.json): удалены только105 добавленных этим workload объектов (6636437504 bytes); все20000 baseline keys/ETags/sizes сохранены. Сгенерированный S0 bucket очищен после readback; owner buckets/volumes и контейнеры не удалялись. Наши сервисы остановлены; foreign stacks/host settings не менялись. Перед новым прогоном восстановить scoped baseline DB и проверить timestamps/фикстуры: текущие post-workload DB rows сохранены для evidence, не являются clean baseline после cleanup объектов. На завершении свободно28488388608 bytes. Historical RED сохранён; R04 и corrective-код следующих строк не запускались; mutation не запускалась. Единственный NEXT —16a.

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

[Первый preflight `af3c88b5`](../VLT-LOAD/artifacts/R03/af3c88b5-ea6d-42f8-8cb2-f6ec466895c8/run-manifest.json), [Compose resource facts](../VLT-LOAD/artifacts/R03/af3c88b5-ea6d-42f8-8cb2-f6ec466895c8/stand-resources.json) и [helper error](../VLT-LOAD/artifacts/R03/af3c88b5-ea6d-42f8-8cb2-f6ec466895c8/lab-helper-error.txt) воспроизвели **R03-H06**: `docker compose config --format json` выдаёт `mem_limit`/`memswap_limit` как decimal byte strings (`"2147483648"`), а `r03_lab.observe_host` сравнивал строку с числом и падал с TypeError. Source `0160ce3e38d6d2cd5242732931cfc391aa21afe9`, digest `60b88bef9a0c9d61a3b51100cdbbdd7c52050dfd4344686cbf24eec87501168e`; исходные байты 1618 файлов сохранены в source archive/checksums.

Bounded inline repair в рамках R03: только `load/gates/r03_lab.py` и `test_r03_lab.py`. Memory admission теперь принимает integer/decimal byte string, сравнивает численные байты и отвергает отсутствующие/невалидные значения, bool/float и различающиеся memory/swap limits. Новые регрессии воспроизвели TypeError и ошибочный допуск bool/float (до исправления exit 1), после исправления весь focused Python suite — 18 tests exit 0; JS suite — 7 tests exit 0; runner shell syntax — exit 0. Пороговые значения/SLO и package-код не менялись. Это завершённый inline repair до окна; measurement остаётся невыполненным.

[Повторный preflight `0ff73501`](../VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/run-manifest.json), [commands/exits](../VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/commands.json), [host facts](../VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/host-observations.json), [checksums](../VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/sha256.json). Source revision та же, с фактическим inline repair: digest `94f282f338ac7f200ea98486be6e5642dcf6b80cab3d930d2bb0cb962ea869a9`; отдельный архив фиксирует эти байты. Resource-only stand digest `6a81abf7ad983ebf7bd2e46d83189f57bc906d22fbc69e3d4d7cb5d01fe67875`; credentials из compose/inspect не сохранялись.

Host admission не готов: governor `powersave`, `intel_pstate/no_turbo=0`; 19 foreign containers; swap включён, занято ≈5.8 GiB. Load SUT остановлен, поэтому actual cpuset/memory limits пока не подтверждены (L-2/L-5); это не product failure. На NVMe свободно ≈27 GiB, вторичный диск — <1 GiB. Существующий PG volume — 5 377 328 KiB; отдельное PG storage или доказанно помещающийся объявленный tmpfs пока не подготовлены (L-4). Warm/cold declaration и run-bound reset outputs L-6/L-7 отсутствуют. Noninteractive sudo требует пароль. [Конкретный scope подготовки](../VLT-LOAD/artifacts/R03/0ff73501-ddff-4906-a01b-3018c0e74c32/host-preparation-proposal.json) сохранён как owner-deferred, действия не выполнялись.

Live/load/mutation/chaos не запускались, owner data/volumes не удалялись, foreign containers и настройки хоста не менялись. Preflight raw outputs/source archives/checksums сохранены; historical RED/REJECTED шага 16 не переписаны. Для возобновления нужны явное решение владельца и lab L-1…L-7; затем новый immutable source/stand/dataset freeze и qualified S0/S окна. Сейчас runnable NEXT отсутствует, **16c/R03 отложен**, R04 и зависимые шаги ждут.

### R04 — код и offline tests (2026-09-28; live отложен)

Владелец уточнил scope: **«живого стенда пока не будет, делаем по максимуму код и тесты»**. Это разрешение на отдельный code/test срез R04 без valid live R03. Код шага 17 выполнен, commit `326f69b`. Измерение вынесено в 17a и не выполнено: live R04 отложен, qualified capture по-прежнему требует готового lab и valid R03. Остаток **18 → 17** за счёт отложения live, на момент выполнения число полностью завершённых строк оставалось 19; новая задача не добавлялась. При текущей синхронизации готовый код выделен в строку 17, live — в 17a: активный остаток 17 → 17, выполненные строки 19 → 20, scope не расширен.

Исходная revision `15170d5c02cb4bfe49fe587cb00db49faada5a50`. [Readiness и offline checks](../VLT-LOAD/artifacts/R04/95affec7-a862-417f-8f53-e6e78282f495/run-manifest.json) сохраняют exact commands/exits, initial readiness, regression-before и хеши итогового load scope. Старый P3 trace остаётся пустым; его verify/report ожидаемо отвергаются. Исторические manifest/top-20/trace хеши до и после совпадают.

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

**Актуальный execution scope после решения владельца, подтверждён 2026-09-29:** живого стенда не будет; live R03/R04/R13/R15/R23/R26/R11/R12 и cold mutation исключены из текущей очереди. Code/test R21/R24, classification R25, B07-R/sync, R05-B07-02 и шесть B14 correctives готовы в указанном offline scope. R20 собрал текущие факты без стенда и без принятия риска. B17 offline review/sync и 37a/37b repairs готовы; оба B17 findings CLOSED по независимому focused recheck. **Offline scope закрыт Done with deviations по последующему указанию владельца завершить план; runnable хвоста нет.** Все named residuals retained вне текущего плана; product risk acceptance не выдан. Все шесть B14/P7 findings исправлены и проверены обычными offline regressions; реальные fault/recovery/SLO claims не выдаются. Historical results выше, включая старые Next, ожидание стенда и Astra review в R05, описывают прежний scope; актуальную очередь задаёт текущая таблица. Новые измерения возможны только по отдельному будущему запросу владельца с новым run ID/source/stand digest и raw evidence, и не являются условием завершения offline работы.

## Справочник: правила доказательств

Read the applicable finding, SLO contract, gate contract, raw run and evidence carrier before a row. The latest conclusion for an audit replaces an older conclusion in that audit; it does not rewrite a product verdict outside its stated scope.

- VLT-LOAD is terminal `Done with deviations`; its P8 receipt is lifecycle evidence, not a product-wide GREEN. Do not execute its old `Pn.m` items. AUDIT-VLT and VLT-HSEAM are archived/superseded; their live Layout-v2 previews are quarantined and are not launch queues.
- B02's last section closes **B02-01/02 in its scoped runtime/verifier scope**. It does not create a new full P6 runtime or an all-SLO PASS. R09's stored P6 evidence remains the authority for the measured SLO-58/64 GREEN and SLO-61 RED; the historical failed-job identities remain unrecoverable.
- Последний [focused recheck B07-02](remaining-evidence/B07-audit.md), 2026-09-28, закрыл persisted participation finding после 19a/R05-B07-02; прежние B07-R и B07 сохранены как история. B07-01 CLOSED: raw всех 9 R05 overlap attempts подтверждён. B07-02 CLOSED: оба product plane проходят structural/verdict admission, 110 negative checks отвергнуты, 56-cell raw-preserving replay и независимый GPT-6 Astra recheck прошли → 32/B14. B07-03 P5 missing-evidence portion устранён; qualified current-source R03/R04 gaps retained RED/REJECTED/unevaluated → R20, новые live measurements исключены. B07-04 missing-diagnostic aspect CLOSED по R06/R08: argv failure установлен, настоящий R08 sweep выполнен, SLO-70 RED не отменён; batch 20000 — source-derived cardinality, не наблюдённая batch series (оба scrape 404), PHP peak не RSS. B07-05 mutation отложен до фиксации API перед 1.0. Collision остаётся unconfirmed по 429-only. Product/live/B08 admission не выдан; прежний B07 сохранён как история.
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
| L-P5-01/02/04; **B07-01** | R05 | **CLOSED в bounded R05 scope по B07-R**: все 9 overlap attempts независимо подтверждены — sweepable, sweep exit 0, lock до/после sweep, target refusal, final uploading/4, retry 204. Не natural-race frequency и не SLO-70 |
| L-P5-01/02/04; **B07-02** | R05-B07-02, шаг 19a | **CLOSED по шагу 19a и focused recheck GPT-6 Astra**: admission обоих product plane, 110 отрицательных participation checks, unchanged-digest 56-cell replay; current source/test fingerprints в аудите. Collab HTTP 403 остаётся historical product outcome |
| B07-03 P5 portion | R05 | **Missing-evidence portion устранён по B07-R**: 85 product attempts / 4702 actor iterations и 9 overlap attempts проверены; gate finding B07-02 CLOSED после 19a и независимого focused recheck. Run adcdcdb7 — 56/56 valid по текущему verifier; 429-only collision unconfirmed. Qualified R03/R04 proof gaps остаются отложенными → R20 |
| L-P5-03, L-02; B07-04 | R06/R08 | **Missing-diagnostic aspect CLOSED по B07-R**: R06 argv `artisan`, exit 1, remaining 20000, PHP peak 36175872; product sweep не запускался. R08 настоящий sweep выполнен, SLO-70 RED сохранён; original P5 stderr не восстановлен и tier-L/SLO-72 proof не выдан |
| drive L-02, SLO-70/72 | R08 | step 11 kept drive source; step 12 SLO-70 RED (80.501 s, 248.4/s, batch 20000 source-derived; telemetry absent/scrape 404, PHP peak 132644864 — не RSS). Cold R07 `>=95` больше не блокирует правки drive: отложен до фиксации API перед 1.0 |
| drive D11 / VLT-MUT P2; B07-05 | R07 | step 13 measured 65.38% (1091+25)/1707. Step 14 measured 79.51% (1420/1786), 84.25% (1514/1797), 84.70% (1522/1797), then 86.20% (1549/1797). Gate 95 exit 1. Owner deferred the chase until the API is frozen before 1.0. Historical scores stay |
| P6 L-P6-01…04; **B02-01/02** | R09 | no re-execution without a source-changing reason |
| P6 concurrent duplicate: 2 dispatch / 2 HTTP | R24 | Code/test выполнен: source digest, red-before и green concurrent regression, независимый review без findings; [результат](#r24--результат-шага-27-2026-09-28-codepackage-tests). Historical verdict неизменён; R26 отложен, current product proof gap → R20 |
| P6 recovery RED, sticky historical failed jobs, SLO-61 RED | R25 | [Classification выполнена](#r25--результат-шага-28-2026-09-28-offline-classification): original capture → completed R09; последующие recovery/SLO-61 → retained RED, без локализованного package defect. Каждая RED cell и attempt перечислены в raw readback; historical job identities неизвестны. R26 отложен, residuals → R20 |
| P6 current full verdict; SLO-58…64 and endpoint M/L | R26 | R24/R25 готовы; R26 исключён до конца плана. Historical RED/unevaluated и current-source duplicate/recovery/failed-job proof gaps → R20; unit suites/overlay не дают full verdict |
| P7 L-P7-01…05 | R11 | live baseline исключён до конца плана; подготовленный code/gates scope проверяется offline в B14. Missing baseline/actual fault proof → R20 |
| B17-01 multipart received/parts integrity | R11-B17-01, шаг 37a | **CLOSED** по `719af61`: received = sum unique durable part sizes для open unassembled session, producer/full reader controls rejected, 177 P7 tests и независимый focused recheck; legitimate zero-byte/assembled/terminal cases сохранены. Только offline, не live proof |
| B17-02 payload/final CAS integrity | R11-B17-02, шаг 37b | **CLOSED** по `42a6f3e`: payload/blob/CAS key/observed checksum согласованы, size check сохранён; both original controls rejected, 185 P7 tests и independent focused recheck 47 tests без findings; только offline |
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
| VLT-REMED handoff: 78.6% < 80% release-readiness | historical RED on `e79b175` / run 29203669361; later main `c502f5f` passed `--min=80`; **current HEAD `9c8497a` unevaluated** (R20, no CI dispatch) | fund/measure current SHA, or explicitly accept the residual/gap |
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

The authorized offline work is ready for owner review when the following are evidenced. This does not establish product-wide readiness or acceptance of the excluded measurements:

- every ledger row is complete, explicitly retained RED/unevaluated with an owner disposition, or an accepted archival limitation;
- B14-03…06 have code fixes and focused ordinary offline regressions (ACK/order, expected absence, staged/part inventory and immutable publication), or an explicit owner disposition for a remaining defect; no actual fault/promotion/recovery claim is inferred from controlled peers;
- B07-R and B14/B17 finish their bounded offline scopes; every actionable code/evidence/reporting finding is repaired and rechecked or has an explicit owner disposition. Missing excluded live/mutation proof is reported separately and does not demand a stand before offline completion;
- current-source mutation proof >=95 for drive, core, documents and media is deferred until the API is frozen before 1.0 and is not required to finish the rest of this ledger; R18/R19 remain valid for their sources;
- the R21 media code/test result is reported with historical R14 throughput/batch-cap RED retained; R23 is explicitly deferred, without a current-source SLO claim;
- R24/R25 code/test/classification results are reported with historical P6 duplicate/recovery/SLO-61 verdicts and unknown original failed-job identities preserved; missing current R26 matrix and R11/R12 P7 baseline/recovery remain explicit deferred gaps;
- R20 publishes the SLO-by-tier table separating historical measurements from current-source gaps, current CI/release/host facts, all 12 deferred rows and the owner-decision list. M/L or 24-hour gaps remain `unevaluated` unless actually measured. No new live stand/run is required to finish the authorized offline scope;
- the owner, not an executor or audit, decides whether any remaining RED, unevaluated target, deployment constraint, required-check limitation, or deferred publication is acceptable and whether to close/archive VLT-LOAD.

## Архивная навигация REC-02 (2026-09-29)

Это навигационное дополнение к историческому реестру, не очередь и не разрешение
на запуск старых команд. Оно покрывает все 47 ссылок REC-02 (36 различных целей).

Байты девяти Markdown-снимков сохранены. `B02-audit.md`, `R01.md`, `R02.md`,
`R05.md`, `R06.md`, `R07.md`, `R09.md` и `R10.md` входят в
[task-start SHA-256 snapshot R09/B02](remaining-evidence/R09-B02-seam-checks/task-start-evidence-sha256.json).
Для `B02-audit.md` также записана побайтная сохранность в
[preservation.json](remaining-evidence/R09-B02-seam-checks/preservation.json).
`B07-audit.md`, `R09.md` и `R10.md` входят в поле `sha256`
[исторического readback R20](remaining-evidence/R20-checks/readback.json);
`B07-audit.md` прямо требует побайтного сохранения прежних отчётов B07-R/B07.
Recorded fingerprints относятся к своим историческим версиям; они не обновлены
и не объявляются хешами текущего HEAD.

В сохранённых снимках адреса `../../../../VLT-LOAD/<suffix>` и
`../../../../VLT-MUT/<suffix>` разрешаются вне архива. От нынешнего каталога
`remaining-evidence/` рабочие префиксы были бы `../../VLT-LOAD/` и
`../../VLT-MUT/`. Исторические repo-пути `plans/VLT-LOAD/`, `plans/VLT-MUT/` и
`plans/remaining-evidence/` после архивации читаются как
`plans/archive/VLT-LOAD/`, `plans/archive/VLT-MUT/` и
`plans/archive/REMAINING-WORK/remaining-evidence/`. Это пояснение маршрутов,
а не замена provenance, raw evidence, manifests или команд воспроизведения.

В таблице слева указаны сохранённые файлы и строки каждого вхождения; справа —
прямой рабочий адрес из этого реестра. Имя цели совпадает с историческим адресом
после префикса `../../../../`. Каталоги и tar.gz доступны для чтения; наличие
маршрута не означает новый verifier outcome, live admission или product GREEN.

| Сохранённые вхождения в `remaining-evidence/` | Рабочая архивная цель |
|:--|:--|
| `B02-audit.md:7`, `R09.md:116` | [VLT-LOAD/artifacts/P6-R09/52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201/](../VLT-LOAD/artifacts/P6-R09/52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201/) |
| `B02-audit.md:90`, `R09.md:84` | [VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa/](../VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa/) |
| `B02-audit.md:94`, `R09.md:82` | [VLT-LOAD/artifacts/P6-R09/aecd7112-8d5c-4fbc-a7ae-a4fe468ced57/rejected.json](../VLT-LOAD/artifacts/P6-R09/aecd7112-8d5c-4fbc-a7ae-a4fe468ced57/rejected.json) |
| `B02-audit.md:96` | [VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa/prometheus/exposition.prom](../VLT-LOAD/artifacts/P6-R09/bc199ced-5b0f-4e77-b5ca-1a73055c0bfa/prometheus/exposition.prom) |
| `B02-audit.md:100`, `B02-audit.md:229` | [VLT-LOAD/slo-gates.md](../VLT-LOAD/slo-gates.md) |
| `B02-audit.md:222` | [VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/prometheus/relayed.json](../VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/prometheus/relayed.json) |
| `B02-audit.md:223` | [VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb/prometheus/relayed.json](../VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb/prometheus/relayed.json) |
| `B02-audit.md:243`, `R01.md:3` | [VLT-LOAD/slo.md](../VLT-LOAD/slo.md) |
| `B02-audit.md:321`, `R09.md:26` | [VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52-runtime-source.tar.gz](../VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52-runtime-source.tar.gz) |
| `B02-audit.md:322`, `R10.md:24` | [VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb-runtime-source.tar.gz](../VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb-runtime-source.tar.gz) |
| `B07-audit.md:126` | [VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe](../VLT-LOAD/artifacts/R05/adcdcdb7-2c7f-49dc-a0d3-8ac704bb51fe) |
| `B07-audit.md:136` | [VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json](../VLT-LOAD/artifacts/R03/4f565edf-58ca-47a0-869f-b9148ebcd2b6/run-manifest.json) |
| `B07-audit.md:140` | [VLT-LOAD/artifacts/P3](../VLT-LOAD/artifacts/P3) |
| `B07-audit.md:144` | [VLT-LOAD/artifacts/R06/03f5d89d-e882-41e9-86e0-43eb0f93cd2e/sweeper](../VLT-LOAD/artifacts/R06/03f5d89d-e882-41e9-86e0-43eb0f93cd2e/sweeper) |
| `B07-audit.md:146` | [VLT-LOAD/artifacts/R08/8a9c70cf-c531-48ac-bdce-d566726aed20/sweeper/dirty-20k.json](../VLT-LOAD/artifacts/R08/8a9c70cf-c531-48ac-bdce-d566726aed20/sweeper/dirty-20k.json) |
| `R01.md:3` | [VLT-LOAD/brief/2026-09-23-l04-core-retry.md](../VLT-LOAD/brief/2026-09-23-l04-core-retry.md) |
| `R01.md:3`, `R09.md:6` | [VLT-LOAD/queue-report.md](../VLT-LOAD/queue-report.md) |
| `R01.md:42` | [VLT-LOAD/artifacts/P6/database/tier-s-volume.json](../VLT-LOAD/artifacts/P6/database/tier-s-volume.json) |
| `R02.md:6`, `R02.md:18` | [VLT-MUT/findings/P0.1-parallel-defect.md](../VLT-MUT/findings/P0.1-parallel-defect.md) |
| `R02.md:6` | [VLT-MUT/findings/P0.2-baseline-drive.md](../VLT-MUT/findings/P0.2-baseline-drive.md) |
| `R02.md:6` | [VLT-MUT/findings/P0.2-baseline-media-filament.md](../VLT-MUT/findings/P0.2-baseline-media-filament.md) |
| `R02.md:6`, `R02.md:52` | [VLT-LOAD/artifacts/P4-l01-mutation.json](../VLT-LOAD/artifacts/P4-l01-mutation.json) |
| `R05.md:5` | [VLT-LOAD/concurrency-report.md](../VLT-LOAD/concurrency-report.md) |
| `R05.md:5`, `R06.md:5` | [VLT-LOAD/findings/P5-concurrency.md](../VLT-LOAD/findings/P5-concurrency.md) |
| `R05.md:5` | [VLT-LOAD/artifacts/P5/run-manifest.json](../VLT-LOAD/artifacts/P5/run-manifest.json) |
| `R05.md:5` | [VLT-LOAD/artifacts/P5/cell-verdicts.json](../VLT-LOAD/artifacts/P5/cell-verdicts.json) |
| `R06.md:5` | [VLT-LOAD/artifacts/P5/sweeper/dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1.a1.json](../VLT-LOAD/artifacts/P5/sweeper/dirty-cardinality-probe.sweeper-overlap.dirty-20k.n1.a1.json) |
| `R06.md:5` | [VLT-LOAD/artifacts/P5/sweeper/dirty-cardinality-probe.sweeper-overlap.dirty-1m.n1.a1.json](../VLT-LOAD/artifacts/P5/sweeper/dirty-cardinality-probe.sweeper-overlap.dirty-1m.n1.a1.json) |
| `R07.md:7` | [VLT-MUT/findings/P0.2-drive-escaped.txt](../VLT-MUT/findings/P0.2-drive-escaped.txt) |
| `R09.md:6` | [VLT-LOAD/findings/P6-queue-semantics.md](../VLT-LOAD/findings/P6-queue-semantics.md) |
| `R09.md:23` | [VLT-LOAD/artifacts/P6-R09/e281e5bb-1bd1-4435-8ed0-69eaa26ee134/rejected.json](../VLT-LOAD/artifacts/P6-R09/e281e5bb-1bd1-4435-8ed0-69eaa26ee134/rejected.json) |
| `R09.md:24` | [VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/](../VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/) |
| `R09.md:60` | [VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/slo-verdicts.json](../VLT-LOAD/artifacts/P6-R09/3df3b9e1-32b8-4e15-8b8f-c4efffe28f52/slo-verdicts.json) |
| `R09.md:82` | [VLT-LOAD/artifacts/P6-R09/aecd7112-8d5c-4fbc-a7ae-a4fe468ced57/](../VLT-LOAD/artifacts/P6-R09/aecd7112-8d5c-4fbc-a7ae-a4fe468ced57/) |
| `R09.md:133` | [VLT-LOAD/artifacts/P6-R09/52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201-runtime-source.tar.gz](../VLT-LOAD/artifacts/P6-R09/52cc42c8-1cb5-48a5-8e5b-0a3c0b9b0201-runtime-source.tar.gz) |
| `R10.md:24` | [VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb/](../VLT-LOAD/artifacts/P6-R10/56823709-fe47-4173-b965-dc51ea523bcb/) |
