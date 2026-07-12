# VLT-CANON — Подключаемые движки-редакторы + финальный структурный канон

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-CANON |
| Title | Подключаемые движки-редакторы (Google-Drive-модель) + финальный структурный канон |
| Version | 0.1.0 |
| Status | ⬜ Not started (design в работе) |
| Document Type | Executable Master Plan |
| Authoring Model | opus (fable) / high |
| Last Updated | 2026-07-11 |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-documents, vaulter-drive, vaulter-media, vaulter-permissions-azgard→azgard, @vaulter/vue |
| Execution Mode | phase-first |
| Target Operator Models | opus (P0.1–P0.3, P2.1 — публичные контракты/нейминг/массовый refactor) · sonnet (остальное) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-CANON |
| Supersedes | VLT-HSEAM — cross-notice в его plan.md ставится ПРИ УТВЕРЖДЕНИИ VLT-CANON (уже стоит); формальная маркировка superseded — item P3.3 (после консолидирующего ADR). До P3.3 VLT-HSEAM НЕ исполнять — единственный владелец поверхности =VLT-CANON |
| Depends on | текущие ACTIVE-планы (VLT-REMED). Запуск ПОСЛЕ них либо решением owner'а. ACTIVE.md не трогать. |

> **Как поднять из файла после /clear:** читать этот plan.md целиком + `findings/structure-canon-inventory.md`
> + `findings/*-research.md` (3 фронта) + `plans/VLT-HSEAM/root/adr-file-handler-seam.md`. Основания —
> Decision Log §5 (D1…Dn) и ## Обсуждение §8. Кода на design-фазе НЕТ; строит `/task:dev`.

## 1. Context

Владелец: «система подключаемых движков-редакторов (модель Google Drive: double-click → редактор по типу
файла) + финальный структурный канон монорепо». Рекон (main-loop, 2026-07-11) показал: **бóльшая часть
инфраструктуры уже существует как швы** — реестр `DocumentHandler`/`HandlerRegistry`/`NodeHandlerResolver`
(mime/ext-диспатч, capabilities, priority, fallback `DownloadOnlyHandler`), дескриптор server→client
(`HandlerDescriptor{id,capabilities,ui}` + маршрут `GET nodes/{node}/handler`), фронтовый реестр
компонентов (`componentMap` по `handler.id`/`ui.component` в `VaulterDocumentNode.vue`). Топология пакетов
(schema-owner core + drive/documents/media) и внутренний layer-canon A (P4.9/P4.10) — **закрыты 🟢**.

Поэтому VLT-CANON = **ДОВЕСТИ, а не построить**: (Часть 2) изолировать вшитый TipTap в драйвер за общим
контрактом, переименовать контракты в kind-нейтраль, формализовать фронт-реестр как публичный шов;
(Часть 1) выбрать модель упаковки/дистрибуции фронтенд-движков и нейминг пакетов по практике
Filament/Nova/Statamic (research-фаза); (Часть 3) финальный структурный канон — переименование
`permissions-azgard→azgard`, развилка `media→drive?`, разбор catch-all-папок (`Documents/`, `Uploads/`,
`Support/`), Access/Uploads-сверка. Поглощает VLT-HSEAM (5 утечек TipTap + policy + DX-каркас + Q1/Q2) и
опирается на закрытые P4.9/P4.10 как на базовую линию. Пакет **пре-1.0** — переименования бесплатны по
SemVer (шумный дифф, но без BC-обязательств). **Filament — ВНЕ scope** (задел на будущее, ROADMAP-пункт;
и так ADR-0017-исключение).

## 2. Execution Rules

- Один item за раз; статус — в плане ДО завершения работы; 6 канонических статусов (`plan-protocol` §статусы).
- Коммит-гейт: item не 🟢/🟠 без закоммиченного диффа; Conventional Commits (рус.), атомарно; гейт
  затронутых пакетов зелёный ДО коммита: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (max),
  pest через `/usr/bin/php8.4`, type-coverage 100%; фронт — `vue-tsc` + vitest + `vite build` (@vaulter/vue).
- Публичная поверхность: каждый item, трогающий `Contracts/`/фасад/config-ключи/дескриптор, сопровождается
  прогоном `vaulter-api-surface` (diff против base) — отчёт в Completion Notes. BC-запись в `UPGRADE.md` +
  `CHANGELOG.md [Unreleased]` на каждое переименование/перенос.
- Переименования/переносы — по `php:namespace-refactor-safety`: якорить find-replace на FQN, не на голый
  токен (ловушка `Node ⊂ NodeComment/NodeGrant/…`); пакет пре-1.0 → фаза alias не нужна, hard rename сразу;
  arch-тест dependency-direction зелёный после каждого переноса.
- Ревью: после P0.1, P0.2, P1.x (публичный фронт-шов), P2.1 — `vaulter-reviewer` (ось BC/SemVer,
  register-vs-boot, publishables). HIGH-риск-срез (массовый refactor) — adversarial self-review.
- План diverged от кода → эскалация (`plan-protocol` §10), не импровизация.
- **Поведенческая нейтральность:** структурные переносы (P2) — механические, ноль изменений поведения;
  фичевые switch'и feature-флагов и dedup-scope целы; тесты пиннят поведение до и после.

## 3. Routing

