VERDICT: GREEN

F01–F05 закрыты на допуске D59. E01 починен, порог архитектурного теста не ослаблен. Подтверждённых остатков в этих правках нет.

F01 — closed. `ShowChangesStartController` и `ListChangesController` идут через `HttpOperationContextFactory::onVisibleDrive`. Чужой диск без единого видимого узла получает `NodeNotFoundException` до `startCursor()` и `list()`, а `onDrive()` превращает его в тот же `drive_not_found`, что и неизвестный id. Проекция D59 не менялась: допущенный читатель вложенной папки по-прежнему получает identity-only надгробия move и смены доступа. `ChangesV1Test` фиксирует отказ незнакомцу и журнал вложенного гранта.

F02 — closed. Search, starred, список корзины и `DELETE /drives/{drive}/trash` используют тот же допуск. Незнакомец не доходит до пустой страницы и до `TrashOperations::empty()`. Грант на вложенную папку и на корень корзины диск допускает. `UniformNotFoundDriveV1Test` покрывает пустые списки глубокого гранта, только видимую корзину, целую чужую корзину и `404` удаляемого диска.

F03 — closed. `OperationGuard::abilities` и `abilitiesMany` ставят `create` только вместе с одним `canCreateIn` на диск. HTTP-тест проверяет оба флага и фактический `POST` при gate true и false. Deny-ветка `NodeResourceCreateGateTest` снова ждёт пустой `folderOptions`.

F04 — closed. `VaulterDrive` берёт `abilities.create` корня через `drives.get` и `nodes.get`, вложенной папки через уже существующий include, прячет «New folder» и uploader, пока флаг ложный, сбрасывает его при навигации и отдаёт слоту `:can-create`. `onNewFolder` при ложном флаге выходит сразу.

F05 — closed. В `direct_signed` `ContentResponder::redirect` вызывает `authorize(Download)` — внутри него сначала View — до проверки kind и ревизии, затем оставляет только `committed` и `superseded`. У previewer оба id дают `403` без `Location` и без вызова signer. Старая ревизия по-прежнему `302`. Infected при статусе committed доходит до `directUrl` и даёт `423` до URL. `QuarantinedDownloadTest` это сохраняет.

E01 — repaired. `PanelDrives` стал фасадом над `PanelDriveAccess` с зависимостями в конструкторе. Новых вызовов `app()` нет. В снимке `service-locator.json` у `filament/src/Resources/NodeResource.php` стоит 1, записей `PanelDrives` нет, лимиты теста не подняты. Правило по-прежнему запрещает рост числа `app()` и требует понижать baseline.

Ограничение: shell недоступен, Pest, PHPStan и vitest здесь не запускались. Зелёные прогоны взяты из журналов исполнителя. Фикстурный коммит `d3e80c75` не входил в проверку.
