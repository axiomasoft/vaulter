# Результаты исследования Tiptap Pro

Обновлено 2026-11-21. Версии клиента зафиксированы в `lab/npm-shrinkwrap.json`. Все документы опытов синтетические.

## Доступ и версии

Ключ ES256 и npm-токен сохранены в корневом `.env` с правами `0600`. Окружение Test принимает подписанные JWT для Document Server. Токен реестра позволил установить основные пакеты коллаборации, Comments, Snapshot, Snapshot Compare, Tracked Changes, Basic AI и дополнительные пакеты Pages, Clipboard и Conversion. Открытый `@tiptap/ai-toolkit` также установлен. Полный перечень, версии и статусы отдельных попыток собраны в [каталоге](module-catalog.md) и [отчёте об установке](lab/pro-inventory.md).

Реестр вернул `403` для Compare, Client AI Toolkit, Import DOCX, Content Protection, Slot, Variable, Diagrams и Whiteboard. По ним доступны сведения из документации. Pages 0.31.0 удалось получить как зависимость PageKit. Прямая установка конфликтует с точной версией ConvertKit, которую требует этот выпуск Pages. [Граф пакетов](results/package-inventory.json) · [Дополнительные сборки и контрольные суммы](results/additional-pro-source-inventory.json)

## Опыты на вашем Cloud

| Сценарий | Наблюдаемый результат | Данные |
| --- | --- | --- |
| Document REST с ES256 JWT | Создание, чтение JSON/Yjs, differential PATCH в метаданные, повторное чтение, создание и список snapshots, HEAD. JSON и основной фрагмент сохранились | [REST](results/rest-2026-11-21T08-19-44-621Z-4100a82a-cd26-481a-89a4-7bcc8f0c9827.json) |
| Два клиента и offline/reconnect | Обе вставки сохранились после соединения и прочитались в новом контексте. Отдельная попытка остановилась на открытии свежего WebSocket | [Успешный прогон](results/browser-cloud-2026-11-21T08-23-47-648Z.json) · [Диагностика подключения](provider-lifecycle-findings.md) |
| Comments и versions | Thread и ответ видны обоим клиентам. Preview оставил живой текст без изменений. Restore вернул 30 → 15. Независимый REST readback после закрытия вкладок подтвердил bold, два комментария и resolved | [Браузерный обмен](results/cloud-comments-versions-2026-11-21T08-30-54-361Z.json) · [Readback](results/readback-comments-versions-2026-11-21T08-38-03-244Z.json) |
| Tracked Changes | В отдельных браузерных контекстах Bob отклонил вставку Alice и принял замену 15 → 30. После закрытия вкладок REST вернул текст с 30 без метки предложения | [Браузерный обмен](results/cloud-tracked-2026-11-21T09-25-34-839Z.json) · [Readback](results/readback-tracked-changes-2026-11-21T09-27-43-374Z.json) |
| Серверная точечная правка | Node обновил p42 с 15 до 30 до запуска браузера. Сохранились bold, p71 «Банк Б» и оба blockId. Повтор тех же Yjs-байтов оставил один эффект. В другом документе текущее 20 привело к отказу локального адаптера до PATCH. Свежий браузер с read-only токеном прочитал итог | [Сервер и свежий браузер](results/server-agent-2026-11-21T09-33-50-408Z.json) · [Независимый REST readback отдельного прогона](results/readback-agent-2026-11-21T08-38-02-628Z.json) |

Успешный REST readback подтверждает доступное с сервера содержимое после закрытия вкладок. Гарантии при падении серверного процесса, SQL-граница и внутренняя схема Cloud остаются неизвестными. Повтор одинакового бинарного update проверяет идемпотентность Yjs. Повтор логического намерения, подтверждение рецензента, транзакционные предусловия и квитанция остаются обязанностью владельца Vaulter. [Спецификация замены](replacement-spec.md)

При отдельных попытках новый сокет оставался в `CONNECTING` до отправки handshake. Попытка Node-клиента также достигла таймаута. Браузерный readback в опыте comments/versions остановился на открытии нового сокета, после чего прошёл независимый REST readback. В сценарии Cloud read-only сервер вернул отрицательное подтверждение update, а последнее повторное подключение достигло таймаута. [Диагностика](provider-lifecycle-findings.md)

## Что показали установленные модули

