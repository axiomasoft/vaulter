# Публичный Comments demo: наблюдения

Дата: 2026-11-21T08:20:36.693Z. Результат: **passed**.

Источник: [официальный публичный demo](https://embed-pro.tiptap.dev/preview/Extensions/Comments?hideSource=false&inline=false). Это **не** собственное Cloud-окружение пользователя; его ключи и npm-доступ здесь не проверяются.

Изолированный headless Chrome; HTTP navigation: 200. Интерактивный editor загружен: true.

| Наблюдение | Подтверждено |
| --- | --- |
| editorLoaded | true |
| commentVisible | true |
| replyVisible | true |
| hiddenFromOpenAfterResolve | true |
| visibleInResolved | true |

Добавление текста и выделение выполняются через стандартный `element.editor` Tiptap и публичные commands; обсуждение создаётся видимой кнопкой Add comment и браузерным prompt Comment content. Проверяется только собственный synthetic текст. Source panel и bundle не используются.

HTTP metadata записей: 59; WebSocket соединений: 0. Headers, HTTP body и demo credentials не собирались. Кадры обработаны decode-frame.mjs: auth payload скрыт, binary payload сохранён только размером и SHA256. Полные результаты: [public-comments.json](results/public-comments.json).

## Наблюдаемый контракт

До создания thread own text не имел marks. После: `[{"type":"inlineThread","attrs":{"data-thread-id":"b6f062b8-2e0e-4d71-9799-72654fe7e5ba"}}]`. После resolve: `[{"type":"inlineThread","attrs":{"data-thread-id":"b6f062b8-2e0e-4d71-9799-72654fe7e5ba"}}]`. Это снимки public editor.getJSON(), а не извлечение внутренних переменных приложения.

Собственное обсуждение открывается кликом по тексту comment. Reply composer — textarea с placeholder Reply to thread …; после ввода появляется Send. Отправленная synthetic reply стала видимой в том же thread.

Кнопка ✓ Resolve скрыла собственное обсуждение из Open. Inline anchor сохранился в JSON editor. Проверка появления в Resolved считается успешной только при visibleInResolved=true в evidence.

За сессию получено SyncStatus(applied=true): 0. Stateless events: `[]`. Служебный запрос versions.list и ответ списка видны в redacted кадрах, если они присутствуют в JSON evidence.

## Следствия для собственной реализации

Observed anchor связывает текст с thread идентификатором; состояние resolved не равно удалению anchor. Собственной системе нужны раздельные жизненные циклы rich-text anchor, discussion record и UI filter. Конкретные storage types thread не выводятся из JSON text editor; для них нужен отдельный опыт Y.Doc/API.

Локальное появление comment/reply или изменение фильтра не доказывает server durability. Наличие отправленного Sync Update не доказывает принятие сервером; входящий SyncStatus даёт дополнительный сигнал applied, но без crash/reconnect опыта не доказывает дисковую фиксацию. document.saved — наблюдаемый event без установленной здесь связи с ACID commit. Внутреннюю SQL-схему и транзакционный порядок этот опыт не определяет.
