# 07-collaboration

Дата: 2026-09-29. Ответ Perplexity; поисковые утверждения не равны проверке первоисточника. Проверенные выводы и ограничения см. в основных документах папки.

## Запрос

Собери 6–8 первичных ссылок и практику Tiptap/ProseMirror schema evolution + Yjs/Hocuspocus persistence/auth/revocation. Для Vaulter structured documents registry важно: type_key and schema_version separate from revision_no; canonical Yjs binary state vs derived JSON; old client removes new nodes on schema mismatch; save/reload and Yjs duplication; snapshot restore while other clients online, connection/room epoch; unknown third-party type installed then extension removed; exporter/editor optional capabilities; malicious rich text URL sanitization; readonly/revoked user websocket enforcement beyond JWT TTL. Используй официальные docs и source repositories; явно отделяй подтверждённое поведение библиотек от предлагаемых решений Vaulter. Никаких таблиц, русский, 800 слов.

## Ответ

Для Vaulter я бы зафиксировал отдельные версии идентичности документа, схемы и содержимого: type_key + schema_version описывают формат, revision_no — прикладную ревизию/исторический checkpoint, а каноническое состояние совместного редактирования хранится как Yjs binary. Ниже — что подтверждают первичные источники библиотек и что следует принять как отдельные решения Vaulter.

## Первичные источники

- — схема задаёт допустимые типы узлов и структуру документа; не всякий примитив создания узла сам проверяет корректность содержимого.
- — Tiptap строит строгую схему из extensions, описывает contentError и рекомендуемое поведение для collaborative editing.
- — JSON и HTML способы сериализации редактора; эти форматы сами по себе не заменяют Yjs persistence.
- — обновления Yjs коммутативны и идемпотентны; applyUpdate принимает повторно применяемое состояние.
- — сохранять Yjs state в Uint8Array; восстановление из JSON с повторным созданием Yjs-состояния может вызвать дублирование при синхронизации.
- — onAuthenticate может отклонить соединение, а connection.readOnly запрещает клиенту писать.
- — серверные lifecycle hooks включают авторизацию и отключение соединения; полезны для принудительного закрытия после изменения прав.
- — URL в href/src требуют проверки допустимых схем и контекстного экранирования; для HTML рекомендуется санитизация.

## Подтверждённое поведение

ProseMirror schema — не механизм миграции. Она ограничивает допустимый документ; Tiptap отдельно позволяет обнаружить несовместимый JSON через enableContentCheck/contentError. Для collaborative editing документация прямо рекомендует не синхронизировать автоматически испорченное/несовместимое содержимое: например, отключить collaboration и сделать редактор readonly до решения.
Следовательно, сценарий «старый клиент не знает новый node и удаляет его» — не универсальная гарантия о каждом пути загрузки, но реальный риск: при загрузке/нормализации по более узкой схеме неизвестные данные могут быть отброшены. Решение Vaulter: клиент проверяет schema compatibility до подключения к комнате; при несовпадении — readonly/upgrade-required, без записи нормализованного документа. Не полагаться на клиентское предупреждение как на защиту сервера.
Yjs update можно применить повторно без повторения эффекта; однако создание нового Y.Doc из JSON — другая операция, не идемпотентное восстановление исходной истории. Hocuspocus прямо предупреждает о дублировании при таком преобразовании и требует сохранять бинарное состояние.

## Предлагаемая модель Vaulter

- Хранить type_key, schema_version, revision_no раздельно. type_key идентифицирует семейство документа; schema_version — версию контракта его структуры; revision_no — монотонную прикладную ревизию для истории, аудита и restore. Не выводить schema version из revision.
- Хранить канонический Yjs binary state (Uint8Array/BLOB), а JSON — как производную проекцию для поиска, рендеринга, экспорта и API. Помечать JSON версией схемы и состоянием генерации; не восстанавливать canonical Y.Doc из JSON. Обновление binary и соответствующей ревизии выполнять согласованно.
- Добавить кэш/артефакт JSON с source_state_hash и schema_version. При несовпадении инвалидировать и перестраивать его из binary совместимым серверным renderer/parser.
- Считать редактор, exporter и extension наборы capabilities. Отсутствующая capability означает «не могу редактировать/экспортировать», а не «узел удалить». Exporter должен явно сообщать о неподдерживаемых node types.
Для стороннего node-типа нужен стабильный namespaced key и политика сохранения неизвестного содержимого. Удаление extension из установленной сборки не должно автоматически переписывать документ: registry должен хранить версию/плагин происхождения и migration path; иначе запретить редактирование или сохранить неизвестный node в opaque representation. Это проектное требование Vaulter, не встроенная гарантия Tiptap.

## Restore и доступ

Восстановление старой ревизии при подключённых клиентах требует ограждения гонки. Предлагаемая схема: увеличить room_epoch, остановить/отклонить старые соединения, закрыть комнату на всех инстансах, применить выбранный Yjs binary как новое canonical состояние, затем разрешить reconnect только с новым epoch. Не «подмешивать» восстановленный документ в активную комнату: активные клиенты могут немедленно внести новые updates. Hocuspocus описывает persistence и отключение, но именно epoch/fencing — решение Vaulter.
JWT TTL ограничивает срок токена, но сам по себе не реализует мгновенный отзыв уже авторизованного WebSocket. При изменении прав Vaulter должен закрыть активные соединения пользователя/документа либо проверять актуальную capability на каждом входящем сообщении через серверный hook; для readonly устанавливать connection.readOnly при auth и менять/отзывать активное соединение при downgrade. Hocuspocus подтверждает read-only и hooks, но модель немедленного revoke нужно реализовать и тестировать в Vaulter.
Наконец, rich-text URL проверять не только при вводе: при каждом рендере/экспорте нормализовать URL, разрешать только утверждённые схемы (обычно https, http, при необходимости mailto) и санитизировать HTML на границе вывода. Это защищает в том числе документы, созданные старым или сторонним клиентом.

Sources:
1. [ProseMirror Guide: schemas](https://prosemirror.net/docs/guide/)
2. [Tiptap: Schema](https://tiptap.dev/docs/editor/core-concepts/schema)
3. [Tiptap: Persistence](https://tiptap.dev/docs/editor/core-concepts/persistence)
4. [Yjs README/source](https://github.com/yjs/yjs/blob/main/README.md)
5. [Hocuspocus: Persistence](https://tiptap.dev/docs/hocuspocus/guides/persistence)
6. [Hocuspocus: Authentication](https://tiptap.dev/docs/hocuspocus/guides/authentication)
7. [Hocuspocus: Hooks](https://tiptap.dev/docs/hocuspocus/server/hooks)
8. [OWASP XSS Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
