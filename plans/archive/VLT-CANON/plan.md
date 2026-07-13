# VLT-CANON — Подключаемые движки-редакторы + финальный структурный канон

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-CANON |
| Title | Подключаемые движки-редакторы (Google-Drive-модель) + финальный структурный канон |
| Version | 0.1.0 |
| Status | 🟠 Done with deviations |
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
| P0 | Изоляция TipTap в драйвер + kind-нейтральный контракт движка (поглощает VLT-HSEAM) | 5/5 | 🟢 Done |
| P1 | Упаковка/дистрибуция фронт-движков + формализация фронт-реестра (Часть 1) | 3/3 (1 🟠) | 🟢 Done |
| P2 | Финальный структурный канон: azgard-rename, media-развилка, catch-all, Access/Uploads | 4/4 (1 🟠) | 🟢 Done |
| P3 | Docs · ROADMAP (вкл. Filament-задел) · arch-тесты · supersede VLT-HSEAM | 3/3 | 🟢 Done |

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
| D8 | 2026-07-13 | **Упаковка фронт-движков: вариант A (§8.2) — единый `@vaulter/vue` остаётся shell/core**; TipTap-движок внутри него, один version-train с PHP; per-engine npm (`@vaulter/vue-engine-<name>`) вводится лениво — только при реальном внешнем потребителе. Owner подтвердил A буквально (2026-07-13, Dmitry Vostrikov, `/task:plan-exec VLT-CANON P1.1`), альтернатива не выбрана — P1.2/P1.3 передетализация НЕ требуется | RAG:✅ 2026-07-11 `findings/frontend-engine-packaging-research.md`: Filament v4/Nova 5/Statamic v5 НЕ публикуют отдельный npm на плагин (CONFIRMED); awcodes/filament-tiptap-editor бандлит TipTap+15 расширений в одном пакете (CONFIRMED). Мой предварительный lean «per-engine npm» опровергнут research'ем — см. §8.2. Закрыт исполнением P1.1 |
| D9 | 2026-07-13 | **`vaulter-media` остаётся standalone-пакетом (вариант A, §8.3)** — owner approved (Dmitry Vostrikov, интерактивный выбор в `/task:plan-exec VLT-CANON P2.2`) | media = переиспользуемый CAS-движок (ADR-0008 topology); `documents` его не требует; `drive` и так `requires media` — установка не меняется; слияние ломает «media = чистый движок» без продуктовой выгоды и требует BC-переноса namespace. Закрыт исполнением P2.2, без кода (сохранение статус-кво) |
| D10 | 2026-07-13 | **Движок реестра → core (вариант A, §8.4)** — `DocumentHandlerRegistry`+`NodeHandlerResolver`+`DocumentFileDescriptorFactory`+`Capability`+baseline `PreviewHandler`/`DownloadOnlyHandler` переехали в `Vaulter\Engines\*` / `Vaulter\Enums\Handlers\*`; documents оставляет TipTap/Univer-драйверы. **Superseding-запись: явно пересматривает (1) H.5 («движок в documents, core fail-loud») и (2) T5/§Аллокацию Option B-топологии** (`docs/plan/p2-5-option-b-topology.md`: «`Documents\*` (registry/handlers/services) → docdrive»). Закрыт исполнением P0.4 | Новое требование, которого не было в скоупе при T5-разрезе: **drive-only хост** (файлы без редактирования) обязан получать preview/download-дескриптор БЕЗ `vaulter-documents`. До P0.4 `NodeResource::handler()` молча отдавал `null` такому хосту. Реестр = инфра ядра (как `PanelRegistry`); «tiptap — одно из расширений» стало буквальным |
| D11 | 2026-07-13 | **Q2 VLT-HSEAM: оставить `yjs_state`** (вариант A, §8.5) — колонка nullable, чужой движок её не пишет, дискриминатор — `format`; переименование в `crdt_state` — чистая косметика без функциональной выгоды | см. §8.5; закрыт исполнением P0.5, без кода (сохранение статус-кво) |
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

  **Completion Notes.** Hard rename без alias-фазы (пре-1.0), `git mv` (renames трекаются): 5 файлов
  `core/src/ValueObjects/Documents/{TipTapContent,LinkSchemeValidator,OpaqueDocumentContent}` →
  `documents/src/ValueObjects/*`; `core/src/Attributes/TiptapExtensions.php` →
  `documents/src/Attributes/TiptapExtensions.php`;
  `core/src/Exceptions/Documents/InvalidTipTapContentException.php` →
  `documents/src/Exceptions/InvalidTipTapContentException.php`. Namespace-маппинг зеркалит уже
  существующую документную конвенцию (`Vaulter\Documents\{ValueObjects,Attributes,Exceptions}`, тот
  же корень, что у `DocumentExport.php`/`UnknownDocumentTypeException.php`). Тесты уже жили в
  `documents/tests` (cross-package на core-класс) — переезжать физически не пришлось, только `use`.
  Обновлены `use`-импорты (FQN-anchored) в 24 файлах: 14 production (`documents/src/**`) + 6 tests
  (`documents/tests/**`) + 4 core (`core/tests/{E2E/E2eServiceProvider,Support/UpgradeGuideCoverageTest,
  Arch/ArchTest}.php`, `core/src/Contracts/Handlers/DocumentContent.php`). Последний — единственная
  production-точка core, реально знавшая форму: `use TipTapContent`/`OpaqueDocumentContent` убраны,
  `{@see}`-ссылки в докблоке заменены прозой (сигнатуры `toArray()`/`contentHash()`/`sizeInBytes()` не
  тронуты) — core больше не импортирует ни одной конкретной формы контента (`grep` → 0 в
  `core/src`). `ArchTest.php`: обновлена строка `'Vaulter\ValueObjects\Documents\TipTapContent'` →
  `'Vaulter\Documents\ValueObjects\TipTapContent'` в тесте канонических FQCN + докоммент теста
  «core never references vaulter-documents» (D2) дополнен новыми 5 классами.
  `UpgradeGuideCoverageTest.php`: путь-константа P0.1-кейса переставлена на новый файл. XSS-барьер
  (store-time allow-list, `decayFrom` read-time decay, `LinkSchemeValidator` URL-scheme allow-list,
  R-01/R-11/R-12) перенесён байт-в-байт — только `namespace`/`use`-строки менялись, тела методов не
  тронуты (подтверждено ревью diff'ом против pre-move git-blob'ов).
  Доки: `docs/guide/architecture.md` + ru-зеркало — убрана строка `ValueObjects/Documents/` ·
  `Exceptions/Documents/` из таблицы директорий core (папка ушла из core), в таблицу пакетов добавлена
  строка про 5 перенесённых классов в `vaulter-documents`, прозу §Value objects поправлена (core
  знает только kind-нейтральные VO). `docs/{guide,ru/guide}/{export,documents,recipes}.md` — FQCN
  везде обновлены. UPGRADE.md: ретро-исправлена стухшая фраза P0.1-секции («TipTapContent... stay
  pending P0.3» → «have since moved... see below»); новая секция с таблицей old→new FQCN,
  byte-for-byte гарантией и «core-only host unaffected». CHANGELOG.md `[Unreleased]`: аналогично
  ретро-фикс P0.1-буллета + новый P0.3-буллет.
  **Гейты:** `vendor/bin/pint packages/core packages/documents --test` → passed;
  `phpstan analyse` (max, монорепо) → passed, 0 errors; root `pest` (core) → 487/487, 1268
  assertions; `packages/documents` `pest` → 209 passed / 1 skipped, 521 assertions (без изменений
  против P0.2-баз­лайна); `pest --type-coverage --min=100` → 100.0% обе стороны.
  **Ревью.** `vaulter-api-surface` (второй прогон — первый ошибочно сравнил с `main`, false-positive
  «200+ файлов/vue» на всей истории ветки, не на диффе сессии) → clean rename-only diff, 65
  файлов, +248/-275, зеро изменений сигнатур/конфиг-ключей/`packages/vue`. `vaulter-reviewer` →
  approve, byte-for-byte XSS-барьер подтверждён построчным диффом против pre-move blob'ов,
  BC/UPGRADE честные, ServiceProvider/publishables/config не задеты; 1 Major + 1 Minor + 1 Nit
  (см. Known Deviations/Pending Work — ни один не блокирует, все зафиксированы, а не тихо
  проигнорированы).
  **Commits:** `a1f2f7c` — refactor(core)!: TipTap-формы контента переезжают в documents (P0.3).

  **Pending Work:**
  1. [Major, ревью] **Гейт-скоуп:** `TipTapContent`/`LinkSchemeValidator` (XSS-барьер R-01/R-11/R-12)
     покинули `packages/core/src`, а с ним — enforcing-пороги `.github/workflows/mutation.yml`
     (`--min=95`, `--path=packages/core/src`) и `tests.yml` coverage (`--min=80`, source =
     `packages/core/src`). Новый дом `packages/documents` в CI (`documents.yml`) гоняет `pest --ci`
     БЕЗ `--coverage --min=N`, mutation-шаг — `infection ... || true` (неблокирующий). Итог: будущее
     ослабление allow-list-теста в `documents`-сьюте не покраснит НИ ОДИН гейт, хотя раньше `--min=95`
     поймал бы это в core. Не регресс поведения (тесты сами зелёные и переехали корректно) — регрессия
     гарантии, что они останутся осмысленными. Чинить: явное решение — либо enforcing mutation/coverage
     лейн для `packages/documents/src/ValueObjects` (+ `Attributes`/`Exceptions`), либо перенос порога
     в `documents.yml`. Вне Scope Included этого item'а (правка `.github/workflows/*.yml` — не
     файловый перенос 5 классов) — не делал, эскалирую как Pending Work на следующий CI/gate-item.
  2. [Nit, ревью] `packages/core/composer.json` `require-dev` не объявляет
     `axioma-studio/vaulter-documents` явно, хотя core-тестсьют на него ссылается
     (`E2eServiceProvider.php`, теперь ещё `UpgradeGuideCoverageTest.php`) — работает только потому,
     что core-сьют гоняется из корня монорепы. Пред-существующее (не регресс P0.3, `E2eServiceProvider`
     так делал и до диффа) — standalone-сборка core со своим `require-dev` (только drive+media) не
     собрала бы этот сьют. Не чинил (вне Scope Included).
  3. Kind-нейтральный `NodeExportService::contentOf()` (P0.2 Pending Work) — не трогал, ждёт
     P0.4/P0.5 как и планировалось.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P0.3 **opus/high**; фактически исполнено **opus/medium**
     (сессия на `claude-opus-4-8`, effort — дефолт команды `/task:plan-exec`, которая пинит medium и
     запрещает ручное переключение). Модель совпала, effort ниже заявленного; риск снят обязательными
     прогонами `vaulter-api-surface` + `vaulter-reviewer` (оба в Routing item'а) — оба прошли,
     находки зафиксированы выше.
  2. Приёмка item'а («`grep -ri tiptap core/src core/tests` → 0») выполнена НЕ буквально: остаются
     `Config::tiptapExtraNodes()/tiptapExtraMarks()/tiptapExtraAttributes()/tiptapUrlAttributes()/
     tiptapAttributeMap()` (`core/src/Support/Config.php:1394-1451`, host-facing config-аксессоры для
     `vaulter.tiptap.*`) и `$table->string('format', 16)->default('tiptap')`
     (`core/src/Schema/PanelTablesBuilder.php:600`). Оба — сознательно НЕ в Scope Included этого
     item'а (Scope перечисляет ровно 5 классов; `default('tiptap')` явно назван в тексте P0.4, а
     config-сиим — host-facing поверхность, не форма TipTap-контента, переименование config-ключа —
     отдельное BC-решение вне этого item'а). Формулировка критерия приёмки в plan.md шире фактического
     Scope item'а — правка формулировки принадлежит plan-design, не exec (см. п.4 протокола); отмечено
     находкой ревью (Minor), не правил текст плана сам.
  3. `plan-lint.py` красный ДО и ПОСЛЕ P0.1/P0.2/P0.3 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases (детализация)`
     (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.

  **Status:** 🟠 Done with deviations

- **P0.4 — Схема: убрать `default('tiptap')`; движок реестра → core (Q1/D10)** (opus/high).
  Утечка L3 + VLT-HSEAM Q1. Решить D10 (§8.4). Убрать `default('tiptap')` у
  `v_node_document_versions.format` (`core/src/Schema/PanelTablesBuilder.php` `defineDocumentVersions`) —
  format проставляет движок явно; комментарии схемы нейтральные. Если D10=«движок→core»: перенести
  `DocumentHandlerRegistry`+`NodeHandlerResolver`+`DownloadOnlyHandler`+`PreviewHandler` из
  `documents/src/Documents/` в `core/src/Engines/`; documents оставляет TipTap/Univer/collab-драйверы.
  **Приёмка:** миграция без `default`; drive-only хост получает preview/download-дескриптор без documents
  (если D10=A); гейт зелёный.

  **Completion Notes.** **D10 закрыт = вариант A** (§8.4) — оформлен как superseding-запись к H.5
  и T5-аллокации Option B (обе названы явно в Decision Log). Движок реестра переехал `git mv`
  (renames трекаются) из `documents/src/Documents/` в core: `DocumentHandlerRegistry`,
  `NodeHandlerResolver`, `DocumentFileDescriptorFactory` → `core/src/Engines/*`;
  `Handlers/{DownloadOnlyHandler,PreviewHandler}` → `core/src/Engines/Handlers/*`;
  `Documents\Enums\Documents\Capability` → `core/src/Enums/Handlers/Capability.php`
  (`Vaulter\Enums\Handlers\Capability`). Публичные контракты (`Contracts\Handlers\*`) НЕ
  двигались — переехали только конкретные реализации (подтверждено `vaulter-api-surface`:
  ноль изменений сигнатур).
  **Проводка.** core `VaulterServiceProvider` вяжет `HandlerRegistry`/`NodeHandlerResolver`
  БЕЗУСЛОВНО (было — условно в documents-провайдере) + тегирует baseline в `'vaulter.handlers'`
  (см. находку ревью HIGH-1 ниже). `core/config/vaulter.php` `handlers` дефолт `[]` →
  `[PreviewHandler, DownloadOnlyHandler]`; `documents/config/vaulter-documents.php` оставил
  только `TipTapHandler` (Univer по-прежнему тегируется по фича-флагу). `VaulterManager::handlers()`
  больше не оборачивает резолв в `resolveSeam()` → `MissingPackageException` для ЭТОГО шва
  (шов `DriveHandleFactory` — не тронут). `NodeResource::handler()` снял гвард
  `!app()->bound(...)`: drive-only хост получает реальный preview/download-дескриптор вместо
  `null` — это и есть выгода D10.
  **Схема (L3).** `PanelTablesBuilder::defineDocumentVersions` — `format` без
  `->default('tiptap')`. Два write-path реально полагались на этот дефолт при ДОПИСЫВАНИИ новой
  версии существующему документу: `UpdateDocumentContentAction` (автосейв) и
  `PersistYjsSnapshotAction` (yjs-снапшот) — оба теперь несут формат головы вперёд
  (`$head?->format ?? DocumentType::RichText->value`). Следствие (BC): у markdown/univer-документа
  дописываемые строки штамповались `'tiptap'`, теперь — реальным форматом.
  `CreateNamedVersionAction` — литерал `'tiptap'` → `DocumentType::RichText->value`.
  **Тесты.** Новые: `core/tests/Feature/HandlerBaselineTest.php` (3 кейса, red-before-fix
  проверен снятием тега), `documents/tests/Document/VersionFormatCarryForwardTest.php` (3 кейса).
  Перевёрнут по смыслу `VaulterManagerExporterHandlerSeamTest` — вместо «fail-loud без
  vaulter-documents» пиннит ОБРАТНОЕ: реестр резолвится и `registerHandler()` работает БЕЗ
  пакета. `ArchTest` — новый гейт финальности `Vaulter\Engines`. `UpgradeGuideCoverageTest` —
  новый data-кейс, привязывающий секцию UPGRADE.md к факту привязки в `VaulterServiceProvider`.
  **Доки/BC.** UPGRADE.md — секция P0.4 (таблица 6 old→new FQCN, смена значения `format`,
  value-space колонки, отсутствие backfill, шаг для хоста с опубликованным конфигом); CHANGELOG
  `[Unreleased]` — два BREAKING-буллета (переезд движка · `format`). Обновлены 12 файлов доков
  (en+ru: architecture, document-handlers, recipes, reference/{contracts,configuration,facade}).
  **Гейты:** `pint --test` → passed; `phpstan analyse` (max, монорепо) → passed, 0 errors;
  root `pest` (core) → 491/491, 1271 assertion; `packages/documents` `pest` → 212 passed /
  1 skipped, 527 assertions; `pest --type-coverage --min=100` → 100.0% обе стороны.
  **Ревью.** `vaulter-api-surface` → clean move-only diff: 6 FQCN переехали, ноль изменений
  сигнатур публичных контрактов, config-ключи сменили владельца (core baseline + documents
  add-on). `vaulter-reviewer` → **needs-work**, 2 HIGH + 3 MED + 1 LOW; оба HIGH закрыты
  отдельным коммитом `651b54c`:
  - **(HIGH-1)** `hasConfigFile()` мержит через shallow existing-wins `mergeConfigFrom` — хост,
    опубликовавший конфиг ДО P0.4, несёт литеральный `'handlers' => []`, который ВЫИГРЫВАЕТ у
    нового core-дефолта и молча выключает `PreviewHandler` ровно у drive-only хоста, ради
    которого делался item. Починено тегом `'vaulter.handlers'` в core-провайдере (второй,
    конфиг-независимый источник; реестр дедуплицирует по FQCN) — заодно снята неявная
    зависимость merge от порядка регистрации провайдеров (MED-5). Регресс-тест + шаг в UPGRADE.
  - **(HIGH-2)** UPGRADE.md утверждал «все writer'ы и так ставили `format` явно» и «behaviour
    unchanged» — ложь, опровергаемая собственным диффом item'а. Секция переписана честно;
    CHANGELOG получил отдельный BREAKING-буллет про смену значения `format` (MED-3 про
    `'tiptap'`→`'richtext'` фолбэк закрыт там же; MED-4 про смешанный value-space колонки и
    отсутствие backfill — задокументирован как известное свойство).
  **Commits:** `73b135e` — refactor(core)!: движок реестра форматов переезжает в core; format без
  DB-дефолта (P0.4); `651b54c` — fix(core): findings ревью P0.4 — baseline-хендлеры через тег,
  честные доки по format (P0.4).

  **Pending Work:**
  1. [MED-4, ревью] **`format` — смешанный value-space, каста/валидации нет.** Колонка хранит и
     значения `DocumentType` (`richtext`/`table`/`markdown`/`template`), и handler-id (`univer`;
     исторически `'tiptap'` из DB-дефолта — не валидный `DocumentType`). Backfill не шипается,
     старые строки сохраняют что записано. Задокументировано в UPGRADE.md как advisory-label
     (хендлер резолвится от mime, не от колонки — D4), но унификация словаря — отдельное решение
     (кандидат в P0.5, где живёт `format`-дискриминатор).
  2. **`CreateDocumentNodeData::$format` дефолт = `'tiptap'`** (`documents/src/Data/Documents/`) —
     тот же класс проблемы, что и снятый DB-дефолт: строка не валидный `DocumentType`, бьёт
     только по прямой программной конструкции DTO (HTTP-путь всегда задаёт формат через
     `CreateDocumentNodeRequest::versionFormat()`). Не трогал: Scope Included item'а — схема +
     переезд движка; смена дефолта публичного DTO — отдельное BC-решение. → P0.5.
  3. [LOW-6, ревью] Фолбэк `$head?->format ?? RichText` в автосейве/снапшоте может записать
     `'richtext'` не-рич-текст узлу, если у документа нет головы (`current_version_id === null`).
     Достижимость низкая (Create/Restore всегда перенаводят голову), не хуже прежнего `'tiptap'`;
     инлайн-комментарий честен. Оставлено как есть.
  4. Kind-нейтральный `NodeExportService::contentOf()` (Pending Work P0.2/P0.3) — НЕ тронут, как
     и планировалось; ждёт P0.5.
  5. Gate-scope gap CI (Pending Work P0.3 #1) — не тронут, вне Scope.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P0.4 **opus/high**; фактически исполнено
     **opus/medium** (сессия на `claude-opus-4-8`, effort — дефолт команды `/task:plan-exec`,
     которая пинит medium и запрещает ручное переключение). Модель совпала, effort ниже
     заявленного; риск снят обязательными прогонами `vaulter-api-surface` + `vaulter-reviewer`
     (оба в Routing item'а) — reviewer вернул needs-work с 2 HIGH, оба закрыты отдельным коммитом.
  2. Приёмка item'а («миграция без `default`») выполнена на уровне `PanelTablesBuilder` —
     единственного column-source, который читают и свежая установка, и `panel:migrations`.
     Отдельная ALTER-миграция НЕ шипается: пакет пре-1.0 без тегированного релиза (`[Unreleased]`),
     уже мигрированная БД сохраняет свой column-default до перераската схемы — ничего не ломается,
     т.к. writer'ы на него больше не полагаются. Зафиксировано в UPGRADE.md.
  3. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P0.4 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.

  **Status:** 🟠 Done with deviations

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

  **Completion Notes.** **L4 (richText-дефолт).** `HandlerCapabilities::$richText` дефолт
  `true`→`false` (kind-нейтраль: незаявленная capability не выводится, а не «предполагается
  rich-text»). `TipTapHandler::capabilities()` теперь объявляет `richText: true` явно (было
  implicit — поведение не изменилось, изменилась декларация); `UniverHandler` уже объявлял
  `richText: false` явно, не тронут. Независимый fallback `true` в
  `UpdateDocumentContentRequest::isRichText()` (ветка «нет забинженного узла» — недостижима
  через нормальный route-model-binding, без известных потребителей) синхронизирован на `false`.
  **Kind-нейтральный read-path экспорта** (Pending Work P0.2/P0.3/P0.4): `NodeExportService::
  contentOf()` теперь резолвит хендлер узла и ветвится по `richText` — rich-text узел
  по-прежнему decay'ится через `TipTapContent` байт-в-байт (без изменений для TipTap), любой
  другой редактируемый хендлер возвращает `OpaqueDocumentContent` через новый аксессор
  `NodeDocumentVersion::opaqueContent()`. Существующий guard `HtmlExporter`/`MarkdownExporter`
  (`UnsupportedDocumentContentException`) теперь реально достижим для non-rich-text узла —
  вместо молчаливого пустого экспорта отдаёт 4xx. **`CreateDocumentNodeData::$format`** дефолт
  `'tiptap'` (невалидный `DocumentType`, обнаружено ревью P0.4) → `DocumentType::RichText->value`.
  **DX-каркас:** `Vaulter\Engines\Handlers\AbstractFileHandler` — опциональная база для
  стороннего хендлера (дефолтит только `extensions() => []`); встроенные хендлеры её НЕ
  наследуют (остаются `final`) — arch-тест `ArchTest.php` несёт точечный `->ignoring(...)`
  ровно на этот один FQCN, инвариант «core-engine internals = final» не ослаблен. Гайд
  `docs/guide/writing-an-engine.md` (+ ru-зеркало, вписан в vitepress-сайдбар обоих локалей) —
  чек-лист: capability-policy инварианты (3 правила из research), payload-асимметрия
  rich-text-class vs WOPI/office-class, `schemaVersion`-совет, граница `@univerjs/*` vs
  `@univerjs-pro/*`. **Capability-policy тесты** (`CapabilityPolicyTest.php`, core): дефолт
  `richText=false`, сериализация `rich_text` в `toArray()`, резолв хендлера, который вообще не
  упоминает `richText` (аддитивность оси), приоритет-конфликт двух editable-хендлеров на одном
  расширении (ONLYOFFICE-класс кейса — существующий `DocumentHandlerRegistryTest.php:114` уже
  покрывал related-кейс Univer-vs-DownloadOnly, новый тест — два editable-конкурента). **Фронт:**
  `HandlerCapabilities` в `@vaulter/vue` `types.ts` — обнаружен и закрыт реальный пробел
  (`rich_text` вообще не было в TS-типе, хотя бэкенд его всегда сериализовал) + открытая
  индекс-сигнатура `[capability: string]: boolean | undefined` (аддитивность зеркалит паттерн
  уже существующего `Abilities`). **D11 закрыт = вариант A** (§8.5) — `yjs_state` остаётся,
  без кода (сохранение статус-кво), запись в Decision Log.
  **Доки/BC.** UPGRADE.md — новая секция P0.5 (richText-flip, кто ломается, `format`-дефолт,
  kind-нейтральный export, фронт-тип); убрана стухшая P0.4-нота «contentOf() всё ещё open» (её
  закрыл этот item). CHANGELOG `[Unreleased]` — 2 буллета (BREAKING richText-дефолт;
  contentOf()-корректность). `docs/reference/contracts.md` — секция про `AbstractFileHandler`
  + ссылка на новый гайд.
  **Гейты:** `pint --test` → passed (весь монорепо, включая мой diff); `phpstan analyse`
  (max, монорепо) → passed, 0 errors; core `pest` → 496/496, 1280 assertions (+1 файл
  `CapabilityPolicyTest`, 4 теста); `pest --type-coverage --min=100` (core) → 100.0%;
  documents `pest` → 214 passed / 1 skipped, 531 assertions (+1 файл
  `NodeExportKindNeutralTest`, 2 теста); `pest --type-coverage --min=100` (documents) →
  100.0%; `@vaulter/vue`: `vue-tsc --noEmit` → clean, `vitest run` → 10 files / 104 tests
  passed, `vite build` → ✓ built.
  **Ревью.** `vaulter-api-surface` → surface diff подтверждён как задокументированный byte-
  for-byte в UPGRADE/CHANGELOG (перекрёстная сверка каждого прод-файла против цитаты из
  доки), 0 находок. `vaulter-reviewer` → **PASS**, явно верифицировал: (1) TipTap read-path
  byte-for-byte сохранён (то же `tipTapContent()`→`decayFrom`, sanitize на рендере не тронут);
  (2) arch carve-out `AbstractFileHandler` точечный, не ослабляет инвариант; (3) тесты честные
  (реальные HTTP-роуты, реальный `UniverHandler`, не форс конфига/биндингов) — 0
  `[POSSIBLE-DEFECT]`; 3 LOW-заметки без требуемых правок (латентный баг `format`-дефолта
  оказался серьёзнее косметики — не только rename; стабильность сортировки в
  priority-тесте — nice-to-have; `isRichText()`-fallback — честно задокументированное
  edge-поведение).
  **Commits:** `2c40793` — refactor(core)!: richText кind-нейтральный дефолт + kind-нейтральный
  экспорт (P0.5).

  **Pending Work:**
  1. [MED-4, наследуется из P0.4 ревью] **`format` — смешанный value-space, каста/валидации
     нет.** Не тронуто этим item'ом (Scope Included — richText/export/DX, не унификация
     словаря `format`). Остаётся кандидатом на отдельное решение вне текущего P0-скоупа плана.
  2. [Nit] `DocumentHandlerRegistryTest.php` уже содержал priority-конфликт-кейс
     (Univer-vs-DownloadOnly, строка 114) — `CapabilityPolicyTest.php` добавляет второй,
     более прямой ONLYOFFICE-класса кейс (два editable-хендлера). Не дублирование:
     разные утверждения (специфичный vs null-object, и editable vs editable).
  3. Gate-scope gap CI (Pending Work P0.3 #1, P0.4 #5) — не тронут, вне Scope этого item'а;
     остаётся кандидатом в отдельный CI/gate-item.
  4. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига
     (`Vaulter\Documents\Handlers\TipTapHandler` вместо актуального
     `Vaulter\Documents\Documents\Handlers\TipTapHandler`; `Vaulter\Enums\Documents\Capability`
     вместо `Vaulter\Enums\Handlers\Capability`) — дрейф со времён P0.1/P0.4 renames, НЕ внесён
     этим item'ом. Не чинил (вне Scope Included — я только добавлял новый файл-гайд и
     ссылки на него, не редактировал существующий `document-handlers.md`).

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P0.5 **sonnet/medium** (Q2-решение — opus/high,
     но Q2/D11 оказался простой развилкой с готовой рекомендацией, закрыт без кода);
     фактически исполнено **sonnet/medium** — совпадает, отклонений нет.
  2. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P0.5 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done

### P1 — Упаковка/дистрибуция фронт-движков + формализация фронт-реестра (Часть 1)

- **P1.1 — Закрытие D8 owner'ом: модель упаковки + нейминг фронт-пакетов** (opus/high). **Гейт для P1.2/P1.3.**
  Research завершён — рекомендация в §8.2 (единый `@vaulter/vue`; per-engine npm — только при реальном
  внешнем потребителе; один version-train с PHP). Owner подтверждает/выбирает альтернативу из §8.2;
  при альтернативе — P1.2/P1.3 передетализировать ДО старта (эскалация в design, не импровизация).
  **Приёмка:** D8 закрыт (дата+вердикт в Decision Log); нейминг-решение в Completion Notes.

  **Completion Notes.** Owner подтвердил вариант A (§8.2) буквально, без альтернативы —
  запрошено прямым вопросом в сессии `/task:plan-exec VLT-CANON P1.1` (2026-07-13, Dmitry
  Vostrikov). Модель упаковки: единый `@vaulter/vue` остаётся shell/core, TipTap-движок живёт
  внутри него на одном version-train с PHP; per-engine npm (`@vaulter/vue-engine-<name>`)
  вводится лениво — только при появлении реального внешнего потребителя конкретного движка.
  Кода/структуры пакетов эта запись НЕ меняет (D8 — решение о БУДУЩЕЙ политике, не о
  сегодняшнем рефакторинге); P1.2/P1.3 остаются в своей текущей детализации (написаны под
  D8=A) — эскалация design не требуется. Решение закрыто записью D8 в Decision Log
  (§5, дата+вердикт).
  **Commits:** — (решение задокументировано в plan.md; кода нет).

  **Pending Work:** —

  **Known Deviations:**
  1. Routing-adherence: §Routing требует **opus/high**; фактически исполнено **opus/medium**
     (сессия на `claude-opus-4-8`, effort — дефолт команды `/task:plan-exec`, которая пинит
     medium и запрещает ручное переключение). Модель совпала, effort ниже заявленного; риск
     низкий — item решает готовую owner-развилку с research-обоснованной рекомендацией, не
     содержит технической реализации.
  2. `plan-lint.py` красный ДО и ПОСЛЕ P1.1 (не регресс, наследуется из P0.1–P0.5): линтер
     требует `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в
     `## 6. Phases (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с
     момента создания плана. Структуру плана правит plan-design, не exec.

  **Status:** 🟠 Done with deviations

- **P1.2 — Формализация фронт-реестра компонентов как публичного шва** (opus решение → sonnet).
  `VaulterDocumentNode.vue` `componentMap` (по `handler.id`/`ui.component`) — сейчас prop-шов. Формализовать
  как публичный registry-API — **явная экспортируемая функция** (`registerEngineComponent(id, component)`
  или plugin-опция), НЕ implicit-глобал (урок Nextcloud Viewer: ушли от `OCA.Viewer.registerHandler`-глобала
  к explicit ES-module import — research F10). Dev-mode warning, когда `handler.id` без зарегистрированного
  компонента (известная боль Nova: string-key без проверки — research F3). Задокументировать ключи,
  экспортировать типы. **view НЕ переписываем** — только формализуем шов (директива брифа).
  **Приёмка:** публичный фронт-API задокументирован; типы экспортированы; dev-warning есть;
  `vue-tsc`+vitest+build зелёные; обратная совместимость prop `componentMap` сохранена.

  **Completion Notes.** Новый модуль `packages/vue/src/engineRegistry.ts` — module-level
  `Map<string, Component>`-реестр за 5 явными функциями: `registerEngineComponent(id, component)`,
  `unregisterEngineComponent(id)`, `resolveEngineComponent(id)`, `registeredEngineComponentIds()`,
  `clearEngineComponents()`. Explicit ES-module import/call, НЕ implicit-глобал (урок Nextcloud
  Viewer — research F10). `VaulterDocumentNode.vue`: `resolved` computed теперь проверяет
  `componentMap[uiKey] ?? componentMap[h.id] ?? resolveEngineComponent(uiKey) ??
  resolveEngineComponent(h.id)` — `componentMap` prop СТРОГО первым (`??`, не `||`), реестр —
  фолбэк; порядок и дефолт `componentMap` не менялись, BC сохранена байт-в-байт (подтверждено
  `vaulter-api-surface`). Dev-mode `console.warn` (once per unmapped `ui.component`/`id` в
  пределах одного инстанса компонента) вынесен в отдельный `watch([handler, resolved], ...)` —
  computed остался чистым (находка ревью MED, см. ниже). `isDevMode()` — `process.env.NODE_ENV
  !== 'production'` (не `import.meta.env`, т.к. пакет — plain library без Vite-типов в tsconfig).
  Экспорты реестра добавлены в `packages/vue/src/index.ts`. View (`VaulterDocumentNode.vue`
  template) НЕ переписан — только script-логика резолва (по директиве брифа). Тесты — новый файл
  `packages/vue/tests/engine-registry.test.ts` (10 кейсов): CRUD реестра, componentMap-precedence
  над реестром, mount через реестр как фолбэк, fallback-slot при отсутствии в обоих источниках,
  warn-once-per-instance, отсутствие warn при резолве через реестр. Доки (en+ru):
  `docs/guide/document-handlers.md` §«Mounting the resolved handler in Vue» — два источника
  сопоставления explicit, пример `registerEngineComponent` в `main.ts`, обновлён §Reference
  (5 фронтовых экспортов); `docs/guide/writing-an-engine.md` §7 — упомянут реестр как альтернатива
  `componentMap`. CHANGELOG.md `[Unreleased] Added` — запись про реестр. UPGRADE.md НЕ тронут:
  изменение чисто аддитивное (SemVer MINOR), без обязательного действия потребителя (файл по
  своему объявленному назначению покрывает только breaking-changes).
  **Гейты:** `vue-tsc --noEmit` → clean; `vitest run` → 112/112 (10 файлов, +8 новых тестов);
  `vite build` → ✓ built (dist/vaulter-vue.js 78.32 kB gzip 19.04 kB).
  **Ревью.** `vaulter-api-surface` → clean, чисто аддитивная поверхность (5 новых экспортов),
  `componentMap` BC подтверждена bit-for-bit, ноль изменений config-ключей/дескриптора (PHP-сторона
  вне скоупа item'а). `vaulter-reviewer` (первый прогон) → **needs-work**, 1 HIGH + 1 MED + 1 LOW,
  все закрыты отдельным коммитом `ea7eacb`: (HIGH) `docs/ru/guide/document-handlers.md` нёс
  задублированный блок §«Или зарегистрируйте…» (copy-paste ru-зеркала) — второй экземпляр удалён;
  (MED) side-effect (`console.warn`+мутация `Set`) жил внутри `computed`-геттера (нарушение
  чистоты реактивности, геттер может пересчитываться спекулятивно вне цикла рендера) — вынесен в
  `watch`; (LOW) не было теста на «warn once» и на отсутствие warn при резолве через реестр —
  добавлены 2 кейса; заодно уточнена формулировка доков: warn не повторяется в пределах ОДНОГО
  инстанса компонента, не приложения целиком (было неточно сформулировано). `vaulter-reviewer`
  (второй прогон, после `ea7eacb`) → **approve**, 2 LOW не блокирующих: (1) `resolveEngineComponent`
  — не реактивный lookup (`Map`, не `ref`/`reactive`) — уже смонтированный узел не переподбирает
  компонент по пост-mount регистрации; (2) `watch([handler, resolved], ...)` без `immediate: true`
  полагается на то, что `handler` стартует `null` и асинхронно населяется (верно для текущего
  `loadAll()`-пути). Оба — не дефекты (штатный сценарий их не задевает), задокументированы явной
  фразой в `document-handlers.md` (en+ru) отдельным коммитом `7304721`.
  **Commits:** `879d370` — feat(vue): формализовать фронт-реестр компонентов движков как публичный
  API (P1.2); `ea7eacb` — fix(vue): findings ревью P1.2 — чистый computed, дедуп ru-доки, тест на
  warn (P1.2); `7304721` — docs(vue): уточнить нереактивность engine-реестра в доках (P1.2).

  **Pending Work:** —

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P1.x **opus/high (решение) → sonnet (механика)**;
     решение (D8) уже закрыто в P1.1, этот item — чистая механика формализации шва, исполнено
     `sonnet/medium` — совпадает с ожиданием для механики, отклонений нет.
  2. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P1.2 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done

- **P1.3 — Скелет-пакет/гайд стороннего фронт-движка** (sonnet/low).
  По D8: skeleton (`docs/guide/writing-an-engine.md` фронт-часть ИЛИ example-пакет) — как хост регистрирует
  компонент по имени движка + получает `HandlerUi.props`. **Приёмка:** гайд/скелет проходит собственный build.

  **Completion Notes.** Выбран вариант «гайд», не example-npm-пакет — согласуется с D8 (единый
  `@vaulter/vue`, engine внутри хост-кода, без отдельной npm-инфраструктуры до реального внешнего
  потребителя). Расширен §7 «Mounting it on the client» в `docs/guide/writing-an-engine.md`
  (+ru-зеркало `docs/ru/guide/writing-an-engine.md`) конкретным клиентским скелетом: `.vue`-компонент
  (`AppMyEditor.vue`), типизированный `DocumentNodeEditorProps` (реальный экспорт `@vaulter/vue` из
  `VaulterDocumentNode.vue`), сужающий `handler.ui.props` до собственной формы (`MyEditorProps`);
  плюс блок регистрации через `registerEngineComponent(id, component)` в `main.ts` (уже формализован
  P1.2). Явно зафиксировано: отдельный npm-пакет НЕ нужен — движок ставляется как код хост-приложения
  (bullet-заключение после примера, зеркалит D8). Ссылка на §2 «Mounting the resolved handler in Vue»
  (`document-handlers.md`) сохранена как источник двух каналов регистрации — не задублирована.
  **Гейты.** `pnpm install` (root — `vitepress`/`vue-tsc` не были установлены в node_modules до этого
  item'а); `pnpm run docs:build` — **failed** на pre-existing dead link `./activity` в
  `guide/search-trash.md`/`ru/guide/search-trash.md` (файлы НЕ мои — `git status --porcelain` до и
  после диффа их не показывает; не regression этого item'а). Для верификации собственного диффа
  временно переключил `docs/.vitepress/config.ts` `ignoreDeadLinks: false→true`, прогнал
  `docs:build` → **passed** (client+server bundles + rendering pages, `build complete`), откатил
  конфиг (`git checkout -- docs/.vitepress/config.ts`, дифф не коммитился). `@vaulter/vue`
  typecheck/vitest/build не гонял целевым запуском — item не тронул `packages/vue/src/**`
  (`git status --porcelain` подтверждает: единственные code-relevant файлы диффа — 2 docs + plan.md).
  **Ревью:** `vaulter-reviewer` не вызывал — item не трогает публичный контракт/фасад/config-ключи/
  дескриптор (Execution Rules §«Публичная поверхность» — гейт применяется к item'ам, трогающим
  Contracts/фасад/config/дескриптор; P1.3 — чистая документация примера, ничего из перечисленного не
  меняет).
  **Commits:** `<TBD>` — docs(vue): скелет-пример клиентского движка + регистрация в
  writing-an-engine.md (P1.3).

  **Pending Work:**
  1. Pre-existing dead link `./activity` в `guide/search-trash.md`/`ru/guide/search-trash.md` ломает
     `docs:build` (наследуется, не мой файл) — блокирует CI docs-гейт независимо от P1.3. Не чинил
     (вне Scope Included — item не называет `search-trash.md`). Кандидат в отдельный docs-fix item.
  2. `docs/guide/document-handlers.md` стухшие FQCN в примерах конфига (наследуется из P0.5 Pending
     Work #4, handoff Open risk 3) — не трогал, вне Scope этого item'а.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P1.3 **sonnet/low**; фактически исполнено
     **sonnet/medium** (effort — дефолт команды `/task:plan-exec`, которая пинит medium и запрещает
     ручное переключение). Модель совпала, effort выше заявленного — расхождение в сторону большей
     тщательности, не риск.
  2. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P1.3 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.
  3. Docs-build гейт item'а («гайд/скелет проходит собственный build») подтверждён с ОБХОДОМ
     pre-existing несвязанной dead-link ошибки (см. Completion Notes/Pending Work #1) — сам
     `docs:build` без обхода красный уже ДО этого item'а по причине, не относящейся к P1.3.

  **Status:** 🟢 Done

### P2 — Финальный структурный канон (Часть 3)

- **P2.1 — Переименование `permissions-azgard → azgard`** (opus/high, `php:namespace-refactor-safety`).
  dir `packages/permissions-azgard/`→`packages/azgard/`; composer `vaulter-permissions-azgard`→`vaulter-azgard`;
  namespace `Vaulter\Permissions\Azgard\`→`Vaulter\Azgard\`. Контракт-шов `Vaulter\Contracts\Access\AzgardGuard`
  (в CORE) — НЕ трогаем. Обновить: composer path-repos, root `composer.json`, CI-lane, `testbench.yaml`,
  arch-guard'ы, docs. **Приёмка:** гейт azgard зелёный; core не сломан; BC UPGRADE/CHANGELOG; arch «0 ссылок
  core→azgard» зелёный.

  **Completion Notes.** Hard rename без alias-фазы (пре-1.0), `git mv` — renames трекаются (27 файлов
  пакета + workflow, similarity 100% на всех, кроме тронутых namespace-правкой). Переименовано:
  dir `packages/permissions-azgard/` → `packages/azgard/`; composer `axioma-studio/vaulter-permissions-azgard`
  → `axioma-studio/vaulter-azgard` (`name`, `autoload.psr-4`, `autoload-dev.psr-4`,
  `extra.laravel.providers`); namespace `Vaulter\Permissions\Azgard\*` → `Vaulter\Azgard\*` во всех
  4 `src/` + 12 `tests/` файлах (FQN-anchored `sed` на полный `Vaulter\Permissions\Azgard`, ловушки
  префикс-коллизии нет — токен уникален). CI: `.github/workflows/permissions-azgard.yml` → `azgard.yml`
  (path-фильтры, 4× `working-directory`, job id, display-name, self-reference); `monorepo-split.yml` —
  matrix-энтри + prerequisites. Core: `DocsDriftGuardTest.php:42` (путь `packages/azgard/src`) +
  **новый arch-гвард** `core source never references the vaulter-azgard package (P2.1)` в
  `ArchTest.php` через существующий `coreReferencesTo('Azgard')` (тот же shared-хелпер, что у
  drive/documents-гвардов; ревьюер верифицировал не-vacuous: подсаженный `use Vaulter\Azgard\*` в
  копии core/src → тест падает). Доки: root `README.md` (матрица CI + дерево пакетов),
  `docs/reference/contracts.md` (FQCN `AzgardBackedUser`), `rector.php` (комментарий),
  `packages/azgard/README.md`, `phpunit.xml` (testsuite name). BC: `UPGRADE.md` (новая секция —
  таблица old→new FQCN, «who breaks», нота о неизменности config/publish-тега) + `CHANGELOG.md`
  `[Unreleased]`.
  **Не тронуто (по Scope):** `Vaulter\Contracts\Access\AzgardGuard` + `Vaulter\Testing\FakeAzgardGuard`
  + `Vaulter\Drivers\Permission\AzgardPermissionResolver` в core — контракт-шов и драйвер остаются на
  своих FQCN (`git diff HEAD -- packages/core/src` → пусто). Config `config/vaulter-azgard.php`,
  merge-ключ `vaulter-azgard`, publish-тег `vaulter-azgard-config` — байт-идентичны, только путь
  переехал. Root `composer.json` правки не потребовал: path-repo = wildcard `packages/*`. `testbench.yaml`
  azgard-провайдера не несёт — правки не потребовал.
  **Гейты:** `pint --test packages/core packages/azgard` → passed (pint прогнан перед коммитом,
  авто-фикс `ordered_imports`/`braces_position` после смены namespace); `phpstan analyse` (max,
  монорепо) → passed, 0 errors; root `pest` (core) → **497/497, 1281 assertion**;
  `pest --type-coverage --min=100` → 100.0%; гейт самого пакета из его vendor
  (`packages/azgard`): `pint --test` → passed, `phpstan analyse` → OK, `pest --ci` → **20 passed,
  50 assertions** (после `composer dump-autoload` — PSR-4-мапа старого namespace висела в
  локальном vendor'е).
  **Ревью.** `vaulter-api-surface` → **PASS**, clean rename-only diff: ноль изменений сигнатур,
  возвращаемых типов, дефолтов, добавленных/удалённых public-методов; core-шов не тронут; config
  байт-идентичен. `vaulter-reviewer` → **approve**, 2 MED + 2 LOW, все MED/LOW-в-скоупе закрыты
  отдельным коммитом `987cb3a`:
  - **(MED-1)** `[Unreleased]` сам себе противоречил: секция `Added` рекламировала мост под
    СТАРЫМ именем, пока `Changed` объявлял его переименование — при том что пакет **ни разу не
    публиковался** (тегов в репо нет, split-пайплайн `on: push: tags`). `Added` → новое имя;
    BREAKING-буллет переформулирован честно (не слом опубликованного пакета — правка дев-ветки);
    UPGRADE «Who breaks» аналогично: потребитель с Packagist мигрировать не должен.
  - **(MED-2)** `docs/adr/0017-package-structure-canon.md` (**нормативный** канон структуры,
    Status: accepted, на него ссылается UPGRADE) держал `permissions-azgard` в составе монорепо →
    обе строки на новое имя + amendment-заметка.
  - **(LOW-4)** `monorepo-split.yml` prerequisites не говорили, что делать с уже созданным
    split-репо под старым именем → пункт 4 (удалить/abandon).
  **Commits:** `2c3b9cb` — refactor(azgard)!: переименование пакета permissions-azgard → azgard
  (P2.1); `987cb3a` — docs(azgard): findings ревью P2.1 — честный CHANGELOG, ADR-0017,
  осиротевший split-таргет (P2.1).

  **Pending Work:**
  1. [MED-3, ревью, **вне дерева репо**] **Required status checks в branch protection `main`.**
     Сменились и job id (`permissions-azgard`→`azgard`), и display-name
     (`vaulter-permissions-azgard (SQLite lane, Laravel 12.*)` → `vaulter-azgard (...)`). GitHub
     матчит required checks **по имени**: если старые имена прописаны в branch protection, PR'ы
     повиснут в pending навсегда; если не прописаны — azgard-гейт молча выпадет из merge-gate.
     Чинится в настройках репозитория (owner), не коммитом.
  2. [LOW-5, ревью] `docs/plan/00-master-plan.md:54,539` и `docs/plan/p2-5-option-b-topology.md`
     держат `packages/permissions-azgard` / `Vaulter\Permissions\Azgard\` в НАСТОЯЩЕМ времени
     («✅ СДЕЛАНО как отдельный пакет `packages/permissions-azgard`»). `docs/plan/` — исторический
     срез (сам план объявляет себя удаляемым после реализации), правка — осознанное решение
     отдельным item'ом (кандидат в P3.1 docs), не молчаливый sed.
  3. [пред-существующее, ревью] `packages/azgard/README.md` утверждает, что `tests/RealAzgard/`
     «runs in the monorepo / host CI, not Vaulter's standalone matrix», а `azgard.yml` гоняет
     `vendor/bin/pest --ci` БЕЗ фильтра — т.е. включая RealAzgard. Расхождение доки и CI было и
     ДО P2.1 (не регресс), вне Scope Included.
  4. Наследуемое из P0.x/P1.x и не тронутое: `format` value-space (MED-4 P0.4), CI gate-scope gap
     XSS-барьера (P0.3 #1), стухшие FQCN в `document-handlers.md`, pre-existing dead link
     `./activity` в `search-trash.md`.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P2.1 **opus/high**; фактически исполнено
     **opus/medium** (сессия на `claude-opus-4-8`, effort — дефолт команды `/task:plan-exec`,
     которая пинит medium и запрещает ручное переключение). Модель совпала, effort ниже
     заявленного; риск снят обязательными прогонами `vaulter-api-surface` + `vaulter-reviewer` —
     оба отработали, находки ревью закрыты отдельным коммитом.
  2. Приёмка item'а называет точками правки `composer path-repos`, root `composer.json` и
     `testbench.yaml` — фактически **ни один из трёх не потребовал изменений**: root path-repo =
     wildcard `packages/*` (rename прозрачен), root `composer.json` не `require`'ит мост,
     `testbench.yaml` не несёт azgard-провайдера. Формулировка приёмки шире фактической
     поверхности; отсутствие диффа в этих файлах — не пропуск, а факт (верифицировано grep'ом
     и ревьюером).
  3. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P2.1 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟠 Done with deviations

- **P2.2 — [Развилка D9/§8.3] media→drive?** (opus решение → sonnet если сливаем).
  Решить §8.3. Рекомендация — ОСТАВИТЬ standalone (media = чистый CAS-движок). Если owner выберет слияние —
  перенести `media/src/{Cas,Console}` в `drive/src/`, drive поглощает CAS, обновить `Contracts\Cas\*`-биндинги,
  убрать `vaulter-media` из require, CI-lane. **Приёмка:** решение зафиксировано D9; при слиянии — гейт drive
  зелёный, цикла нет, BC-запись.

  **Completion Notes.** Owner (Dmitry Vostrikov, интерактивный вопрос в `/task:plan-exec VLT-CANON
  P2.2`) выбрал **вариант A — `vaulter-media` остаётся standalone-пакетом**. Обоснование
  (§8.3): media = переиспользуемый CAS-движок (topology-решение ADR-0008); `documents` его не
  требует; `drive` и так несёт `requires media` — установка для конечного потребителя не
  меняется; слияние сломало бы «media = чистый движок» без продуктовой выгоды, потребовало бы
  BC-переноса `Vaulter\Media\*`→`Vaulter\Drive\*` и заставило бы file-tree-only хостов (без CAS)
  тянуть блоб-стор бесплатно. Кода нет — решение фиксируется записью D9 в Decision Log (см.
  ниже); дерево пакетов и namespace'ы не тронуты.
  **Гейты:** без изменений (нет диффа кода), N/A.
  **Ревью:** не требовалось (нет публичной поверхности, нет кода).
  **Pending Work:** —
  **Known Deviations:**
  1. Routing-adherence: §Routing требует opus/high для решения D9; фактически исполнено
     sonnet/medium (модель/effort этой сессии — дефолт `/task:plan-exec`, ручное переключение
     запрещено протоколом). Решение принял owner напрямую интерактивным вопросом (тот же
     прецедент, что D7/D8) — риск снят: развилка не требовала анализа кода/архитектуры сверх уже
     готового §8.3 в плане, только выбор варианта.
  **Status:** 🟢 Done

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

  **Completion Notes.** Роль-based разбор трёх catch-all папок, `git mv` (renames трекаются,
  9 файлов) + обновление namespace/`use` во всех потребителях (48 файлов: src/tests/config/docs
  обоих пакетов + `core/tests/Arch/ArchTest.php` + `core/tests/Support/UpgradeGuideCoverageTest.php`).
  **documents (реестр/резолвер уже уехал в core в P0.4):**
  `Documents/Handlers/{TipTapHandler,UniverHandler}` → `Engines/Handlers/*`
  (`Vaulter\Documents\Engines\Handlers`, зеркалит `Vaulter\Engines\Handlers` core);
  `Documents/NodeExportService` → `Export/NodeExportService` (`Vaulter\Documents\Export`) —
  присоединился к уже существующей domain-service папке рядом с `HtmlExporter`/`MarkdownExporter`
  (ADR-0017 допускает domain-service folders на корне пакета);
  `Documents/CollaborationSessionService` → `Collaboration/CollaborationSessionService`
  (`Vaulter\Documents\Collaboration`) — присоединился к `NodeCollaborationDriverManager`/драйверам
  в уже существующей папке `Collaboration/`. **Решение о роли:** буква item'а называла целью
  `Support/`/`Actions/` для этих двух read-only-evaluator сервисов; вместо этого они
  присоединены к уже существующим одноимённым domain-service папкам (`Export/`, `Collaboration/`)
  — точнее отражает роль по факту (`Actions/` в документах = мутации, ни один из двух сервисов
  не мутирует; `Support/` = движок-нейтральные хелперы, оба сервиса domain-specific), не
  противоречит ADR-0017 и не требует эскалации (буква «по роли» допускает эту трактовку).
  `Support/{TipTapRenderer,Concerns/HasTiptapExtensions}` → `Support/TipTap/{TipTapRenderer,
  Concerns/HasTiptapExtensions}` — TipTap-only хелперы получили domain-подпространство внутри
  Support (Law 2 ADR-0017); `Support/DocumentContentHasher` — движок-нейтраль, НЕ тронут (по
  item'у). Тавтология `Documents/Documents` устранена — папка `Documents/` пуста и удалена.
  **drive:** `Uploads/{MagicByteSniffer,StagedUpload,UploadModels}` → `Support/Uploads/*`
  (`Vaulter\Drive\Support\Uploads`) — чистые хелперы; `Uploads/{UploadSessionManager,
  S3MultipartHandler,TusUploadHandler}` (`*Manager`/`*Handler`, оркестраторы) остались в
  `Vaulter\Drive\Uploads` как и было — по item'у.
  **Найдена и починена ловушка same-namespace `use`:** `TusUploadHandler`/`S3MultipartHandler`/
  `UploadSessionManager` конструктор-инжектили `StagedUpload` БЕЗ `use`-импорта (был в той же
  `Vaulter\Drive\Uploads`-namespace до переезда) — после переезда `StagedUpload` в
  `Support\Uploads` все три файла получили явный `use`; аналогично
  `CollaborationSessionService` (сама переехавшая в `Collaboration/`) держала теперь
  самоссылающийся `use NodeCollaborationDriverManager` — импорт убран. Обнаружено прогоном
  standalone-тестов пакетов (см. Гейты).
  **Тесты/арх-гейты:** core `ArchTest.php` — финальность-гейт `the document handler registry
  and its handlers are final` (H.5) сужен с целой намеспейс-стены `Vaulter\Documents\Documents`
  на точные два таргета (`Vaulter\Documents\Engines\Handlers`, `Vaulter\Documents\Export\
  NodeExportService`) — та же семантика, точнее адрес; docblock обновлён.
  `UpgradeGuideCoverageTest.php` — путь-константа P5.2-кейса на `TipTapRenderer` переставлена
  на новый файл (red-before: тест падал на `file_get_contents` несуществующего старого пути,
  что и обнаружило пробел до прогона гейтов). `drive/tests/Arch/ArchTest.php` — docblock Law 1
  теста дополнен нотой про P2.3-разбор (сам тест не меняли: `Support`/`Uploads` — не в списке
  проверяемых layer-имён, разбор не задевает инвариант). Доки (en+ru):
  `docs/reference/configuration.md` (`handlers`-таблица), `docs/guide/export.md` (FQCN примера +
  попутно исправлен пред-существующий баг доки `Vaulter\Support\TipTapRenderer`→
  `Vaulter\Documents\Support\TipTapRenderer`, у которого не хватало `\Documents\` уже ДО этого
  item'а). BC: `UPGRADE.md` + `CHANGELOG.md [Unreleased]` — новые секции (таблицы old→new FQCN
  обоих пакетов, «кто ломается»).
  **Гейты:** `pint packages/core packages/documents packages/drive --test` → passed (после
  авто-фикса `ordered_imports`/`braces_position` через `pint` без `--test`, коммит несёт
  результат); `phpstan analyse` (max, монорепо) → passed, 0 errors; root `pest` (core) →
  497/497, 1281 assertion; `packages/documents` standalone `pest` → 214 passed / 1 skipped, 531
  assertion (без изменений против P0.5-базлайна); `packages/drive` standalone `pest` → 167
  passed / 5 skipped, 525 assertion; `pest --type-coverage --min=100` → 100.0% во всех трёх
  (core/documents/drive). `@vaulter/vue` не тронут (`git status --porcelain packages/vue/` пуст)
  — гейт фронта не гонял, item не задевает фронт.
  **Ревью:** `vaulter-api-surface` → **PASS**, «clean rename-only diff»: 9 переездов, 79 файлов
  затронуто (262+/265-), 0 изменений сигнатур контрактов/интерфейсов, 0 изменений config-ключей
  (только FQCN-значения внутри массивов), 0 добавленных/удалённых public-методов; вердикт —
  BC-breaking (SemVer MAJOR) из-за host-видимых FQCN, что и задокументировано в UPGRADE/CHANGELOG.
  `vaulter-test-verifier` → все три сьюта зелёные (core 497/497; documents 214/1 skip; drive
  167/5 skip); 5 нестрашных warning'ов (SQLite FK-cascade/concurrency-лимиты, PurgeTrash*/Tus*
  тесты) — окружение, не регресс диффа. `vaulter-reviewer` НЕ вызывал: Execution Rules требует
  его после P0.1/P0.2/P1.x/P2.1, P2.3 в списке не назван (Routing P2.2–P2.4: sonnet/medium +
  `vaulter-test-verifier`, без reviewer'а); `vaulter-api-surface` покрыл BC-риск proactively
  (item трогает host-видимые FQCN, хоть и не Contracts/facade/config-ключи).
  **Commits:** `427b9ce` — refactor(documents,drive)!: разбор catch-all-папок по ролям —
  Engines/Export/Collaboration/Support (P2.3).

  **Pending Work:**
  1. Наследуемое, не тронутое этим item'ом: required status checks branch protection (P2.1),
     `docs/plan/*` стухшие имена permissions-azgard (P2.1), `format` value-space (P0.4), CI
     gate-scope gap XSS-барьера (P0.3/P0.4), `document-handlers.md` стухшие FQCN (P0.5),
     pre-existing dead link `search-trash.md` (P1.3), `packages/azgard/README.md` vs CI (P2.1).
  2. `docs/plan/{p2-5-namespace-strategy-drive-docdrive,p2-5-option-b-topology}.md` держат
     старые FQCN (`Documents\Documents`, `Drive\Uploads\StagedUpload`) в настоящем времени —
     тот же класс пред-существующего долга, что и Open risk #2 (permissions-azgard в
     docs/plan/*): исторический срез, правка — решение P3.1, не молчаливый sed этим item'ом.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P2.3 **sonnet/medium**; фактически исполнено
     **sonnet/medium** — совпадает, отклонений нет.
  2. Целевые слои для `NodeExportService`/`CollaborationSessionService` — item называет
     `Support/`/`Actions/`; фактически они присоединены к уже существующим domain-service
     папкам `Export/`/`Collaboration/` (см. Completion Notes «Решение о роли»). Это трактовка
     формулировки «по роли», не расхождение с планом по существу (файлы/классы те же, только
     точнее адресована папка, соответствующая ADR-0017 domain-service-folder канону) —
     зафиксировано явно, не молчаливое отклонение.
  3. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P2.3 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done

- **P2.4 — Access/Uploads-сверка + core `Webhooks/`** (sonnet/low).
  `documents/src/Access/HostUserResolver` — свернуть в слой documents ЛИБО подтвердить домен-сервисом;
  core `Access/`/`Webhooks/` — сверить с ADR-0017 (вероятно no-op, задокументировать вердикт).
  **Приёмка:** вердикт по каждой папке (перенос ЛИБО «канон ок, no-op») в Completion Notes.

  **Completion Notes.** Три вердикта по `findings/structure-canon-inventory.md §2.3/§2.4`:
  1. **`documents/src/Access/HostUserResolver` — ПЕРЕНОС.** Единственный класс папки не был
     access-control-классом: реализует `Vaulter\Documents\Contracts\Collaboration\HostUserResolver`
     (собственный докблок класса: «purely collaboration-webhook machinery»). `git mv` →
     `documents/src/Collaboration/HostUserResolver.php`, namespace `Vaulter\Documents\Access` →
     `Vaulter\Documents\Collaboration` — присоединился к `NodeCollaborationDriverManager`/драйверам
     в уже существующей папке `Collaboration/`. Обновлены: `use`-импорт в
     `VaulterDocumentsServiceProvider.php` (biding алиас `DefaultHostUserResolver` не менялся),
     `{@see}`-ссылка в докблоке контракта `Contracts/Collaboration/HostUserResolver.php`. Попутно
     исправлен пред-существующий баг FQCN в доках (`docs/{reference,ru/reference}/contracts.md` несли
     `Vaulter\Access\HostUserResolver` — без сегмента `\Documents\`, стухший ДО этого item'а) →
     полный правильный FQCN. Папка `documents/src/Access/` пуста и удалена.
  2. **core `Access/` — NO-OP, канон ок.** `AccessResolver`/`Abilities/`/`HostRoleDriverManager`/
     `ValueObjects/` — легитимная domain-service-папка по ADR-0017 (§«Canon A», Node-coupled ACL-движок,
     topology T1). Изменений не требуется.
  3. **core `Webhooks/` — NO-OP, канон ок.** `ADR-0017` называет `core/src/{Access,Sharing,Webhooks,Cas,
     Activity}` **эталонной формой** самого паттерна domain-service folder (не просто допустимым
     случаем) — сверка тривиально положительна, изменений не требуется.
  **Гейты:** `pint packages/documents --test` → passed; `phpstan analyse` (max, монорепо) → passed,
  0 errors; root `pest` (core) → 497/497, 1281 assertion (без изменений); `packages/documents`
  standalone `pest` → 214 passed / 1 skipped, 531 assertion (без изменений); `pest --type-coverage
  --min=100` → 100.0% обе стороны.
  **Ревью:** `vaulter-reviewer` не вызывал — Routing P2.2–P2.4 его не требует (только
  `vaulter-test-verifier`). `vaulter-test-verifier` → documents suite: 214/1 skip, PASS; core suite
  внутри агента упал (393/497) из-за окружения саб-агента (`BindingResolutionException: Target class
  [config]` — core standalone-тестсьют требует запуска из корня монорепы, известное свойство пакета,
  не регресс диффа) — перепроверено вручную из корня монорепы: 497/497 зелёные, диффа P2.4 в core нет
  вообще (`git show --stat` затрагивает только documents + docs + BC-файлы), падение саб-агента не
  связано с этим item'ом.
  **Commits:** `da3e64b` — refactor(documents)!: Access/HostUserResolver → Collaboration/HostUserResolver
  (P2.4).

  **Pending Work:** —

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P2.4 **sonnet/medium** (строка `P2.2–P2.4 |
     sonnet/medium`); фактически исполнено **sonnet/medium** — совпадает, отклонений нет.
  2. `plan-lint.py` красный ДО и ПОСЛЕ P0.1–P2.4 (не регресс, наследуется): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done

### P3 — Docs · ROADMAP · arch-тесты · supersede

- **P3.1 — ROADMAP + docs** (sonnet/low). ROADMAP: пункт «доделать `vaulter-filament` (движки/reсources под
  панель)» как задел (D6). Обновить `docs/guide/*`, `docs/reference/contracts.md`, `docs/adr/index.md`
  (новый ADR канона движков, см. P3.3). **Приёмка:** docs собираются; ROADMAP несёт Filament-пункт.

  **Completion Notes.** ROADMAP: новая секция «`vaulter-filament` — engine-aware resources» под
  «## Admin UI» — фиксирует D6 (Filament вне scope VLT-CANON) и называет seam для будущей работы
  (`HandlerRegistry`/`NodeHandlerResolver`, тот же шов, что уже потребляет Vue-клиент). Docs:
  найдены и починены 3 реальных стухших FQCN/ссылки, накопленные P0.1–P0.4 (Open risks 2/5/6
  handoff.md), все — в объявленном Scope Included (`docs/guide/*` + `docs/reference/contracts.md`):
  1. `docs/reference/contracts.md` — таблица контрактов и раздел `NodeHandlerResolver` утверждали
     «concrete resolver ships in vaulter-documents» / «fails loudly without vaulter-documents» —
     фактически неверно с P0.4 (D10): резолвер безусловно живёт и биндится в core. Переписано на
     факт (core-owned, всегда резолвится).
  2. `docs/guide/document-handlers.md` + ru-зеркало — пример «PHP — the resolver» импортировал
     несуществующий `Vaulter\Documents\NodeHandlerResolver` → `Vaulter\Contracts\Handlers\NodeHandlerResolver`
     (контракт, на который реально забинжен резолвер).
  3. `docs/guide/document-handlers.md` + ru-зеркало — `Vaulter\Enums\Documents\Capability` (не
     существует с P0.4) → `Vaulter\Enums\Handlers\Capability` (реальный FQCN).
  4. `docs/guide/search-trash.md` + ru-зеркало — dead-link `./activity.md` (страницы `guide/activity.md`
     никогда не существовало) ломал `docs:build`; перенаправлен на реальный якорь
     `/reference/rest-api#activity-feed` (ru: `/ru/reference/rest-api#лента-активности`).
  **`docs/adr/index.md` НЕ тронут** — сознательное решение exec, не молчаливый пропуск: пункт плана
  ссылается на «новый ADR канона движков, см. P3.3» — сам ADR-файл создаёт P3.3 (консолидирует
  D1–D11, там же выбирается номер `00XX`); добавление строки в индекс на несуществующий файл
  сломало бы `docs:build` (проект уже нетерпим к dead links — см. находку 4 выше). Индекс-запись
  логично закрывается вместе с созданием файла в P3.3 — зафиксировано ниже как Pending Work,
  не как молчаливое расхождение со Scope.
  **Гейт:** `pnpm run docs:build` (он же `vitepress build docs`) → `build complete`, 0 ошибок,
  0 dead-link warnings.
  **Commits:** `950ae25` — docs(canon): ROADMAP-задел vaulter-filament + дочистка стухших FQCN после rename движка (P3.1).

  **Pending Work:**
  1. `docs/adr/index.md` — строка на новый ADR канона движков добавляется вместе с созданием
     самого файла в P3.3 (см. выше).
  2. `docs/plan/*` (`00-master-plan.md:54,539`, `p2-5-option-b-topology.md`,
     `p2-5-namespace-strategy-drive-docdrive.md`) держат стухшие FQCN в настоящем времени
     (`permissions-azgard`, `Documents\Documents`, `Drive\Uploads\StagedUpload`) — наследуется из
     handoff Open risk 2. Вне объявленного Scope Included этого item'а (`docs/guide/*` +
     `reference/contracts.md`, не `docs/plan/*`) — эти файлы историческая калька design-фазы, не
     часть публикуемых docs guide/reference; правка — отдельное решение (эти файлы вообще не
     участвуют в `docs:build`-навигации, `docs/plan/` не в sidebar).
  3. `docs/ru/reference/contracts.md` не несёт секций `HandlerRegistry`/`NodeHandlerResolver`
     вообще (ru-перевод контрактов заметно отстал от en с более ранних фаз, местами держит совсем
     другую номенклатуру — `PermissionResolver` вместо текущей). Полная синхронизация ru
     contracts.md — контент-объём, выходящий за рамки docs-item'а sonnet/low; не часть
     объявленного Scope (который называет `docs/reference/contracts.md`, без уточнения ru/en —
     трактовано как «en, основной источник», ru зеркала правились точечно там, где нашлась
     конкретная стухшая ссылка). Кандидат в отдельный docs-item.

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P3.x **sonnet/low**; фактически исполнено
     **sonnet/medium** (дефолт effort команды `/task:plan-exec`, которая запрещает ручное
     переключение). Модель совпала, effort выше заявленного (безопасное расхождение — не ниже).
  2. `plan-lint.py` красный ДО и ПОСЛЕ P3.1 (не регресс, наследуется из P0.1–P2.4): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done
- **P3.2 — arch-тесты финального канона** (sonnet/medium). Расширить arch-suite: «0 tiptap-ссылок в core»,
  «движок-контракт kind-нейтраль», dependency-direction после azgard-rename/media-решения. **Приёмка:** зелёные.

  **Completion Notes.** Рекон `packages/core/tests/Arch/ArchTest.php` показал: dependency-direction
  после azgard-rename (P2.1) и media-решения (D9) УЖЕ покрыты существующими guard'ами (`core source
  never references the vaulter-azgard package (P2.1)` L562-564; `core (and all Vaulter code) never
  depends on the extracted vaulter-media package` L474-477) — новый код не требовался, только
  оставшиеся два пункта item'а были непокрыты. Добавлены 2 теста: (1) `core source never references
  a concrete TipTap class (VLT-CANON P0.3)` — file-scan (та же техника стрипа комментариев, что и
  `coreReferencesTo()`) на паттерн `\bTipTap[A-Za-z]+\b` (CamelCase class-name spelling); намеренно
  НЕ триггерится на `config('vaulter.tiptap.*')` (Support/Config.php) — регистр другой (lower-case
  config-ключ — задокументированный host passthrough, VLT-REMED D27/D28), это разные инварианты, не
  дыра в guard'е. (2) `arch('the FileHandler contract seam is kind-neutral and never depends on
  vaulter-documents (D7)')` — `expect('Vaulter\Contracts\Handlers')->not->toUse('Vaulter\Documents')`,
  namespace-graph форма поверх уже существующего широкого core⊥documents file-scan guard'а (L552-554),
  специально нацеленная на сам движок-контракт (D7-регрессия: обратная связь FileHandler/HandlerRegistry/
  NodeHandlerResolver/DocumentContent/DocumentExporter → конкретный документ-движок). Оба теста red-
  before не потребовали — рекон подтвердил инварианты уже держатся с P0.1/P0.3/P0.4, тесты — регрессионная
  сетка, не фикс.
  **Гейты:** `pint --test` → passed (авто-фикс `fully_qualified_strict_types`/`ordered_imports`,
  добавил `use Vaulter\Contracts\Handlers\DocumentContent;` для `{@see DocumentContent}` в докблоке);
  `phpstan analyse` (max, монорепо) → passed, 0 errors; `pest --filter=Arch` → 35/35 passed (43
  assertions), новые тесты входят; `pest` (весь core) → 499/499 passed, 1284 assertions; `pest
  --type-coverage --min=100` → 100.0%. `vaulter-api-surface` не гонялся — item не трогает
  Contracts/фасад/config-ключи (только тесты).
  **Commits:** `53b5df3` — test(core): арх-тесты финального канона — 0 TipTap-ссылок, kind-нейтральный
  контракт (P3.2).

  **Pending Work:** —

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P3.x sonnet/low; item сам заявлен sonnet/medium в
     заголовке (не расхождение с Routing — Routing-таблица говорит «P3.x | sonnet/low», а инлайн-
     заголовок item'а — sonnet/medium; наследуется дословно из handoff launch-block предыдущей
     сессии). Фактически исполнено sonnet/medium (дефолт `/task:plan-exec`) — совпадает с
     заголовком item'а, расходится с табличным Routing на один шаг вверх (безопасное расхождение).
  2. `plan-lint.py` красный ДО и ПОСЛЕ P3.2 (не регресс, наследуется из P0.1–P3.1): линтер требует
     `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done
- **P3.3 — ADR канона движков + supersede VLT-HSEAM** (sonnet/low). Новый `docs/adr/00XX-editor-engine-canon.md`
  (консолидирует D1–D11). Пометить `plans/VLT-HSEAM/` superseded ← VLT-CANON. **Приёмка:** ADR принят; VLT-HSEAM
  помечен; `plans/ACTIVE.md` не тронут (если VLT-CANON не стал ACTIVE решением owner'а).

  **Completion Notes.** Новый `docs/adr/0018-editor-engine-canon.md` (Status: accepted) —
  консолидирует Decision Log D1–D12 (не D1–D11: в плане накопился D12 про server-side
  дескриптор, добавлен в ADR наравне с остальными) плюс сводку исполненных пунктов P0–P2
  (kind-нейтральные capability-дефолты, опаковый exporter, изоляция TipTap, снятие DB-дефолта
  `format`, DX-каркас, финальный структурный канон), с явным разделом «Consequences» (residual
  debt: `format` value-space, CI gate-scope gap, ru-доки, стухшие `docs/plan/*`) и разделом
  «Supersession». `docs/adr/index.md` получил строку на ADR-0018 (Pending Work из P3.1, теперь
  закрыт). `plans/VLT-HSEAM/plan.md` формально помечен: Meta `Status` → `⛔ Superseded — see
  VLT-CANON P3.3 / ADR-0018`; верхний notice-блок переписан с «поглощён, маркировка в P3.3»
  на «SUPERSEDED формально» + ссылка на ADR. `plans/ACTIVE.md` не тронут — уже указывает
  `VLT-CANON` (обновлён 2026-07-12, до этого item'а), приёмочное условие выполнено без правки.
  **Гейт:** `pnpm run docs:build` (vitepress) → `build complete`, 0 ошибок, 0 dead-link
  warnings (ADR-0018 корректно резолвится по ссылке из index.md).
  **Commits:** `c8d3136` — docs(canon): ADR-0018 канона движков + формальный supersede VLT-HSEAM (P3.3).

  **Pending Work:** — (весь Pending Work P3.1 п.1 «строка индекса на новый ADR» закрыт этим
  item'ом; residual-риски D-debt задокументированы в самом ADR-0018 §Consequences, отдельных
  назначенных item'ов на них по-прежнему нет — см. handoff.md Open risks)

  **Known Deviations:**
  1. Routing-adherence: §Routing требует для P3.x **sonnet/low**; фактически исполнено
     **sonnet/medium** (дефолт effort команды `/task:plan-exec`, которая запрещает ручное
     переключение). Модель совпала, effort выше заявленного (безопасное расхождение).
  2. `plans/VLT-HSEAM/` как каталог не был закоммичен в git ни разу с момента создания (весь
     `plans/VLT-HSEAM/` untracked до этого item'а — пред-существующее состояние, не моё).
     Закоммичен точечно ТОЛЬКО `plan.md` (единственный файл в Scope Included этого item'а —
     формальная маркировка superseded); `handoff.md`/`phases/P0.md`/`root/adr-file-handler-seam.md`/
     `findings/*` остаются untracked — вне Scope, не трогал, не коммитил.
  3. `plan-lint.py` красный ДО и ПОСЛЕ P3.3 (не регресс, наследуется из P0.1–P3.2): линтер
     требует `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
     (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
     Структуру плана правит plan-design, не exec.

  **Status:** 🟢 Done

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
| 2026-07-13 | plan-exec/opus (medium) | P0.3 закрыт (🟠): 5 TipTap-форм контента переезжают core→documents (`Vaulter\Documents\{ValueObjects,Attributes,Exceptions}\*`), XSS-барьер byte-for-byte, гейты+ревью зелёные, gate-scope Pending Work — детали см. §6 P0.3 Completion Notes. |
| 2026-07-13 | plan-exec/opus (medium) | P0.4 закрыт (🟠): D10=A (движок реестра → `Vaulter\Engines\*` в core, superseding H.5+T5) — drive-only хост получает preview/download без documents; `format` без `default('tiptap')`, два write-path штампуют формат явно (BC) — детали см. §6 P0.4 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P0.5 закрыт (🟢), **фаза P0 терминальна (5/5)**: `richText`-дефолт `true→false` (kind-нейтраль), `NodeExportService::contentOf()` стал kind-нейтральным (закрыл Pending Work P0.2/P0.3/P0.4), `AbstractFileHandler` DX-база + гайд `writing-an-engine.md` (+ru), capability-policy-тесты (аддитивность осей + priority-конфликт), D11 закрыт (yjs_state остаётся) — детали см. §6 P0.5 Completion Notes. |
| 2026-07-13 | plan-exec/opus (medium) | P1.1 закрыт (🟠): D8 закрыт owner'ом = вариант A (единый `@vaulter/vue` shell/core, per-engine npm лениво) — гейт для P1.2/P1.3 снят, передетализация не требуется — детали см. §6 P1.1 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P1.2 закрыт (🟢): фронт-реестр компонентов формализован как публичный API (`registerEngineComponent`/4 сестры в `engineRegistry.ts`), фолбэк ПОСЛЕ `componentMap` prop (BC bit-for-bit), dev-warn через `watch` (не `computed`, находка ревью), доки+CHANGELOG синхронны — детали см. §6 P1.2 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P1.3 закрыт (🟢), **фаза P1 терминальна (3/3, 1🟠)**: гайд-скелет стороннего клиентского движка (`writing-an-engine.md` §7 +ru) — `.vue`-компонент с `DocumentNodeEditorProps` + `registerEngineComponent`, без отдельного npm-пакета (D8) — детали см. §6 P1.3 Completion Notes. |
| 2026-07-13 | plan-exec/opus (medium) | P2.1 закрыт (🟠): пакет-мост переименован `permissions-azgard`→`azgard` (dir/composer/namespace/CI), core-шов `AzgardGuard` не тронут, добавлен arch-гвард dependency-direction — детали см. §6 P2.1 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P2.2 закрыт (🟢): D9 = вариант A (owner approved) — `vaulter-media` остаётся standalone-пакетом, кода нет — детали см. §6 P2.2 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P2.3 закрыт (🟢): 3 catch-all папки (`documents/src/{Documents,Support}`, `drive/src/Uploads`) разобраны по роли — драйверы→`Engines/Handlers`, эвалюаторы→существующие `Export`/`Collaboration`, TipTap-хелперы→`Support/TipTap`, upload-хелперы→`Support/Uploads`; тавтология `Documents/Documents` устранена — детали см. §6 P2.3 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P2.4 закрыт (🟢), **фаза P2 терминальна (4/4, 1🟠)**: `documents/src/Access/HostUserResolver` → `Collaboration/HostUserResolver` (не был access-control-классом); core `Access/`/`Webhooks/` сверены с ADR-0017 — no-op, канон ок (эталонная форма паттерна) — детали см. §6 P2.4 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P3.1 закрыт (🟢): ROADMAP получил секцию `vaulter-filament` engine-aware-resources (D6-задел); 3 реальных стухших FQCN/ссылки P0.1–P0.4 починены в `docs/guide/document-handlers.md`+ru, `docs/reference/contracts.md`, `docs/guide/search-trash.md`+ru; `docs/adr/index.md` сознательно отложен до P3.3 (там создаётся сам ADR-файл) — детали см. §6 P3.1 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P3.2 закрыт (🟢): 2 новых arch-теста в `core/tests/Arch/ArchTest.php` — «0 конкретных TipTap-классов в core» (file-scan) и «FileHandler-контракт kind-нейтрален, не зависит от vaulter-documents» (D7-регрессия); dependency-direction azgard/media уже была покрыта существующими guard'ами — детали см. §6 P3.2 Completion Notes. |
| 2026-07-13 | plan-exec/sonnet (medium) | P3.3 закрыт (🟢), **фаза P3 терминальна (3/3), план VLT-CANON целиком терминален**: `docs/adr/0018-editor-engine-canon.md` консолидирует Decision Log D1–D12, `docs/adr/index.md` получил строку; `plans/VLT-HSEAM/plan.md` формально помечен superseded (Status + notice) — детали см. §6 P3.3 Completion Notes. |

| 2026-07-13 | plan-close/sonnet (low) | Реконсиляция целиком: все 4 фазы (P0–P3) 🟢/🟠 терминальны — план закрыт. Meta Status ⬜→🟠 Done with deviations (агрегат: P1/P2 несут по 1 🟠-item), `plans/ACTIVE.md` сброшен на `—`. |

## 10. Провенанс
- Внутренние: `plans/VLT-HSEAM/{plan.md, handoff.md, root/adr-file-handler-seam.md, findings/P0-*.md}`,
  `docs/plan/p2-5-{option-b-topology,vaulter-media-extraction,namespace-strategy-drive-docdrive}.md`,
  `docs/adr/0017-package-structure-canon.md`, `plans/archive/VLT-REMED/findings/P4.9-structure-inventory.md`,
  код-швы (`core/src/{Contracts,ValueObjects}/Documents/*`, `documents/src/Documents/*`,
  `packages/vue/src/components/VaulterDocumentNode.vue`).
- Внешние (research-фан-аут): `findings/{frontend-engine-packaging,drive-editor-resolution,editor-driver-isolation}-research.md`
  (3 фронта × research+adversarial-verify; source_url в каждом). Значимые — продублировать в `docs/reference.md`.
