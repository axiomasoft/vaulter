# Несовместимые схемы: локальный эксперимент

Дата: 2026-11-21. Результат **локального OSS-стенда**, не Tiptap Cloud и не закрытого Pro extension. Проверяется риск клиента без node type, который нужен текущему документу; heading служит воспроизводимым аналогом неизвестного Pro node.

**Результат: PASS — 1 тест, 1.0 s; весь запуск Playwright 1.4 s.** Использован headless Chrome, `http://127.0.0.1:5193`, локальный Hocuspocus из стенда. Тест: [schema-compatibility.spec.mjs](lab/tests/schema-compatibility.spec.mjs).

Установленный профиль: Tiptap core/StarterKit/Collaboration/CollaborationCaret `3.35.0`, `@tiptap/y-tiptap` `3.3.1`, Hocuspocus provider/server `4.9.0`, Yjs `13.6.33`, Playwright `1.65.1`. Эти версии не означают совместимость с другими комбинациями.

## Действия и проверенные наблюдения

1. Обычный writer подключился к уникальной комнате `tiptap-pro-lab-schema-*`. Его редактор записал heading уровня 2 и следующий paragraph. Оба обычных редактора увидели одинаковое дерево, их `unsynced` достиг нуля.
2. В отдельном browser context создан **новый Y.Doc и новый provider**, имеющий разрешение записи в исходную комнату. Editor создан через constructor действующего редактора. Его extensions клонированы; в StarterKit отключён `heading`, Collaboration привязан к новому Y.Doc, Caret — к новому provider. Это не read-only reader: сохранность нельзя объяснить запретом записи.
3. У legacy editor включён `enableContentCheck: true`; обработчик `onContentError` вызывает `disableCollaboration()`. Тест подтвердил отсутствие `heading` в legacy schema, успешную аутентификацию, хотя бы одно событие `contentError` и выполнение обработчика отключения collaboration.
4. Третий независимый normal browser context подключился к исходной комнате **после** этого события. Оба его редактора получили исходные heading и paragraph. Writer также сохранил исходное дерево. Тест не восстанавливает и не переписывает содержание после подключения legacy.

Дерево, которое сравнивается до подключения legacy и в новых normal clients:

```json
{
  "type": "doc",
  "content": [
    {
      "type": "heading",
      "attrs": { "level": 2 },
      "content": [{ "type": "text", "text": "Heading unavailable in legacy schema" }]
    },
    {
      "type": "paragraph",
      "content": [{ "type": "text", "text": "A normal paragraph remains editable by current clients." }]
    }
  ]
}
```

В тесте есть JSON attachment `schema-compatibility-observation`: исходный snapshot, legacy schema/error/callback state, финальный snapshot нового клиента и outcome. Он доступен в reporter, сохраняющем attachments; обычный console list reporter показывает итог проверок. В attachment не включаются JWT, ключи, auth frames или private source.

## Что это устанавливает и что остаётся неизвестным

На проверенных версиях поддержанный путь `enableContentCheck` + обработчик `disableCollaboration()` обнаружил неизвестный heading и **не удалил его из shared server state**, доступного новым normal clients в этом запуске. Это положительная проверка конкретного механизма защиты schema compatibility.

Эксперимент не проверяет режим без `enableContentCheck`, неизвестные marks/attrs, изменение схемы уже открытого документа, исторические ревизии, offline legacy writes, произвольные прямые Yjs mutations или server restart. Тест читает новое подключение к локальному серверу; его in-memory persistence не доказывает дисковую долговечность. Отдельно не заявляется блокировка всех UI-команд legacy editor: обязательные assertions относятся к `contentError`, callback и сохранению server tree.

Для Vaulter это аргумент включить такую проверку в приёмку schema upgrades и удаления Pro dependencies. Он не заменяет существующие плановые правила: одинаковые schema у писателей, объявление поддерживаемой версии, отказ несовместимому writer и новое поколение при управляемом переходе. Nodes/marks закрытых расширений требуют самостоятельного корпуса.

Официальная документация описывает защиту от неизвестного schema content и минимальные версии её реализации: [Collaboration: content that does not fit the schema](https://tiptap.dev/docs/editor/extensions/functionality/collaboration#content-that-doesnt-fit-the-schema). Указанный выше PASS получен самим локальным экспериментом, а не выведен из документации.

## Повторение

```bash
cd plan/tiptap-pro/lab
npx playwright test tests/schema-compatibility.spec.mjs
```

Стенд автоматически запускается Playwright config либо используется уже работающий локальный. Production код, основной план и vendor packages экспериментом не изменены.
