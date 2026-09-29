# Vaulter: финальная сверка планового слоя после архивации

Дата: 2026-09-29. Проверенный HEAD: `e3281de95e96921704c1290d5c414b5d8a4a2455`.
Результат: **семантика архивации подтверждена; навигация требует исправлений**.
Найдено 3 замечания уровня Minor: 50 неработающих Markdown-ссылок и 3 неверных
текстовых пути в Sources. Blocker/Major в проверенном плановом scope не обнаружены.

Это отчёт для последующего исполнителя, не новый план или исполнительный лист.
Исходные документы проверялись read-only. По уточнению владельца единственная
запись этой проверки — данный файл в `audits/`; исправления ниже не выполнялись.

## Замечания

### REC-01 — Minor: три ссылки из архивных заголовков VLT-LOAD ведут вне архива

Места: `plans/archive/VLT-LOAD/plan.md:1`, `index.md:1`, `roadmap.md:1`.
В каждом стоит `../../REMAINING-WORK/REMAINING-WORK.md`, что разрешается в
несуществующий `plans/REMAINING-WORK/REMAINING-WORK.md`.
Читатель архивного предупреждения не может перейти к итоговым dispositions.
Это нарушает проверяемое требование владельца о корректности ссылок после архивации.

Исправление для следующего исполнителя: заменить только адрес этих трёх ссылок на
`../REMAINING-WORK/REMAINING-WORK.md`. Целевой файл существует; в
[VLT-LOAD/handoff.md](../plans/archive/VLT-LOAD/handoff.md) уже используется этот адрес.
Текст о terminal P0–P8, запрет повторного исполнения и отсутствие product GREEN сохранить.

### REC-02 — Minor: 47 ссылок из remaining-evidence имеют лишние уровни `../`

Все перечисленные ссылки начинаются с `../../../../VLT-LOAD/` либо
`../../../../VLT-MUT/`. От каталога `plans/archive/REMAINING-WORK/remaining-evidence/`
они ведут к отсутствующим каталогам в корне репозитория. Правильный относительный
префикс для существующих архивных целей — `../../VLT-LOAD/` / `../../VLT-MUT/`.
Все 47 целей после такой коррекции проверены на существование; потери самих evidence
эта проверка не выявила. Последствие — неработающая навигация к доказательствам.

Пути ниже относительны к `plans/archive/REMAINING-WORK/remaining-evidence/`.

| Файл | Строки | Число ссылок |
|---|---|---:|
| [B02-audit.md](../plans/archive/REMAINING-WORK/remaining-evidence/B02-audit.md) | 7, 90, 94, 96, 100, 222, 223, 229, 243, 321, 322 | 11 |
| [B07-audit.md](../plans/archive/REMAINING-WORK/remaining-evidence/B07-audit.md) | 126, 136, 140, 144, 146 | 5 |
| [R01.md](../plans/archive/REMAINING-WORK/remaining-evidence/R01.md) | 3 (три ссылки), 42 | 4 |
| [R02.md](../plans/archive/REMAINING-WORK/remaining-evidence/R02.md) | 6 (четыре ссылки), 18, 52 | 6 |
| [R05.md](../plans/archive/REMAINING-WORK/remaining-evidence/R05.md) | 5 (четыре ссылки) | 4 |
| [R06.md](../plans/archive/REMAINING-WORK/remaining-evidence/R06.md) | 5 (три ссылки) | 3 |
| [R07.md](../plans/archive/REMAINING-WORK/remaining-evidence/R07.md) | 7 | 1 |
| [R09.md](../plans/archive/REMAINING-WORK/remaining-evidence/R09.md) | 6 (две ссылки), 23, 24, 26, 60, 82 (две ссылки), 84, 116, 133 | 11 |
| [R10.md](../plans/archive/REMAINING-WORK/remaining-evidence/R10.md) | 24 (две ссылки) | 2 |

При исправлении учитывать сохранность исторических audit/evidence bytes и recorded
fingerprints: сначала проверить, какие Markdown-файлы входят в фиксированные digest-наборы.
Для таких снимков оставить исходные байты и дать рабочую навигацию из существующего
архивного документа с пояснением исторического пути. Не переписывать raw JSON,
manifest, tar.gz, journal, fingerprints или historical verdicts ради замены пути.
Не применять глобальную замену `plans/VLT-*`: исторические пути в evidence могут
быть частью доказательства, а не ссылкой для перехода.

### REC-03 — Minor: неверные текстовые Sources в двух handoff

В [VLT-HSEAM/handoff.md](../plans/archive/VLT-HSEAM/handoff.md), строка 8:

- `../archive/VLT-CANON/handoff.md` разрешается через лишний `archive/`;
  существующая цель — `../VLT-CANON/handoff.md`.
