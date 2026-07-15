# VLT-REL — Release-готовность vaulter: minimal-тег для старта внедрения в экосистему

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-REL |
| Title | Release-готовность vaulter: замороженная публичная поверхность → minimal-тег → остальное летит параллельной BC-оптимизацией |
| Version | 0.6.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan (design-first: доки/гейты/тег, кода не пишем) |
| Authoring Model | opus/high |
| Last Updated | 2026-07-15 (P4 закрыта 🟢 1/1 — `root/upgrade-path.md` собран, SemVer-классификация известного потока изменений) |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-drive, vaulter-documents, vaulter-media, vaulter-filament |
| Execution Mode | phase-first: P1 (cut-line + SemVer) — гейт P2; P2 (заморозка публичной поверхности) — гейт P3–P5 **и** гейт для любого будущего src-выхода VLT-LOAD (вкл. `MetricsRecorder` из P8, D31 в `plans/VLT-LOAD/phases/P8.md`) |
| Ось | **стабильность публичного API + версионная дисциплина**. Ортогонально VLT-LOAD (поведение под нагрузкой) и VLT-MUT (корректность/мутационный гейт). Планы не пересекаются. |
| Target Operator Models | opus/high (P1, P2, P5 — версия/контракт/тег, необратимые решения) · sonnet/medium (P3, P4 — сборка каталогов из уже принятых находок) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-REL |
| Depends on | **VLT-LOAD** — находки как вход (L-01…L-06, `slo.md` §5 «сегодня не выполняется» → каталог условий внедрения P3); план НЕ ждёт закрытия VLT-LOAD P3–P7 — тегуем текущую стабильную поверхность сейчас, остаток летит вдогонку backward-compatible (см. P4). **VLT-MUT** — D11-гейт: dev-срез фикса L-01 (`S3MultipartHandler.php`) не открывается, пока `vaulter-drive` не закрыт до Score ≥ 95 (`plans/VLT-LOAD/plan.md:221`) — это ограничение P3/P4 наследуют как есть, не отменяют. |
| Branch | `remediation/audit-vlt` (design-фаза — коммитим только артефакты `plans/`; код фаз P3/P4 — по решению владельца, отдельными dev-срезами вне этого плана) |
| Paused By | — |

> **Как поднять из файла после /clear:** читать этот `plan.md` целиком + `plans/VLT-LOAD/plan.md` §7
> (таблица L-01…L-06, D9, D11) + `plans/VLT-LOAD/slo.md` §5 («сегодня не выполняется») +
> `.claude/rules/public-api.md` (уже существующее правило публичного контракта, генерируется
> harness agent-packs — не изобретаем заново в P2). Детализация фаз — в `phases/Pn.md`; текущая
> точка — `handoff.md`. Чата не требуется.

## 1. Context

Vaulter — монорепо шести Laravel-пакетов (`axioma-studio/vaulter-{core,drive,documents,media,
filament,azgard}`), ни разу не тегированный (`git tag` — пусто). Экосистема academici ждёт старта
внедрения, но полное закрытие VLT-LOAD (нагрузочная готовность, P3–P7 ещё открыты, часть находок —
гипотезы L-02…L-06) и VLT-MUT (мутационный гейт, `vaulter-drive` ещё < Score 95 — D11) займёт
месяцы. VLT-REL решает задачу «тегнуть сейчас то, что уже стабильно» — не дожидаясь этих двух
планов, но и не игнорируя их находки: известные ограничения (L-01 доказан прогоном VLT-LOAD P2.8 —
`S3MultipartHandler::complete()` собирает весь файл в RAM, `packages/drive/src/Cas/
S3MultipartHandler.php:152-188`) идут в релиз как задокументированные условия внедрения, а не как
блокеры тега.

Ось плана — **BC/версионная дисциплина**, ортогональная VLT-LOAD (поведение под нагрузкой) и
VLT-MUT (корректность через мутационный гейт). Идея: (1) провести cut-line — что из публичной
поверхности (`Contracts/` + фасады, M0–M4b по памяти проекта) идёт в minimal-тег сейчас; (2)
заморозить эту поверхность arch-test/snapshot гейтом ДО тега (механизм уже есть в репо —
`.claude/rules/public-api.md` + агент `vaulter-api-surface`, не изобретаем заново); (3) выбрать
версионную политику — **0.x стартово** (D2: BC формально не гарантирован, но фактически держится
арх-гейтом из P2 — 1.0.0 отклонён, т.к. L-02…L-06 ещё не подтверждены/опровергнуты VLT-LOAD); (4)
скаталогизировать известные ограничения по поверхностям как «условия внедрения» (L-01…L-06,
`slo.md` §5); (5) прописать upgrade-путь — остаток VLT-LOAD (P8 наблюдаемость = MINOR, hot-path
фиксы L-01…L-06 = PATCH/MINOR, сигнатуры не трогаются) прилетает backward-compatible, не ломая
ранних потребителей тега.

