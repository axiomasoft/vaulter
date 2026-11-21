# DOCX conversion: локальный экспорт и собственная архитектура

2026-11-21. Исследованы установленные `@tiptap-pro/extension-export-docx@0.34.4` и `@tiptap-pro/extension-convert-kit@0.16.1`: публичные exports/types, отдельные участки runtime pipeline и синтетический результат OOXML. Vendor source и bundles в отчёт не копируются.

Воспроизводимый опыт: [`scripts/conversion-probe.mjs`](scripts/conversion-probe.mjs), evidence: [`results/conversion-probe.json`](results/conversion-probe.json). **28 checks PASS, global fetch вызван 0 раз.** Три DOCX с собственным содержимым лежат в ignored `lab/artifacts/`: baseline, comments, revisions. Probe использует Node, существующие npm dependencies и Python standard library `zipfile`/`ElementTree`; npm/lockfile и `lab/main` не меняет, `.env` не читает.

## 1. Что фактически работает без Convert Service

Публичная функция `exportDocx({ document, exportType:'buffer', customNodes:[], styleOverrides:{} })` приняла Tiptap JSON и вернула Node Buffer с полноценным DOCX. До загрузки Pro package глобальный fetch заменён на trap, который запрещает запрос. В baseline находятся heading, bold, hyperlink, bullet/ordered lists, table и hard break; отдельные samples содержат comments и tracked insert/delete.

Это доказывает локальность именно этих export paths. Для них не нужны appId/JWT или работающий Cloud WebSocket. Это не проверка всех опций и не доказательство отсутствия сети при изображениях, автоматическом embedding шрифтов или иных remote inputs.

Поддержанные output виды по API: `buffer`, `string`, `base64`, `blob`, `stream`. Buffer/stream предназначены для server environment. `ExportDocx` — editor extension/command facade, тогда как `exportDocx` — самостоятельная функция. Editor command умеет подбирать live editor CSS, зарегистрированную нумерацию, comments/provider и notes; standalone вызывающий передаёт эти дополнительные данные явно.

Package metadata требует `docx:^9.7.1`; установленная dependency — `docx@9.16.0`. Значительная часть docx machinery уже включена в Pro export bundle; наличие установленного 9.16.0 не доказывает точную версию каждой bundled внутренней части. Наш результат зависит от зафиксированного Pro artifact, а не от предположения о его build dependency.

## 2. Восстановленный pipeline

Это source-level описание конкретного export entry path; внутренности hosted import service из него не следуют.

1. Принять Tiptap JSON, style/config overrides, adapters, optional notes/comments/fonts.
2. Скомпилировать CSS style input в DOCX options и объединить с explicit `styleOverrides`, которым отдаётся приоритет.
3. При наличии custom-node DSL скомпилировать его один раз; function adapter для того же node type имеет приоритет. Runtime context передаётся вниз рекурсивно, вместе с лимитами глубины/размеров.
4. Опционально собрать/встроить шрифты; автоматическая font path может обращаться к сети и обрабатывает ошибки по семействам.
5. Подготовить comments: значения Word IDs, root/reply metadata и lookup `threadId → Word comment IDs` для anchor emission.
6. Создать document-scoped revision ID allocator, numbering/style definitions и mappings для footnotes/endnotes.
7. Обойти top-level nodes; node adapters создают Paragraph/Table и inline runs. Overrides могут быть объектами или callback с контекстом table/row/cell/document index. Результаты собираются в один document.
8. Отдельно разрешить header/footer slots и их scoped overrides, notes и section/page settings.
9. Упаковать OOXML relationships/content types/parts в DOCX ZIP через packer; вернуть запрошенный output type.

В exports доступны, среди прочего, `convertNode`, `convertParagraph`, `convertHeading`, `convertTextNode`, `convertBulletList`, `convertOrderedList`, `convertTable`, `convertTableRow`, `convertTableCell`, `convertImage`, `convertHeader`, `convertFooter`, `createExportStyles` и custom DSL compiler. Для собственной реализации эти Pro helpers не нужны: они показывают границы задач, которые можно реализовать собственными adapters.

