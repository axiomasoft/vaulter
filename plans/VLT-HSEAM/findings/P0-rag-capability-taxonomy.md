# P0 — RAG: таксономия типов файлов и capability-осей (WOPI, Collabora, ONLYOFFICE, Nextcloud, Google Drive, Notion)

**Дата:** 2026-07-10 · **Метод:** первоисточники (MS WOPI docs, discovery.xml Collabora, formats-JSON
ONLYOFFICE, исходники Nextcloud, Google Drive API, Notion API) · **Статус:** verified, флаги внизу

## Ключи регистрации по платформам

| Платформа | Ключ | Capability-декларация | Fallback | N хендлеров на тип |
|---|---|---|---|---|
| MS WOPI | extension | `<action name="edit" ext="docx" requires="locks,update">` | — (host) | actions на ext; per-file сужает CheckFileInfo |
| Collabora | **MIME** (+legacy ext) | actions: view/edit/view_comment; `default="true"` | view | issue #3368 просит больше |
| ONLYOFFICE | ext (+mime aliases) | actions: view/edit/**lossy-edit**/auto-convert/comment/review/fill/encrypt | view | — |
| Nextcloud | 4 реестра: Viewer (mimes[]), FileAction (предикат enabled()), DirectEditing (mimetypes + **mimetypesOptional**), TemplateCreator | пер-реестр | download/details | «первый default выигрывает», дубль id — reject |
| Google Drive | **primary/secondary** MimeTypes + FileExtensions | supportsCreate/Import/MultiOpen…; import/export-матрицы queryable через `about` | preview/download | да, user `useByDefault` |
| Notion | закрытый enum `type` блока, но **открытый по контракту**: неизвестное → `"type":"unsupported"` | пер-блочная | unsupported-passthrough | — |

## Повторяющиеся capability-оси (кандидаты в контракт)

`view` · `edit` · **`lossyEdit`** · `createNew` · `createFromTemplate` · `convert` (+матрица целей) ·
`preview/thumbnail` · `embedView` · `mobileView/Edit` · `annotate/comment` · `fill` · `collab/rtc` ·
`batchOpen` · `encrypt`.

Критичный сплит: **per-handler (static, discovery)** — какие действия существуют для типа —
vs **per-file-instance (runtime)** — можно ли ЭТОМУ юзеру на ЭТОМ файле (WOPI CheckFileInfo,
NC `enabled()`, Drive `capabilities`). У vaulter per-instance слой = AccessResolver + то, что
`capabilities(FileDescriptor)` уже вычисляется пер-файлово. ✅

## Уроки BC-безопасности (как реестры росли без break)

1. **Открытые string-enum + предписанный ignore-unknown** (WOPI: hosts MUST ignore незнакомые
   actions/placeholders; Notion `unsupported`). PHP-вывод: capabilities НЕ как exhaustively-match
   backed enum у потребителей.
2. **Discovery вместо хардкода**: «что можно с mime X» — queryable API, новый тип = строка данных,
   не код клиентов.
3. **Fallback — сам по себе зарегистрированный хендлер** (download-only): новый тип НИКОГДА не
   breaking — падает на fallback, пока его не заберёт реальный хендлер.
4. **Аддитивный рост actions на стабильном конверте** (ONLYOFFICE добавлял embedview/convert/
   mobileView/formsubmit в 7.2→8.1 без слома; MS не удаляет — «locked»). Имя action не переиспользовать.
5. **Двусторонняя негоциация**: WOPI `requires="locks,update"` — хендлер декларирует, что требует
   от хоста; деградация вместо полуработы.
6. **Primary vs secondary/optional + явная lossy-ность** (Drive primary/secondary, NC
   mimetypesOptional, OO lossy-edit/auto-convert). Один булев `canEdit` доказуемо недостаточен.
7. **N хендлеров на тип с детерминированным tiebreak с первого дня** — «ровно один хендлер на тип»
   и есть допущение, которое потом ЛОМАЕТ.
8. **Версионируй discovery-поверхность, не хендлеры** (Notion-Version header).
9. **Разделяй реестры по вопросу** (NC: render? / row-actions? / THE editor? / что создаёт New?) —
   создание добавили через годы, не тронув viewer-контракт. Мелкие опциональные интерфейсы, не
   один жирный.

## Матрица «вид файла × оси» (сжатая; полная — в отчёте агента)

- Rich-doc / spreadsheet / presentation — самые зрелые оси: view+edit+create+convert+comment+collab.
- CSV — канонический lossy-edit кейс. PDF — view+annotate+fill, редко full-edit.
- Legacy-binary (doc/xls) — «editable только после convert» = отдельное состояние.
- Whiteboard/diagram — кастомные MIME (`application/vnd.excalidraw+json`-стиль) — реестр обязан
  принимать произвольные MIME. Notion нативного whiteboard НЕ имеет (SEO-клеймы 2024 — ложь).
- Image/video — view+thumbnail (+range-streaming как скрытая per-instance способность).
- Archive — «structured preview» (листинг) ≠ view.
- Unknown binary — download-only fallback везде.

## Не удалось верифицировать

- Алгоритм конфликта дефолтов у Google Drive (только поле `useByDefault`).
- NC «unknown mime → download-only» — архитектурный вывод, не цитата дока.
- Список форматов generic-preview Drive (страница не фетчилась).

## Источники

learn.microsoft.com/.../cloud-storage-partner-program/online/discovery ·
github.com/CollaboraOnline/online (discovery.xml co-24.04) · forum.collaboraonline.com/t/364 ·
github.com/CollaboraOnline/online/issues/3368 · api.onlyoffice.com/docs/docs-api/using-wopi/wopi-discovery ·
github.com/ONLYOFFICE/document-formats · github.com/nextcloud/viewer ·
github.com/nextcloud-libraries/nextcloud-files (fileAction.ts) · github.com/nextcloud/server
(PR 17625, ITemplateManager.php) · docs.nextcloud.com (mimetypes) ·
developers.google.com/workspace/drive/api (apps, about, integrate-open) ·
developers.notion.com (reference/block, upgrade-guide-2025-09-03)
