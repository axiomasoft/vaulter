# Remaining Work — единый исполнительный лист

**Единственный актуальный документ для работы — этот файл.** Здесь порядок, статусы, остаток, результаты и следующая команда. Старые `remaining-evidence/`, VLT-LOAD и архивные планы — доказательства/история; их launch/next-блоки не задают текущую очередь. Дублирующие START-HERE, ACTIVE и RUNBOOK удалены. Для обычных шагов новые status/handoff/carrier-файлы не создавать.

**Обновлено:** 2026-09-27. Последняя завершённая работа: **R05, исправление кода B07-01/02**, commit `d042695`; 30 offline tests passed. Live R05 ещё не выполнен.

**Остаток:** 27 запланированных шагов: **20 запусков работ/измерений + 3 аудита + 3 обновления по аудиту + 1 решение владельца**. 9 строк выполнено. Ещё 1 условная строка для repairs по результатам R24/R25; она не входит в 27, пока конкретная работа не установлена. Это число шагов, не процент готовности продукта и не оценка времени.

Новые задачи нельзя добавлять молча: для каждой вставки нужны finding, причина, scope и изменение счётчика «было → стало». Повтор неуспешного шага остаётся в той же строке с последним результатом и причиной повтора. Если новый дефект не обнаружен, очередь не расширять.

**Следующая команда — только R06:**

```text
task:dev plans/REMAINING-WORK.md R06
```

R08 ждёт измеренной причины из R06. Переходить сразу от исправления R05 к R08 нельзя. Номера R — идентификаторы задач, а не порядок запуска. **Порядок запуска задаёт только таблица ниже, сверху вниз.** Отдельных очередей «код» и «измерения» больше нет; справочник findings ниже не является очередью.

Статусы: **ГОТОВО** — выполнен именно указанный шаг; **СЛЕДУЮЩИЙ** — единственный разрешённый сейчас запуск; **ОЖИДАЕТ** — шаг ещё не выполнен; **УСЛОВНО** — нужен только при указанном результате предыдущего шага. ГОТОВО у измерения с RED означает, что измерение выполнено, а дефект остаётся в своём corrective route. ГОТОВО у кода не означает, что live-доказательство получено.

## Исполнительная таблица

В каждой строке один запуск и один результат. После запуска исполнитель отмечает эту строку ГОТОВО, добавляет ссылку/commit и переводит первую допустимую следующую строку в СЛЕДУЮЩИЙ. Не пропускать незавершённые зависимости. Если проверка выявила дефект, вставить corrective строку перед зависимым шагом; до исправления не двигать указатель дальше. Никакая строка ниже не разрешает автоматический запуск следующей задачи в том же запросе.