## 3. Node/mark → OOXML mappings

| Вход | Выход / сохранённая информация | Статус |
| --- | --- | --- |
| `heading(level=2)` | Paragraph style `Heading2` | Проверено |
| `paragraph` + `textAlign=center` | `w:p`, `w:pPr/w:jc` | Проверено |
| `indent=24` CSS px | `w:ind/@w:left=360` twips | Проверено |
| `spacingBefore=12`, `spacingAfter=6` px | `w:spacing before=180 after=90` | Проверено |
| Text + bold | `w:r/w:rPr/w:b` и `w:t` | Проверено |
| Link mark | `w:hyperlink` с `r:id`; внешний relationship с target URL | Проверено; URL не скачивается |
| Bullet/ordered list | Paragraph `numPr`; definitions в `word/numbering.xml`, formats bullet/decimal | Проверено |
| Table 2×2 | `w:tbl`, две `w:tr`, четыре `w:tc`, `w:tblGrid` из двух columns | Проверено |
| Cell background `#ddeeff` | Shading fill `DDEEFF` | Проверено |
| Cell `verticalAlign=middle` | `w:vAlign val=center` | Проверено |
| Row height 24 px / atLeast | `w:trHeight val=360 hRule=atLeast` | Проверено |
| `hardBreak` | `w:br` | Проверено |
| Italic/underline/strike/sub/superscript/code/textStyle | Run properties; exact underline pattern, font/color/size требуют дополнительных attrs | Source/types; здесь не проверены по отдельности |
| Images | ImageRun + media/relationship, размеры/crop и error policy | Source/types; image export здесь не проверен |
| Header/footer/notes/page settings | Отдельные package parts и section properties | Source/types; здесь не проверены |

При `orderedList(start=3)` fixture текст и numbered paragraphs сохранились. Наш assertions проверяет наличие decimal definitions, но **не доказывает корректный старт счётчика именно с 3**. Для него нужен отдельный assertion на numbering level override/instance.

## 4. ConvertKit — schema для сохранения информации

ConvertKit сам не импортирует и не экспортирует файл. Он поставляет совместимый набор Tiptap node/mark extensions с дополнительными attributes. Прогон через меньшую schema способен убрать данные, которые последующий export уже не восстановит.

Проверенный schema inventory находится в `convertKitSchema` evidence. Для CJS `getSchema([ConvertKit])` установленная комбинация дала `TypeError: x.default.configure is not a function` в text-align default import. Source inspection связывает это с CJS interop wrapper вокруг `@tiptap/extension-text-align`, а не с DOCX conversion function. Публичный вариант `ConvertKit.configure({textAlign:false})` позволил получить schema inventory; именно эта настройка явно записана в result. Browser/ESM integration этим Node опытом не объявляется сломанной или проверенной.

Группы сохраняемых attrs:

- Paragraph/heading: indent/first-line/right indent, spacing before/after, line height, font size, contextual spacing, keep-next/keep-lines. Отсутствующее значение обычно null, чтобы применялось наследование styles.
- TextStyle: font family/size, color, background, line height. Underline отдельно сохраняет Word-specific `underlineType`, а не только приблизительный CSS pattern.
- Image: src/alt/title, width/height и cropTop/Bottom/Left/Right.
- Table: width/indent, outer/inside borders, cell margins; row height и `exact|atLeast`; cell span/column widths/background/vertical alignment/borders/margins.
- Lists: обычный start/type, optional `numberingFormat` на outer list и отдельный реестр многоуровневых numbering definitions. Registry нельзя заменить одним CSS `list-style-type`.
- Bookmark/page break и поддержанные note/revision/comment records относятся к отдельным node/mark/data contracts.

Public subpath `/ooxml-registry` содержит независимый от DOM реестр единиц/attrs, encoder/decoder и helpers table grid/revision identity. В runtime `ooxmlAttributes` данного artifact перечислено 19 entries; schema содержит и дополнительные attrs, поэтому нельзя считать эти 19 entries полной schema Word.

