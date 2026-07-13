# VLT-MUT — Мутационный гейт: починить измерение, довести Score до ≥95

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-MUT |
| Title | Мутационный гейт vaulter: снять ложный `--parallel`, измерить честно, довести все пять пакетов до Score ≥ 95 |
| Version | 0.1.0 |
| Status | 🔵 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | opus / high |
| Last Updated | 2026-07-13 |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-documents, vaulter-drive, vaulter-media, vaulter-filament |
| Execution Mode | phase-first (фазы P2–P6 независимы между собой — можно брать в разных сессиях) |
| Target Operator Models | opus/high (P0, P1, P7 — решения о гейте и пороге) · sonnet/medium (P2–P6 — написание тестов под известных мутантов) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-MUT |
| Depends on | — (VLT-REMED/VLT-CANON закрыты; этот план **отменяет** их вывод «mutation-гейт закрыт», см. §5 D1) |
| Branch | `remediation/audit-vlt` (не менять; коммитить срезами) |

> **Как поднять из файла после /clear:** читать этот plan.md целиком + `findings/P0.1-parallel-defect.md`
> (воспроизведение дефекта) + `findings/P0.2-baseline-<пакет>.md` (списки выживших мутантов — это и есть
> ТЗ фаз P2–P6). Логи прогонов не коммитятся; воспроизводятся скриптом из `findings/P0.1`.

## 1. Context

VLT-REMED P5.9 / Open risk #4 (VLT-CANON handoff) объявили mutation-гейт закрытым: пять job'ов
`pest --mutate --path=… --covered-only --parallel --processes=4 --min=95`, у всех пяти пакетов
зафиксирован «Score 100.00 %». **Это измерение не состоялось.**

Флаг `--parallel` делает гейт неспособным упасть. Механика: `MutationTest::start()`
(`pest-plugin-mutate` v4.0.1) запускает мутанта как дочерний процесс, передавая ему **исходный argv
родителя** — вместе с `--parallel`; плюс, когда `processId !== null`, доставляет `PARATEST=1`,
`TEST_TOKEN`, `LARAVEL_PARALLEL_TESTING=1`. В ребёнке `Pest\Plugins\Parallel::handleArguments()` видит
`--parallel` → `exit($this->runTestSuiteInParallel($arguments))` → ParaTest получает argv с
`--mutate`/`--path`/`--covered-only` и `--filter="…"` (кавычки литеральные — Symfony `Process` передаёт
массив argv без шелла) → падает, не выполнив ни одного теста → **ненулевой код возврата → Pest считает
мутанта убитым**. Убиты все, всегда.

Воспроизведение (packages/drive, PHP 8.4 + pcov, Postgres 16, **холодный** кэш мутаций,
`src/Uploads/TusUploadHandler.php`):

| режим | Score | escaped | wall |
|:--|--:|--:|--:|
| `--mutate --covered-only` (sequential) | **74.07 %** | 7 | 2m05s |
| `+ --parallel --processes=1` | 100.00 % | 0 | 1m02s |
| `+ --parallel --processes=4` | 100.00 % | 0 | 0.33s |

Один и тот же файл, одна и та же БД, тот же кэш. `--processes=1` тратит реальное время (дети
запускаются и падают ≈2.3 с каждый), но всё равно рапортует 100 % — значит дело не в конкуренции за
БД, а в самом флаге. Плагин `pest-plugin-mutate` **v4.0.1 во всех пяти** `composer.lock` → дефект
одинаков везде.

Класс дефекта — тот же, что у снятого Infection: **гейт, который физически не может упасть** (там —
`|| true` и отсутствующий Pest-адаптер, здесь — `--parallel`). Отличие в том, что этот гейт выглядел
рабочим: он честно тратил минуты CI и печатал «Score 100.00 %».

Побочно (обнаружено тем же прогоном, отдельная запись в §5 D3): `drive-mutation` гонялся на SQLite,
где 5 тестов самоскипаются (4 × `tests/Concurrency` + FK-cascade в `tests/Feature/PurgeTrashTest`),
поэтому при `--covered-only` PG-only код (advisory-xact-lock / FOR UPDATE / cascade-delete) вообще не
мутировался. На PG лейн даёт 172 passed / 0 skipped против 167 passed / 5 skipped на SQLite.

## 2. Execution Rules