VLT-REMED/VLT-CANON (архив) релиз не покрывали — первый прецедент тега для этого пакета.

## 2. Execution Rules

- Один item за раз; статус обновляется В ПЛАНЕ до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (plan-protocol §5).
- **После P2 (заморозка) публичная поверхность НЕ меняется без нового D# в этом плане + синхронного
  апдейта `## 7. Contracts`** — любое расширение `Contracts/`/фасадов после снапшота проходит через
  §10 (триггер «public contract/SemVer»), не тихим коммитом.
- План разошёлся с кодом/находками VLT-LOAD/VLT-MUT → не импровизировать, эскалировать (§10).
- Фазы строго последовательны для гейтов: **P1 → P2 → {P3, P4 параллельно} → P5**; P3/P4 не
  требуют друг друга по данным, но обе требуют P2 (снапшот — вход для «что попало в тег»).
- Этот план **не пишет код**: P1–P4 — доки/гейты/каталоги, P5 — тег и публикация. Содержательные
  фиксы (L-01…L-06) остаются dev-срезами вне плана, по D-LOAD-5 (`plans/VLT-LOAD/plan.md:140`).
- После закрытия item/фазы — перезаписать `plans/VLT-REL/handoff.md`.

## 3. Routing

| Items | Model/effort | Exec | Почему |
|:--|:--|:--|:--|
| P1.* | opus/high | manual | версионное решение (SemVer-класс стартового тега) — effort high+ MANDATORY (§9), BC-обязательство |
| P2.* | opus/high | manual | заморозка публичного контракта — триггер §10 «public contract/SemVer», effort high+ MANDATORY |
| P3.*, P4.* | sonnet/medium | plan-exec | сборка каталога/доки из уже принятых находок VLT-LOAD (L-01…L-06, `slo.md` §5) и уже принятой SemVer-политики (D2) — без новых архитектурных решений |
| P5.* | opus/high | manual | необратимое внешнее действие (git tag + публикация) — подтверждение владельца обязательно, не автоматизируется |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P1 | Cut-line релиза и SemVer-политика | 1/1 | 🟢 Done |
| P2 | Заморозка публичной API-поверхности · **гейт P3–P5** | 2/2 | 🟢 Done |
| P3 | Условия внедрения по рискованным поверхностям (L-01…L-08 + F-P2.8-1) | 1/1 | 🟢 Done |
| P4 | Upgrade-путь и BC-классификация будущих фиксов | 1/1 | 🟢 Done |
| P5 | Тег и публикация minimal-релиза | 0/1 | ⬜ Not started |