- `../../docs/adr/0018-editor-engine-canon.md` разрешается в `plans/docs/`;
  существующая цель — `../../../docs/adr/0018-editor-engine-canon.md`.

В [AUDIT-VLT-MIGRATION-PREVIEW/handoff.md](../plans/archive/AUDIT-VLT-MIGRATION-PREVIEW/handoff.md),
строка 8: `../archive/AUDIT-VLT/handoff.md` нужно адресовать как
`../AUDIT-VLT/handoff.md`. Markdown-ссылка на тот же handoff в строке 5 уже корректна.

Это текстовые пути, они не включены в число 50 Markdown-ссылок. Следующий исполнитель
может точечно исправить Sources; запреты на исполнение старых preview сохранить.

## Подтверждённые инварианты

| Предмет | Результат и основание |
|---|---|
| Содержимое `plans/` вне `archive/` | Полный обход файловой системы выявил только [NEXT-COMMANDS.md](../plans/NEXT-COMMANDS.md). Активных `ACTIVE`, `START-HERE`, `RUNBOOK`, handoff или иных планов вне архива нет. |
| Текущая очередь | [REMAINING-WORK](../plans/archive/REMAINING-WORK/REMAINING-WORK.md), строки 1–9 и 21, прямо объявляет архив, 0 активных шагов и отсутствие следующего запуска. 53 строки итоговой таблицы: 40 ГОТОВО, 12 ОТЛОЖЕНО, 1 УСЛОВНО. Отложенные строки не объявлены завершёнными. |
| Старые handoff/Next | Все семь верхнеуровневых handoff находятся в архиве. AUDIT-VLT, VLT-CANON, VLT-REMED и VLT-REL описывают терминальное состояние; два migration-preview запрещают executable item; VLT-LOAD содержит архивный баннер и terminal Next. Вложенные migration-source, старые команды архивации, исторические стрелки Next и status projections остаются историей, не разрешением на запуск. |
| Историческая несинхронность | VLT-LOAD handoff:5 ещё говорит об отложенной архивации; VLT-REL/VLT-REMED содержат старые команды архивации; VLT-MUT Meta всё ещё In progress. Их архивное положение, более новые баннеры и итоговый register явно задают исторический контекст. Это не новая текущая очередь и не основание повторять работу. |
| VLT-MUT | [plan.md:1](../plans/archive/VLT-MUT/plan.md) явно откладывает cold mutation и tests ради mutants до API freeze перед 1.0. REMAINING-WORK:7,96 сохраняет drive 86.20%, gate 95 exit 1, четыре gaps R07/14, R16/22, R17/23, R22/25. R18 95.33% и R19 95.51% относятся только к frozen sources. |
| Live | REMAINING-WORK:95 сохраняет восемь gaps: R03/16c, R04/17a, R13/20, R15/21, R23/26, R26/30, R11/31, R12/34. Новый stand/run/source-qualified proof отсутствует; historical RED/REJECTED/unevaluated не заменены GREEN. |
| Release prerequisites | REMAINING-WORK:99–104 сохраняет current coverage unevaluated, external enforcement constraints, deferred split/Packagist provisioning/publication и scheduler execution unproved. Исторический v0.2.0 в VLT-REL не объявляет готовность текущего release. |
| Последующие исправления | [Post-archive remediation](2026-09-29-post-archive-remediation.md) фиксирует локальные исправления release verifier и Hocuspocus lock, но прямо не подтверждает release readiness. Реальные permissions/token/API, release actions и CI не проверены; свежая Composer-установка завершилась exit 100, оригинальный игнорируемый AzGuard lock остаётся с validate exit 2. Это сохранённые результаты предыдущего запуска, здесь они не перемерялись. |

NEXT-COMMANDS.md содержит ручные команды работы после архивации и будущего нового
аудита после стабилизации API. Его наличие соответствует прямому условию владельца;
оно не возобновляет архивные Todo и не доказывает выполнение prerequisites.

## Объём проверки и ограничения

Прочитаны корневые AGENTS.md, применён нативный SKM `quality:artifact-review`;
дополнительных AGENTS.md в `plans/` и `audits/` не найдено. Проверены архивные
entrypoints, handoff, relevant status/Next, итоговые dispositions и оба существующих
post-archive отчёта. Продуктовый код и готовность внешних систем заново не аудировались.

Статический поиск inline Markdown-ссылок охватил 503 Markdown-файла в `plans/`.
После исключения примеров `javascript:` и динамического PHP href подтверждены
50 несуществующих файловых адресов из REC-01/02. Все 50 предполагаемых исправленных
целей существуют. В REMAINING-WORK проверены 35 локальных ссылок на заголовки —
неразрешённых якорей не найдено. В двух прежних `audits/*.md` проверена 41 локальная
файловая ссылка — все цели существуют.