- Один item за раз; статус — в плане ДО завершения работы; 6 канонических статусов (`plan-protocol`).
- **Дисциплина D35 — гейт не подгоняем под результат.** Порог `--min` меняется ТОЛЬКО как ratchet вверх
  и ТОЛЬКО после измеренного Score. Понижение порога ради зелёного CI запрещено; неподъёмное — честной
  строкой долга, а не понижением планки.
- Ни один item не считается закрытым, пока Score не **перемерен на холодном кэше** (кэш мутаций живёт в
  `vendor/pestphp/pest-plugin-mutate/.temp` и переживает прогоны — горячий кэш возвращает прошлый вердикт).
- Тесты, убивающие мутантов, обязаны проверять **поведение через публичный интерфейс**, а не
  подстраиваться под мутатор. Ассерт на текст сообщения — валиден, если этот текст и есть контракт
  (сообщение ошибки в API-ответе, вывод команды); если нет — мутатор гасится точечно
  (`@pest-mutate-ignore` + причина), и это **тоже** записывается как решение, а не прячется.
- Коммит-гейт: гейт затронутых пакетов зелёный ДО коммита (`pint --test`, `phpstan analyse` max, pest,
  type-coverage 100 %). Conventional Commits (рус.), атомарно, срезами.
- `git push`/PR — только по явной просьбе владельца.

## 3. Routing

