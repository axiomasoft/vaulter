# Первичная разведка Perplexity

Дата: 2026-09-29. Ответ поискового синтезатора; утверждения требуют сверки с первоисточниками. Проверенные выводы см. в основных документах папки.

## Запрос

Research primary-source open-source implementation examples for a Laravel embedded file/document platform audit. Need concrete repository file links, not generic advice: (1) Nextcloud server distinction file Node structural type vs mimetype, app capabilities and permissions; (2) Spatie laravel-medialibrary FileAdder shared validation for programmatic upload, accepted MIME and max size; (3) tus/tusd resumable upload completion hooks and validation limits; (4) Laravel scoped container bindings for Octane tenant/user operation context. Explain applicable patterns and limitations, do not claim these projects prove security or copy their entire architectures. Prefer official documentation and actual GitHub source. Keep concise.

## Ответ

Для аудита полезно разделить структурный тип узла, MIME-тип, права на операцию и валидацию содержимого: эти примеры показывают отдельные механизмы, но не являются доказательством безопасности и не предполагают копирования архитектуры целиком.

## Nextcloud: узел, MIME, права и capabilities

- Node type — не MIME-type. В публичном FileInfo Nextcloud TYPE_FILE обозначает структурный тип file, а getMimetype() возвращает отдельное значение вроде image/png; getPermissions() возвращает битовую маску прав. Это хорошая модель для хранения собственных node_type/kind отдельно от mime_type, не выводя «это файл» из MIME-префикса. ·
- Проверять право на конкретное действие. В Nextcloud permissions — отдельные биты READ, UPDATE, DELETE, SHARE, и т. п.; права на файл или каталог могут различаться. Для Laravel-платформы переносимый принцип — проверять право на конкретную операцию и целевой объект, а не трактовать «пользователь видит документ» как право на все действия. ·
- Capabilities — объявление возможностей приложения, не разрешение доступа. В Nextcloud приложения регистрируют capabilities как метаданные, доступные клиентам; это полезно для обнаружения функций и согласования поведения UI/API, но не заменяет проверку авторизации на каждом запросе.

## Spatie Media Library: единый путь добавления

В видны проверки размера в toMediaCollection() и toMediaCollectionFromRemote(), а после подготовки метаданных processMediaItem() вызывает guardAgainstDisallowedFileAdditions(). Там проверяются callback коллекции acceptsFile и список acceptsMimeTypes.
Это полезный образец: направлять HTTP-, CLI- и внутренние загрузки через общий application service, который вызывает одну и ту же политику размера, MIME и коллекции. Но это не означает, что сама библиотека автоматически охватывает любую точку входа: для программных загрузок аудит должен подтвердить, что они действительно проходят через FileAdder/общий сервис. MIME в метаданных — не подтверждение безопасного содержимого, а проверка размера должна опираться на фактический размер файла/объекта, не на пользовательское поле. Пример настройки MIME-ограничений есть в .

## tusd: завершение и предел размера

- tusd поддерживает хуки, включая pre-create, post-receive и post-finish; документация хуков — . В исходнике handler есть MaxSize и ошибка превышения размера, а также канал уведомлений о завершении загрузки. ·
- Предел и проверка — разные задачи. Настройка максимального размера ограничивает объём принимаемых данных; хук завершения годится для последующей проверки уже собранного файла и запуска обработки. Если нужна проверка до разрешения загрузки, передавайте ограничения/решение авторизации в pre-create-механизм и проверяйте фактические данные при завершении. Не считайте post-finish предварительным барьером: файл к этому моменту уже загружен.
- Конкретная реализация может зависеть от режима протокола и конфигурации tusd; прежде чем опираться на лимит как на инвариант, стоит проверить используемую версию и путь загрузки. Обсуждение -max-size в experimental draft path указывает на такую область аудита, но само по себе не устанавливает уязвимость для иных конфигураций.

## Laravel / Octane: контекст операции

Laravel scoped регистрирует объект на один request/job lifecycle и сбрасывает scoped instances при начале нового цикла, в том числе при обработке новых запросов Octane. Исходник контейнера показывает, что scoped binding отмечается отдельно, хотя создаётся через singleton-механизм; именно сброс lifecycle не даёт ему жить как обычному worker-level singleton. ·
Для контекста tenant/user/операции используйте scoped объект с явной инициализацией из текущего аутентифицированного запроса. Не храните изменяемые tenant/user данные в singleton-сервисах, статических свойствах или долгоживущих фасадах; для фоновых задач задавайте контекст из payload job, а не рассчитывайте на HTTP request. scoped ограничивает срок жизни экземпляра в контейнере, но сам по себе не гарантирует корректное заполнение, очистку любого внешнего состояния или авторизацию.

Sources:
1. [GitHub: FileInfo.php](https://github.com/nextcloud/server/blob/master/lib/public/Files/FileInfo.php)
2. [API FileInfo](https://nextcloud-server.netlify.app/classes/ocp-files-fileinfo)
3. [GitHub: константы прав](https://github.com/nextcloud/server/blob/master/lib/public/Constants.php)
4. [документация WebDAV](https://docs.nextcloud.com/server/stable/developer_manual/client_apis/WebDAV/basic.html)
5. [Nextcloud capabilities source](https://github.com/nextcloud/server/tree/master/lib/public/Capabilities)
6. [nextcloud](https://nextcloud.com/developer/)
7. [FileAdder.php](https://github.com/spatie/laravel-medialibrary/blob/main/src/MediaCollections/FileAdder.php)
8. [тестах коллекций](https://github.com/spatie/laravel-medialibrary/blob/main/tests/Feature/FileAdder/MediaConversions/MediaCollectionTest.php)
9. [официальный файл проекта](https://github.com/tus/tusd/blob/main/docs/hooks.md)
10. [handler](https://github.com/tus/tusd/blob/main/pkg/handler/unrouted_handler.go)