Детализация фаз — в `phases/Pn.md`: **P1 закрыт 🟢** (1/1); **P2 закрыт 🟢** (2/2 — SSOT-энумерация
P2.1 + исполнимый snapshot-гейт P2.2, поверхность заморожена на v0.1.0); **P3 закрыт 🟢** (1/1 —
каталог `known-limitations.md`); **P4 закрыт 🟢** (1/1 — `root/upgrade-path.md`, SemVer-классификация
известного потока изменений); P5 — скелет, разворачивается `/task:plan-design VLT-REL P5`
(тег+публикация, opus/high manual, требует только P2).

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-15 | Создан план VLT-REL, взят `ACTIVE` (`plans/ACTIVE.md`). **VLT-LOAD получает `Paused By: VLT-REL, 2026-07-15`** — его P3–P7 остаются формально открытыми, VLT-REL не ждёт их закрытия. **VLT-MUT НЕ приостановлен** — остаётся собственным независимым треком; VLT-REL от него зависит по D11-гейту (см. §0 Depends on), но не перехватывает его исполнение. | Экосистема ждёт старта внедрения; полное закрытие VLT-LOAD (P3–P7, гипотезы L-02…L-06 ещё не подтверждены) — горизонт месяцы. Тег текущей стабильной поверхности (`Contracts/`+фасады M0–M4b) разблокирует старт без риска для BC; остаток летит вдогонку backward-compatible (см. P4). RAG:— (внутреннее решение владельца о приоритизации, репо-контекст `plans/VLT-LOAD/plan.md`/`plans/VLT-MUT/plan.md`). |
| D2 | 2026-07-15 | SemVer-политика стартового тега — **0.x**, не 1.0.0. | Владелец: 0.x — сигнал «готово к внедрению», но без формального BC-обязательства (semver.org: major=0 допускает breaking в minor); фактическая стабильность держится арх-гейтом из P2, а не номером версии. 1.0.0 отклонён как альтернатива: BC-обязательство вступило бы раньше, чем риски L-02…L-06 подтверждены/опровергнуты VLT-LOAD (P3/P5/P6) и `vaulter-drive` закрыт по VLT-MUT (D11) — преждевременный major-контракт на неподтверждённой поверхности. RAG:— (внутреннее версионное решение владельца, не внешний факт; semver.org-правило само по себе стандартно известно, не предмет верификации). |
| D3 | 2026-07-15 | npm-пакет `@vaulter/vue` (0.0.0) — **ВНЕ git-тега 0.1.0, отдельный npm-трек**. git-тег монорепо покрывает 6 composer-пакетов; `@vaulter/vue` версионируется своим циклом, его TS/API-поверхность НЕ входит в P2-заморозку и P4-upgrade-путь; в `root/cut-line.md` (P1.1) документируется строкой как связанный, поставляемый отдельно канал. | При детализации P1 разведка вскрыла развилку: `CHANGELOG.md [Unreleased]` числит `@vaulter/vue` релизным артефактом, но §0 Meta описывает «монорепо шести Laravel-пакетов» и не включает vue в Related Packages. Выбор — вне git-тега: (1) git-тег монорепо по природе composer/PHP-версионное событие, npm-пакеты версионируются независимо по конвенции; (2) `@vaulter/vue` на 0.0.0 — API ещё не стабилизирован, локстеп в 0.1.0 навесил бы BC-обещание (пусть 0.x) на наименее проверенную поверхность — против «minimal»-этоса плана; (3) P2-гейт остаётся на `.claude/rules/public-api.md` (целится в PHP) — не тащим TS-arch-test; (4) совпадает с §0 Meta. Провенанс развилки — `## Обсуждение` §1. RAG:— (repo-grounded: `packages/vue/package.json` = `@vaulter/vue` 0.0.0, npm; `CHANGELOG.md [Unreleased]`; §0 Meta). Отклонён локстеп 0.1.0 (расширил бы scope P2/P4 на TS-контракт наименее стабильного пакета). |
| D4 | 2026-07-15 | **P2 разложена на 2 items** (P2.1 энумерация-SSOT → `root/contracts/public-api-surface.md`; P2.2 per-package snapshot-regression гейт + amend-процедура). **Arch-snapshot гейт — in-scope этого плана как «гейт» (§2), НЕ содержательный фикс** — отдельно от dev-срезов L-01…L-06 (D-LOAD-5). | §2 Execution Rules явно скоупит «гейты» наравне с доками в P1–P4; заморозка без enforcement-теста — док без зубов (`cut-line.md` §4 инвариант «breaking ловится P2-гейтом» требует исполнимого гейта). Разложение: SSOT (док) и enforcement (тест) — разные концерны с последовательной зависимостью (P2.2 морозит то, что P2.1 энумерировал). RAG:— (repo-grounded: `plan.md:57-68` §2, `root/cut-line.md:130-132`). |
| D5 | 2026-07-15 | **Автоматический гейт морозит только сигнатуры интерфейсов-контрактов + фасад**; config-ключи / публикуемые ресурсы / биндинги ServiceProvider энумерируются в SSOT-доке (P2.1) и держатся review-правилом `.claude/rules/public-api.md` + §2 D#-дисциплиной, НЕ рефлексионным тестом. | Рефлексия точна и малошумна на сигнатурах интерфейсов (удаление/rename/сужение типа = breaking детектируется точно, `public-api.md:19`); config-дефолты и публикуемые ресурсы меняются органичнее и морозятся рефлексией шумно/малоценно для 0.x-тега. Альтернатива «морозить всё механически» отклонена: высокий шум → девелоперы привыкают к `UPDATE_SNAPSHOT` без D# → заморозка становится театром (pre-mortem failure-mode #2). RAG:— (repo-grounded: `.claude/rules/public-api.md:18-23`, `root/cut-line.md:61-74`). |
| D7 | 2026-07-15 | **P3.1-каталог покрывает ПОЛНЫЙ набор Findings-долга VLT-LOAD §7 — L-01…L-08 + F-P2.8-1**, а не только L-01…L-06 из формулировки скелета P3 (supersedes скелет-скоуп). Заодно исправлен якорь L-01: `packages/drive/src/Uploads/S3MultipartHandler.php` (скелет ошибочно писал `Cas/`). | Релизный `known-limitations.md` обязан быть полным: L-08 (**факт** — sweeper/relay/GC не в расписании пакетом) — реальное условие эксплуатации (хост заводит cron); F-P2.8-1 (throughput одиночного стрима ниже пола SLO-40) — реальное ограничение применимости; L-07 (CAS-лок int4-коллизии) — та же природа, что L-02…L-06. Исключить их = оставить настоящие условия внедрения за бортом релизной доки. Скелет L-01…L-06 был наброском, не sign-off'ом скоупа. RAG:— (repo-grounded: `plans/VLT-LOAD/plan.md` §7 строки L-07/L-08/F-P2.8-1; `find packages -name S3MultipartHandler.php` → `Uploads/`). |
| D6 | 2026-07-15 | **Per-package snapshot-тесты** (в `packages/<pkg>/tests/Arch/` каждого пакета с контрактами: core/drive/documents/azgard) + golden на пакет, гоняются существующими per-package CI-job'ами; media/filament — empty-surface гвард. | Альтернатива «единый monorepo-снапшот» отклонена: корневой `composer.json` НЕ включает filament/azgard в `require` (`plan.md:15-19` composer.json) → единая suite не автолоадит их классы (autoload-gap). Per-package форма совпадает с существующей инфрой (`packages/*/tests/Arch/ArchTest.php` + per-package `.github/workflows/*.yml` гоняют собственный `composer test`) — новый workflow не нужен. RAG:— (repo-grounded: корневой `composer.json` require, `.github/workflows/drive.yml` working-directory per-package). |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-15 | issue-planner/opus | План создан: VLT-REL взял `ACTIVE`, VLT-LOAD получил `Paused By` — детали см. D1/D2 выше. |
| 2026-07-15 | issue-planner/opus | P1 детализирован (1 item P1.1 — cut-line + версия 0.1.0), скелет-маркер снят; развилка npm-канала vue закрыта D3 (вне git-тега) — детали см. phases/P1.md P1.1 + D3/Обсуждение §1. |
| 2026-07-15 | opus/high (`/task:plan-run VLT-REL P1.1`) | P1.1 закрыт (🟢), **фаза P1 закрыта (🟢, 1/1)**: `root/cut-line.md` — граница тега 0.1.0 (6 composer IN + vue OUT), версия 0.1.0 + bump-rule major=0, судьба CHANGELOG — детали см. phases/P1.md P1.1 Completion Notes + D2/D3. |
| 2026-07-15 | opus/high (`/task:plan-design VLT-REL P2`) | P2 детализирован — 2 items (P2.1 SSOT-энумерация, P2.2 per-package snapshot-гейт), v0.3.0, скелет-маркер снят; приняты D4/D5/D6 — детали см. phases/P2.md + D4/D5/D6. |
| 2026-07-15 | opus/high (`/task:plan-run VLT-REL P2.1`) | P2.1 закрыт 🟢: `root/contracts/public-api-surface.md` — SSOT 26 контрактов + фасад + config/ресурсы/биндинги, drift-check clean — детали см. phases/P2.md P2.1 Completion Notes. |
| 2026-07-15 | opus/high (`/task:plan-run VLT-REL P2.2`) | P2.2 закрыт 🟢, **фаза P2 закрыта 2/2 🟢**: per-package snapshot-гейт морозит 26 контрактов + фасад рефлексией, media/filament empty-surface гвард, `§7 Contracts` → frozen (v0.1.0), гейт P3–P5 снят — детали см. phases/P2.md P2.2 Completion Notes. |
| 2026-07-15 | issue-planner/opus (`/task:plan-design VLT-REL P3`) | P3 детализирован — 1 item P3.1 (каталог `root/known-limitations.md`), v0.5.0, скелет-маркер снят; принят D7 (полный набор L-01…L-08 + F-P2.8-1, якорь L-01 → `Uploads/`) — детали см. phases/P3.md P3.1 + D7. |
| 2026-07-15 | sonnet/medium (`/task:plan-exec VLT-REL P3.1`) | P3.1 закрыт 🟢, **фаза P3 закрыта 1/1 🟢**: `root/known-limitations.md` собран — L-01…L-08+F-P2.8-1, преамбула, два раздела (доказано/факт · гипотезы), D11-гейт, связь с 0.1.0 — детали см. phases/P3.md P3.1 Completion Notes. |
| 2026-07-15 | issue-planner/opus (`/task:plan-design VLT-REL P4`) | P4 детализирована — 1 item P4.1 (`root/upgrade-path.md`, SemVer-классификация известного потока: MetricsRecorder P8 · L-01…L-08 · F-P2.8-1), v0.6.0, скелет-маркер снят; новых D# нет (применение ратифицированного правила §4/AMEND/D2) — детали см. phases/P4.md P4.1. |
| 2026-07-15 | sonnet/medium (`/task:plan-exec VLT-REL P4.1`) | P4.1 закрыт 🟢, **фаза P4 закрыта 1/1 🟢**: `root/upgrade-path.md` собран — ключ классификации + таблица 11 строк (MetricsRecorder, L-01…L-08, F-P2.8-1, VLT-LOAD P3–P7) + связь с AMEND/`## 7. Contracts`/D11 — детали см. phases/P4.md P4.1 Completion Notes. |

