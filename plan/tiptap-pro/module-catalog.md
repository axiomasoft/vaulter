# Полный каталог направлений Tiptap Pro для исследования

Дата проверки: **2026-11-21**. Каталог охватывает все платные/pilot направления текущего официального [All features](https://tiptap.dev/docs/editor/extensions/overview), их документированные companion packages и серверные сервисы, а также utilities, которые часто по старым материалам принимают за Pro. Официальный feature index на дату проверки содержит 93 записи; это число возможностей, а не npm-пакетов. Список внутренних неопубликованных пакетов и будущих продуктов из публичных источников установить нельзя.

Источник имён — installation/API pages производителя. Каталог не ограничивается семью первоначально установленными пакетами. Наличие карточки в документации, доступ к private registry и доступ к конкретной backend capability — три самостоятельных факта. Никаких vendor source, source maps или токенов в этот документ не включено.

**Статус доступа — срез локального lab от 2026-11-21**, обновлённый после расширенного прохода 14 canonical Pro packages. `Установлен` подтверждает скачивание указанной версии, но не завершённый функциональный эксперимент. `403` — наблюдавшийся npm E403 по выданному доступу; причина/entitlement из одного этого кода не выводится. `Не проверен` — попытка установки/исполнения в этом срезе не подтверждена. Более свежие данные находятся в [package-inventory.json](results/package-inventory.json) и отчётах расширенного прохода.

Backend в таблицах означает требование исследуемого Tiptap-продукта. Сохранение любого документа в нашем приложении всё равно проходит серверный ACL/owner path. «Без backend» здесь означает отсутствие обязательного сервиса **Tiptap** для самой функции.

Приоритеты относятся к будущей реализации относительно действующего `plan/`: **P0** — базовые зависимости, сохранность/права и текущие сквозные сценарии; **P1** — review/history и запрошенные офисные функции; **P2** — дополнительные layout/template/diagram/board продукты. Это не исключает P2 из исследования всех Pro-модулей. Колонка собственной замены — цель для будущей реализации без обязательной Pro-зависимости, не заявление о уже готовом аналоге.

## 1. Documents, collaboration и backend operations

| Возможность и первичный источник | Пакет / сервис | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Cloud provider](https://tiptap.dev/docs/collaboration/provider/integration) | `@tiptap-pro/provider` | Да: Document Server Cloud/on-prem для документированного Pro provider | Установлен `3.12.9`; Cloud-проверки отражены отдельно в findings | Адаптер Tiptap OSS/Yjs поверх собственного Hub, auth, reconnect и явные подтверждения сохранения. **P0**, фазы 2/3 |
| [Document REST](https://tiptap.dev/docs/collaboration/documents/rest-api) | HTTP API Document Server; отдельный обязательный REST npm package не требуется | Да | Endpoint-прогоны см. [findings](findings.md); не приравнивать к скачиванию provider | Авторизованные create/read/update/status, ограниченные mutations, идемпотентность и выдача подтверждённого результата. **P0** |
| [Access-control authentication](https://tiptap.dev/docs/authentication) | Сервисные JWT permissions и серверное формирование токена | Да для проверки прав; npm token не выполняет эту роль | Формирование JWT и тестовый Cloud-доступ проверяются отдельно | Собственные tenant/project/subject, короткая сессия, room/resource scope, отзыв, generation fence. **P0** |
| [Webhooks](https://tiptap.dev/docs/collaboration/core-concepts/webhooks) | Backend runtime settings; document/content/comment/version/user events | Да: отправитель и принимающий endpoint | Не проверено отдельным webhook-получателем | Outbox у владельца, ограниченные события, подпись, дедупликация доставки, retry и восстановление projection. **P0/P1**, не источник commit сам по себе |
| [Runtime configuration](https://tiptap.dev/docs/collaboration/operations/configure) | Backend настройки origins, auth, versions, threads и hooks | Да | Наличие конкретных настроек не подтверждено каталогом | Собственная конфигурация доверенной атрибуции, permissions/recheck, сохранения и TTL. **P0** |

Это отдельный исследовательский слой, хотя все перечисленные элементы не представлены самостоятельными extension-карточками. Backend Tiptap не становится наблюдаемым исходным кодом только потому, что установлен его frontend provider.

## 2. Review, comments, versions и compare

| Возможность и первичный источник | Пакет | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Comments](https://tiptap.dev/docs/comments/getting-started/install) | `@tiptap-pro/extension-comments` | Да для документированного thread storage/API; anchors находятся в документе | Установлен `3.12.9`; эксперименты см. [comments design](comments-versions-design.md) | Устойчивые range/block anchors, thread/replies/resolve, авторство, серверные права, HTTP/headless и lifecycle при удалении цели. **P1**, H3/фаза 7 |
| [Snapshots](https://tiptap.dev/docs/collaboration/documents/snapshot) | `@tiptap-pro/extension-snapshot` + provider | Да: история/metadata и restore сервера | Установлен `3.12.9` | Пользовательская история поверх неизменяемых revisions, auto/manual rules и ACL; restore нового поколения. **P0/P1**, фазы 2/3 |
| [Compare snapshots](https://tiptap.dev/docs/collaboration/documents/snapshot-compare) | `@tiptap-pro/extension-snapshot-compare` | Да в документированной provider/history интеграции | Установлен `3.12.9` | Историческое rich-text сравнение и атрибуция при известных источниках; отдельный read-only diff view. **P1** |
| [Compare](https://tiptap.dev/docs/compare/getting-started/overview) | `@tiptap-pro/compare` | Сам расчёт может выполняться локально в browser/server; managed backend не обязателен | **npm 403**; это не отказ Snapshot Compare | Свой структурный changeset по двум разрешённым документам/версиям, сохранение marks/attrs, связь с review. **P1** |
| [Tracked Changes](https://tiptap.dev/docs/tracked-changes/getting-started/install) | `@tiptap-pro/extension-tracked-changes` | Локальный review возможен в редакторе; совместное сохранение и доверенные решения требуют backend приложения | Установлен `0.14.3`; эксперименты/границы см. [tracked design](tracked-changes-design.md) | Собственная модель suggestion, группировка/авторство, accept/reject, undo/redo, concurrent reviewers, headless commit и P/Q. **P1**, прямой мотив trial в H3 |

Timeline, snapshot comparison и текущие suggestions — различные модели. Данные Pro comments не тождественны базовым Documents comments. Их серверное авторство и разрешения также нельзя восстановить по скрытой UI-кнопке. Связанный документ: [plan-gap-map](plan-gap-map.md).

## 3. AI: три самостоятельных направления

| Возможность и первичный источник | Пакет / сервис | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Basic AI Generation](https://tiptap.dev/docs/ai/basic/install) | `@tiptap-pro/extension-ai`; generation service либо [custom LLM](https://tiptap.dev/docs/ai/basic/custom-llms) | Нужен процесс вызова LLM; сервис Tiptap заменяется своим integration path | Установлен `3.12.9`; наличие ключа внешней модели не предполагается | Свои generation/autocomplete/rewrite команды и streaming UI над выбранным хостом провайдером; серверная авторизация записи. **P1**, исполнитель фазы 3.4 |
| [Client AI Toolkit](https://tiptap.dev/docs/ai/ai-toolkit/client/install) | `@tiptap-pro/client-ai-toolkit` | Editor tools исполняются в browser; LLM orchestration/provider отдельно. Это не обещание работы с закрытым браузером | **npm 403** | Ограниченные client tools и workflow по локальному editor; переиспользовать серверный contract/receipt там, где есть долговременный эффект. **P1**, UI-дополнение SDK |
| [Server AI Toolkit](https://tiptap.dev/docs/ai/ai-toolkit/install) | **Открытый helper** `@tiptap/ai-toolkit`, paid Cloud/on-prem service API | Да: paid серверный primitive/tool execution; helper сам этот сервис не заменяет | Helper `0.5.0` установлен из публичного npm; доступ backend устанавливается отдельным API-прогоном | Собственный Office SDK: адресное чтение, prepare/commit/status/verify, владельцы Documents/Sheets, работа без DOM, Laravel AI/AgenTools/HTTP. **P0**, фазы 3/5/7 |

Не создавать вымышленный канонический `@tiptap-pro/ai-toolkit`: текущая server-install документация указывает `@tiptap/ai-toolkit`. Публичность helper не означает бесплатность серверных API. Своя модель/оркестратор не должна получать приватный ключ сервиса, DSN или возможность расширить доверенный контекст пользователя.

## 4. Conversion и schema companion

| Возможность и первичный источник | Пакет / сервис | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [ConvertKit/schema](https://tiptap.dev/docs/conversion/getting-started/overview) | `@tiptap-pro/extension-convert-kit` | Сам набор editor nodes/marks локален; conversion endpoint отдельно | Установлен `0.16.1` | Собственный проверенный rich-text schema/profile для офисных свойств и миграций. **P1**, compatibility корпуса |
| [DOCX import](https://tiptap.dev/docs/conversion/getting-started/install) | `@tiptap-pro/extension-import-docx`; `/v2/convert/import/docx` | Да для документированного Cloud-конвертера | **npm 403** | Свой import pipeline с исходным Media blob, ограничениями и loss report, а не немая публикация урезанного документа. **P1**, отдельное расширение scope текстового продукта |
| [DOCX export](https://tiptap.dev/docs/conversion/getting-started/install) | `@tiptap-pro/extension-export-docx`; отдельно REST export | **Editor extension локальна**, не требует token/network; REST — сервисный путь | Установлен `0.34.4` | Собственная JSON→DOCX генерация и корпус roundtrip/layout/marks/tables. **P1** |
| [DOC legacy export](https://tiptap.dev/docs/conversion/export/doc/editor-extension) | `@tiptap-pro/extension-export-doc`; `/v2/convert/export/doc` | Да: editor обращается к Convert backend | Установлен `0.7.4`; runtime не проверен | Отдельный legacy-конвертер только по продуктовому требованию; не объявлять DOC эквивалентом DOCX. **P2** |
| [PDF export](https://tiptap.dev/docs/conversion/getting-started/install) | `@tiptap-pro/extension-export-pdf`; REST export | Да в документированном сервисном пути | Установлен `0.7.4`; runtime не проверен | Свой render/print pipeline с закреплённой revision, шрифтами и контролем прав. **P1/P2** |
| [ODT export](https://tiptap.dev/docs/conversion/getting-started/install) | `@tiptap-pro/extension-export-odt`; REST export | Да в документированном сервисном пути | Установлен `0.7.4`; runtime не проверен | Собственный writer/конвертер с известным subset и отчётом свойств. **P2** |
| [EPUB export](https://tiptap.dev/docs/conversion/getting-started/install) | `@tiptap-pro/extension-export-epub`; REST export | Да в документированном сервисном пути | Установлен `0.5.4`; runtime не проверен | Собственная упаковка document/assets/navigation и проверка получателя. **P2** |
| [Markdown export](https://tiptap.dev/docs/conversion/export/markdown/editor-extension) | `@tiptap-pro/extension-export-markdown` + **OSS `@tiptap/markdown`**; отдельно REST export | **Editor extension локальна**, сериализует через `@tiptap/markdown`; REST требует backend | Установлен `0.2.8`; OSS `@tiptap/markdown` `3.35.0` установлен транзитивно; runtime не проверен | Использовать открытый serializer либо собственный поддержанный Markdown profile и download API. **P0/P1**, базовый Markdown |
| [Markdown import service](https://tiptap.dev/docs/conversion/import/markdown/rest-api) | REST `/v2/convert/import/markdown`; текущая overview не требует отдельного актуального import npm extension | Да для этого endpoint; OSS parsing в редакторе — независимый путь | Не проверен отдельно | Свой parser/validator для объявленного subset. Не переносить Cloud-ограничения parser автоматически на весь редактор. **P0/P1** |

Specific Markdown export documentation утверждает отсутствие API calls/credentials у editor package. Это уточняет общую install page, где исключением назван только DOCX. В матрице разделены editor и REST реализации; общий ярлык «Conversion требует Cloud» был бы неверным.

Legacy `@tiptap-pro/extension-import` и `@tiptap-pro/extension-export` сохранены в [migration guide](https://tiptap.dev/docs/conversion/legacy/migration-guide), но не являются выбранными текущими format-specific entrypoints. Их историческое наличие не увеличивает число актуальных независимых capabilities. Conversion сам по себе не решает realtime, OCR или очередь заданий. Наше Sheets XLSX/CSV направление остаётся отдельным: его нет среди перечисленных форматов text Conversion.

## 5. Pages и utilities страницы

| Возможность и первичный источник | Пакет | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Pages](https://tiptap.dev/docs/pages/getting-started/install) | `@tiptap-pro/extension-pages` | Нет для pagination; Cloud conversion подключается отдельно | Прямая установка `0.31.0` — `ERESOLVE`: exact peer ConvertKit `0.15.1` против lab `0.16.1`; `0.31.0` доступен nested у PageKit и source-only archive, runtime не проверен | Свой paginated editor layout, page format/margins/header/footer/zoom, deterministic preview tests. **P2**, оформление офиса |
| [PagesTableKit](https://tiptap.dev/docs/pages/guides/pages-tablekit) | `@tiptap-pro/extension-pages-tablekit` | Нет для layout | Установлен `0.4.8`; runtime не проверен | Pagination-safe rich-text tables, повторение/разбиение и сохранение DOCX cell attrs. **P2**; не spreadsheet engine |
| [PageKit](https://tiptap.dev/docs/pages/guides/pagekit-usage) | `@tiptap-pro/extension-pages-pagekit` | Нет; composition helper | Установлен `0.1.21`; runtime не проверен | Собственная согласованная сборка page/table/break extensions. **P2**, удобство установки |
| [PageBreak](https://tiptap.dev/docs/pages/core-concepts/page-break-node) | `@tiptap-pro/extension-pagebreak` | Нет для editor node | Транзитивно установлен `0.3.6`; runtime не проверен | Свой сериализуемый page-break node, команды/clipboard/drag и экспорт. **P2** |

Pages не содержит каноническую schema сам по себе: current install требует ConvertKit и pagination-safe TableKit. PageKit объединяет Pages/TableKit/PageBreak, но **по умолчанию включает только Pages и PageBreak**; `table:{}` включает таблицы. ConvertKit остаётся отдельной установкой, его собственный table stack отключается при выборе Pages TableKit. Эти companion packages нужно учитывать и исследовать, даже если верхняя карточка продукта одна.

## 6. Composable Docs

| Возможность и первичный источник | Пакет | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Content Protection](https://tiptap.dev/docs/composable-docs/content-protection/getting-started/overview) | `@tiptap-pro/extension-content-protection` | Документированная alpha **client-side only**; это не server ACL | **npm 403**; pilot access не подтверждён | Собственные policy-aware UI и серверная проверка допустимых mutations/выдачи полей. Редакционная redaction не заменяет секретность Y.Doc. **P0 для границы прав / P2 для продукта** |
| [Slots](https://tiptap.dev/docs/composable-docs/slots/getting-started/overview) | `@tiptap-pro/extension-slot` | Документированная alpha client-side; server filling не обещан | **npm 403** | Свои типизированные editable template fields, validation и fill-only workflow, server-side commit для headless. **P2**, шаблоны договоров/уроков |
| [Variables](https://tiptap.dev/docs/composable-docs/variables/getting-started/overview) | `@tiptap-pro/extension-variable` | Runtime values живут в editor; серверного API/sync значений здесь нет | **npm 403** | Собственные references/resolvers к авторизованным источникам, dependency graph и воспроизводимый server export. **P2** |

Имена `extension-slot`/`extension-variable` — в единственном числе. В Variables документ хранит reference, а не готовое значение; его нельзя считать уже сохранённой формулой/ячейкой Sheets. В Content Protection локальные политики разных collaborators не авторизуют чужой network update; совместная синхронизация и защита серверных данных — самостоятельные уровни.

## 7. Diagrams и Whiteboard

| Возможность и первичный источник | Пакет / entrypoints | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Diagrams](https://tiptap.dev/docs/diagrams/getting-started/install) | `@tiptap-pro/extension-diagrams`; `/core`, `/export`, `/theme`, `/echarts`, `/d3`, `/mermaid`, `/drawio`, `/bpmn`, `/pele` | Локальные nodes/renderers; авторизованные Data Sources и часть server exports — отдельная интеграция | **npm 403**; pilot alpha недоступен по текущему npm-доступу | Собственная chart/diagram node schema, adapters к открытым renderers, data source ACL, snapshot/export/fallback и tools агента. **P2**, не подмена вычислителя Sheets |
| [Whiteboard](https://tiptap.dev/docs/editor/whiteboard/getting-started/overview) | `@tiptap-pro/extension-whiteboard`; optional `/collaboration` | Single-user board локален; shared persistence/collaboration требуют backend, Yjs optional entry | **npm 403**; pilot alpha недоступен по текущему npm-доступу | Самостоятельная board model: shapes, arrows, camera, undo, frames, image storage; затем совместный journal/permissions. **P2**, отдельный продукт |
| [Whiteboard agent tools](https://tiptap.dev/docs/ai/ai-toolkit/agents/whiteboard) | Whiteboard + Server AI Toolkit; не новый guessed npm package | Да для документированного server toolkit path | Не проверен | Board-specific owner/tools и отдельные document resources для frame content с теми же правами и командными квитанциями. **P2** |

Diagrams subpaths — exports одного пакета, а не список дополнительных npm installs. Whiteboard не является обычным document + StarterKit: его kit приносит свой document/undo/text schema. Это существенные schema/runtime границы будущего независимого аналога.

## 8. Платный clipboard utility

| Возможность и первичный источник | Пакет | Backend обязателен? | Доступ в срезе | Цель самостоятельной реализации / приоритет |
| --- | --- | --- | --- | --- |
| [Paste Handler](https://tiptap.dev/docs/editor/extensions/functionality/paste-handler) | `@tiptap-pro/extension-paste-handler` | Нет: преобразования pasted HTML в browser | Установлен `3.12.9`; current feature catalog: Team; runtime не проверен | Собственные Office HTML normalization rules с corpus Word/Excel/Outlook/Google Docs/Sheets, ошибками и fallback. **P1**, ввод/clipboard; не XLSX import |

FileHandler и PasteHandler — разные возможности: первый передаёт приложению dropped/pasted files, второй преобразует HTML clipboard. Получение стилизованной таблицы из clipboard не означает сохранность полноценной электронной книги.

## 9. Utilities, которые сейчас доступны как open source

Следующие пункты включены, чтобы «все Pro» не превратилось в ненужное переписывание открытых extensions. Их текущие canonical packages — `@tiptap/*`, а не предполагаемые исторические `@tiptap-pro/*`. Здесь возможное переиспользование OSS также является способом своей поставки без vendor Pro runtime. Обычные Bold/Paragraph/Link и прочий базовый StarterKit перечислены в официальном каталоге, но не дублируются как платные модули.

| Utility и первичный источник | Текущий пакет | Backend | Состояние lab в срезе / цель / приоритет |
| --- | --- | --- | --- |
| [UniqueID](https://tiptap.dev/docs/editor/extensions/functionality/uniqueid) | `@tiptap/extension-unique-id` | Нет; есть server utility | Не установлен. Проверить reuse для blockId, включая split/join/paste/readonly/sync; не обещать соответствие нашей семантике без tests. **P0**, 3.1 |
| [Table of Contents](https://tiptap.dev/docs/editor/extensions/functionality/table-of-contents) | `@tiptap/extension-table-of-contents` | Нет; server generation helper отдельный | Не установлен. Собственный ToC UI + согласованные anchors. **P1** |
| [DragHandle](https://tiptap.dev/docs/editor/extensions/functionality/drag-handle), [React](https://tiptap.dev/docs/editor/extensions/functionality/drag-handle-react), [Vue](https://tiptap.dev/docs/editor/extensions/functionality/drag-handle-vue) | `@tiptap/extension-drag-handle`, `-react`, `-vue-2`, `-vue-3`; отдельно `@tiptap/extension-node-range` | Нет | Не установлены. Reuse block move/select и проверить идентичность при move vs copy. **P1** |
| [Emoji](https://tiptap.dev/docs/editor/extensions/nodes/emoji) | `@tiptap/extension-emoji` | Нет | Не установлен. Стандартный node/fallback и совместимость schema. **P2** |
| [Mathematics](https://tiptap.dev/docs/editor/extensions/nodes/mathematics) | `@tiptap/extension-mathematics` | Нет для render | Не установлен. LaTeX text-node rendering и экспорт профиля. **P2**, текстовая математика не расчёт Sheets |
| [Details](https://tiptap.dev/docs/editor/extensions/nodes/details) | `@tiptap/extension-details` с content/summary | Нет | Не установлен. Reuse collapsible structure, serializer/readonly tests. **P2** |
| [Invisible characters](https://tiptap.dev/docs/editor/extensions/functionality/invisiblecharacters) | `@tiptap/extension-invisible-characters` | Нет | Не установлен. Decorations для визуализации пробелов и разделителей. **P2** |
| [FileHandler](https://tiptap.dev/docs/editor/extensions/functionality/filehandler) | `@tiptap/extension-file-handler` | Extension локален; upload/storage приложения отдельно | Не установлен. Привязать callbacks к Media upload, limits и ACL. **P1** |
| [Selection](https://tiptap.dev/docs/editor/extensions/functionality/selection), [TrailingNode](https://tiptap.dev/docs/editor/extensions/functionality/trailing-node), [upgrade utilities](https://tiptap.dev/docs/guides/upgrade-tiptap-v2) | `@tiptap/extensions` и соответствующие subpath exports; также Focus, Placeholder, CharacterCount, Dropcursor/Gapcursor, UndoRedo | Нет | Bundle установлен `3.35.0`. Reuse; функция должна быть явно зарегистрирована и согласована с collaboration history. **P0/P1** |
| [Find and Replace](https://tiptap.dev/docs/editor/extensions/functionality/find-and-replace) | `@tiptap/extension-find-and-replace` | Нет | Не установлен. Client search UX; не эквивалент серверной `text.replace_exact` с receipt. **P1** |
| [TextStyleKit](https://tiptap.dev/docs/guides/upgrade-tiptap-v2) | `@tiptap/extension-text-style`: color/background/font-size/family/line-height | Нет | Пакет установлен `3.35.0`. Reuse допустимых marks/attrs, schema parity. **P1** |
| [Core Markdown](https://tiptap.dev/docs/editor/markdown) | `@tiptap/markdown` | Нет для parsing/serialization | Установлен транзитивно `3.35.0`. Совместимый локальный Markdown profile и export. **P0/P1** |
| [Collaboration](https://tiptap.dev/docs/editor/extensions/functionality/collaboration), [Caret](https://tiptap.dev/docs/editor/extensions/functionality/collaboration-caret) | `@tiptap/extension-collaboration`, `@tiptap/extension-collaboration-caret` + Yjs | Shared mode требует provider/server; сами bindings открытые | Установлены `3.35.0`. Основа своей коллаборации, без утверждения, что bindings содержат все Pro backend features. **P0** |

## 10. Программа дальнейшего анализа по всем модулям

1. Для каждой canonical package из таблиц снять metadata/version/peer graph, выяснить доступность с текущим npm token, зафиксировать 403 отдельно от transport failure. Дополнительный проход выполняется последовательно отдельным исполнителем и не должен менять названия по догадке.
2. У доступных пакетов изучить public types, runtime export surface и наблюдаемую связь frontend/backend. Source analysis должен давать нашу спецификацию поведения, state transitions, storage/channel dependencies, не перенос proprietary code в собственный пакет.
3. Для каждой функции построить свой минимальный corpus: success, concurrency, permission, offline/reload/restore, headless и schema mismatch там, где применимо. Для layout/clipboard добавить браузерные fixtures, для conversion — исходный файл и отчёт свойств, для AI — детерминированный executor до настоящей модели.
4. У недоступного модуля сохранить документированные API и честный статус «нужен доступ для code-level/runtime проверки». UI/demo observation и документация не заменяют отсутствующий пакет или серверный entitlement.
5. Каждая самостоятельная замена использует владельцев и инварианты основного плана: один canonical payload, ACL при выдаче и commit, поколения, command identity, подтверждение записи и выводимые projections. Исследование всего Pro не отменяет самостоятельные Sheets/SDK требования — см. [product-context](product-context.md).

Этот каталог добавляет полноту направлений исследования. Он не устанавливает новые зависимости, не изменяет основной `plan/` и не объявляет все перечисленные аналоги реализованными.
