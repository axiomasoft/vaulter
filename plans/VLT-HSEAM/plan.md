# VLT-HSEAM — Editor-agnostic шов файловых обработчиков: закрыть утечки TipTap до 1.0

> ⛔ **SUPERSEDED — формально помечен VLT-CANON P3.3 (2026-07-13).** Этот план НЕ исполнять.
> Весь скоуп P0.1–P0.5 (утечки L1–L5, capability-policy, DX-каркас) и развилки Q1/Q2 перенесены
> в `plans/VLT-CANON/plan.md` (фаза P0, Decision Log D7/D10/D11) и там исполнены и закрыты
> (🟢 Done). Консолидирующий ADR: `docs/adr/0018-editor-engine-canon.md`. Единственный владелец
> поверхности — VLT-CANON.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | VLT-HSEAM |
| Title | Editor-agnostic шов файловых обработчиков: закрыть утечки TipTap до 1.0 |
| Version | 0.1.0 |
| Status | ⛔ Superseded — see VLT-CANON P3.3 / ADR-0018 |
| Document Type | Executable Master Plan |
| Authoring Model | opus (fable) |
| Last Updated | 2026-07-10 |
| Repository | /home/vostrikov/projects/packages/vaulter (axioma-studio/vaulter) |
| Related Packages | vaulter-core, vaulter-documents, vaulter-drive, vaulter-filament, @vaulter/vue |
| Execution Mode | phase-first |
| Target Operator Models | opus (P0.1–P0.2 — публичные контракты) · sonnet (P0.3–P0.5) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:plans/VLT-HSEAM |

