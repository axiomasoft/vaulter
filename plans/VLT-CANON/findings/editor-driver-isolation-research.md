# VLT-CANON research — editor-driver-isolation

**Дата:** 2026-07-11 · **Метод:** research-fanout (sonnet/perplexity-web deep+advanced) + adversarial verify (независимый повторный поиск). RAG:✅ 2026-07-11.
Вердикты verify встроены к load-bearing утверждениям. REFUTED — детальные поправки, зафиксированы в §Verify.

## Findings

### F1 **[load-bearing]**

Синтез Perplexity (deep search, источники: tiptap.dev/docs/hocuspocus/guides/collaborative-editing, tiptap.dev/docs/hocuspocus/provider/install, api.onlyoffice.com WOPI discovery, api.laravel.com/docs/6.x/Illuminate/Support/Manager.html) рекомендует контракт из 4 сущностей: DocumentEngine (метаданные+matching+фабрика), DocumentHandler (per-document resolved объект), DocumentDescriptor (чистые данные для UI), DocumentCapabilities (edit/realtime/comments/structured_export/preview_only/wopi/binary_source). Резолюция — через Manager/registry с ordered matchers (не плоская map ext=>driver): нормализация контекста (mime, extension, storage backend, size, source kind, mode) → supports(context) на каждом engine → сортировка по priority → один handler. Engine-классы должны быть stateless/singleton-safe, Handler — per-resolution contextual, Descriptor — сериализуемый и versionable.