| Items | Model/effort · Tool Capability | Why |
|:--|:--|:--|
| P0.1, P0.2 | opus/high | измерительная методология: холодный кэш, изоляция БД/storage, аггрегация шардов |
| P1.x | opus/high | форма гейта в CI (порог, шардинг, PG-лейн) — решение, каскадящее на все пять пакетов |
| P2–P6 | sonnet/medium + `vaulter-test-writer`, `vaulter-test-verifier` | механика: список выживших мутантов известен, пишем тесты до зелёного |
| P7 | opus/xhigh | пересмотр ложных закрытий (VLT-REMED P5.9, Open risk #4, closure-матрица §6) и вердикта release-readiness |

## 4. Phase Index & Status Board

| Фаза | Название | Items | Статус |
|:--|:--|:--|:--|
| P0 | Доказательство дефекта + честная базовая линия пяти пакетов | 2 | 🔵 In progress (drive 🟢, остальные четыре ⚪) |
| P1 | Честная форма гейта в CI (снять `--parallel`, PG-лейн drive, ratchet-порог, шардинг) | 3 | 🔵 In progress (drive 🟢) |
| P2 | `vaulter-drive` → Score ≥ 95 (база 48.34 %, 780 выживших) | TBD | ⚪ Todo |
| P3 | `vaulter-core` → Score ≥ 95 | TBD | ⚪ Todo |
| P4 | `vaulter-documents` → Score ≥ 95 | TBD | ⚪ Todo |
| P5 | `vaulter-media` → Score ≥ 95 | TBD | ⚪ Todo |
| P6 | `vaulter-filament` → Score ≥ 95 | TBD | ⚪ Todo |
| P7 | Пороги = 95, документация, пересмотр ложных закрытий, release-readiness | 3 | ⚪ Todo |

## 5. Decision Log

| ID | Решение | Основание |
|:--|:--|:--|
| D1 | **Вывод VLT-REMED P5.9 «mutation-гейт закрыт» отменяется.** Гейт не мерил ничего; «Score 100.00 %» пяти пакетов — артефакт `--parallel`. Открывается заново этим планом. | §1, воспроизведение на холодном кэше |
| D2 | `--parallel` из mutation-job'ов **снимается совсем**. Параллелизм — на уровне runner'ов (matrix-шарды), а не сломанного флага. Апстрим-баг не чиним и не ждём. | дефект в `pest-plugin-mutate` v4.0.1; обход дороже job-матрицы |
| D3 | `drive-mutation` переезжает на Postgres (зеркало `drive-pgsql`): иначе PG-only код (Concurrency, FK-cascade) не мутируется при `--covered-only`. | 172 passed / 0 skipped на PG против 167 + 5 skipped на SQLite |
| D4 | Цель — **Score ≥ 95 по факту**, достигается написанием тестов (владелец: «идти до 95»). Промежуточно порог — ratchet: `--min = floor(измеренный Score) − 5`, поднимается каждой фазой. Порог НИКОГДА не опускается. | D35; решение владельца 2026-07-13 |
| D5 | Изоляция параллельных прогонов (нужна и матрице, и локальному замеру): БД по `TEST_TOKEN`/`DB_DATABASE` + tmpfs-изоляция testbench-`storage` (иначе `Storage::fake('local')` шарит staged-байты между воркерами). | эмпирика: без неё шарды роняли друг другу миграции и staged-файлы |
| D6 | **Драйвер замера — по факту скипов пакета, а где скипы идут в обе стороны — union двух прогонов** (мутант убит, если убит хотя бы в одном; слияние по ID мутанта из логов). `--covered-only` Score не завышает (непокрытое не попадает в знаменатель), но код, покрытый ТОЛЬКО тестами другого драйвера, выпадает из-под гейта целиком. Раскладка: drive → PG (0 sqlite-only, 5 pg-only), documents → PG (0 / 2), filament → SQLite (драйвер-условных нет), **media → union** (3 sqlite-only / 3 pg-only), **core → union** (4 sqlite-only `BlobDedupIndexTest` + pgsql-ветки в src, покрытые только на PG). | инвентаризация скипов 2026-07-13 |

## 6. Phases (детализация)

### P0 — Доказательство дефекта + честная базовая линия

**P0.1 — Воспроизвести дефект `--parallel` и зафиксировать методику замера.** 🟢 Done (2026-07-13)
- DoD: три прогона одного файла (sequential / `--processes=1` / `--processes=4`) на холодном кэше дают
  расходящийся Score; механика прослежена до `MutationTest::start()` и `Parallel::handleArguments()`;
  методика честного замера (холодный кэш + БД на шард + tmpfs storage) записана и воспроизводима.
- Артефакт: `findings/P0.1-parallel-defect.md`.

**P0.2 — Честная базовая линия всех пяти пакетов.** 🔵 In progress (drive 🟢, четыре ⚪)
- DoD: для каждого пакета — Score на холодном кэше без `--parallel`, полный список выживших мутантов
  (файл:строка:мутатор), сгруппированный по причине (контрактный текст / косметика / реальная дыра).
  drive меряется **на Postgres** (D3).
- Артефакты: `findings/P0.2-baseline-{drive,core,documents,media,filament}.md`.
- Списки выживших = ТЗ фаз P2–P6; без них фазы не стартуют.

- **drive** 🟢 Done (2026-07-13): **Score 48.34 %** — 722 убито, **780 выжило**, 8 таймаутов из 1510
  мутантов (прежние «100.00 % / 1569» — артефакт). Артефакты: `findings/P0.2-baseline-drive.md` +
  `findings/P0.2-drive-escaped.txt` (полный перечень, 780 строк). Крупнейшая группа — 237 выживших
  `RemoveArrayItem`: незапиненные payload outbox-события, `context` активности, **правила валидации**
  в Request'ах и поля API-ресурсов. Это не косметика, а прямые дыры внешнего контракта.
- **core / documents / media / filament** ⚪ Todo. documents и media мерить **на Postgres** — у них свои
  PG-лейны (yjs/gin и CAS), т.е. та же недомутация, что была у drive.

### P1 — Честная форма гейта в CI

**P1.1 — Снять `--parallel` во всех пяти workflow.** 🔵 In progress
- `.github/workflows/{mutation,documents,drive,media,filament}.yml`.
- Порог на этом шаге — ratchet по измеренному Score (D4), с комментарием-пойнтером на этот план.
- **drive** 🟢 Done (2026-07-13): `--parallel --processes=4` снят, `--min=95` → `--min=43`
  (`floor(48.34) − 5`). Это НЕ понижение планки: 95 никогда не был измерен — прежний Score был
  артефактом. 43 — страховка от регресса на время P2; цель 95 остаётся.
- Остальные четыре ⚪ — ждут своей базовой линии (P0.2). До неё `--parallel` в них НЕ снимаем: без
  измеренного порога получится либо вечно-красный лейн, либо гейт без `--min`, т.е. та же фикция.

**P1.2 — `drive-mutation` на Postgres.** 🟢 Done (2026-07-13)
- Зеркалит `drive-pgsql`: `services: postgres:16-alpine` + healthcheck `pg_isready`, extensions
  `pdo_pgsql,pgsql,pcntl,posix`, DB_* из job-env, `coverage: xdebug`.

**P1.3 — Шардинг mutation-job'ов (время прогона).** ⚪ Todo — **отложен намеренно**
- Sequential-мутация drive ≈ 90 мин в один job. Пока это терпимо: гейты всё равно не блокируют merge
  (branch protection недоступен, §8 Q1), и корректность важнее минут CI.
- Если/когда станет больно — matrix по `src/*`, на шард своя БД (D5). Две ловушки, обе проверены на
  локальном замере: шард без мутантов даёт `Score 0.00 %` и уронит `--min` (исключать явно), а шарды
  обязаны иметь свои БД/storage/coverage — иначе роняют друг другу миграции и staged-файлы.
- Смена формы job'а меняет имена чеков — делать ДО того, как чеки станут required (§8 Q1).

### P2–P6 — Довести пакеты до Score ≥ 95

Форма каждой фазы одинакова, ТЗ = список выживших из P0.2:
1. Сгруппировать выживших по причине.
2. **Реальные дыры** (мутант меняет поведение, тест не ловит) — писать тесты. Это основная работа.
3. **Контрактные тексты** (сообщение ошибки в API-ответе, вывод команды, которые обещаны docs) — тест
   ассертит текст; мутант умирает законно.
4. **Косметика** (внутренние строки, которые ничему не обещаны) — точечный `@pest-mutate-ignore` с
   причиной. Каждый случай — строка в Completion Notes фазы, не молча.
5. Перемерить на холодном кэше, поднять `--min` в workflow до `floor(Score) − 5`, но не выше 95.

### P7 — Финализация

**P7.1 — Пороги = 95 во всех пяти job'ах.** ⚪ Todo (после P2–P6)
**P7.2 — Документация.** ⚪ Todo — `CHANGELOG.md`/`UPGRADE.md` [Unreleased]: гейт был фиктивным, что
именно исправлено, какие мутаторы погашены и почему.
**P7.3 — Пересмотр ложных закрытий.** ⚪ Todo — VLT-REMED P5.9, VLT-CANON handoff Open risk #4,
`plans/archive/VLT-REMED/findings/P5.3-closure-matrix.md` §6, вердикт release-readiness. Пересчитать
честно; закрытые P5-находки других классов не трогать.

## 7. Инварианты / критерии приёмки (каждая фаза)

- Score перемерен на **холодном** кэше мутаций (иначе цифра — из прошлого прогона).
- `--min` не опускался ни разу за всю историю плана (проверяется по git-логу workflow-файлов).
- Гейт проверен на падение: искусственно внесённая регрессия роняет job.
- Ни один `@pest-mutate-ignore` не поставлен без строки-обоснования в Completion Notes.
- Тесты пакета зелёные, `pint`/`phpstan max`/type-coverage 100 % — до коммита.

## 8. Открытые вопросы

- **Q1 → решено по существу, ждёт действия владельца (покупка).** Пять mutation-job'ов не входят в
  required status checks `main` и не могут: org `axioma-studio` на плане **free** + репозиторий
  **private** → `branches/main/protection` и `rulesets` отвечают 403. Зафиксировано в
  `plans/archive/VLT-CANON/handoff.md` Open risk #1.

  **GitHub Pro не подходит** — это план личного аккаунта, а репозиторий принадлежит организации;
  на org-owned private репо он не влияет (текст ошибки GitHub вводит в заблуждение). Работающих
  вариантов два: сделать репо публичным (Free enforce-ит всё на public) — **отклонено владельцем
  2026-07-13** («vaulter вряд ли будет публичным, и добавятся ещё закрытые пакеты») — либо
  **GitHub Team на организацию**.

  Решение владельца: **Team**. Считается за место, а не за репозиторий: одно место покрывает vaulter,
  corex, loqum, botkit, erp-sdk и будущие закрытые пакеты; плюс открывает **org-level rulesets** — один
  набор правил на всю организацию, иначе каждый новый пакет стартует с незащищённым `main`. Действие
  (оплата) — за владельцем; как только план сменится, required checks проставляются через
  `gh api …/rulesets`, и Open risk #1 закрывается по-настоящему.

  **До смены плана `main` не защищён ничем:** красный PR мержится, прямой push и force-push в `main`
  проходят. Весь CI, включая честный mutation-гейт этого плана, остаётся **сигнальным** — умеет сказать
  «сломано», не умеет не пустить.
- **Q2.** `Mutation Testing / Mutation (advisory)` (core, `mutation.yml`) поднят только на `push: [main]` +
  `workflow_dispatch` — на PR не исполняется. Даже при доступном branch protection required-check'ом не
  стал бы. Менять триггер на `pull_request`? Решение — за владельцем (шапка объявляет job advisory
  by design).