Ключевые единицы: 15 twips/px, 20 twips/pt, 9525 EMU/px, 240 units для proportional line spacing, 50 units/процент table width, 1000 units/процент crop, 8 border units/pt. Первые две конверсии подтверждены assertions; остальные здесь рассмотрены по declarations/registry. В поставляемой metadata отмечена асимметрия старой half-point→pixel формулы, поэтому round-trip чисел требует tolerance, а не слепого сравнения float.

Raw OOXML passthrough существует как зарезервированный type, но в документации этой версии прямо отмечен как не имеющий активной preservation behavior. Нельзя обещать lossless сохранение произвольных Word features только на основании названия registry.

## 5. Comments и tracked revisions

Standalone comments export принимает `comments:{threads:[...]}`. Наш собственный text node имеет mark `inlineThread` с `data-thread-id`, matching thread содержит root/reply comments. Полученный ZIP содержит `word/comments.xml`, `word/commentsExtended.xml`, `word/commentsIds.xml`; range start/end сбалансированы вокруг anchor.

Root/reply импортированные Word IDs из synthetic input `originalWId='7'/'8'` сохранены. Root range использует ID 7, references указывают на 7 и 8. Source/types дополнительно различают Word-stable `originalDurableId` и обычный `w:id`, который Word может перенумеровать. Наш пример содержит durable IDs и создаёт metadata parts, но не проверяет reconciliation после редактирования файла в Microsoft Word.

Публичные provider IDs нельзя безусловно использовать как Word integer IDs. Пакет имеет fallback hash-based allocation и collision avoidance; для собственного экспорта нужен собственный document-scoped allocator и явная таблица соответствий, а не вера в отсутствие hash collisions.

`suggestion(type=add/delete)` из synthetic sample экспортирован как настоящие Word `w:ins/w:del`; удалённый текст находится в `w:delText`. Автор `Synthetic Author` и дата сохранены, миллисекунды времени нормализованы. Это подтверждено четырьмя OOXML checks.

Существенный предел: sample с `suggestion(type=markChange)` и добавленным bold сохранил текущее bold, но получил **0 `w:rPrChange`**. Следовательно, этот опыт не подтверждает native Word formatting-review round-trip. Наличие docx classes для property changes в bundle не означает, что соответствующий Pro adapter их использует. Нельзя объединять проверенные текстовые revisions и непроверенные/неперенесённые formatting proposals в обещание «все tracked changes сохраняются».

## 6. Где возникают сетевые ветки

В рассмотренном exporter source remote operations используют fetch. Разделение важно для своей реализации:

| Ветка | Сеть / credentials | Что доказано здесь |
| --- | --- | --- |
| Baseline JSON→DOCX, comments, text revisions | Сеть не вызвана | 28 checks и fetch trap |
| Image src | Fetch bytes, проверить image format/dimensions, затем media part | Source reviewed; не выполнено |
| Явные `fonts:[{name,data}]` TTF/OTF | Уже предоставленные bytes можно встроить локально | Types/source; не выполнено |
| `embedFonts=true` в browser | Поиск `@font-face`, возможный Google Fonts CSS/asset fetch | Source/types; не выполнено |
| WOFF2 automatic font path | POST Convert endpoint `/fonts/convert`, multipart file/fontFamily; bearer JWT + `X-App-Id` | Source reviewed; запрос не отправлялся |
| Hosted DOCX import | Отдельная сервисная ветка с собственным entitlement/API | **Не проверена** |

ConvertKit endpoint helper выбирает region или explicit endpoint; сам helper запросов не делает. Для WOFF2 appId/JWT относится к конкретной font conversion ветке, а не к каждому обычному DOCX export. `embedFonts` browser-only; в Node на автоматический поиск page fonts полагаться нельзя.