## 7. Contracts

### Exported

| Контракт | Версия | Статус | Потребители | Уведомлены |
|:--|:--|:--|:--|:--|
| `root/contracts/public-api-surface.md` (SSOT-снапшот заморозки, деливерабл P2.1) + исполнимый per-package snapshot-гейт (P2.2, `packages/{core,drive,documents,azgard}/tests/Arch/PublicApiSnapshotTest.php` + golden; media/filament empty-surface гвард) | v0.1.0 | **frozen (v0.1.0)** — 26 контрактов + фасад заморожены рефлексией сигнатур (item-коммит `2ded9e4`); изменение поверхности — только через AMEND (`root/contracts/AMEND.md`): регенерация golden + D# + CHANGELOG MINOR | экосистема academici (все потребители vaulter) · VLT-LOAD (P8: `MetricsRecorder`-контракт, D31 в `plans/VLT-LOAD/phases/P8.md`, попадёт под тот же снапшот ПОСЛЕ заморозки, MINOR-путь — см. P4/AMEND) | — (тег не нарезан — публикация P5) |

### Consumed

| Контракт | Донор-план | Pinned | Замечено | Реакция |
|:--|:--|:--|:--|:--|
| — | — | — | — | план пока не потребляет чужих frozen-контрактов — связь с VLT-LOAD/VLT-MUT идёт через `§0 Depends on` и D-лог (D11-гейт, L-01…L-06), не через замороженный `## 7. Contracts` донора |