| Шаг | Статус | Работа / фаза | Исполнитель | Команда или действие | Результат / условие перехода |
|:--|:--|:--|:--|:--|:--|
| 01 | ГОТОВО | R01 — scoped retry implementation/proof | — | Не запускать повторно | [R01](remaining-evidence/R01.md), accepted bounded proof в R09; старый R01 run rejected |
| 02 | ГОТОВО | R02 — baseline inventory | — | Не запускать повторно | [R02](remaining-evidence/R02.md); cold baseline, не текущий >=95 proof |
| 03 | ГОТОВО | R09 и B02 — scoped runtime/verifier | — | Не запускать повторно | [R09](remaining-evidence/R09.md), [B02](remaining-evidence/B02-audit.md); SLO-61 RED сохранён |
| 04 | ГОТОВО | R10 — scoped endpoint evidence | — | Не запускать повторно | [R10](remaining-evidence/R10.md); M/L/24-hour остаются unevaluated |
| 05 | ГОТОВО | R14 — GC/CAS измерение | — | Не запускать старый R14 повторно | [R14](remaining-evidence/R14.md); throughput и batch cap RED → шаги R21/R22/R23 |
| 06 | ГОТОВО | R18 — media mutation до R21 | — | Не запускать повторно | [R18](remaining-evidence/R18.md); 95.33% PG+SQLite; новый media source проверяется в R22 |
| 07 | ГОТОВО | R19 — filament mutation | — | Не запускать повторно | [R19](remaining-evidence/R19.md); 95.51% SQLite |
| 08 | ГОТОВО | B07 — предыдущий аудит | — | Сохранить результат | [B07](remaining-evidence/B07-audit.md): FAILED, B08 не допущен; это выполненный аудит с открытыми findings |
| 09 | ГОТОВО | R05 — исправление кода B07-01/02 | GPT-6 Sol | Не повторять code lane | [R05](remaining-evidence/R05.md), `d042695`; 30 offline tests, live pending |
| **10** | **СЛЕДУЮЩИЙ** | **R06 — dirty-20k измерение причины** | **Grok 4.7** | `task:dev plans/REMAINING-WORK.md R06` | Capture-код уже готов. Сохранить stderr/exit/PHP peak/progress и классифицировать причину. Неубедительный результат → corrective R06 перед шагом 11 |
| 11 | ОЖИДАЕТ | R08 — причинное исправление drive / disposition | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R08` | Только после R06. Если product defect не установлен, записать обоснованный no-fix disposition; код не придумывать |
| 12 | ОЖИДАЕТ | R08 — tier-S измерение | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R08` | После шага 11, frozen source; измеренный SLO-70 GREEN/RED и race/retry. RED сохранить и маршрутизировать, не выдавать за acceptance |
| 13 | ОЖИДАЕТ | R07 — cold drive mutation и gate | GPT-5.6 Sol | `task:dev plans/REMAINING-WORK.md R07` | Тесты подготовлены. Финальный R08 source, sequential PG, >=95 и regression-failing 95 gate; иначе corrective R07 |
| 14 | ОЖИДАЕТ | R05 — affected live P5 cells | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R05` | Код шага 09 готов; проверить реальные actors/iterations/status/Retry-After/locks/pool/overlap/final state. Failed controls → corrective R05 |
| 15 | ОЖИДАЕТ | R03 — full-window throughput | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R03` | Код готов; полный class-A/B window, attribution SLO-40/43/49/52. Historical RED не переписывать |
| 16 | ОЖИДАЕТ | R04 — P3 trace/ANALYZE capture | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R04` | Код готов; непустой flushed trace, bindings, actual ANALYZE, top-20/N+1 |
| 17 | ОЖИДАЕТ | B07-R — независимый переаудит | GPT-6 Astra | Проведи B07-R по plans/REMAINING-WORK.md; сохрани только plans/remaining-evidence/B07-audit.md; не меняй код, raw evidence или статусы R. | Нужны R03/R04/R05/R06/R08 evidence и cold R07 >=95. Finding → его corrective R, без допуска B08 |
| 18 | ОЖИДАЕТ | B07-R — синхронизация листа | Ответственный исполнитель | Прочитать B07-audit.md и обновить только затронутые статусы/маршруты этого листа | Только явный допуск B07-R открывает шаги 19–20; аудитор сам свои findings не закрывает |
| 19 | ОЖИДАЕТ | R13 — 1,000-anchor/tree measurement | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R13` | После допуска B07-R; реальные SQL/CTE/tree timings; product defect → отдельный package R |
| 20 | ОЖИДАЕТ | R15 — реальная advisory collision | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R15` | После valid R05 и допуска B07-R; confirmed/disproved/unconfirmed, 429-only не доказывает collision |
| 21 | ОЖИДАЕТ | R16 — cold core mutation | GPT-5.6 Sol | `task:dev plans/REMAINING-WORK.md R16` | Код/тесты готовы; sequential PG+SQLite ID union >=95 и failing gate; иначе corrective R16 |
| 22 | ОЖИДАЕТ | R17 — cold documents mutation | GPT-5.6 Sol | `task:dev plans/REMAINING-WORK.md R17` | Код/тесты готовы; sequential PG >=95 и failing gate; иначе corrective R17 |
| 23 | ОЖИДАЕТ | R21 — media GC исправление | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R21` | Исправить наблюдаемые R14 throughput/batch-cap defects, package tests cap/progress; без SLO claim |
| 24 | ОЖИДАЕТ | R22 — cold media requalification | GPT-5.6 Sol | `task:dev plans/REMAINING-WORK.md R22` | R21 frozen source; PG+SQLite union >=95 и failing gate; иначе corrective R21/R22 |
| 25 | ОЖИДАЕТ | R23 — GC/CAS повторное измерение | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R23` | После R21/R22; прежние пороги; RED → corrective R21, historical R14 неизменён |
| 26 | ОЖИДАЕТ | R24 — core duplicate delivery | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R24` | Concurrent regression, repair и independent audit; новый core source требует актуализации затронутого mutation proof перед acceptance |
| 27 | ОЖИДАЕТ | R25 — P6 recovery RED classification | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R25` | Каждая RED cell → harness correction, новый package R или retained RED; historical failed-job identities не выдумывать |
| 28 | УСЛОВНО | Новые repairs/requalification из R24/R25 | По назначенному scope | Вставить отдельные строки с новым R либо актуализацией затронутого mutation R | Выполнить необходимые repairs и current-source proof до R26; не оставлять неназванный blocker |
| 29 | ОЖИДАЕТ | R26 — frozen-source full P6 matrix | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R26` | После R24/R25 и необходимых repairs; duplicate/recovery/failed-job deltas, tier SLO table. SLO-61 RED или M/L unevaluated сохранить явно |
| 30 | ОЖИДАЕТ | R11 — P7 live baseline | GPT-6 Sol | `task:dev plans/REMAINING-WORK.md R11` | Код готов; valid R05, R08 и relevant R24/R25 paths; `/ready`, реальные named operations/metrics/seeded joins |
| 31 | ОЖИДАЕТ | B14 — P7/mutation аудит | GPT-6 Astra | Проведи B14 по plans/REMAINING-WORK.md; сохрани только plans/remaining-evidence/B14-audit.md. | R11 baseline и применимые current-source mutation proofs; findings → corrective rows перед R12 |
| 32 | ОЖИДАЕТ | B14 — синхронизация листа | Ответственный исполнитель | Прочитать B14-audit.md и обновить этот лист | Допуск R12 только без findings, инвалидирующих run |
| 33 | ОЖИДАЕТ | R12 — full tier-S P7 recovery matrix | Grok 4.7 | `task:dev plans/REMAINING-WORK.md R12` | После B14; immutable run, first/retry, DB↔object joins, per-cell verdicts; recovery RED → concrete corrective R |
| 34 | ОЖИДАЕТ | R20 — текущие факты и owner handoff | Grok 4.6 | `task:dev plans/REMAINING-WORK.md R20` | Current SLO-by-tier, CI/release/scheduler facts и список решений владельца; не принимать риск и не публиковать |
| 35 | ОЖИДАЕТ | B17 — итоговый аудит handoff | GPT-6 Astra | Проведи B17 по plans/REMAINING-WORK.md; сохрани только plans/remaining-evidence/B17-audit.md. | Findings → named corrective rows; аудит не закрывает VLT-LOAD |
| 36 | ОЖИДАЕТ | B17 — синхронизация листа | Ответственный исполнитель | Прочитать B17-audit.md и обновить этот лист | Полный актуальный список завершённого, RED/unevaluated и решений владельца |
| 37 | ОЖИДАЕТ | Решение владельца | Владелец | Рассмотреть R20/B17 и каждую оставшуюся deviation | Только владелец принимает риск, решает публикацию/deployment и close/archive VLT-LOAD |