Это не полный Markdown renderer/link crawler: reference-style, HTML-ссылки,
все якоря остальных архивных документов и произвольные текстовые пути сплошь не
проверялись. Внешние URL не запрашивались: сетевой availability аудит не входил
в локальную сверку архива. Исторические команды и программный код не исполнялись.

Package suites, live/load campaigns, mutation, CI dispatch, публикация, push и
изменение внешних систем не выполнялись. Новые планы и исполнительные листы не созданы.

## Git и передача следующему исполнителю

На входе `git status --short` пуст; ветка `remediation/audit-vlt`, относительно
локального `origin/remediation/audit-vlt` — ahead 6. Fetch не выполнялся, поэтому
это сравнение с локальным remote-tracking ref. `git diff --check` до записи отчёта — exit 0.
Последний коммит, изменявший `plans/`: `49ae826` (`docs(plan): архивировать завершённые планы`).

Отчёт оставлен без commit. После его записи `git status --short` подтвердил
единственное изменение: `?? audits/2026-09-29-plan-layer-reconciliation.md`.
`git diff --exit-code`, `git diff --cached --exit-code` и `git diff --check` — exit 0;
исходные tracked files сохранены. Все 16 файловых ссылок самого отчёта разрешаются,
trailing whitespace отсутствует.
Следующему исполнителю передаются REC-01–03 для точечной коррекции навигации с
проверкой сохранности исторических evidence. Закрытие этих замечаний требует
проверки адресов и scoped diff, а не запуска live/mutation/CI или нового плана.

## Исправления навигации — 2026-09-29

Повторная проверка выполнена на HEAD `e3281de95e96921704c1290d5c414b5d8a4a2455`.
Все REC-01–03 подтверждены. Исходный текст отчёта выше сохранён побайтно;
его SHA-256 до дополнения — `08b0e4c0dd0feb859b08c4324e6b88926853f65249cfd84cefdc200861e80a98`.
Этот раздел фиксирует исправления, не заменяет исторические findings и verdicts.
Прочитаны AGENTS.md, отчёт, затронутые документы и контракты сохранности;
применён нативный SKM `quality:artifact-review`. В `plans/` и `audits/`
дополнительных AGENTS.md нет.

