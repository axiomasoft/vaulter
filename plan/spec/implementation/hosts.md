# Тестовые потребители пакетов

Все пути ниже принадлежат тестовому коду репозиториев Vaulter, Sheets и AI. Потребитель устанавливает собранные ZIP/tgz, использует документированный вход пакета и предъявляет результат через HTTP, браузер или повторный запуск процесса. Файлы продуктов в фазах отсутствуют. Процессы этих стендов описаны в [карте Docker](processes.md).

## Состав установки

| Потребность | Composer | Браузер / отдельный процесс |
| --- | --- | --- |
| Одиночный документ, профиль FlexCms | `axiomasoft/vaulter` | `@vaulter/vue`, Vue 3, Tiptap и расширения поддерживаемого пресета |
| Совместный документ, дополнительная возможность FlexCrm/Edufy | База + `axiomasoft/vaulter-collaboration` | `@vaulter/vue-collaboration`, Yjs, привязка Tiptap к Y.Doc и образ `ghcr.io/axiomasoft/vaulter-collaboration-hub` |
| Книга | База + `axiomasoft/vaulter-sheets` | `@vaulter/sheets-vue`, серверный `@vaulter/sheets-runtime`. Для раскрытия ссылки в тексте отдельно `@vaulter/sheets-tiptap` |
| Инструменты агента | На стороне владельца `axiomasoft/vaulter-ai-core` и выбранные `axiomasoft/vaulter-ai-documents`, `axiomasoft/vaulter-ai-sheets` либо оба. Адаптер живого текста `axiomasoft/vaulter-ai-collaboration` требует готовности 3.5 | PHP-исполнитель `axiomasoft/vaulter-ai-laravel-ai`; дополнительно `axiomasoft/vaulter-ai-agentools`. Отдельному Python-процессу достаточно клиента Office HTTP. Loqum использует границу сообщений в 7.2 |

## 1.3 · Одиночный текст из списка

**Каталог** `vaulter/e2e/consumers/documents-basic/`. Это новый самостоятельный тестовый потребитель. Основа действующего прогона по исходной ревизии — [скрипт сборки](../../checks/flexcms/run.sh), [HTTP-сценарий](../../checks/flexcms/host.php) и [тест в браузере](../../checks/flexcms/browser.mjs). У исходного прогона прямой вход по ID. В 1.3 вход пользователя начинается из Drive.

```text
e2e/consumers/documents-basic/
  run.sh                            # создать временный Laravel, установить архивы, seed, build, запустить тест
  frontend/package.json             # публичные npm-пакеты редактора и тестовые инструменты
  frontend/npm-shrinkwrap.json       # npm-граф тестового фронта
  frontend/index.html
  frontend/src/main.ts              # createVaulterClient, app.use(VaulterPlugin), выбор viewer
  frontend/src/DocumentList.vue      # VaulterDrive @open-node → выбранный node → VaulterViewer
  frontend/src/TextDocument.vue      # node + preset → VaulterDocumentNode, передать default slot в редактор
  frontend/src/TipTapEditor.vue      # создать editor, обработать onUpdate/readonly, уничтожить при закрытии
  laravel/routes/web.php             # тестовая HTML-страница со ссылкой на собранный фронт
  laravel/seed.php                   # отдельный drive, 2 пользователя, rich text/Markdown, grants
  fixtures/rich-text.json
  fixtures/markdown.json
  tests/open-save.spec.ts            # список, два экземпляра, reload, reader, rich text и Markdown
```

1. `run.sh` создаёт свежий временный каталог и схему SQLite. Переиспользовать порядок сборки и источники зависимостей из исходного прогона. Для кандидата брать переданные ZIP/tgz и сохранить их SHA-256, версии PHP/Node, перечень установленных пакетов, таблиц и экспортов. Исключить доступ к закрытому npm/Composer-реестру при проверке базы. Снимок до 1.2 и кандидат после 1.2 имеют разные ожидаемые JS-экспорты.
2. Тестовый Laravel создаёт обычных пользователей owner и reader и гранты Drive. Начальные документы создаются через Documents. HTTP guard теста — штатная Laravel Basic-аутентификация только на loopback. На всех чтениях и правках браузер использует действующего пользователя. API пакета берёт префикс и middleware из `config/vaulter.php`. Пример базового API — `/api/vaulter/v1`. Подключить тот же CSS/JS-артефакт обоим пользователям.
3. `TextDocument.vue` зарегистрировать как `viewers['vaulter/tiptap']`. Стандартный viewer передаёт компоненту `node`, `readonly` и `presentation.viewer.props.preset`. Обёртка внутри `VaulterDocumentNode` получает `content, readonly, setContent, flush, conflict`. Загружать JSON в Tiptap без эха `onUpdate`. Пользовательский ввод передаёт `editor.getJSON()` в `setContent`; кнопка сохранения вызывает `flush`. При конфликте оставить локальный текст доступным. Для read-only запретить ввод в редакторе. В rich-text активировать расширения для изображений и таблиц, в Markdown — его закрытый набор. [Договор viewer](../../../packages/vue/src/viewers/VaulterViewer.vue) · [Слот](../../../packages/vue/src/components/VaulterDocumentNode.vue)
4. Из списка открыть каждый тип. Сравнить форматированный JSON после повторного открытия, включая атрибуты ссылки, встроенную обычную таблицу и поддерживаемый Markdown. В двух независимых вкладках прочитать один ETag. Сохранить из A, затем из B. Получить 200 с новым ETag и 412 с локальным текстом B. Reader видит версию A, попытка прямого PUT с актуальным ETag даёт 403. Последнее открытие владельца возвращает версию A. [Примеры REST](../base-install.md)