В текущем исследовании доступ к hosted import ограничен 403 в общей проверке сервисов. Ни успешный standalone export, ни разбор экспортированного ZIP **не доказывают работу DOCX→Tiptap import** или внутренний серверный parser.

## 7. Независимая реализация без Pro

Следующий проект опирается на собственную model/normalization и OOXML, а не на копирование vendor adapters/DSL.

### Общий слой

Ввести versioned intermediate document: blocks/runs, tables/numbering, assets/fonts, notes/comments/review records, page/section styles. Tiptap JSON adapter сохраняет поддержанные conversion attrs в своём namespace и явно регистрирует известные unsupported features. Один registry единиц и значений используется import, editor HTML и export. Default/inherited/explicit values различаются; null не превращается автоматически в 0.

Document projection выбирается явно: исходная, proposed или review. Проекция для агента и exported DOCX должна совпадать с выбранным режимом. Текстовые revisions и formatting revisions — разные возможности с отдельной матрицей поддержки.

### Экспорт

Для Node/браузера можно использовать MIT-библиотеку [docx](https://github.com/dolanmiu/docx), которая предоставляет declarative document generation на обеих платформах. Собственный adapter registry сопоставляет PM nodes/marks со своими intermediate records, затем с Paragraph/TextRun/Table и relationships. Архив и OOXML vocabulary регулируются [ECMA-376](https://ecma-international.org/publications-and-standards/standards/ecma-376/).

Отдельные сборщики создают numbering IDs, relationships, comments IDs, revision IDs, media и font parts. Изображения приходят как разрешённые media asset IDs/bytes; worker разрешает их в storage. Для браузерного preview отдельно поддерживается CSS, а document export использует явные styles/units; computed DOM не должен быть единственным источником сохранённой геометрии.

Custom nodes подключаются typed adapters с ограниченным результатом block/inline/table, а не строковым eval. Если нужен переносимый JSON DSL, его grammar, limits и validators проектируются отдельно под наши типы. Для font embedding первый scope — TTF/OTF bytes; WOFF2 декодирование можно вынести в собственный worker, без обязательной зависимости от Tiptap Convert API.

### Импорт

Собственный importer читает ZIP parts и relationships, затем styles/numbering/sections, потом body runs/tables/comments/notes/revisions в intermediate model. Нужен XML parser с корректными namespaces, лимиты распаковки и запрет автоматического скачивания external relationships. HTML→Tiptap как единственный путь теряет numbering geometry, originals revisions и специализированные attrs, поэтому rich import должен маппировать OOXML напрямую.

На первом этапе достаточно документированного supported subset: paragraphs/headings, text marks, lists, basic tables и media. Comments/revisions, merged cells, section headers, fonts и advanced layout добавляются отдельными вертикалями с fixtures. Для неподдержанных свойств сохранять original asset и отчёт потерь; lossless passthrough становится отдельным форматом хранения, а не неявным обещанием.

### Проверки

Текущий probe уже задаёт acceptance subset на 28 assertions; его можно запускать против своей реализации с тем же собственным sample и XML assertions. Нормализованные XML/semantic checks предпочтительнее равенства ZIP bytes: timestamps и relationship IDs могут отличаться.

Следующие необходимые проверки: ordered start=3; nested numbering; rowspan/colspan; paragraph styles inheritance; linked/in-document hyperlinks; images/crop; header/footer/notes; font embedding; native formatting revisions; comments после Word save с переиндексацией IDs; DOCX→model→DOCX→model. До появления этих fixtures и успешного import нельзя заявлять полной Word fidelity.

## Дополнительная проверка клиентских конвертеров

Для DOC, PDF, ODT и EPUB вызовы установленных расширений дошли до подставного `fetch`. Все четыре передали multipart с готовым `docxFile`. PDF без ExportDocx передал JSON с сериализованным `doc`. Возвращённый подставным сервером Blob дошёл до callback. Эти опыты фиксируют клиентскую часть протокола. [Runner](scripts/editor-utilities-probe.mjs) · [Результаты](results/editor-utilities.json)