Общий стенд эксклюзивен для load, mutation, chaos, DB reset и cold-cache работы. Для каждого измерения нужны новый run ID, source/stand digest, exact commands/exits, raw evidence и cleanup/readback. Исправление и измерение с одинаковым R — разные строки; повторный `task:dev ... R05` после шага 09 означает именно live-фазу шага 14, а не повтор готового кода. Исполнитель выбирает первую незавершённую строку указанного R только при выполненных зависимостях; номер R не позволяет перескочить очередь.

## Справочник: правила доказательств

Read the applicable finding, SLO contract, gate contract, raw run and evidence carrier before a row. The latest conclusion for an audit replaces an older conclusion in that audit; it does not rewrite a product verdict outside its stated scope.

- VLT-LOAD is terminal `Done with deviations`; its P8 receipt is lifecycle evidence, not a product-wide GREEN. Do not execute its old `Pn.m` items. AUDIT-VLT and VLT-HSEAM are archived/superseded; their live Layout-v2 previews are quarantined and are not launch queues.
- B02's last section closes **B02-01/02 in its scoped runtime/verifier scope**. It does not create a new full P6 runtime or an all-SLO PASS. R09's stored P6 evidence remains the authority for the measured SLO-58/64 GREEN and SLO-61 RED; the historical failed-job identities remain unrecoverable.
- B07 is the latest audit of R02–R08: it failed. B08 has no admission. B07-01 and B07-02 remain audit-open; their code corrections are recorded in step 09, live proof/re-audit pending; B07-03–05 are missing-evidence gates, not newly proved product defects.
- R14 is a completed measurement, not an unfinished measurement: CAS integrity/memory and GC delete/RSS were GREEN; **GC scan throughput and the 1,000-row batch cap were RED**. That result creates R21/R22/R23 below. Do not call R14 unperformed.
- R18 (media mutation 95.33% PG+SQLite union) and R19 (filament mutation 95.51% SQLite) are complete on their frozen sources. A media source change in R21 invalidates R18 only for that changed media source and routes to R22; it does not erase the recorded result.
- P4 L-01's measured streaming bytes/memory result remains historical evidence. Its separate D11 mutation claim is unproved because it used `--parallel`; R02 and the package-specific mutation rows, not a rerun of L-01, route that defect.
- No smoke, S0 probe, one-row cardinality diagnostic, terminal task receipt, passing unit suite, offline control, or test-only overlay is an authoritative tier-S/M/L product result. A completed measurement may be RED; record that result and follow its route.
- Mutation proof uses a cold cache, the declared database drivers, real skip/timeout denominator and no `--parallel`. The target is >=95; no configured minimum may be lowered to make a gate pass.