- Источник: [Tiptap Collaborative Editing docs (via Perplexity synthesis, дополнительно api.onlyoffice.com WOPI discovery, api.laravel.com Manager)](https://tiptap.dev/docs/hocuspocus/guides/collaborative-editing)
- Применимость к vaulter: Это почти буквально то, что в vaulter уже есть в packages/documents (реестр DocumentHandler, mime/ext-диспатч, capabilities, priority, fallback DownloadOnly) — подтверждает правильность текущей архитектуры и даёт язык для явного разделения Engine (stateless, регистрация) vs Handler (per-resolution) vs Descriptor (то, что уходит во фронт), чего сейчас, судя по описанию задачи, нет как отдельного явного слоя.

### F2 **[load-bearing]**

Tiptap 3 (stable, июнь 2025) остаётся headless ProseMirror-обёрткой, но: (1) типизация extensions/storage стала строгой (TS), убирает произвольные опции; (2) официально поддерживается server-side рендеринг без DOM через новый пакет @tiptap/static-renderer (React/HTML/Markdown вне браузера); (3) для коллаборации введён официальный пакет y-tiptap (расширяет y-prosemirror под Tiptap); (4) рекомендуемый бэкенд для коллаборации Tiptap 3 — Hocuspocus ≥3, актуальный мажор Hocuspocus 4.0 вышел в марте 2026 и требует миграции конфигурации сервера/auth hooks/provider-контракта с 2.x; (5) undo/redo теперь через @tiptap/extension-undo-redo вместо legacy history при коллаборации; (6) popovers переехали с tippy.js на Floating UI.

- Источник: [Tiptap Editor v3 (release notes, upgrade guide, newreleases.io v3.3.1)](https://tiptap.dev/tiptap-editor-v3)
- Применимость к vaulter: Vaulter уже поднял Node до 22 под hocuspocus v4 (см. коммит 72f4dcb в git log) — это совпадает с находкой. Значит текущий TipTap-драйвер vaulter, вероятно, уже на v3-совместимой траектории; при изоляции TipTap-специфики за контрактом важно НЕ протащить в core типы/понятия y-tiptap/Hocuspocus (комнаты, awareness, provider lifecycle) — они должны жить только в TipTap-драйвере, а core-контракт видит только 'realtime: bool' capability.

### F3 **[load-bearing]**

Univer (dream-num/univer) — основной OSS SDK (большинство @univerjs/* пакетов) под Apache-2.0, позиционируется как full-stack embeddable framework для spreadsheet/doc/slides с модульной плагин-архитектурой (100+ независимых плагинов) и Node.js API для server-side создания/редактирования/вычислений. НО: пакеты @univerjs-pro/* и часть 'advanced preset' бандлов НЕ open-source — требуют отдельной Univer Pro лицензии через Univer Server (license.txt/licenseKey.txt), без лицензии — watermark и лимиты (concurrency, file size, collaboration quota). Некоторые @univerjs-pro/* пакеты формально помечены Apache-2.0 в npm-метаданных, но фактически завязаны на лицензируемую Pro-экосистему.

- Источник: [Univer Pro Licensing docs + github.com/dream-num/univer issue #4143 + opensourcealternative.to/project/univer](https://docs.univer.ai/guides/pro/license)
- Применимость к vaulter: Если vaulter в будущем добавит табличный движок (Univer под таблицы, как упомянуто в теме), нужно чётко разделять код на OSS-путь (только @univerjs/*, без @univerjs-pro/*) и явно documented экстра-зависимость, если понадобится collaboration/import-export/PDF/history — эти фичи у Univer частично Pro-only. В контракте DocumentCapabilities такой движок должен честно объявлять урезанные capabilities в community-режиме.

### F4

Для WOPI-движков (Collabora/OnlyOffice через Nextcloud Office) актуальный паттерн 2025: файл-тайп диспетчеризация живёт НЕ в WOPI-клиенте, а в отдельном viewer/file-action слое хоста (Nextcloud OCA.Viewer.registerHandler({id, mimes, fileExtensions?, component}) + registerFileAction с enabled/exec-предикатами), который затем передаёт управление в Office-приложение, которое уже само владеет WOPI discovery/token-flow к Collabora. MIME-first матчинг с fallback на extension (т.к. многие форматы схлопываются в application/octet-stream). WOPI discovery экспонирует поддерживаемые actions (view/edit/embedview/mobile/conversion).

- Источник: [Nextcloud Collabora Integration Guide + help.nextcloud.com Viewer registerHandler/registerFileAction threads](https://help.nextcloud.com/t/collabora-integration-guide/151879)
- Применимость к vaulter: Подтверждает, что в контракте vaulter WOPI/office-движки должны быть отдельным 'engine kind' (office-frame/wopi-host), а не притворяться rich-text движком: их дескриптор — iframe/action-URL/token-TTL, а не JS-компонент с payload документа. Полезно для будущей capabilities-таксономии (уже сделана в VLT-HSEAM, вне фокуса этого research, но эта деталь стыкуется с ней).

### F5 **[load-bearing]**

Illuminate\Support\Manager (driver-регистрация + lazy factory + __call proxy на текущий driver) удобен внутри Laravel-ядра, но для ПУБЛИЧНОГО пакетного API имеет системные проблемы: публичный контракт становится 'что бы ни реализовал текущий driver' без явного интерфейса; смена методов драйвера молча ломает публичный API через __call; IDE/статический анализ деградирует без IDE-helper костылей; Manager жёстко требует $app/container и Laravel-стиль конфигурации, что мешает framework-agnostic использованию. Рекомендация индустрии: держать Manager-подобный класс ВНУТРИ пакета для регистрации/резолюции, но публично экспонировать узкий явный интерфейс (например DocumentEngineRegistry/DocumentEngineManagerContract) без публичного __call; резолвить по explicit resolve(context)/describe(context), а не заставлять потребителей знать driver('name') как основной путь.

- Источник: [Illuminate\Support\Manager API docs + reddit.com/r/PHP 'Don't use Illuminate\Support for your framework' + crnkovic.me Laravel Behind the Scenes: Manager](https://api.laravel.com/docs/12.x/Illuminate/Support/Manager.html)
- Применимость к vaulter: Прямой ответ на вопрос темы 'bespoke узкий контракт vs Illuminate\Support\Manager'. Для vaulter (пре-1.0, публичный composer-пакет) рекомендация: если DocumentHandler-реестр сейчас построен на голом Manager с публичным __call — это стоит заменить/обернуть явным интерфейсом до 1.0, пока переименования бесплатны по SemVer. Manager можно оставить как внутреннюю реализацию, но не как публичную поверхность.

### F6

Statamic 5 Bard — расширяемый rich-text редактор поверх Tiptap/ProseMirror с двумя явными точками расширения: JS-сторона (Statamic.$bard.addExtension()/replaceExtension()/buttons()) и PHP-сторона рендеринга через Statamic\Fieldtypes\Bard\Augmentor (addExtension/replaceExtension). Nova 5 custom fields — Composer-пакет: PHP field-класс + отдельные Vue-компоненты для index/detail/form/preview, конфиг прокидывается через withMeta(), персистентность может кастомизироваться через fillModelWithData(). Рекомендуемый архитектурный паттерн для 'один канонический движок, много host-адаптеров' — capability-driven adapter layer поверх общего document-core (schema/transforms/validation), а не прямое протекание Bard-внутренностей или Nova-Vue-внутренностей в бизнес-код.

- Источник: [Statamic Bard extending docs + statamic.dev/fieldtypes/bard](https://statamic.dev/extending/bard)
- Применимость к vaulter: Именованные vendor-примеры (Statamic 5, Nova 5), запрошенные пользователем, подтверждают паттерн 'front+back split одного драйвера' на практике: PHP-сторона (Augmentor/field-класс) отдельна от JS-стороны (Bard extension/Vue-компонент), обе питаются общей схемой. Это структурно похоже на текущий шов vaulter componentMap-по-handler.id + DocumentHandler-реестр в PHP — подтверждает, что выбранное направление не изобретение, а установившийся паттерн в экосистеме CMS-пакетов.

### F7

[UNVERIFIED-детали] Общая рекомендация по версионированию Tiptap/ProseMirror JSON для долгосрочного хранения: хранить explicit schemaVersion отдельно от content JSON, версионировать НАБОР extensions (не редактор целиком), никогда не мутировать документ молча при чтении (миграция in-memory на лету, запись — только по явному save), держать deprecated-ноды в схеме, но скрывать из UI, вместо немедленного удаления. Упомянутые конкретные Tiptap API (enableContentCheck, onContentError/contentError event, /assets/schemas/tiptap-json-schema.json) — эти детали в найденных источниках подтверждены только общими блогами о content-migration (webtwizz, docsie, adobe), а НЕ прямой ссылкой на tiptap.dev docs в этом заходе — точность самих названий API требует отдельной проверки на tiptap.dev перед тем, как закладывать их в контракт vaulter.

- Источник: [Общий паттерн версионирования (частично неподтверждённые детали API — см. open_questions)](https://tiptap.dev/tiptap-editor-v3)
- Применимость к vaulter: Если vaulter хранит TipTap JSON как канонический контент узла документа (node-tree модель), стоит заранее заложить schemaVersion-поле в модель/миграцию documents-пакета — это дешёвая структурная защита сейчас (пре-1.0), которая станет дорогой после 1.0. Но точные имена Tiptap validation API нужно сверить напрямую в доках перед тем как на них полагаться.

### F8

В Filament v4 нет встроенного глобального реестра 'file preview by mime' — паттерн строится вручную через custom Infolist Entry (extends Filament\Infolists\Components\Entry, artisan make:filament-infolist-entry), с собственным internal registry (viewer($pattern,$view)/viewers()/fallbackViewer()), MIME wildcard matching (image/*) и Blade-диспетчеризацией через <x-dynamic-component :component="$getEntryWrapperView()">. Готовый community-пример такого паттерна — плагин filamentphp.com/plugins/gheith3-file-view-entry (поддерживает Filament 4.x, превью image/video/audio/pdf/text, fallback на download).

- Источник: [Filament 4 custom Infolist Entry pattern + Gheith File View Entry plugin](https://filamentphp.com/plugins/gheith3-file-view-entry)
- Применимость к vaulter: Именованный инструмент (Filament v4) из запроса. Подтверждает, что для packages/filament у vaulter нет 'бесплатного' встроенного механизма подключения DocumentHandler-descriptor к Filament infolist — потребуется собственная тонкая обёртка (FilePreviewEntry-подобная), которая читает тот же дескриптор/capabilities, что и Vue-фронт, а не дублирует mime-логику отдельно для админки.

### F9

Термин 'driver descriptor'/'handler descriptor' не является устоявшимся в PHP/Vue/Laravel-экосистеме; существующий близкий паттерн — 'backend-driven component descriptor/manifest': бэкенд возвращает {component, props, mount, assets}, фронт резолвит имя через реестр (аналог Vue app.component(name, definition) + createApp().mount()). Пример реализации — пакет mpbarlow/laravel-vue-component-helper (register/registerDefault/component/inject/mount).

- Источник: [Vue 3 Application API (component registration by name) + laravel-vue-component-helper package](https://vueframework.com/docs/v3/br/api/application-api.html)
- Применимость к vaulter: Не блокирует решение — vaulter уже реализовал этот паттерн как componentMap по handler.id в VaulterDocumentNode.vue, что структурно совпадает с найденным 'component descriptor/manifest' подходом. Название можно оставить как есть (DocumentDescriptor), это не противоречит устоявшейся терминологии, т.к. устоявшейся терминологии по факту нет.

## Recommendations (агента-исследователя)

- Ввести явный слой DocumentEngine (stateless, регистрация+matching) отдельно от DocumentHandler (per-resolution, contextual) отдельно от DocumentDescriptor (сериализуемый, versionable, уходит во Vue) — сейчас, судя по описанию, TipTap-специфика протекает в контракты/валидатор core именно потому, что этого разделения нет как явных трёх сущностей.
- Вынести весь TipTap-specific код (extensions, ProseMirror schema, content-validation, y-tiptap/Hocuspocus wiring) из packages/documents (core) в отдельный TipTap-драйвер-пакет (или чёткий namespace-модуль) — пре-1.0 это бесплатно по SemVer, после 1.0 будет breaking change.
- Если реестр DocumentHandler сейчас построен как публичный extends Illuminate\Support\Manager с доступным __call — обернуть/заменить его до 1.0 узким явным интерфейсом (DocumentEngineRegistry с методами register/resolve/describe/supportedEngines), оставив Manager (если он вообще нужен) как внутреннюю деталь реализации, не как часть публичного контракта.
- Заложить в DocumentDescriptor поле schemaVersion (или engineVersion) для контента, отдаваемого TipTap-драйвером — дёшево сделать сейчас, дорого добавлять постфактум для уже сохранённых документов.
- Для будущих не-TipTap движков (Univer/WOPI) НЕ заставлять их дескриптор повторять форму TipTap-дескриптора (JSON payload документа) — office/WOPI-движки должны отдавать принципиально другой payload (iframe/action-url/token), это asymmetry по капабилити, а не изъян контракта.
- Если/когда добавляется Univer как табличный драйвер — явно фиксировать в коде и документации границу @univerjs/* (Apache-2.0, доступно всем) vs @univerjs-pro/* (лицензия, watermark/лимиты без неё), чтобы не завязать open-source ядро vaulter на скрытую коммерческую зависимость.
- Для packages/filament — не дублировать mime-dispatch логику отдельно от Vue-стороны: строить FilamentPreviewEntry, читающий тот же DocumentDescriptor/capabilities, что и componentMap во Vue, а не отдельный parallel registry.

## Open questions

- Точные имена и поведение Tiptap content-validation API (enableContentCheck, onContentError/contentError event, /assets/schemas/tiptap-json-schema.json) не подтверждены прямой ссылкой на tiptap.dev в этом заходе — нужна отдельная проверка в официальных доках Tiptap v3 перед тем, как закладывать эти конкретные механизмы в контракт vaulter.
- Может ли Univer (если появится как драйвер) использовать тот же Yjs/Hocuspocus collab-backend, что и TipTap, или у него принципиально свой collaboration-стек — источники подтверждают только то, что Univer 'advertises collaboration' как капабилити, без деталей совместимости транспорта с Hocuspocus.
- Как именно OnlyOffice WOPI discovery отличается от Collabora WOPI discovery по формату XML/capabilities (mime-based vs action-based) — источник по OnlyOffice был использован только для общей WOPI-механики, детального сравнения двух вендоров не проводилось.
- Нет прямого источника по факту устройства текущего DocumentHandler-реестра vaulter (Manager-based или нет) — рекомендация про замену Manager дана по общему паттерну индустрии, не по факту аудита текущего кода vaulter в рамках этого research-захода.

## Verify (adversarial, независимый поиск)

### REFUTED — 1. Perplexity-синтез рекомендует контракт из 4 сущностей (DocumentEngine/DocumentHandler/DocumentDescriptor/DocumentCapabilities) с резолюци…
Контрольный поиск подтверждает: официальные доки Hocuspocus/Tiptap НЕ содержат типов/классов DocumentEngine, DocumentHandler, DocumentDescriptor, DocumentCapabilities — там есть только Y.js Doc, documentName и хуки (onLoadDocument, onChange, onStoreDocument и т.д.). Эти 4 сущности и Manager/ordered-matchers паттерн — синтетическая архитектурная рекомендация исследователя (по аналогии с Laravel Manager), а НЕ то, что 'рекомендует' цитируемый источник. Атрибуция источнику вводит в заблуждение: сам источник таких абстракций не предлагает.
- Контр-источник: https://tiptap.dev/docs/hocuspocus/guides/collaborative-editing

### REFUTED — 2. Tiptap 3 (июнь 2025): строгая типизация, @tiptap/static-renderer для SSR без DOM, официальный пакет y-tiptap для коллаборации, рекомендуе…
Большинство деталей подтверждено независимо: @tiptap/static-renderer (SSR без DOM/Editor-инстанса) — реален; официальный пакет для Yjs-биндингов называется @tiptap/y-tiptap (а не голое 'y-tiptap') — подтверждено; переезд popovers с tippy.js на Floating UI — подтверждено; UndoRedo-расширение для коллаборации, отдельное от legacy history — подтверждено. НО дата релиза Hocuspocus 4.0 указана неверно: по официальным release notes и upgrade-guide Tiptap стабильный Hocuspocus 4.0 вышел 8 мая 2026, а не в марте 2026. Это фактическая ошибка в датировке ключевого版 (версии), достаточная для REFUTED по конкретному пункту (5) утверждения.
- Контр-источник: https://tiptap.dev/blog/release-notes

### CONFIRMED — 3. Univer core (@univerjs/*) под Apache-2.0, но @univerjs-pro/* и часть advanced preset бандлов не open-source, требуют Pro-лицензию через U…
Независимый поиск подтверждает все детали: dream-num/univer core под Apache-2.0; @univerjs-pro — коммерческая лицензия (license.txt/licenseKey.txt через Univer Server); без лицензии — watermark + конкретные лимиты (concurrency/collab quota до 5 документов и 3 участников, print 3 страницы, import 1MB/export 10k cells и т.д.); зафиксирован реальный GitHub issue #4143, где @univerjs/preset-sheets-advanced формально маркирован Apache-2.0 в package.json, но зависит от закрытого @univerjs-pro — maintainer это подтверждает и обещает open-source в будущем. Утверждение полностью соответствует независимо найденным фактам.
- Контр-источник: https://github.com/dream-num/univer/issues/4143

### CONFIRMED — 4. Illuminate\Support\Manager (driver-регистрация + lazy factory + __call proxy) проблематичен для публичного пакетного API (неявный контрак…
Независимый контрольный поиск другой формулировкой подтверждает ту же критику по существу: __call делает публичный API неявным и недокументированным, ломает статический анализ/IDE-автокомплит, создаёт скрытую рантайм-связь driver→метод без компайл-тайм проверки, и является приемлемым для framework-internal use, но проблематичным как стабильная граница публичного пакета. Рекомендация индустрии совпадает: держать явный интерфейс (например через сервис-контракты), а Manager использовать только для driver lifecycle/резолюции внутри, не выставляя __call наружу.
- Контр-источник: https://api.laravel.com/docs/12.x/Illuminate/Support/Manager.html
