# ADR — Шов файловых обработчиков: не строить заново, закрыть утечки до 1.0

**Статус:** proposed (approve owner'ом = запуск P0 плана VLT-HSEAM)
**Дата:** 2026-07-10 · **Автор:** opus (design-сессия) · **Решения:** D1–D8 в `plans/VLT-HSEAM/plan.md §5`

## Контекст

Запрос владельца: «отдельная папка, где регистрируются все обработчики файлов; tiptap — основное
расширение, но не единственное; клиенты встраивают собственные расширения по каркасу; главный
критерий — отсутствие breaking changes потом». Проверено RAG по четырём фронтам
(`findings/P0-*.md`): рекон кодовой базы, extension-модели редакторов (TipTap/Editor.js),
Laravel-реестры (Manager/Flysystem/Filament/Nextcloud), capability-таксономия Drive-платформ
(WOPI/Collabora/ONLYOFFICE/Nextcloud/Google Drive/Notion).

## Решение (суть)

**Реестр обработчиков НЕ строим — он уже есть и по дизайну совпадает с battle-tested аналогами.**
`DocumentHandler` + `HandlerRegistry` + `NodeHandlerResolver` (mime/ext → handler, capabilities
пер-файлово, priority-tiebreak, fallback `DownloadOnlyHandler`, три канала регистрации:
container-tag / config FQCN / runtime) — это ровно дистиллят паттернов WOPI/Nextcloud/Flysystem.
«Папка обработчиков» в правильной форме уже существует: **behaviour-пакет** (`packages/documents`
= расширение tiptap над core-швами); единица стороннего расширения — composer-пакет, биндящийся
в core-контракты, как Filament-плагин или Flysystem-адаптер, а НЕ каталог внутри ядра.

**Что делаем вместо переработки — закрываем 5 утечек TipTap в ядро, пока пакет не зарелижен
(пост-1.0 каждая = major bump или миграция у всех хостов):**

1. **L5/нейминг:** core-контракты `Contracts/Documents/*` → kind-нейтральные
   `Contracts/Handlers/*`; `DocumentHandler` → `FileHandler`; `DocumentCapabilities` →
   `HandlerCapabilities`. Хендлер описывает ЛЮБОЙ файл (image/*, xlsx, cad), не только документ.
2. **L1/exporter:** `DocumentExporter::export(TipTapContent)` → `export(DocumentContent)` —
   опаковый контракт; TipTap-специфика — внутри реализации экспортёров documents-пакета.
3. **L2/валидатор:** `TipTapContent`, `TiptapExtensions`, `InvalidTipTapContentException`
   переезжают из core в documents (владелец формы — хендлер).
4. **L3/схема:** убрать `default('tiptap')` у `v_node_document_versions.format` — format
   проставляет хендлер явно (молчаливая маркировка чужого контента как tiptap = порча данных);
   комментарии схемы — нейтральные («handler-native snapshot»).
5. **L4/VO:** `richText = true` по умолчанию («историческая форма TipTap») → нейтральный дефолт
   + policy эволюции capabilities (см. ниже).

**Политика эволюции capabilities (BC-урок №1/№4/№6 из таксономии):**
- Набор осей — открытый: добавление оси = аддитивный конструктор-параметр с дефолтом +
  новый ключ в `toArray()`; клиенты обязаны игнорировать незнакомые ключи (задокументировать
  в контракте и клиентском SDK).
- Оси `lossyEdit`/`convert`/`createNew` СЕЙЧАС не добавляем (нет потребителя — YAGNI), но
  дизайн-инвариант «добавление оси не ломает» фиксируем тестом.
- Fallback `DownloadOnlyHandler` — гарантия «новый тип файла ≠ breaking change»: неизвестный
  MIME всегда обслужен (download), пока его не заберёт реальный хендлер с приоритетом выше.

## Альтернативы (рассмотрены и отклонены)

- **A1. Каталог `extensions/` внутри core со всеми хендлерами.** Отклонено: ни одна зрелая
  платформа так не делает; единица расширения — пакет/приложение (Filament plugin, Nextcloud app,
  Flysystem adapter). Каталог в ядре = ядро знает про все расширения → обратная связность.
- **A2. Переход реестра на `Illuminate\Support\Manager`.** Отклонено: statefulness,
  `$app`-coupling, god-object через `__call()`; RAG-вердикт — публичный контракт должен быть
  узким интерфейсом, Manager максимум внутренняя деталь. Текущий bespoke-реестр уже правильный.
- **A3. Персистить резолвнутый handler-id на Node.** Отклонено: WOPI/Nextcloud/Drive резолвят
  динамически по mime; персист денормализует, протухает при регистрации лучшего хендлера.
- **A4. Пре-имплементация хендлеров под будущие типы (canvas, diagram, PDF-annotate).**
  Отклонено: готовность к будущему = открытый контракт + fallback, а не спекулятивный код.
  UniverHandler (spreadsheets, feature-gated) уже доказывает расширяемость шва.
- **A5. Полная переработка структуры под «каркас расширений».** Отклонено как переизобретение
  уже существующей структуры (behaviour-пакеты и есть каркас) — вместо этого дешёвый
  DX-слой: `AbstractFileHandler`-база + гайд «как написать своё расширение» (skeleton-пакет).

## Спорное (вынесено в план §7 Обсуждение, решает owner)

- **Q1. Переезд движка реестра в core.** Сейчас движок (`DocumentHandlerRegistry`,
  `DownloadOnlyHandler`, `PreviewHandler`, резолвер) сознательно в documents (H.5), и host без
  vaulter-documents получает fail-loud. Drive-only хосту (файлы без редактирования) descriptor'ы
  preview/download нужны без documents-пакета. Рекомендация: движок + baseline-хендлеры
  (Download/Preview) → core; documents оставляет только TipTap/Univer/collab. Контраргумент:
  задокументированное решение H.5, лишний вес ядра.
- **Q2. `yjs_state` в схеме.** Оставить как есть (колонка nullable, чужой хендлер её не пишет)
  vs нейтральное имя `crdt_state`. Рекомендация: оставить (переименование — косметика, Yjs и
  есть CRDT коллаборационного слоя; format-колонка уже дискриминирует).

## Последствия

- (+) Ядро становится editor-agnostic по факту, а не по декларации; «tiptap — одно из расширений».
- (+) Новые типы файлов и новые оси capabilities — аддитивны навсегда (fallback + open-set policy).
- (+) Сторонний разработчик получает каркас: интерфейс + Abstract-база + skeleton-гайд.
- (−) Один проход переименований по всему монорепо (пре-1.0 — бесплатно по SemVer, но шумный дифф).
- (−) Documents-пакет забирает TipTap-валидатор — его тесты переезжают вместе с ним.

## Провенанс

RAG-выжимки: `plans/VLT-HSEAM/findings/P0-recon-core-seams.md`,
`P0-rag-editor-extension-models.md`, `P0-rag-laravel-registry-patterns.md`,
`P0-rag-capability-taxonomy.md` (источники и не-верифицированные хвосты — внутри файлов).