| Items | Model/effort · Tool Capability | Why |
|:--|:--|:--|
| P0.1, P0.2, P0.3 | opus/high + `vaulter-api-surface`, `vaulter-reviewer` | SemVer-публичные контракты + нейминг движка каскадит на фронт/пакеты |
| P0.4 | opus/high + `vaulter-api-surface`, `vaulter-reviewer` | решает D10 (перенос реестра в core = ревизия H.5 + T5-аллокации Option B), миграция схемы |
| P0.5 | sonnet/medium; Q2-решение (D11) — opus/high | механика L4/L5 дешёвая; Q2 — schema-решение |
| P1.1–P1.3 | opus/high (решение) → sonnet (механика) + `vaulter-reviewer` | публичный фронт-шов + выбор модели упаковки — DX-обязательство |
| P2.1 | opus/high | массовый namespace-refactor `permissions-azgard→azgard` — closed-enum триггер эскалации |
| P2.2–P2.4 | sonnet/medium + `vaulter-test-verifier` | механические переносы catch-all по канону |
| P3.x | sonnet/low | docs/ROADMAP/arch-тесты/supersede |

Остальное — по SSOT-матрице (`plan-protocol` §9): package-implementer sonnet/medium.

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Изоляция TipTap в драйвер + kind-нейтральный контракт движка (поглощает VLT-HSEAM) | 2/5 | 🟡 In progress |
| P1 | Упаковка/дистрибуция фронт-движков + формализация фронт-реестра (Часть 1) | 0/3 | ⬜ Not started |
| P2 | Финальный структурный канон: azgard-rename, media-развилка, catch-all, Access/Uploads | 0/4 | ⬜ Not started |
| P3 | Docs · ROADMAP (вкл. Filament-задел) · arch-тесты · supersede VLT-HSEAM | 0/3 | ⬜ Not started |

## 5. Decision Log