| Замечание | Disposition |
|:--|:--|
| REC-01 | **Закрыто.** В первой строке VLT-LOAD `plan.md`, `index.md`, `roadmap.md` исправлены ровно три адреса на `../REMAINING-WORK/REMAINING-WORK.md`. Цель существует; terminal P0–P8, запрет повторного запуска и отсутствие product GREEN сохранены. |
| REC-02 | **Закрыто через рабочую архивную навигацию с сохранением снимков.** Все 47 вхождений проверены; 36 различных целей существуют. Все девять Markdown-файлов входят в исторические digest-наборы, поэтому обычных изменяемых ссылок среди этих 47 нет. Их байты оставлены без изменений; таблица всех рабочих маршрутов и объяснение исторических префиксов добавлены в [существующий реестр](../plans/archive/REMAINING-WORK/REMAINING-WORK.md#архивная-навигация-rec-02-2026-09-29), с указателем у его архивного вступления. |
| REC-03 | **Закрыто.** В Sources VLT-HSEAM исправлены два пути, в AUDIT-VLT-MIGRATION-PREVIEW — один. Все три цели существуют; запреты на исполнение preview сохранены. |

**Основания сохранности REC-02 до редактирования:** `B02-audit.md`, `R01.md`,
`R02.md`, `R05.md`, `R06.md`, `R07.md`, `R09.md`, `R10.md` перечислены в
[task-start-evidence-sha256.json](../plans/archive/REMAINING-WORK/remaining-evidence/R09-B02-seam-checks/task-start-evidence-sha256.json).
[preservation.json](../plans/archive/REMAINING-WORK/remaining-evidence/R09-B02-seam-checks/preservation.json)
также защищает B02 и записывает исторические before/after для R09.
`B07-audit.md`, `R09.md`, `R10.md` входят в `sha256` исторического
[R20 readback](../plans/archive/REMAINING-WORK/remaining-evidence/R20-checks/readback.json).
B07 требует побайтного сохранения прежних B07-R/B07. README в этом evidence-каталоге
тоже входит в снимок, поэтому навигация добавлена в изменяемый итоговый реестр.
Входные хеши девяти carriers уже отличаются от recorded исторических версий;
соответствие текущего HEAD старым fingerprints не заявлено, fingerprints не обновлялись.

**Изменённые файлы в этом исправлении:**

- [VLT-LOAD/plan.md](../plans/archive/VLT-LOAD/plan.md), [index.md](../plans/archive/VLT-LOAD/index.md), [roadmap.md](../plans/archive/VLT-LOAD/roadmap.md) — только адрес в архивном баннере.
- [VLT-HSEAM/handoff.md](../plans/archive/VLT-HSEAM/handoff.md), [AUDIT-VLT-MIGRATION-PREVIEW/handoff.md](../plans/archive/AUDIT-VLT-MIGRATION-PREVIEW/handoff.md) — только три текстовых пути Sources.
- [REMAINING-WORK.md](../plans/archive/REMAINING-WORK/REMAINING-WORK.md) — указатель и навигационное дополнение; прежний текст реестра сохранён.
- Этот файл аудита — только данный датированный раздел в конце.

**Фактические проверки:**

- Статический обход inline Markdown-ссылок: 502 Markdown-файла в рабочем `plans/`, три в `audits/`; локальные цели проверены, внешние URL не запрашивались. Пример `javascript:` и динамический PHP href исключены. В `plans/` остаются ровно 47 исторических файловых ссылок REC-02; для каждого вхождения проверены соответствующая строка таблицы и существование прямой рабочей цели (47/47, различных целей 36/36). Новые и исправленные адреса разрешаются.
- Все 36 ссылок на Markdown-якоря в изменённых документах разрешаются: 35 прежних и один новый в реестре. Расширенная проверка девяти сохранённых carriers: два из трёх Markdown-якорей разрешаются, один старый якорь вне REC-01–03 указан ниже; десять `#L…` адресуют существующие строки кода. Все десять текстовых путей Sources в двух handoff существуют, включая три исправленных.
- SHA-256 всех 20 Markdown-carriers в корне `remaining-evidence/` совпадает с входным состоянием; все девять REC-02-файлов также побайтно совпадают с HEAD. Проверено, что после удаления из сравнения только нового указателя и навигационного дополнения прежние байты реестра совпадают с HEAD. Его таблицы, результаты, historical verdicts и dispositions не менялись.
- Проверка таблицы: 53 строки = 40 ГОТОВО +12 ОТЛОЖЕНО +1 УСЛОВНО, активных строк нет. Сохранены восемь live gaps R03/16c, R04/17a, R13/20, R15/21, R23/26, R26/30, R11/31, R12/34 и четыре mutation gaps R07/14, R16/22, R17/23, R22/25. Архивные handoff/Next/Todo остаются историей; VLT-MUT и tests ради mutants отложены до API freeze перед 1.0. Drive 86.20%, gate 95 exit 1, frozen-source R18/R19 и ограничения current coverage, enforcement, split/Packagist, scheduler сохранены. Release/live/mutation prerequisites не объявлены выполненными.
- Scoped diff проверен: ровно шесть tracked Markdown-документов в `plans/archive/` изменены этой работой; JSON, manifests, tar.gz, journals, raw evidence и recorded fingerprints не изменены. `git diff --check` и `git diff --cached --exit-code` — exit 0. Исходный текст аудита проверен как неизменный префикс итогового файла; trailing whitespace в дополнении отсутствует.

**Оставшиеся ограничения и git status:**

На входе уже было `D plans/NEXT-COMMANDS.md` и `??` этого аудита. Чужое удаление
не восстановлено. В HEAD вне `plans/archive/` есть только NEXT-COMMANDS.md;
в рабочем дереве вне архива файлов нет. Поэтому условие «остался только
NEXT-COMMANDS.md» подтверждается для HEAD, но не для текущего рабочего дерева.
Исходная ссылка на него в исторической части этого аудита сейчас не разрешается
из-за входного удаления; она не переписана. Входное удаление не относится к REC.

Расширенная проверка выявила прежний якорь
`B02-audit.md:210 → ../REMAINING-WORK.md#7-owner-execution-batches-and-model-routing`:
такого заголовка в нынешнем реестре нет. Это отдельное историческое ограничение
вне трёх REC и файловых маршрутов их 47 ссылок; снимок не изменён. Проверка не
является полным Markdown-renderer/crawler: reference-style, HTML-ссылки и все
якоря остальных архивных файлов не проверялись сплошь.

Итоговый `git status --short`: шесть `M` архивных документов из списка выше,
входное `D plans/NEXT-COMMANDS.md`, `?? audits/2026-09-29-plan-layer-reconciliation.md`.
Index пуст. Коммиты и push не выполнялись. Live, load campaigns, mutation,
CI dispatch, package suites, установка зависимостей и публикация не запускались;
split repositories не создавались, токены не запрашивались. Новых планов,
исполнительных листов и дополнительных отчётов нет. Внешняя и продуктовая
готовность этим исправлением навигации не проверена и не заявлена.