## Справочник: findings и критерии закрытия (не очередь)

Соответствие findings задачам и критерии доказательства. Статусы и действия берутся только из исполнительной таблицы выше.

| Finding | R | Критерий / evidence |
|:--|:--|:--|
| L-04 core retry | R01 | no relaunch; R26 only supplies any later full-P6 re-verdict |
| P4 L-01 streaming / D11 | R02, R07, R16, R17, R22 | no aggregate/parallel mutation claim; each package's >=95 criterion controls |
| F-P2.8-1/2/3; SLO-40/43/49/52 | R03 | immutable full window, materialized tagged metrics, adequate samples; retain or route any RED |
| L-P3-01, L-P3-03 | R04 | nonempty flushed trace, bindings, actual ANALYZE, top-20/N+1 result; no claim from zero rows |
| L-P3-04/L-05 endpoint scan | R26 | tier-specific verdicts, or explicit unevaluated M/L/24-hour rows in R26/R20 |
| L-P5-01/02/04; **B07-01** | R05 | negative controls reject no-sweep/no-overlap; affected live cells and Astra re-review prove the path |
| L-P5-01/02/04; **B07-02** | R05 | negative controls for a missing actor/incomplete iteration; affected cells and Astra re-review |
| B07-03 P5 portion | R05 | raw per-actor/status/Retry-After/lock/pool/overlap evidence; 429-only remains unconfirmed |
| L-P5-03, L-02; B07-04 | R06 | measured failure classification; no guessed drive fix |
| drive L-02, SLO-70/72 | R08 | focused/package evidence plus SLO-70 result (GREEN or RED); current cold R07 >=95 before accepting final drive source |
| drive D11 / VLT-MUT P2; B07-05 | R07 | >=95 with real denominator and no `--parallel`; otherwise add tests and rerun R07 |
| P6 L-P6-01…04; **B02-01/02** | R09 | no re-execution without a source-changing reason |
| P6 concurrent duplicate: 2 dispatch / 2 HTTP | R24 | a source digest, regression test and independent audit; then R26 product run has no duplicate residual or records RED |
| P6 recovery RED, sticky historical failed jobs, SLO-61 RED | R25 | carrier maps every red cell to harness defect, package R, or retained RED; never invent historical job identities |
| P6 current full verdict; SLO-58…64 and endpoint M/L | R26 | immutable verified run, SLO table by tier, duplicate/recovery and failed-job deltas; RED remains routed, M/L absent is unevaluated |
| P7 L-P7-01…05 | R11 | baseline evidence plus actual fault paths; code preparation alone does not close it |
| P7 authoritative recovery | R12 | immutable run, real first/retry attempts, DB<->object joins, per-cell verdicts |
| L-06; L-P3-02/05/06/07 | R13 | 1,000-anchor series, actual SQL/CTE/tree timings; separate package fix decision if needed |
| L-03, L-P4-01; SLO-76…78 | R21 | package tests prove cap/progress; no SLO claim from code checks |
| R21 source change / media D11 | R22 | >=95 and enforced regression-failing gate; otherwise return to R21 |
| R14 RED cells after R21 | R23 | scan throughput and batch cap measured against unchanged SLO; retain GREEN/RED by metric |
| L-07 | R15 | confirmed, disproved, or explicitly unconfirmed from raw lock/HTTP evidence |
| core D11 / VLT-MUT P0.2/P1.1/P3 | R16 | >=95 with workflow failure control; otherwise return to R16 test work |
| documents D11 / VLT-MUT P0.2/P1.1/P4 | R17 | >=95 with workflow failure control; otherwise return to R17 test work |
| media D11 / VLT-MUT P5 | R22 if R21 changes source | R18 remains closure evidence until then |
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
| remaining product RED/unevaluated SLOs, including SLO-61 | no one may accept them through R20/B17 | after R26/R12 current evidence, accept each named deviation or authorize its repair |