| D# | Date | Decision | Why |
|:--|:--|:--|:--|
| D1 | 2026-07-11 | **Не строить реестр/дескриптор/фронт-реестр заново — они существуют как швы**; VLT-CANON их ДОВОДИТ | Рекон main-loop: `HandlerRegistry`+`HandlerDescriptor`+`componentMap` уже = дистиллят WOPI/NC/Drive (наследуется от VLT-HSEAM D1) |
| D2 | 2026-07-11 | **VLT-CANON поглощает VLT-HSEAM целиком** (5 утечек L1–L5 + capability-policy + DX-каркас + Q1/Q2) — VLT-HSEAM помечается superseded в P3 | Один owner-скоуп «движки как плагины»; держать два плана на одну поверхность = дрейф |
| D3 | 2026-07-11 | **Опираемся на закрытые P4.9/P4.10 (layer-canon A) как на базовую линию** — не переоткрываем | Update Log VLT-REMED: P4.9/P4.10 🟢 (ADR-0017); VLT-CANON — уровень топологии ВЫШЕ |
| D4 | 2026-07-11 | **Динамический резолв движка по mime сохраняем; handler-id на Node НЕ персистим** (наследует VLT-HSEAM D7) | WOPI/NC/Drive резолвят динамически; персист протухает при регистрации лучшего движка |
| D5 | 2026-07-11 | **Bespoke узкий контракт движка, НЕ `Illuminate\Support\Manager`** (наследует VLT-HSEAM D4) | statefulness/$app-coupling/__call; публичный контракт пакета = узкий интерфейс, Manager максимум внутренняя деталь |
| D6 | 2026-07-11 | **Filament — вне активного scope** (ROADMAP-задел; ADR-0017-исключение) | Директива owner'а 2026-07-11: не тратить ресурсы, задел на будущее |
| D7 | 2026-07-12 | **Нейминг контракта движка: `FileHandler` + `Contracts/Handlers/`, kind-нейтраль (вариант A, §8.1)** — owner approved (Dmitry Vostrikov, запуск `/task:plan-exec VLT-CANON P0.1`) | см. §8.1: контракт описывает ЛЮБОЙ файл (не только редактируемые), fallback-хендлеры (`DownloadOnlyHandler`/`PreviewHandler`) — не «движки»; уже согласовано RAG VLT-HSEAM (L5) |
| D8 | — (рекомендация 2026-07-11; **закрывается owner'ом в P1.1**) | *[упаковка фронт-движков]* — рекомендация research'а: единый `@vaulter/vue` как shell/core; per-engine npm НЕ вводить до реального внешнего потребителя; TipTap — один version-train с PHP. **Открыт до P1.1**: P1.2/P1.3 стартуют ТОЛЬКО после закрытия D8 (гейт) | RAG:✅ 2026-07-11 `findings/frontend-engine-packaging-research.md`: Filament v4/Nova 5/Statamic v5 НЕ публикуют отдельный npm на плагин (CONFIRMED); awcodes/filament-tiptap-editor бандлит TipTap+15 расширений в одном пакете (CONFIRMED). Мой предварительный lean «per-engine npm» опровергнут research'ем — см. §8.2 |
| D9 | — | *[media→drive слияние]* — резолвится в P2.2 по ## Обсуждение §8.3 | см. §8.3 |
| D10 | — | *[Q1 VLT-HSEAM: движок реестра → core]* — резолвится в P0.4 по ## Обсуждение §8.4. При выборе A запись оформляется как **superseding H.5 + T5 (Option B-топология)** — явная ревизия обоих предшественников, не молчаливый переворот | см. §8.4 (⚠-блок) |
| D11 | — | *[Q2 VLT-HSEAM: `yjs_state`→`crdt_state`]* — резолвится в P0.5 по ## Обсуждение §8.5 | см. §8.5 |
| D12 | 2026-07-11 | **Server-side дескриптор (`GET nodes/{node}/handler`) сохраняем — это WOPI-класс паттерна, НЕ универсальная норма** (Drive/Box/Dropbox мапят mime→viewer на клиенте — adversarial verify REFUTED «универсальность»); честное основание: (а) WOPI CheckFileInfo-модель подтверждена; (б) статика реестра (mime/priority/capabilities ≈ discovery.xml) отделена от динамики прав per-file/per-user — у нас это AccessResolver, НЕ смешивать в capabilities; (в) баг-класс протухших mime→editor ассоциаций при персисте — CONFIRMED (усиливает D4) | RAG:✅ 2026-07-11 `findings/drive-editor-resolution-research.md` (вкл. REFUTED-ноты) |

## 6. Phases (детализация)

> ⚠ P1 несёт research-зависимые слоты (упаковка/нейминг) — заполняются по `findings/*-research.md`
> ПОСЛЕ завершения research-фан-аута (`workflows/wf-vlt-canon-research.js`). Пока — `[NEEDS RESEARCH]`.

### P0 — Изоляция TipTap в драйвер + kind-нейтральный контракт движка

Поглощает VLT-HSEAM P0.1–P0.5. Цель: TipTap перестаёт протекать в ядро; контракт движка — kind-нейтраль;
любой сторонний движок биндится по тому же шву. Пре-1.0 — переименования бесплатны.

- **P0.1 — Kind-нейтральный нейминг контрактов движка** (opus/high, `vaulter-api-surface`+`vaulter-reviewer`).
  Решить D7 (§8.1) ПЕРВЫМ. Затем по `php:namespace-refactor-safety`:
  - `core/src/Contracts/Documents/DocumentHandler.php` → `Contracts/Handlers/FileHandler.php`
    (ИЛИ `Contracts/Engines/DocumentEngine.php` — по D7); `HandlerRegistry`, `NodeHandlerResolver`,
    `DocumentContent`, `DocumentExporter` — в тот же нейтральный namespace.
  - `core/src/ValueObjects/Documents/{DocumentCapabilities→HandlerCapabilities, HandlerDescriptor,
    HandlerUi, FileDescriptor}` — в `ValueObjects/Handlers/`.
  - Обновить все `use` в documents/drive/core/vue-types (FQN-anchored), config-ключи `vaulter.handlers`,
    docs (`docs/reference/contracts.md`, `docs/guide/document-handlers.md`).
  - **Приёмка:** оба гейта зелёные; `vaulter-api-surface` diff = только переименования; BC-запись
    UPGRADE/CHANGELOG; фронт-типы (`packages/vue/src/types.ts` `HandlerDescriptor`) синхронны.

  **Completion Notes.** Rename исполнен по D7=A (`FileHandler` + `Contracts/Handlers/`), hard rename
  без alias-фазы (пре-1.0). Перенесены `git mv` (renames трекаются, similarity 83–98%):
  `Contracts/Documents/{DocumentHandler→FileHandler, HandlerRegistry, NodeHandlerResolver,
  DocumentContent, DocumentExporter}` → `Contracts/Handlers/*`;
  `ValueObjects/Documents/{DocumentCapabilities→HandlerCapabilities, HandlerDescriptor, HandlerUi,
  FileDescriptor}` → `ValueObjects/Handlers/*`. Обновлены все `use` (FQN-anchored, ловушка
  `DocumentHandler ⊂ DocumentHandlerRegistry` обойдена негативным lookahead `(?!Registry)`) в core
  (VaulterManager, Support/Config, Facades/Vaulter, VaulterServiceProvider, NodeResource, ArchTest,
  VaulterManagerExporterHandlerSeamTest, FakeExporter) и documents (14 файлов src + 1 tests).
  Фронт: `packages/vue/src/types.ts` TS-тип `DocumentCapabilities`→`HandlerCapabilities`
  (`HandlerDescriptor`/`HandlerUi` не менялись). Docs: 14 файлов (en+ru: guide/document-handlers,
  guide/architecture, guide/recipes, guide/export, reference/{contracts,facade,configuration});
  в contracts.md секция `## Documents` → `## Handlers`. BC-записи: UPGRADE.md (таблица 9 старых→новых
  FQCN + нота по `@vaulter/vue`), CHANGELOG.md `[Unreleased]`. ROADMAP.md — швы WOPI/экспортёров.
  Config-ключи (`vaulter.handlers`/`vaulter.exporters`) НЕ трогались — только комментарии.
  `TipTapContent`/`OpaqueDocumentContent`/`LinkSchemeValidator` намеренно остались в
  `ValueObjects/Documents` (их изоляция — P0.3, вне scope).
  **Гейты:** `vendor/bin/pint --test` → passed (pint прогнан перед коммитом, авто-фикс
  ordered_imports/phpdoc_align); `phpstan analyse` (max) → passed, 0 errors; `pest` → passed,
  487/487, 1267 assertions; `pest --type-coverage --min=100` → 100.0%; `@vaulter/vue`:
  `vue-tsc --noEmit` → clean, `vitest run` → 10 files / 104 tests passed, `vite build` → ✓ built.
  **Ревью:** `vaulter-api-surface` → «clean rename-only diff», 32 файла, ноль изменений сигнатур/
  дефолтов/возвращаемых типов, ноль новых/удалённых public-методов. `vaulter-reviewer` → BC/SemVer,
  ServiceProvider, Facade, Publishables — pass; 3 находки по докам (2×MED, 1×LOW): недочищенные
  старые FQCN в `architecture.md` (en/ru — проза §Value objects + таблица доменной структуры),
  самопротиворечивый bullet D2-извлечения в CHANGELOG, 2 стух­ших докблока
  (VaulterDocumentsServiceProvider, ArchTest). Все три починены ОТДЕЛЬНЫМ коммитом (6088c50),
  гейт после фикса перепрогнан зелёным.
  **Commits:** `e7db977` — refactor(core)!: kind-нейтральный нейминг контрактов движка — FileHandler (P0.1);
  `6088c50` — docs(core): дочистить старые FQCN после rename движка (P0.1).

  **Pending Work:** —

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P0.1 **opus/high**; фактически исполнено **opus/medium**
     (сессия на `claude-opus-4-8`, effort — дефолт команды `/task:plan-exec`, которая пинит medium и
     запрещает ручное переключение). Модель совпала, effort ниже заявленного; риск снят обязательными
     прогонами `vaulter-api-surface` + `vaulter-reviewer` (оба в Routing item'а) — находки ревью
     найдены и закрыты отдельным коммитом.
  2. `plan-lint.py` красный ДО и ПОСЛЕ P0.1 (не регресс item'а): линтер требует `phases/P0..P3.md` и
     секцию `## 6. Update Log`, а план держит детализацию фаз инлайном в `## 6. Phases (детализация)`
     (Update Log — `## 9`), каталог `plans/VLT-CANON/phases/` пуст с момента создания плана.
     Структуру плана правит plan-design, не исполнитель — вынесено в handoff Open risks.

  **Status:** 🟠 Done with deviations

- **P0.2 — Exporter принимает опаковый контент** (opus/high, `vaulter-reviewer`).
  `DocumentExporter::export(TipTapContent)` → `export(DocumentContent)` (опаковый); TipTap-специфика — внутри
  реализаций экспортёров `documents/src/Export/*`. Утечка L1. **Известные точки правки в core/tests:**
  `packages/core/tests/Fixtures/Documents/FakeExporter.php` (реализует старую сигнатуру — разойдётся с
  интерфейсом). **Приёмка:** контракт опаковый, phpstan-clean обе стороны; export-тесты зелёные.

  **Completion Notes.** Контракт `Vaulter\Contracts\Handlers\DocumentExporter::export()` расширен с
  конкретного `TipTapContent` до опакового `DocumentContent` (интерфейс УЖЕ существовал в том же
  namespace — новый тип не вводился). Реализации сужают сами: `documents/src/Export/{HtmlExporter,
  MarkdownExporter}` проверяют `instanceof TipTapContent` и бросают новое
  `Vaulter\Exceptions\Documents\UnsupportedDocumentContentException` (в CORE, рядом с
  `UnknownExportFormatException` — по находке ревью, см. ниже). `NodeExportService::contentOf()`
  сменил возвращаемый тип на `DocumentContent`; `core/tests/Fixtures/Documents/FakeExporter.php`
  приведён к новой сигнатуре. Доки (7 файлов: `docs/{guide/export,guide/recipes,reference/contracts,
  guide/errors}.md` + ru-зеркала) и BC-записи (`UPGRADE.md` — секция с diff'ом и рецептом миграции;
  `CHANGELOG.md [Unreleased]`) синхронны. Новый тест `documents/tests/Document/ExporterContentGuardTest.php`
  (3 кейса) пиннит guard.
  **Гейты (после фиксов ревью):** `vendor/bin/pint --test packages/core packages/documents` → passed;
  `phpstan analyse` (max, монорепо) → passed, 0 errors; `pest packages/core/tests` → 487/487, 1268
  assertions; `pest` в `packages/documents` → 209 passed / 1 skipped, 521 assertion (+3 новых);
  `pest --type-coverage --min=100` → 100.0% обе стороны.
  **Ревью.** `vaulter-api-surface` → дифф ровно ожидаемый: сигнатура `export()` + новый класс
  исключения, ноль изменений config-ключей и прочих public-сигнатур. `vaulter-reviewer` → **needs-work**,
  6 находок, все закрыты отдельным коммитом `5b3a352`: (1-HIGH) guard и класс исключения имели 0%
  покрытия — добавлен `ExporterContentGuardTest`; (2-MED) guard недостижим через собственный путь
  экспорта (см. Known Deviations 2) — доки перестали обещать защиту, которой у хоста нет; (3-MED)
  исключение лежало в `documents`, хотя контракт — core-шов и core-only хост со своим экспортёром
  обязан уметь его бросать → перенесено в `Vaulter\Exceptions\Documents\`; (4-LOW) примеры в доках
  бросали `\RuntimeException` в обход `VaulterException` → каноническое исключение; (5-LOW)
  формулировка CHANGELOG была инвертирована («narrows») — параметр РАСШИРЯЕТСЯ, ломаются реализаторы;
  (6-LOW, pre-existing) стухший FQCN `Vaulter\Support\TipTapRenderer` в примерах → `Vaulter\Documents\Support\`.
  **Commits:** `03b302c` — refactor(core)!: экспортёр принимает опаковый DocumentContent (P0.2);
  `5b3a352` — fix(core): findings ревью P0.2 — тесты guard'а, исключение в core, честные доки (P0.2).

  **Pending Work:**
  - **Kind-нейтральный `NodeExportService::contentOf()`** — тело до сих пор гонит любую версию через
    `NodeDocumentVersion::tipTapContent()` → `TipTapContent::decayFrom()`, который при `type !== 'doc'`
    возвращает `TipTapContent::empty()`, а не бросает. Следствие: не-TipTap-узел (Univer/spreadsheet,
    `capabilities.editable=true` ⇒ `availableFormats()` отдаёт `['html','markdown']`) экспортируется
    в ПУСТОЙ документ вместо отказа. Опаковый контракт (scope P0.2) это подготовил, но read-path
    остался TipTap-only — доводить в P0.4/P0.5 (там же живёт схема/`format`-дискриминатор). Зафиксировано
    докблоком на `contentOf()` и нотой в `docs/guide/errors.md` / `UPGRADE.md`.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P0.2 **opus/high**; фактически исполнено **opus/medium**
     (сессия на `claude-opus-4-8`, effort — дефолт команды `/task:plan-exec`, которая пинит medium и
     запрещает ручное переключение). Модель совпала, effort ниже заявленного; риск снят обязательным
     прогоном `vaulter-reviewer` (в Routing item'а) — он вернул needs-work с 6 находками, все закрыты.
  2. Приёмка item'а («контракт опаковый, phpstan-clean обе стороны, export-тесты зелёные») выполнена
     буквально, но опаковость КОНТРАКТА не делает опаковым READ-PATH: guard в экспортёрах сегодня
     недостижим через `GET .../export` (см. Pending Work). Это не регресс — до P0.2 не-TipTap-узел
     экспортировался в пустой док ровно так же; P0.2 не ухудшил и не исправил этот путь, он лишь
     сделал контракт готовым к исправлению. Доки не обещают несуществующую защиту.
  3. `plan-lint.py` красный ДО и ПОСЛЕ P0.2 (не регресс item'а, наследуется из P0.1): линтер требует
     `phases/P0..P3.md` и секцию `## 6. Update Log`, а план держит детализацию фаз инлайном в
     `## 6. Phases (детализация)` (Update Log — `## 9`). Структуру плана правит plan-design.

  **Status:** 🟠 Done with deviations

- **P0.3 — TipTap-валидатор/рендер/расширения переезжают из core в documents** (opus/high).
  `core/src/ValueObjects/Documents/{TipTapContent, LinkSchemeValidator, OpaqueDocumentContent}`,
  `core/src/Attributes/TiptapExtensions.php`, `core/src/Exceptions/Documents/InvalidTipTapContentException.php`
  → `documents/src/` (внутрь TipTap-драйвера). Их тесты переезжают вместе. Утечка L2. Core перестаёт знать
  про форму TipTap. **Осторожно:** это XSS-барьер (VLT-REMED R-01/D27–D29) — allow-list-синхрон и decay
  на чтении сохранить байт-в-байт, не ослабить. **Известные точки правки в core/tests:**
  `packages/core/tests/E2E/E2eServiceProvider.php` (`TipTapContent::fromArray` в e2e-setup — сменить use на
  новый FQCN; core уже require-dev'ит documents) + прочие use в core/tests по grep.
  **Приёмка:** `grep -ri tiptap core/src core/tests` → 0 (кроме нейтральных комментариев и легитимных
  cross-package use на documents-FQCN); XSS-тесты зелёные обе стороны.

- **P0.4 — Схема: убрать `default('tiptap')`; движок реестра → core (Q1/D10)** (opus/high).
  Утечка L3 + VLT-HSEAM Q1. Решить D10 (§8.4). Убрать `default('tiptap')` у
  `v_node_document_versions.format` (`core/src/Schema/PanelTablesBuilder.php` `defineDocumentVersions`) —
  format проставляет движок явно; комментарии схемы нейтральные. Если D10=«движок→core»: перенести
  `DocumentHandlerRegistry`+`NodeHandlerResolver`+`DownloadOnlyHandler`+`PreviewHandler` из
  `documents/src/Documents/` в `core/src/Engines/`; documents оставляет TipTap/Univer/collab-драйверы.
  **Приёмка:** миграция без `default`; drive-only хост получает preview/download-дескриптор без documents
  (если D10=A); гейт зелёный.

- **P0.5 — `richText` дефолт + policy эволюции capabilities + DX-каркас + Q2** (sonnet/medium → opus для Q2).
  Утечки L4/L5. `richText=true` → нейтральный дефолт; тест-инвариант «добавление оси capabilities не ломает
  клиента» (открытое множество, аддитивные оси, клиент игнорит незнакомые ключи). DX: `AbstractFileHandler`-база
  + гайд `docs/guide/writing-an-engine.md` (skeleton). Решить D11 (§8.5, `yjs_state`).
  Research-довесы (`editor-driver-isolation-research.md`): (а) политика payload-асимметрии — office/WOPI-класс
  движков отдаёт ПРИНЦИПИАЛЬНО другой payload (iframe/action-url/token), контракт НЕ заставляет чужой движок
  повторять форму TipTap-дескриптора — зафиксировать в контракте/гайде; (б) `schemaVersion` поле в
  content/descriptor TipTap-драйвера (дёшево сейчас, дорого постфактум — [UNVERIFIED-детали, решение по
  месту]); (в) тест-кейс конфликт-резолва: одно расширение претендуется двумя движками (аналог xml→word/cell
  у ONLYOFFICE) — explicit priority уже есть, покрыть тестом; (г) при будущем Univer-драйвере — граница
  `@univerjs/*` (Apache-2.0) vs `@univerjs-pro/*` (коммерческий) фиксируется в docs (CONFIRMED).
  **Приёмка:** capability-policy-тест зелёный; конфликт-тест зелёный; гайд написан (вкл. payload-асимметрию);
  VLT-HSEAM P0.1–P0.5 покрыты → пометить superseded (в P3).

### P1 — Упаковка/дистрибуция фронт-движков + формализация фронт-реестра (Часть 1)

- **P1.1 — Закрытие D8 owner'ом: модель упаковки + нейминг фронт-пакетов** (opus/high). **Гейт для P1.2/P1.3.**
  Research завершён — рекомендация в §8.2 (единый `@vaulter/vue`; per-engine npm — только при реальном
  внешнем потребителе; один version-train с PHP). Owner подтверждает/выбирает альтернативу из §8.2;
  при альтернативе — P1.2/P1.3 передетализировать ДО старта (эскалация в design, не импровизация).
  **Приёмка:** D8 закрыт (дата+вердикт в Decision Log); нейминг-решение в Completion Notes.

- **P1.2 — Формализация фронт-реестра компонентов как публичного шва** (opus решение → sonnet).
  `VaulterDocumentNode.vue` `componentMap` (по `handler.id`/`ui.component`) — сейчас prop-шов. Формализовать
  как публичный registry-API — **явная экспортируемая функция** (`registerEngineComponent(id, component)`
  или plugin-опция), НЕ implicit-глобал (урок Nextcloud Viewer: ушли от `OCA.Viewer.registerHandler`-глобала
  к explicit ES-module import — research F10). Dev-mode warning, когда `handler.id` без зарегистрированного
  компонента (известная боль Nova: string-key без проверки — research F3). Задокументировать ключи,
  экспортировать типы. **view НЕ переписываем** — только формализуем шов (директива брифа).
  **Приёмка:** публичный фронт-API задокументирован; типы экспортированы; dev-warning есть;
  `vue-tsc`+vitest+build зелёные; обратная совместимость prop `componentMap` сохранена.

- **P1.3 — Скелет-пакет/гайд стороннего фронт-движка** (sonnet/low).
  По D8: skeleton (`docs/guide/writing-an-engine.md` фронт-часть ИЛИ example-пакет) — как хост регистрирует
  компонент по имени движка + получает `HandlerUi.props`. **Приёмка:** гайд/скелет проходит собственный build.

### P2 — Финальный структурный канон (Часть 3)

- **P2.1 — Переименование `permissions-azgard → azgard`** (opus/high, `php:namespace-refactor-safety`).
  dir `packages/permissions-azgard/`→`packages/azgard/`; composer `vaulter-permissions-azgard`→`vaulter-azgard`;
  namespace `Vaulter\Permissions\Azgard\`→`Vaulter\Azgard\`. Контракт-шов `Vaulter\Contracts\Access\AzgardGuard`
  (в CORE) — НЕ трогаем. Обновить: composer path-repos, root `composer.json`, CI-lane, `testbench.yaml`,
  arch-guard'ы, docs. **Приёмка:** гейт azgard зелёный; core не сломан; BC UPGRADE/CHANGELOG; arch «0 ссылок
  core→azgard» зелёный.

- **P2.2 — [Развилка D9/§8.3] media→drive?** (opus решение → sonnet если сливаем).
  Решить §8.3. Рекомендация — ОСТАВИТЬ standalone (media = чистый CAS-движок). Если owner выберет слияние —
  перенести `media/src/{Cas,Console}` в `drive/src/`, drive поглощает CAS, обновить `Contracts\Cas\*`-биндинги,
  убрать `vaulter-media` из require, CI-lane. **Приёмка:** решение зафиксировано D9; при слиянии — гейт drive
  зелёный, цикла нет, BC-запись.

- **P2.3 — Разбор catch-all: `Documents/`, `Uploads/`, `Support/`** (sonnet/medium, `vaulter-test-verifier`).
  По `findings/structure-canon-inventory.md §2.3`:
  - `documents/src/Documents/` → развести (частично сделано P0.4): реестр/резолвер (→ `Engines/` или core),
    драйверы → `Engines/Handlers/`, `CollaborationSessionService`/`NodeExportService` → слои
    (`Support/`/`Actions/` по роли), убрать тавтологию `Documents/Documents`.
  - `drive/src/Uploads/` (6 плоских) → роль-based: `*Handler`/`*Manager` — домен-сервис ок;
    `MagicByteSniffer`/`StagedUpload` → `Support/Uploads`; `UploadModels` — helper.
  - `documents/src/Support/` → `TipTapRenderer`/`HasTiptapExtensions` внутрь TipTap-драйвера (совместно с P0.3);
    `DocumentContentHasher` — движок-нейтраль.
  **Приёмка:** ноль изменений поведения; arch-тесты канона зелёные; `vaulter-test-verifier` сводка pass.

- **P2.4 — Access/Uploads-сверка + core `Webhooks/`** (sonnet/low).
  `documents/src/Access/HostUserResolver` — свернуть в слой documents ЛИБО подтвердить домен-сервисом;
  core `Access/`/`Webhooks/` — сверить с ADR-0017 (вероятно no-op, задокументировать вердикт).
  **Приёмка:** вердикт по каждой папке (перенос ЛИБО «канон ок, no-op») в Completion Notes.

### P3 — Docs · ROADMAP · arch-тесты · supersede

- **P3.1 — ROADMAP + docs** (sonnet/low). ROADMAP: пункт «доделать `vaulter-filament` (движки/reсources под
  панель)» как задел (D6). Обновить `docs/guide/*`, `docs/reference/contracts.md`, `docs/adr/index.md`
  (новый ADR канона движков, см. P3.3). **Приёмка:** docs собираются; ROADMAP несёт Filament-пункт.
- **P3.2 — arch-тесты финального канона** (sonnet/medium). Расширить arch-suite: «0 tiptap-ссылок в core»,
  «движок-контракт kind-нейтраль», dependency-direction после azgard-rename/media-решения. **Приёмка:** зелёные.
- **P3.3 — ADR канона движков + supersede VLT-HSEAM** (sonnet/low). Новый `docs/adr/00XX-editor-engine-canon.md`
  (консолидирует D1–D11). Пометить `plans/VLT-HSEAM/` superseded ← VLT-CANON. **Приёмка:** ADR принят; VLT-HSEAM
  помечен; `plans/ACTIVE.md` не тронут (если VLT-CANON не стал ACTIVE решением owner'а).

## 7. Инварианты / критерии приёмки (каждая фаза)
1. Гейты затронутых пакетов зелёные: pint + phpstan **max** + pest + type-cov 100% (+ фронт: vue-tsc+vitest+build).
2. Цикла нет: arch-тест «0 ссылок `core/src` → feature-namespace» (вкл. `Vaulter\Azgard` после P2.1).
3. Структурные переносы (P2) — ноль изменений поведения; feature-флаги/dedup-scope/XSS-барьер целы.
4. Публичная поверхность: `vaulter-api-surface` diff на каждом контракт-item; BC — UPGRADE + CHANGELOG.
5. `@vaulter/vue` prop-совместимость (`componentMap`) сохранена; view не переписан.
6. Filament не трогаем (D6); только ROADMAP-пункт.

## 8. Обсуждение (спорное — рецензия и рекомендация; решает owner/резолвится в фазе)

### 8.1 Нейминг контракта движка: `FileHandler` vs `DocumentEngine` (→ D7, P0.1)
Бриф говорит «контракт **DocumentEngine**»; VLT-HSEAM D3 выбрал «**FileHandler**» (kind-нейтраль).
- **Вариант A (рекоменд.): `FileHandler` + `Contracts/Handlers/`.** Плюсы: контракт описывает ЛЮБОЙ файл
  (image/*, xlsx, pdf, download-only), не только редактируемые доки; «Engine» подразумевает редактирование, а
  fallback `DownloadOnlyHandler` и `PreviewHandler` — не «движки-редакторы». Уже согласовано RAG VLT-HSEAM
  (L5). Минус: расходится с буквой брифа.
- **Вариант B: `DocumentEngine` + `Contracts/Engines/`.** Плюсы: буква брифа, «движок» звучит продуктовее.
  Минусы: сужает семантику (preview/download — не движки); придётся отдельный контракт для не-редакторов ИЛИ
  натяжка имени. **Рекомендация: A** — при желании owner'а «Engine» вынести как ПОДтип `FileHandler` для
  редактируемых (capabilities.editable), сохранив нейтральную базу. **Закрыто: вариант A** (D7, 2026-07-12,
  owner approved) — исполнение (сам рефакторинг) остаётся в скоупе P0.1 exec.

### 8.2 Упаковка/нейминг фронт-движков (→ D8, P1.1) — research ЗАВЕРШЁН, рекомендация зафиксирована
Research (`findings/frontend-engine-packaging-research.md`; verify: несущие Filament/no-npm/tiptap-editor
CONFIRMED; Statamic-находка — REFUTED в детали API-имени: реальный вызов `Statamic.$components.register(...)`,
не `Statamic.component(...)`; сама модель «string-key реестр + Composer-only» у Statamic подтверждена):
Filament v4 / Nova 5 / Statamic v5 **не публикуют отдельный npm** на плагин — фронт живёт внутри
Composer-пакета (resources/js + Vite → dist), один version-train с PHP; прямой прецедент
awcodes/filament-tiptap-editor бандлит TipTap + ~15 расширений внутри одного пакета. Nova 5 (string-key
`Nova.booting` component binding без compile-time проверки) подтверждает: id-based `componentMap` — норма.
Nextcloud Viewer ушёл от implicit-global к **explicit ES-module import** реестра — предостережение для
формализации нашего шва (P1.2: экспортируемая функция, не глобал).
- **Вариант A (рекоменд., = D8): единый `@vaulter/vue` остаётся shell/core; TipTap-движок — внутри него,
  один version-train; per-engine npm вводится ЛЕНИВО** — только при реальном внешнем потребителе движка
  (тогда flat-нейминг `@vaulter/vue-engine-<name>`). Плюсы: экосистемная норма (3/3 эталона), ноль
  преждевременной инфраструктуры, простой хост. Минусы: bundle несёт TipTap даже без него (смягчение:
  tree-shake через named exports уже есть).
- **Вариант B: per-engine npm сейчас** (`@vaulter/vue-engine-tiptap`…). Плюсы: буквальная симметрия с
  backend behaviour-пакетами. Минусы: research не нашёл НИ ОДНОГО прецедента (open question: standalone
  editor-engine npm под чужой хост — не найден); N пакетов + версионирование против PHP без потребителя.
- **Вариант C: rename в `@vaulter/view`.** Косметика без смены модели — отвергнуть (шум без выгоды).
**Рекомендация: A.** Мой предварительный lean (per-engine) опровергнут research'ем — фиксирую честно.

### 8.3 media→drive слияние? (→ D9, P2.2)
- **Вариант A (рекоменд.): ОСТАВИТЬ media standalone.** Плюсы: media = переиспользуемый CAS-движок (решение
  ADR-0008/topology); documents его не требует; слияние ломает «media = чистый движок» без продуктовой выгоды;
  drive и так `requires media` (один install). Минусы: +1 пакет в дереве.
- **Вариант B: слить media в drive.** Плюсы: −1 пакет; CAS «ближе» к своему единственному тяжёлому
  консюмеру. Минусы: drive обязан тянуть CAS даже если хост хочет только file-tree; ломает established
  topology-инвариант; BC-перенос namespace `Vaulter\Media\*`→`Vaulter\Drive\*`. **Рекомендация: A.**

### 8.4 Q1 (VLT-HSEAM): движок реестра → core? (→ D10, P0.4)
⚠ **Вариант A — это ревизия ДВУХ предшественников, оба называем явно:** (1) решения H.5 («движок в
documents, core fail-loud») и (2) **аллокации T5/§Слои Option B-топологии** (`docs/plan/p2-5-option-b-topology.md`
строки T5/§Аллокация: «`Documents\*` (registry/handlers/services) → docdrive»). Закрытие D10=A оформляется
как **superseding-запись**: D10 явно ссылается на H.5 и T5 как на пересматриваемые, обоснование — новое
требование drive-only хоста, которого при T5-разрезе не было в скоупе. Молчаливого переворота нет.
- **Вариант A (рекоменд.): движок (`HandlerRegistry`+`NodeHandlerResolver`) + baseline
  (`DownloadOnlyHandler`/`PreviewHandler`) → core; documents оставляет TipTap/Univer/collab.** Плюсы:
  drive-only хост (файлы без редактирования) получает preview/download-дескриптор БЕЗ vaulter-documents;
  «tiptap — одно из расширений» становится буквальным; реестр = инфра ядра (как PanelRegistry). Минусы:
  ревизия H.5 + T5 (см. выше); +~4 класса в core; аккуратный перенос тестов.
- **Вариант B: оставить в documents.** Плюсы: ноль работы, T5/H.5 нетронуты. Минусы: ядро декларирует
  editor-agnostic, но без documents шов мёртв; documents де-факто обязателен. **Рекомендация: A**
  (совпадает с рекомендацией VLT-HSEAM Q1).

### 8.5 Q2 (VLT-HSEAM): `yjs_state`→`crdt_state`? (→ D11, P0.5)
- **Вариант A (рекоменд.): оставить `yjs_state`.** Колонка nullable, чужой движок её не пишет; Yjs и есть CRDT
  collab-слоя; дискриминатор — `format`. Переименование = косметика + шум миграций + трогать vue/hocuspocus.
- **Вариант B: `crdt_state`.** Плюсы: нейтральность буквы. Минусы: выгода косметическая. **Рекомендация: A.**

## 9. Update Log

| Date | Who (role/model) | What |
|:--|:--|:--|
| 2026-07-11 | design/opus (fable) | План создан: рекон + research-фан-аут (3 фронта, `workflows/wf-vlt-canon-research.js`), инвентарь `findings/structure-canon-inventory.md`, P0–P3 с D1–D11, ## Обсуждение §8. Filament выведен из scope (D6). |
| 2026-07-11 | design/opus (fable) | Research завершён (6 агентов, 302k токенов, adversarial verify): 3 `findings/*-research.md`; D8 зафиксирован (единый `@vaulter/vue`, per-engine npm лениво — прежний lean опровергнут), D12 добавлен (server-дескриптор = WOPI-класс, не универсалия); P0.5/P1.1/P1.2 обогащены (payload-асимметрия, schemaVersion, dev-warning, explicit-import реестр). |
| 2026-07-11 | design/opus (fable) | DoR-аудит (`workflows/wf-vlt-canon-audit.js`, 4 finder-измерения + adversarial verify, 6 находок / 0 REJECTED) — все исправлены: (1-Blocker) supersede-тайминг унифицирован (cross-notice в VLT-HSEAM plan.md сейчас, формальная маркировка P3.3), Meta-поле выровнено; (2) D8 переоформлен открытым до P1.1, P1.1 = гейт для P1.2/P1.3; (3) Routing дополнен P0.4/P0.5; (4) §8.4/D10 явно называют ревизию T5-аллокации Option B (не только H.5); (5) P0.2/P0.3 дополнены точками правки core/tests (FakeExporter, E2eServiceProvider), grep расширен на core/tests; (6) §8.2 честно фиксирует REFUTED-нюанс Statamic-API. |
| 2026-07-12 | plan-exec/sonnet (prep P0.1) | D7 (§8.1) закрыт owner'ом: вариант A — `FileHandler` + `Contracts/Handlers/`, kind-нейтраль. Approve получен запуском `/task:plan-exec VLT-CANON P0.1`. Сам рефакторинг (rename+перенос) — предмет исполнения P0.1, ещё не начат. |
| 2026-07-12 | plan-exec/opus (medium) | P0.1 закрыт (🟠, deviation по effort в Routing): `DocumentHandler`→`FileHandler`, контракты/VO движка → `Contracts\Handlers\*` / `ValueObjects\Handlers\*`, фронт-тип + docs + BC-записи синхронны — детали см. §6 P0.1 Completion Notes. |
| 2026-07-12 | plan-exec/opus (medium) | P0.2 закрыт (🟠): `DocumentExporter::export()` принимает опаковый `DocumentContent`, TipTap-сужение — внутри экспортёров; kind-нейтральный read-path (`contentOf()`) вынесен в Pending Work → P0.4/P0.5 — детали см. §6 P0.2 Completion Notes. |

## 10. Провенанс
- Внутренние: `plans/VLT-HSEAM/{plan.md, handoff.md, root/adr-file-handler-seam.md, findings/P0-*.md}`,
  `docs/plan/p2-5-{option-b-topology,vaulter-media-extraction,namespace-strategy-drive-docdrive}.md`,
  `docs/adr/0017-package-structure-canon.md`, `plans/archive/VLT-REMED/findings/P4.9-structure-inventory.md`,
  код-швы (`core/src/{Contracts,ValueObjects}/Documents/*`, `documents/src/Documents/*`,
  `packages/vue/src/components/VaulterDocumentNode.vue`).
- Внешние (research-фан-аут): `findings/{frontend-engine-packaging,drive-editor-resolution,editor-driver-isolation}-research.md`
  (3 фронта × research+adversarial-verify; source_url в каждом). Значимые — продублировать в `docs/reference.md`.
