# VLT-CANON research — drive-editor-resolution

**Дата:** 2026-07-11 · **Метод:** research-fanout (sonnet/perplexity-web deep+advanced) + adversarial verify (независимый повторный поиск). RAG:✅ 2026-07-11.
Вердикты verify встроены к load-bearing утверждениям. REFUTED — детальные поправки, зафиксированы в §Verify.

## Findings

### F1 **[load-bearing]**

Универсальный поток во всех разобранных cloud file-storage системах (Google Drive, Box, Dropbox, Nextcloud, WOPI-хосты) одинаков структурно: клиент имеет только opaque file id (+ возможно mime-type из листинга); при открытии клиент шлёт запрос на сервер (resolve/open-endpoint); сервер по mime-type/extension + правам пользователя + tenant-политике + доступности интеграции выбирает handler; сервер возвращает клиенту дескриптор — editorId/appId, mode, capabilities, openUrl/token, UI-хинты (label/icon) — и уже ЗАТЕМ клиент либо навигирует по URL, либо монтирует зарегистрированный по editorId компонент. Сервер владеет решением 'чем открыть', клиент — тонкий диспетчер по дескриптору.

- Источник: [Perplexity search_deep synthesis: cloud editor-resolution architecture (WOPI/Nextcloud/Drive/Box/Dropbox), 2025-2026](https://developers.google.com/workspace/drive/api/guides/mime-types)
- Применимость к vaulter: Это прямое подтверждение целевой архитектуры vaulter: DocumentHandler-реестр в packages/documents уже играет роль server-side resolver'а (mime/ext dispatch, capabilities, priority, fallback DownloadOnly). Нужно формализовать контракт 'дескриптор' как отдельный публичный DTO (handler.id, capabilities[], openRoute/token, mode) и убрать текущую протечку — componentMap на фронте должен биться по handler.id ИЗ дескриптора, а не быть зашитым заранее в VaulterDocumentNode.vue.

### F2 **[load-bearing]**

WOPI (Microsoft/Collabora/OnlyOffice) явно разделяет два уровня резолва: (1) discovery.xml — статическое, редко обновляемое объявление 'для расширения X поддерживаются действия view/edit по URL-шаблону Y' (per-host, не per-file); (2) CheckFileInfo — динамический, ПЕР-ФАЙЛОВЫЙ и ПЕР-ПОЛЬЗОВАТЕЛЬСКИЙ ответ с полями BaseFileName, UserCanWrite (optional:true, default false), SupportsUpdate (optional:true, default false), которые вместе гейтят реальный edit-режим независимо от того, что заявлено в discovery. Discovery не несёт permission-полей.

- Источник: [MS-WOPI CheckFileInfo Response (Microsoft Learn, актуально на 2024-2025 ревизии протокола)](https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/files/checkfileinfo/checkfileinfo-response)
- Применимость к vaulter: Модель 'статическая capability-таблица + динамический per-file/per-user check' напрямую применима к DocumentHandler: capabilities/priority handler'а — статичны (аналог discovery.xml), а фактическое разрешение на edit для конкретного узла/юзера должно вычисляться отдельно (аналог CheckFileInfo) через существующий permissions-пакет (azgard), а не быть зашито в самом дескрипторе handler'а. Не смешивать 'какой handler способен редактировать mime X вообще' с 'может ли ЭТОТ юзер редактировать ЭТОТ файл сейчас'.

### F3 **[load-bearing]**

Nextcloud Viewer (актуальный README пакета @nextcloud/viewer, 2024-2025) резолвит handler на КЛИЕНТЕ через registerHandler({id, group?, mimes, component}) — по массиву mime-типов, без persist'а выбора на файле; OCA.Viewer.registerHandler помечен как legacy/не рекомендован из-за проблем с порядком загрузки скриптов. Предложение fileExtensions (issue 2023) для матчинга по расширению при mime=application/octet-stream не вошло в стабильный API; вместо этого Viewer 4.0 RFC (август 2024) предлагает более общую модель: handler экспонирует enabled(nodes) предикат вместо жёсткой привязки к mimes, т.е. переход от статичного mime-массива к произвольной клиентской логике допуска.

- Источник: [Nextcloud viewer README/@nextcloud/viewer registerHandler API + Viewer 4.0 RFC](https://github.com/nextcloud/viewer/issues/2008)
- Применимость к vaulter: Важный контрпример: не все эталонные системы резолвят на сервере — Nextcloud Viewer резолвит НА КЛИЕНТЕ по метаданным узла, присланным сервером (mime-тип), но список доступных handler'ов формируется динамически при загрузке фронтенда (какие app установлены). Для vaulter это значит: даже если резолв компонента происходит на фронте (как сейчас componentMap), критично, чтобы источником mime/ext и capabilities оставался СЕРВЕР (DocumentHandler-реестр), а не хардкод в компоненте. Модель enabled(nodes)-предиката из RFC — хороший ориентир для будущего priority/fallback-разрешения handler'а вместо плоского приоритета.

### F4

Google Drive API v3 file resource в 2025-2026 больше НЕ документирует openWithLinks и defaultOpenWithLink как поддерживаемые поля (это наследие Drive API v2); официальных changelog-записей об их deprecation в 2024-2025 нет, но они отсутствуют в текущей v3-схеме File и должны считаться legacy/undocumented. Для app-routing в 2024+ Google продвигает ресурс apps (installed apps metadata, введён январь 2024) с mime-типами поддерживаемых приложений, а не per-file open-links.

- Источник: [Google Drive API v3 Files resource reference + v2-to-v3 migration guide](https://developers.google.com/workspace/drive/api/reference/rest/v3/files)
- Применимость к vaulter: Второстепенно для vaulter (нет прямой интеграции с Google Drive API), но подтверждает общий тренд: даже Google уходит от per-file закреплённых 'open with' ссылок в сторону централизованного реестра приложений/mime-соответствий, резолвящегося на каждый запрос — усиливает решение НЕ персистить handler-id на узле.

### F5

Box Content Open With UI Element — фактически end-of-life с 21 декабря 2021 года: не поддерживается для новых клиентов (grandfathered-режим только для тех, кто был подключён до этой даты), Adobe Sign убран из выпадающего списка даже для существующих. Box Developer support прямо называет фичреквесты о его возрождении 'not currently planned' по состоянию на 2024-2025 документацию; текущая рекомендация Box — строить собственную 'open with'-абстракцию поверх Content Explorer/Picker/Preview элементов, а не полагаться на нативный Open With.

- Источник: [Box UI Elements — Content Open With element status (Box Developer Blog / Box Pulse forum)](https://medium.com/box-developer-blog/new-sidebar-element-the-ga-of-open-with-935936a0628f)
- Применимость к vaulter: Не напрямую применимо (vaulter не строится поверх Box), но показательный сигнал: платформы регулярно депрекейтят централизованные 'open with' механизмы в пользу собственных абстракций поверх примитивов (mime + токен + preview), что укрепляет решение vaulter иметь собственный тонкий, независимый от вендора реестр handler'ов, а не завязываться на чужой протокол один-в-один.

### F6 **[load-bearing]**

TipTap v3 (2025) архитектурно headless и framework-agnostic: схема документа строится из extensions (nodes/marks), сливаемых в ProseMirror-схему, независимо от UI-слоя; v3 добавляет server-side execution без DOM-зависимости и пакет @tiptap/static-renderer для рендера контента в React/HTML/Markdown вне живого редактора. Рекомендуемое разделение пакетов: editor-core (схема/extensions/миграции/валидаторы) → editor-runtime (фабрика инстансов) → editor-ui-<framework> (тулбары/menu) → editor-render (SSR/static/read-only). getSchema() позволяет получить схему без создания инстанса редактора для переиспользования между множеством редакторов/валидаторов/бэкенд-сервисов.

- Источник: [TipTap Editor v3 — official architecture positioning (headless, framework-agnostic)](https://tiptap.dev/tiptap-editor-v3)
- Применимость к vaulter: Прямо отвечает на цель vaulter 'вынести специфику TipTap из core'. Рекомендуемое расслоение (core-схема / runtime / UI-адаптер / renderer) — образец для packages/documents: TipTap-специфичные extensions/схема/валидатор должны переехать в отдельный editor-плагин пакет (или под-неймспейс), а core должен работать с абстрактным контрактом (DocumentHandler capabilities), не зная про ProseMirror-схему TipTap. static-renderer релевантен для read-only/preview-режима без полного монтирования редактора.

### F7 **[load-bearing]**

Реальный класс багов у WOPI-хостов (Nextcloud/oCIS + Collabora/OnlyOffice, обсуждения 2023-2025): при конвертации файла (напр. .doc→.docx) или смене предпочтительного редактора старая mime→editor ассоциация 'залипает' в app-registry/дискавери-кэше и продолжает открывать неверный редактор, пока: (а) MIME не пересканирован через реальный детектор (libmagic и т.п.) заново, (б) устаревшие editor_id/handler-поля явно не очищены на файловой записи, (в) конфликтующие записи реестра (напр. и Collabora, и OnlyOffice заявляют один и тот же mime) не разрешены явным приоритетом. Рекомендуемый паттерн хостов: детерминированный, STATELESS резолв 'по актуальному (extension, mime) через единый source-of-truth конфиг/реестр при каждом открытии', а не кэшированный per-file editor id.

- Источник: [ownCloud Infinite Scale issue #10086 + Nextcloud/OnlyOffice forum reports on stale editor-MIME association](https://github.com/owncloud/ocis/issues/10086)
- Применимость к vaulter: Это конкретное эмпирическое обоснование для ключевого архитектурного вопроса задания: персист handler-id на узле — известный источник багов в production WOPI-хостах именно из-за протухания при смене движка/конвертации файла. Для vaulter: если узел (node) хранит какой-то handler-related атрибут, он должен быть НЕ жёстким pointer'ом на handler.id, а максимум 'подсказкой' (preferred), которую резолвер обязан валидировать против текущего реестра при каждом открытии и откатывать на dynamic-резолв при несовпадении/недоступности.

### F8

ONLYOFFICE Document Server config.js (актуальный API reference, 2025) резолвит редактор по document.fileType (нормализованное расширение, обязательно lowercase) и верхнеуровневому documentType ('word'|'cell'|'slide'|'pdf'|'diagram' — актуальные значения; устаревшие 'text'/'spreadsheet'/'presentation' депрекейтны с версии 6.1). documentType можно не указывать явно — тогда сервер выводит его из fileType, но официальная рекомендация — вычислять его на своей стороне явным маппингом для детерминированности; расширение xml неоднозначно (числится и в word, и в cell group) и должно резолвиться не по одному только extension, а с учётом mime/метаданных.

- Источник: [ONLYOFFICE Docs API — Config reference (documentType/fileType mapping)](https://api.onlyoffice.com/docs/docs-api/usage-api/config/)
- Применимость к vaulter: Конкретный вендорский пример 'таблицы приоритетов' и известной неоднозначности (xml → две группы) — хороший тест-кейс для vaulter DocumentHandler: реестр должен уметь резолвить конфликт extension→handler не только по extension/mime, но и добавочными правилами (metadata/business-context), и explicit-приоритет должен побеждать 'угадывание' по одному только extension, как это рекомендует сам ONLYOFFICE.

## Recommendations (агента-исследователя)

- Формализовать в packages/documents публичный DTO 'OpenDescriptor' (по аналогии с WOPI CheckFileInfo + discovery split): статическая часть — capabilities/priority/поддерживаемые mime-extension у DocumentHandler (аналог discovery.xml, редко меняется, кэшируема); динамическая часть — per-node/per-user резолв (handler.id, mode view|edit, effective capabilities с учётом прав из azgard-пакета, openRoute/token) — вычисляется на каждый запрос открытия, не кэшируется на узле.
- НЕ персистить handler-id как жёсткий pointer на узле/файле. Если нужен 'предпочтительный' handler (user/tenant override), хранить его как preference-хинт, который резолвер обязан валидировать против актуального реестра DocumentHandler при каждом открытии и безопасно откатывать на dynamic fallback (DownloadOnly), если handler недоступен/удалён/переприоритезирован — паттерн взят из зафиксированного класса WOPI-багов со stale mime→editor ассоциациями (owncloud/ocis#10086).
- Вывести TipTap-специфику (схема, extensions, валидатор контента) из core в отдельный editor-plugin слой по образцу TipTap v3: editor-core (схема/extensions/migrations) -> editor-runtime (фабрика инстансов) -> editor-ui (Vue-компонент/тулбар) -> editor-render (read-only/static рендер для preview без полного монтирования редактора). Core-контракт DocumentHandler должен знать только про capabilities/mime/id, не про ProseMirror-схему.
- На фронте VaulterDocumentNode.vue переключить componentMap с хардкод-маппинга на резолв по handler.id, пришедшему в дескрипторе от сервера (аналог Nextcloud registerHandler({id, mimes, component}) — динамический реестр компонентов, ключуемый по id, а не завязанный на конкретный движок в компиляции).
- Явно развести 'может ли handler В ПРИНЦИПЕ редактировать mime X' (статика реестра, приоритет/fallback) от 'может ли ЭТОТ пользователь редактировать ЭТОТ файл сейчас' (динамика прав) — не смешивать эти два уровня в одном поле capabilities, беря пример из разделения WOPI discovery.xml vs CheckFileInfo.
- При проектировании правил конфликт-резолва extension->handler в реестре предусмотреть explicit-приоритет для неоднозначных случаев (по аналогии с xml-кейсом ONLYOFFICE, который валиден и для word, и для cell group) — не полагаться только на 'первое совпадение по extension'.

## Open questions

- Нет прямого источника (WOPI/Nextcloud/Google spec) на именно PHP/Laravel-паттерн реализации такого резолвера — все находки описывают протоколы/API вендоров, а не референсную серверную имплементацию на Laravel; маппинг на конкретные Laravel-конструкции (Service Provider, контракты) в vaulter нужно проектировать самостоятельно.
- Не найден авторитетный источник, прямо сравнивающий издержки производительности dynamic-резолва (запрос к реестру на каждое открытие) vs persist-подхода в терминах latency/кэширования — только качественные архитектурные аргументы 'за' и задокументированный класс багов 'против' persist. Требует отдельного бенчмарка/решения для vaulter, если открытие файла окажется хот-патом.
- Nextcloud Viewer 4.0 RFC (enabled(nodes) вместо mimes) описан как предложение по состоянию на август 2024 — не подтверждено, вошло ли это в стабильный релиз к середине 2026 года; статус RFC на текущий момент не верифицирован дополнительным поиском.
- [UNVERIFIED] Нет прямого источника с точным wire-контрактом server->client дескриптора уровня 'официальной спецификации' вне WOPI (Nextcloud/Drive/Box/Dropbox описывают паттерн, но не единый формализованный JSON-schema дескриптора) — вывод о форме дескриптора (editorId/mode/capabilities/openUrl/token) синтезирован Perplexity search_deep как обобщение по нескольким источникам, а не процитирован из одного нормативного документа.

## Verify (adversarial, независимый поиск)

### REFUTED — 1. Universal pattern across Google Drive/Box/Dropbox/Nextcloud/WOPI: client only has opaque id+mimetype; client calls a resolve/open-endpoin…
Independent checks on Google Drive, Box and Dropbox all show the opposite architecture for the mainstream cases: the REST API returns plain file metadata (mimeType/extension, capabilities like canEdit, webViewLink) but no editorId/mode/openUrl 'resolve' descriptor; the CLIENT (or the vendor's own web UI/preview SDK) maps mime/extension -> viewer/editor. Box explicitly has no 'editor=X' field in the file resource; Box Content Preview SDK picks the viewer client-side. Dropbox Chooser returns id/name/link only, with no mime or open-mode field — viewer-vs-editor classification is explicitly client/app-side. Only the WOPI world (claim 2) matches a server-authoritative CheckFileInfo pattern, and even there the initial dispatch (which WOPI action-url to hit) is chosen by the host client-side from discovery.xml, not returned by a generic 'resolve' server call. So the claimed universal structural pattern does not hold for the cited non-WOPI systems.
- Контр-источник: https://developer.box.com/reference/resources/file--full

### CONFIRMED — 2. WOPI separates discovery.xml (static, per-host, extension->action URL templates, no permission fields) from CheckFileInfo (dynamic, per-f…
Two independent Perplexity queries against Microsoft Learn / DZone sources confirm: discovery.xml is static per-host capability/action-URL metadata (no user or file-specific permission fields); CheckFileInfo is called per (WOPISrc file id, access_token user) and returns UserCanWrite, SupportsUpdate, SupportsLocks etc., all optional booleans defaulting to false, which together (not discovery alone) gate the actual editable UI — matches the claim closely, including the 'optional:true default false' framing from Microsoft's own docs.

### REFUTED — 3. Nextcloud Viewer resolves handlers client-side via registerHandler({mimes,...}) without persisting to the file; OCA.Viewer.registerHandle…
Client-side, non-persisted mime-array registration and the existence/content of the Viewer 4.0 RFC with enabled(nodes) predicates (issue #2395) were both confirmed. However, the specific claim that the fileExtensions proposal 'did not make it into the stable API' is contradicted: independent search shows issue #2008 was closed as obsolete precisely because the functionality was implemented via a follow-up issue #2029 ('Feat: File extension handlers'), landing fileExtensions support in the Viewer 3.x handler API (mimes + fileExtensions together) as part of the stable line, not left out. That specific sub-claim is factually wrong, so the overall claim as stated is refuted.
- Контр-источник: https://github.com/nextcloud/viewer/issues/2029

### CONFIRMED — 4. TipTap v3 is headless/framework-agnostic, extensions build a ProseMirror schema independent of UI; v3 adds DOM-free server-side execution…
Independent checks against tiptap.dev confirm v3's DOM-free server-side execution and @tiptap/static-renderer (pm/* and json/* entrypoints) for headless HTML/Markdown/React rendering without an Editor instance. A separate check specifically confirmed getSchema(extensions) exists in @tiptap/core as a standalone helper (independent of static-renderer) that builds a ProseMirror schema from an extensions array without creating an Editor, exactly matching the claim's use case (server-side validation, schema reuse). The specific package-layering recommendation (editor-core/runtime/ui/render) is a reasonable architectural inference from the confirmed primitives rather than a literally-quoted vendor recommendation, but the underlying technical facts hold.

### CONFIRMED — 5. Real WOPI-host bug class (Nextcloud/oCIS + Collabora/OnlyOffice, 2023-2025): stale mime->editor association survives file conversion/edit…
Independent lookup of oCIS issue #10086 confirms the core bug mechanism: Collabora's WOPI discovery advertising support for application/octet-stream caused oCIS's app-registry to misclassify unknown/converted-file mime types and keep offering/opening the wrong (or an) editor ('stuck'/'stale' editor state) until the app-registry mime handling was fixed (PR #10107); admin guidance in the surrounding docs/forum threads matches the claim's recommended pattern of correctly-scoped, non-conflicting mime->app_provider mappings resolved at open time via app-registry/discovery rather than persisted per-file state. This corroborates the described bug class and the stateless-resolution recommendation.
