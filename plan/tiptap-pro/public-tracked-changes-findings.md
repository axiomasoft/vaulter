# Tracked Changes: наблюдение публичного демо

Дата: 2026-11-21. Источник: [официальное демо Tracked Changes](https://embed-pro.tiptap.dev/preview/Experiments/TrackedChanges). Это **публичный локальный playground**, а не наше Cloud-окружение и не проверка совместного редактирования двух пользователей.

Воспроизводимый runner: [`scripts/public-tracked-changes.mjs`](scripts/public-tracked-changes.mjs). Результат: [`results/public-tracked-changes.json`](results/public-tracked-changes.json), `status=passed`, **11 assertions, 16 шагов**. Запуск:

```bash
node plan/tiptap-pro/scripts/public-tracked-changes.mjs
```

Требуются установленные зависимости `lab/` и системный Chrome. Runner запускает отдельный headless Chrome через Playwright; существующая desktop-сессия не используется. Состояние каждого запуска изолировано новым browser context.

## Что проверено

Внешняя страница `/preview/Experiments/TrackedChanges` отвечает HTTP 200 и содержит iframe. Рабочая страница после redirect — `https://embed-pro.tiptap.dev/src/experiments/trackedchanges/react/`. Внутри находится `.tiptap.ProseMirror[contenteditable="true"]` и кнопки управления tracking/review. На DOM-элементе редактора публично доступен объект `.editor`, включая `getJSON()` и обычные команды Tiptap. Поэтому наблюдение использует два независимых представления: DOM `data-suggestion-*` и ProseMirror JSON.

Исходные предложения демо очищаются через `Reject All`. Затем при выключенном tracking создаётся отдельный paragraph с нашим текстом `ZZLAB_BASE alpha beta omega`. Дальнейшие ввод, Backspace и replacement выполняются браузерным input; публичный Editor API задаёт точный диапазон selection и фокус. Это устраняет рассогласование DOM selection и ProseMirror selection после нажатия toolbar-кнопок.

| Операция | Наблюдаемое поведение |
| --- | --- |
| Вставка при включённом tracking | Создаётся `suggestion` mark с `type=add`; вставленный текст присутствует в документе |
| Reject insertion | Синтетический вставленный текст удаляется, suggestion markers исчезают |
| Accept insertion | Текст сохраняется, suggestion markers исчезают |
| Удаление существующего текста | Текст остаётся в документе под mark `type=delete` до решения reviewer |
| Reject deletion | Текст остаётся, marker удаляется |
| Accept deletion | Текст действительно удаляется |
| Replacement | Возникают связанные `replaceDeletion` и `replaceInsertion` с одним suggestion ID |
| Reject replacement | Возвращается исходное значение `ZZLAB_REPLACE_OLD` |
| Accept replacement | Остаётся новое значение `ZZLAB_REPLACE_NEW` |
| Выключенный tracking | Ввод `ZZLAB_UNTRACKED` изменяет документ напрямую, без suggestion markers |

Кнопки `Accept All` и `Reject All` использованы в проверках. Индивидуальный review, review диапазона, несколько авторов, undo/redo и таблицы этим runner не проверены.

## Наблюдаемый формат данных

Текстовые suggestions представлены обычными ProseMirror text nodes с mark:

```json
{
  "type": "suggestion",
  "attrs": {
    "id": "<generated-suggestion-id>",
    "type": "add | delete | replaceDeletion | replaceInsertion",
    "userId": "current-demo-user",
    "createdAt": "<ISO timestamp>",
    "updatedAt": "<ISO timestamp>",
    "userMetadata": { "name": "Demo User", "avatarUrl": "<public avatar URL>" },
    "markChanges": null
  }
}
```

Это сводка наших synthetic JSON nodes, а не извлечённый исходный код расширения. Полные снимки **только нашего paragraph** находятся в `steps[].snapshot.syntheticBlocks`; исходный текст playground и bundles в отчёт не копируются.

DOM отражает те же сведения через `data-suggestion-id`, `data-suggestion-type`, `data-suggestion-user`, `data-suggestion-created`, `data-suggestion-updated`, `data-suggestion-user-metadata`. Для marks есть также пустой атрибут `data-suggestion`. У block paragraph в JSON присутствует набор nullable suggestion-атрибутов, например `suggestionId`, `suggestionType`, `suggestionAttributeChanges`; их существование в schema не доказывает корректность block-level операций, которые здесь не выполнялись.

Особенность replacement: при замене `ZZLAB_REPLACE_OLD` на `ZZLAB_REPLACE_NEW` общий префикс `ZZLAB_REPLACE_` остаётся обычным текстом. Под `replaceDeletion` находится только `OLD`, под `replaceInsertion` — только `NEW`; обе половины имеют один ID. Следовательно, нельзя ожидать, что suggestion обязательно хранит две полные копии выделенного диапазона. Видимый порядок частей DOM может отличаться от порядка text nodes JSON; для результата review следует применять семантику принятия/отклонения, а не читать сырой `textContent`.

## Сеть

В зафиксированном успешном прогоне загрузка страницы создала 63 HTTP requests. **Каждый из 16 последующих шагов дал 0 новых HTTP requests и 0 WebSocket frames; за весь прогон не открылось ни одного WebSocket.** В результате сохранены endpoint metadata, resource types и HTTP statuses, без headers и response bodies. URL query values и fragments очищены.

Это доказывает, что перечисленные текстовые review-операции работают в этом playground без серверного round-trip. Это не доказывает отсутствие сетевых зависимостей у других функций, фоновых операций за пределами наблюдаемого интервала или режима Cloud collaboration. Сохранение после перезагрузки, ACL и долговечность этих данных не проверялись.

Установленный в нашем `lab/` номер Tiptap нельзя приписывать bundle публичного демо: версия самого Tracked Changes из UI не установлена. Результат фиксируется URL, датой, версией Chrome и фактическими JSON/DOM observations.

## Следствия для собственной реализации

Далее перечислены проектные выводы из эксперимента, а не утверждения о внутреннем устройстве Tiptap:

1. Для text review достаточно собственной ProseMirror schema/commands над marks; решение accept/reject может быть локальной транзакцией с последующей штатной синхронизацией документа через Yjs.
2. Удалённый текст нужно удерживать до review. Чтение агентом требует явно выбранной проекции: исходный текст, текст с принятыми предложениями либо представление review; обычный `getText()` не заменяет этот контракт.
3. Replacement должен связывать обе половины одной сущностью review, сохранять автора и время и корректно обрабатывать общие префиксы/суффиксы.
4. Авторство, reviewer ACL и audit нельзя доверять только клиентским `userId`/marks: этот playground серверную проверку не демонстрирует. В Vaulter такие гарантии должны задаваться отдельно.
5. Следующий этап — два клиента с Yjs, группировка соседних правок, пересечение предложений разных авторов, undo/redo, block/list/table операции и поведение клиента без suggestion schema. Текущий эксперимент не закрывает эти пункты.