## Обсуждение

### 1 — npm-пакет `@vaulter/vue` относительно git-тега 0.1.0

Развилка вскрыта при детализации P1 (2026-07-15): `CHANGELOG.md [Unreleased]` числит
`@vaulter/vue` (npm, 0.0.0) релизным артефактом, но §0 Meta описывает «монорепо шести
Laravel-пакетов» и не включает vue в Related Packages.

- **(рекоменд.) Вариант A — вне git-тега, отдельный npm-трек.** Плюсы: git-тег монорепо =
  composer/PHP-версионное событие, npm-пакеты версионируются независимо (конвенция); vue на
  0.0.0 не стабилизирован — не навешиваем BC-обещание на наименее проверенную поверхность;
  P2-гейт остаётся PHP-only (`.claude/rules/public-api.md`), без TS-arch-test; совпадает с
  §0 Meta. Минусы: два разных номера версии у одного релиза — нужно явно задокументировать
  связь каналов в cut-line.
- **Вариант B — локстеп 0.1.0 в тот же тег.** Плюсы: один номер на весь релиз, буквально по
  нарративу CHANGELOG. Минусы: P2-заморозка и P4-upgrade-путь расширяются на публичный TS/API
  vue — scope плана растёт за счёт наименее стабильного пакета; преждевременное BC-обязательство.

**Статус:** Resolved → D3 (Вариант A).