## Правило обновления после каждого шага

After every implementation or measurement slice, its executor must immediately:

1. record the result in this file’s executed row: revision/commit, commands/exits, run ID, source/stand digest, raw links, GREEN/RED/unevaluated and limitations; use a short result block below the table when the row is insufficient. Existing `remaining-evidence/RNN.md` files are historical supporting reports, not another current status register. Do not create a new plan, status file, handoff or carrier for ordinary step completion; store bulky raw logs in an artifact directory and link them here;
2. mark the exact executed row in the execution table ГОТОВО with its result/carrier/commit; update affected findings; insert any concrete corrective/requalification rows; mark exactly one next admitted row СЛЕДУЮЩИЙ replace the command at the top and recalculate the remaining-step counter; never leave completed code and pending measurement in one ambiguous status;
3. preserve raw evidence, SLO thresholds and historical verdicts. A correction never overwrites a rejected or RED artifact.

For an independent audit, the auditor writes only its `Bxx-audit.md` report (the explicit exception for review separation); the subsequent map-sync is performed by the responsible executor. For a failed verification, return to the named corrective R in this map. Do not leave a generic `blocked` status when a code, measurement, re-audit, or owner-decision route is known.

## Критерии завершения

Vaulter is ready for an owner close decision only when the following are evidenced, not merely discussed:

- every ledger row is complete, explicitly retained RED/unevaluated with an owner disposition, or an accepted archival limitation;
- B07 and B14/B17 have no unresolved finding that invalidates a dependent run;
- current-source mutation proof is >=95 for drive, core, documents and media under the declared drivers; R18/R19 remain valid for their sources;
- the R21 media fix and R23 measurement resolve or explicitly retain the R14 throughput/batch-cap RED;
- R26 contains the current P6 matrix and explicitly reports duplicate delivery, recovery, failed-job deltas and SLO-61; R12 contains the current P7 recovery matrix;
- R20 publishes the current SLO-by-tier table, current CI/release/host facts and the owner-decision list. M/L or 24-hour gaps remain `unevaluated` unless actually measured;
- the owner, not an executor or audit, decides whether any remaining RED, unevaluated target, deployment constraint, required-check limitation, or deferred publication is acceptable and whether to close/archive VLT-LOAD.