| Модуль | Проверка | Данные |
| --- | --- | --- |
| Provider, Comments, Snapshot, Snapshot Compare | 19 локальных проверок структур Yjs, CRUD, команд истории и клиентского сравнения. Cloud дополнительно показал `version.created`, `version.preview`, `document.reverted`, `document.saved` и `content.changed` | [Разбор кода](source-level-review.md) · [JSON](results/source-state-probe.json) |
| Tracked Changes | 28 проверок восьми сценариев, включая marks, attrs, split, группировку, вложенные правки разных авторов и remote updates | [Разбор](tracked-changes-design.md) · [JSON](results/tracked-changes-probe.json) |
| Basic AI и Server AI Toolkit | 13 проверок с локальными подставными ответами. Plain-text replacement потерял bold в испытанном сценарии. Preview с последующим accept сохранил bold. Проверены streaming, reject и независимая правка во время стрима | [Разбор AI и проект чата](ai-reverse-engineering.md) · [JSON](results/ai-toolkit-local.json) |
| DOCX | 28 локальных проверок OOXML, форматирования, списков, таблиц, комментариев и текстовых revisions. В опыте с `markChange` bold попал в DOCX, а отдельное событие изменения форматирования в Word отсутствовало | [Разбор конвертации](conversion-design.md) · [JSON](results/conversion-probe.json) |
| PasteHandler, Markdown, Pages | Вставка через редактор преобразовала ARIA-заголовок, очистила Office-элементы и восстановила вложенный список. Markdown совпал с открытым сериализатором. Физический разрыв сохранился как `pageBreak` и увеличил число страниц в PageKit с обоими проверенными наборами схемы | [JSON](results/editor-utilities.json) |
| DOC, PDF, ODT, EPUB | Локальный mock получил multipart с `docxFile` для всех четырёх форматов. В режиме PDF без ExportDocx клиент передал JSON с сериализованным `doc`. Удалённый конвертер в этих пяти опытах не вызывался | [JSON](results/editor-utilities.json) |
| Локальная коллаборация и права | 6 Playwright-сценариев проверяют merge, fresh read, scope JWT, отказ read-only, несовпадение схем и удалённые accept/reject | [Проверки стенда](lab/validation.md) · [Несовпадение схем](schema-findings.md) |

Отказ read-only записи не откатил уже внесённое изменение в локальный Y.Doc отправителя. Пишущие клиенты и новое чтение в локальном опыте его не получили. При несовместимой схеме `enableContentCheck` и вызов `disableCollaboration` сохранили исходный заголовок для следующего клиента. Для Vaulter это два разных исхода, которые стоит различать в клиентском автомате.

## Повторение

После установки зависимостей команды запускаются из корня репозитория. Cloud-браузерные сценарии используют запущенный `lab/` на `127.0.0.1:5193`. Каждый сценарий записи создаёт новые документы с префиксом `tiptap-pro-lab-`. При закрытии браузеров Cloud оставляет такие документы в тестовом окружении.

```bash
node plan/tiptap-pro/scripts/capture-browser.mjs --mode=cloud
node plan/tiptap-pro/scripts/cloud-comments-versions.mjs
node plan/tiptap-pro/scripts/cloud-tracked-changes.mjs
node plan/tiptap-pro/scripts/cloud-rest.mjs
node plan/tiptap-pro/scripts/server-agent-story.mjs
```

`server-agent-story.mjs --server-only` ограничивает опыт операциями Node и REST. `cloud-readback.mjs` принимает `--kind` и имя ранее созданного синтетического документа. Для `comments-versions` дополнительно нужен `--thread` из соответствующего результата.

Отдельные локальные опыты используют установленные npm-пакеты и системный Chrome. AI-проверка подставляет ответы и не обращается к LLM.

```bash
node plan/tiptap-pro/scripts/source-state-probe.mjs
node plan/tiptap-pro/scripts/tracked-changes-probe.mjs
node plan/tiptap-pro/scripts/ai-toolkit-probe.mjs
node plan/tiptap-pro/scripts/conversion-probe.mjs
node plan/tiptap-pro/scripts/editor-utilities-probe.mjs
node --test plan/tiptap-pro/scripts/*.test.mjs
```

В JSON сетевого обмена auth payload удалён, а нераспознанные бинарные кадры представлены длиной и хешем. Отдельно хранятся результаты успешных и неуспешных запусков. Артефакты лаборатории, установленные пакеты и приватные параметры исключены из Git.