> Примечание: ACTIVE остаётся VLT-REMED — этот план спроектирован и ЖДЁТ очереди
> (запуск после завершения VLT-REMED либо решением owner'а). ACTIVE.md не трогали.

## 1. Context

Владелец запросил «папку-реестр обработчиков файлов»: tiptap — основное, но не единственное
расширение; клиенты встраивают собственные обработчики по каркасу; критерий №1 — отсутствие
breaking changes после релиза. Design-сессия (рекон + RAG по 4 фронтам, `findings/P0-*.md`)
показала: реестр уже существует (`DocumentHandler`/`HandlerRegistry`/`NodeHandlerResolver`,
mime/ext-диспатч, capabilities, priority, fallback `DownloadOnlyHandler`) и совпадает с
дистиллятом WOPI/Nextcloud/Flysystem/Filament; «папка обработчиков» в правильной форме —
behaviour-пакеты монорепо (`packages/documents` = tiptap-расширение). Полная переработка
отклонена (ADR: `root/adr-file-handler-seam.md`). Скоуп плана — 5 резидуальных утечек TipTap
в ядро (нейминг контрактов, exporter-сигнатура, TipTap-валидатор в core, `format` default,
`richText` default), политика эволюции capabilities и DX-каркас для сторонних хендлеров.
Всё — пре-1.0, пока переименования бесплатны по SemVer.

## 2. Execution Rules

- Один item за раз; статус — в плане ДО завершения работы; 6 канонических статусов байт-в-байт.
- Коммит-гейт: item не 🟢/🟠 без закоммиченного диффа; Conventional Commits (рус.), атомарно;
  gate пакета зелёный ДО коммита: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`,
  pest-сьюты через `/usr/bin/php8.4`, type-coverage 100%.
- Публичная поверхность: каждый item P0.1–P0.2 сопровождается прогоном субагента
  `vaulter-api-surface` (diff публичного API против base) — отчёт в Completion Notes.
- Переименования — по дисциплине `php:namespace-refactor-safety`: якорить find-replace на FQN,
  не на голый токен; пакет НЕ зарелижен → фаза alias не нужна, hard rename сразу.
- Ревью: после P0.1, P0.2, P0.4 — `vaulter-reviewer` (ось BC/SemVer, register-vs-boot).
- План diverged от кода → эскалация (§10 протокола), не импровизация.

## 3. Routing

| Items | Model/effort · Tool Capability | Why |
|:--|:--|:--|
| P0.1, P0.2 | opus/high | SemVer-публичные контракты, переименования по всему монорепо |
| P0.5 | sonnet/medium + `vaulter-api-surface`, `vaulter-test-verifier` | финальная сверка поверхности и гейта |

Остальное — по SSOT-матрице (`plan-protocol` §9): package-implementer sonnet/medium.

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Закрытие утечек TipTap + политика эволюции + DX-каркас | 0/5 | ⬜ Not started |

## 5. Decision Log

| D# | Date | Decision | Why |
|:--|:--|:--|:--|
| D1 | 2026-07-10 | Реестр обработчиков НЕ строить заново — он существует и корректен; скоуп = закрытие утечек | Рекон: `findings/P0-recon-core-seams.md` (RAG:✅ 2026-07-10 рекон монорепо) |
| D2 | 2026-07-10 | Единица стороннего расширения = composer-пакет, биндящийся в core-контракты; НЕ каталог в ядре | RAG:✅ 2026-07-10 Filament plugins / Flysystem adapters / Nextcloud apps (`P0-rag-laravel-registry-patterns.md`) |
| D3 | 2026-07-10 | Kind-нейтральный нейминг до 1.0: `Contracts/Documents/*`→`Contracts/Handlers/*`, `DocumentHandler`→`FileHandler`, `DocumentCapabilities`→`HandlerCapabilities` | Хендлер описывает любой файл; пост-1.0 rename = major (RAG:✅ 2026-07-10 рекон L5) |
| D4 | 2026-07-10 | Публичный контракт остаётся узким bespoke-интерфейсом; `Illuminate\Support\Manager` НЕ вводить | RAG:✅ 2026-07-10 statefulness/$app-coupling/__call (`P0-rag-laravel-registry-patterns.md`) |
| D5 | 2026-07-10 | `DocumentExporter::export()` принимает опаковый `DocumentContent`; TipTap-специфика — в реализациях documents | Утечка L1; пост-1.0 = major (RAG:✅ 2026-07-10 рекон) |
| D6 | 2026-07-10 | Политика эволюции capabilities: открытое множество, аддитивные оси с дефолтами, клиенты игнорируют незнакомые ключи; fallback-хендлер = инвариант «новый тип ≠ breaking» | RAG:✅ 2026-07-10 WOPI ignore-unknown / Notion unsupported / OO аддитивный рост (`P0-rag-capability-taxonomy.md`) |
| D7 | 2026-07-10 | Динамический резолв mime→handler сохранить; handler-id на Node НЕ персистить | RAG:✅ 2026-07-10 WOPI/NC/Drive резолвят динамически; персист протухает (`P0-rag-editor-extension-models.md` п.9) |
| D8 | 2026-07-10 | Хендлеры под будущие типы НЕ пре-имплементировать; готовность = открытый контракт + fallback + DX-каркас (Abstract-база + skeleton-гайд) | YAGNI; UniverHandler уже доказал шов (RAG:✅ 2026-07-10 таксономия, урок 3) |

## 6. Update Log

| Date | Who (role/model) | What |
|:--|:--|:--|
| 2026-07-10 | issue-planner/opus | План создан: design-сессия, 4 RAG-фронта в findings/, ADR в root/, P0 детализирован |

## 7. Обсуждение (решает owner до запуска P0)

### Q1 — Переезд движка реестра в core (влияет на P0.3)

**Сейчас:** движок `DocumentHandlerRegistry` + baseline-хендлеры (`DownloadOnlyHandler`,
`PreviewHandler`) + `NodeHandlerResolver` живут в `packages/documents`; core держит только
контракты и «fails loudly with an install hint» без documents (задокументированное решение H.5).

**Проблема:** drive-only хост (файлы без редактирования) не получает handler-descriptor'ы
вообще — а preview/thumbnail/download это drive-концерны, не document-концерны.

- **Вариант A (рекоменд.): движок + Download/Preview-хендлеры → core; documents оставляет
  TipTap/Univer/collab.** Плюсы: реестр = инфраструктура ядра (как PanelRegistry); drive-only
  хост работает из коробки; «tiptap — одно из расширений» становится буквальным. Минусы:
  ревизия H.5-решения; +вес ядра (~4 класса); нужен careful move тестов.
- **Вариант B: оставить как есть.** Плюсы: ноль работы, H.5 не трогаем. Минусы: ядро декларирует
  editor-agnostic, но без documents-пакета шов мёртв; documents становится де-факто обязательным.

### Q2 — `yjs_state` в схеме `v_node_document_versions` (влияет на P0.4)

- **Вариант A (рекоменд.): оставить имя.** Колонка nullable, чужой хендлер её не пишет; Yjs и
  есть CRDT collab-слоя; дискриминатор — `format`. Переименование = косметика + шум миграций.
- **Вариант B: переименовать в `crdt_state`.** Плюсы: нейтральность буквы схемы. Минусы:
  выгода чисто косметическая, vue/hocuspocus-server трогать всё равно придётся.

Решения по Q1/Q2 зафиксировать как D9/D10 до старта P0.3/P0.4; без них items стоят на
`[NEEDS CLARIFICATION]`-гейте.