## 2.1–2.2 · Два браузера, одна комната

| Существующий файл Vaulter | Изменение и проверка |
| --- | --- |
| `e2e/src/CollabEditor.vue` | Обновить потребитель до контракта закрытого Vue-пакета. Создать `createCollaborationAdapter({client})`, передать его в `VaulterPlugin`; привязать Tiptap к Y.Doc действующего поколения. При смене поколения освободить прежний editor. Статус сохранения брать из подтверждения записи |
| `tests/Integration/E2E/E2eServiceProvider.php`, `E2eActingAs.php` | Только тестовый setup создаёт владельца, второго редактора, reader и документ. Аутентифицировать три независимых клиента. Принудительные остановки и отзыв выполнять в стенде через владельца. В production-сборке пакета тестовый провайдер отсутствует |
| `e2e/tests/collab.spec.ts` | Версия в WebSocket, два записавших автора, рестарт, чтение новым клиентом, отказ reader, старое поколение после restore. Зафиксировать позиции двух записей, подтверждение и итоговое содержимое. [Границы протокола](../collaboration.md) |
| `e2e/docker-compose.yml`, `e2e/docker/testbench.pgsql.yaml` | Один запуск контура PHP + PostgreSQL + Hub + тестовая страница. PHP владеет проверкой грантов; Hub обращается к закрытым endpoints. Секреты подписи остаются внутри контура |

## 3.4, 6.2, 7.1–7.2 · Потребители закрытых возможностей

| Фаза / каталог | Что создаётся | Как принять |
| --- | --- | --- |
| 3.4 · `vaulter-ai/tests/consumers/office/` | Тестовая Laravel-установка владельца с PHP-портом Office, HTTP-обработчиком `POST /api/vaulter-ai/v1/operations` и тестовым guard. `seed.php` создаёт два изолированных субъекта и проект. `app/FixtureCallContextResolver.php`, `FixtureHostPolicy.php`, `FixtureResourceDirectory.php` реализуют контракты ядра. Тестовый агент вызывает публичные операции через адаптер Laravel AI | Фиксированный ответ модели приводит к одной правке, одной квитанции и проверке содержимого сохранённой версии. На другом субъекте/проекте название, diff и квитанция закрыты. Запуск без ключа модели воспроизводим. Профиль с реальным тестовым ключом запускается отдельно |
| 3.4 · тот же каталог | `tests/AgentoolsPortTest.php` устанавливает дополнительный адаптер и передаёт одни и те же входы через `ToolPort`. В базовой матрице удалить опциональный пакет из манифеста потребителя и повторить сборку | Хост выбирает профиль, связывает idempotency key действия с `command_id`, получает тот же исход у владельца. Установка только документного адаптера публикует только его команды |
| 6.2 · `vaulter-sheets/tests/consumer/laravel/routes/web.php`, `tests/consumer/vue/src/WorkbookReferenceFixture.vue`, `tests/e2e/workbook-reference.spec.ts` | Закрытый viewer `VaulterSpreadsheet` подключить на `vaulter/univer`. Расширение `WorkbookReference` отображает разрешённый диапазон по обычной HTTPS-ссылке тестового origin. Форма пути `/office/workbooks/{nodeId}/sheets/{sheetId}?range=D2%3AD4`. Сервер повторно разрешает оба ID и доступ к книге при раскрытии | Показать вычисленное значение авторизованному reader. После потери доступа, удаления листа или запрета на экспорт вывести нейтральное представление. Отключить расширение, сохранить и открыть текст в базовом viewer. Ссылка сохраняется с фиксированными координатами. [Спецификация книг](../spreadsheets.md) |
| 7.1 · `vaulter-ai/tests/consumers/office/` | `routes/web.php` и `resources/views/proposals.blade.php` дают два тестовых действия для принятия и отклонения. Оба вызывают доверенный обработчик решения под текущим пользователем и привязывают согласие к сохранённому digest. `tests/ProposalDecisionTest.php` проверяет двух рецензентов | Конкурентно принять и отклонить P, оставить Q ожидающим. Выигрывает одно решение P, исполнение сохраняет максимум один эффект под исходным субъектом. Утрата доступа до commit прекращает запись; при потере ответа доступный пользователь видит тот же результат |
| 7.2 · `vaulter-ai/clients/python/vaulter_office/client.py`, `tests/consumers/office/tests/remote_client.py` | Запустить отдельный процесс с HTTP-адресом владельца и короткоживущим удостоверением fixture. Допустить только Office HTTP. Потерять первый успешный ответ commit, повторить status и verify; следующую сессию начать с уже сохранённого `command_id` | В клиенте нет DSN, ORM и секретов владельца. Результат и число эффектов совпадают с локальным PHP-портом. Между субъектами результат недоступен |
| 7.2 · `vaulter-ai/tests/consumers/office/tests/FinalMessageTest.php` | Имитировать интерактивный источник. Тестовые входы `(session-1,event-2,partial)`, `(session-1,event-3,final)`, повтор финального события и reconnect. Хост связывает авторизованную сессию с субъектом и дедуплицирует финал до запуска одного задания | На частичную реплику ноль задач. На финал и его дубликат одна задача, после reconnect один исход. Согласование проходит через обработчик решения. Для теста достаточно фейка событий; Loqum — возможный поставщик такого канала |

Секрет провайдера принадлежит процессу агента. Владельцу передаётся проверенное удостоверение инициатора. Любые `actor_id`, организация и признак одобрения в JSON модели отвергаются согласно схеме. Сторонний runtime работает на том же контракте PHP/HTTP. [Wire-примеры](../../examples/office-sdk.json) · [Регистрация и решения](../host-integrations.md)
