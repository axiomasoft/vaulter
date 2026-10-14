# P3.5 + P3.6 + P3.7: код написан, ревью и прогоны — в новой сессии

Состояние: код трёх пунктов написан одной сессией (claude-code / claude-sonnet-5-5 / high), НЕ закоммичен, пункты в
статусе `in_progress` (`plan.py start` выполнен, `finish` не вызывался). В рабочем дереве: ~110 файлов вне `plans/`
(в т.ч. staged-удаления `NodeDocumentHeads`, `CommentsFeature`, `DisabledCommentsFeature`). Чужих правок вне плана нет,
`plans/…` содержит владельческие изменения — не трогать.

## Что уже проверено (быстро, точечно)

- `php -l` по всем изменённым файлам — чисто; `pint` (fix) по изменённым файлам пяти пакетов — применён.
- `scripts/php84 php scripts/inventory/inventory.php --check` — все генераторы `ok` (i1/i2 пополнены, i4 → `mode: block`,
  target-map/identities регенерированы), `scripts/t baseline --compare` — зелёный (кроме pre-existing warn по `vue`).
- Точечные pest-прогоны (секунды) зелёные: drive `tests/Modules`, `tests/Providers`, `tests/Doctor/ScanDoctorChecksTest`,
  `tests/Concurrency/CompleteUploadRetryTest`, `tests/Tenancy/PrivateScannerTenantBoundaryTest`, `tests/Uploads/P3/Scan*`;
  documents `Providers/BindingsRegistrarTest`, `Configuration/DocumentsConfigTest`, `Unit/DocumentsConfigMergeTest`;
  azgard `ProviderBindingTest`, `Doctor`, `ActorGuardTest`, `Access`; media `tests/Configuration`;
  core `Modules`, `Profiles`, `Content`, `Feature/VaulterManagerExporterHandlerSeamTest` (201 + 11 тестов).
- НЕ запускались: полные сьюты `scripts/t sqlite -p core|drive|documents|azgard|media`, PHPStan, `-p repo`
  (`BoundaryScanTest`), public-api snapshot тесты (требуют перегенерации), filament.

## Карта изменений по пунктам (файлы пересекаются — см. «Коммиты»)

**P3.5** — снятие activation-проб, I4 блокирует.
- core: `VaulterManager` (DriveHandleFactory через `Capabilities::require`), новые `Contracts/Drive/{NodeSnapshotContributor,DriveExtension}`,
  `Data/Snapshots/Mapping/NodeSnapshotMapper` (контрибьюторы, reserved/duplicate → `InvalidExtensionResult`),
  `Exceptions/Support/InvalidExtensionResult` (минимальный; P3.9 делает ProblemException), `Modules/ExtensionRegistry::handlerClass`,
  удалён `Contracts/Drive/NodeDocumentHeads`.
- drive: `DriveModule` (capability `DriveHandleFactory`, слоты `NodeSnapshotContributor`/`DriveExtension`), `DriveHandle::extension()`
  (только объявленный ключ; иначе `MissingPackageException`) и `uploads()`, `Uploads/UploadsOf`, контроллеры Tus/S3,
  `Providers/BindingsRegistrar::validateScanner` (через `Capabilities`; алиас `vaulter-drive.uploads.scan.scanner` сворачивается
  в `vaulter.scan.scanner`), `Doctor/ScanScannerCheck`, команды `ReconcileScanWork`/`RescanFileRevisions`.
- media: `MediaModule` — capability `ContentScanner` (`providesFromConfig('vaulter.scan.scanner')`, OptionalSingle).
- documents: `Support/DocumentHeads` = вклад `documents.heads`; `DocumentOperations`/`CommentOperations implements DriveExtension`.
- azgard: ключ `vaulter-azgard.guard` (`providesFromConfig`), `AzgardPermissionDriver` (Kernel + AzgardConfig, без bound-проб),
  `AzgardConfig::guard()`, request-bound биндинг `AzgardGuard` удалён, docs `extending/permission-drivers.md` (en+ru).
- scripts/tests: `i4.json` (mode block, −9 записей), `Allowlist` (block допускает только never/late), `BoundaryScanTest`,
  ledger `changes.json` (+`config:vaulter-azgard.guard`).

**P3.6** — слоты drive.
- core: `Access/PermissionDriverCatalog` (вклады слота + host-карта конфига, host побеждает), `PermissionDriverManager`,
  `AccessDriverCheck`, `InstallCommand`, `ConfigValidator`; новые `Contracts/Drive/DownloadGuard`, `Content/DownloadGuards`
  (fail-closed агрегатор; `ContentDelivery`, `ShareContentGuard`, `ShareResolver` идут через него, `ContentAvailabilityGuard implements DownloadGuard`);
  новый `Contracts/Profiles/ProfileOption`, `ProfilePolicy` (Kernel вместо `CommentsFeature`); `FileHandlerRegistry` (слот как
  источник), `Handlers/NodePresenter` (KindPresenter из слота), `DriveSliceRegistrar` (убраны CommentsFeature/тег built-in хендлеров/биндинг NodePresenter).
  Удалены `CommentsFeature`, `DisabledCommentsFeature`.
- drive: `DriveModule` (слоты PermissionDriver, FileHandler, KindPresenter, NodeContentLifecycle, UploadProtocolHandler,
  UploadInspector, DownloadGuard, ProfileOption + все built-in вклады, capability NodePresenter), `UploadSessionManager`
  (протокол из слота; Direct → tus), `UploadInspection` (инспекторы из слота), `BindingsRegistrar` (lifecycle из слота).
- documents: `DocumentsModule` (KindPresenter, NodeContentLifecycle, ProfileOption ×2), `Profiles/{CommentsProfileOption,AllowedDocumentTypesProfileOption}`,
  `DocumentsConfig` (больше не `CommentsFeature`).
- azgard: вклад `azgard.permission` в слот PermissionDriver (конфиг-запись драйвера из registrar убрана).
- tests/fixtures: target-map rules (+ProfileOption, −CommentsFeature/Disabled), i1/i2 allowlists, core `Content/DownloadGuardsTest` + fixtures,
  `Profiles/Fixtures`, drive `Modules/DriveSlotsTest`.

**P3.7** — слоты media/documents.
- media: `MediaModule` — capability `StorageDriver` (`providesFromConfig('vaulter-media.storage_driver', Flysystem)`), биндинг убран из registrar.
- documents: `DocumentsModule` (слоты DocumentType/DocumentExporter + built-in вклады, capabilities ExportFilenameResolver и CollaborationDriver),
  `Providers/BindingsRegistrar` (реестры читают вклады, config-списки дедуплицируются по классу), `Collaboration/CollaborationDriverManager` (транспорт из Capabilities).
- tests: documents `Providers/BindingsRegistrarTest` (дубль ключа → `InvalidModuleGraph`, реестры из вкладов), media `Configuration/StorageDriverCapabilityTest`.

Записанные `plan.py log` (deviation/note) по каждому пункту — читать через `plan.py show`, не угадывать.

## На что смотреть особенно (риски, нужен взгляд со стороны)

1. `Capabilities::require()` каждый раз `container->make(configured class)` (не мемоизирован); контейнерный `app(ContentScanner::class)` — singleton-фабрика.
   Идентичность скан-сканера проверяется на boot через `require`, а job берёт `app(...)`: два разных объекта — безопасно ли для stateful-сканера?
2. Fail-closed: `DownloadGuards` оборачивает любое не-Problem исключение гарда в `ContentUnavailableException` (теряется причина); Problem — пробрасывается как есть.
3. `NodeSnapshotMapper` отдаёт контрибьюторам ВСЕ узлы страницы; принимается только ключ `document` (иначе `InvalidExtensionResult`); shape фрагмента `document` не менялся.
4. `PermissionDriverCatalog`: host-карта `vaulter.access.permission_drivers` перекрывает вклады слота; дефолты конфига оставлены (удаление — P4). Нет ли расхождения валидации (ConfigValidator/ProfilePolicy/Manager/Doctor/Install)?
5. `DriveHandle::extension()` для неизвестного ключа бросает `MissingPackageException::forContract($op, 'the extension')` — имя пакета не известно.
6. Удалён публичный метод `DocumentsConfig::enabled()` и контракты `NodeDocumentHeads`/`CommentsFeature` — snapshot-тесты публичного API (core/documents/azgard) надо перегенерировать.
7. Хост больше не может выбрать `ContentScanner`/`AzgardGuard` биндингом в контейнере без конфигурации — только ключом конфига; docs обновлены только для azgard.
8. `ProfileOption` — слот вида Handler (план говорил «Contributor»); `allowed_document_types.validate` — no-op (реестр типов наполняется позже валидации профилей).
9. `i4.json` в `mode: block` содержит 24 записи (never + jwt-optional) — `Allowlist` block-режим теперь допускает только `never` и late-категории; проверить, что это не ослабляет I4.
10. Рантайм-тест «DownloadGuard вызывается на download/share-download/direct-signed» — только агрегатор + source-проверка; настоящие path-тесты требуют spy-модуля в drive-сьюте (пробел).

## Коммиты

Код трёх пунктов переплетён в общих файлах (`DocumentsModule`, `DriveModule`, `MediaModule`, allowlists i1/i2/i4, target-map, baseline, docs):
раздельные коммиты по пунктам дали бы красные промежуточные состояния. Рекомендация: после зелёных проверок `plan.py finish` каждого пункта
(P3.5 → P3.6 → P3.7) и ОДИН коммит `plan.py commit <plan> P3.5 --role executor` с `--path` на файлы P3.6/P3.7; для P3.6/P3.7 записать
`plan.py log --type deviation` («единый коммит из-за общих файлов») и передать sha в `finish --commit`. Не `git add -A`, не amend, не push.

---

# ПРОМПТ ДЛЯ НОВОЙ СЕССИИ

```
/task:plan-run 2026.10.13-№1-VAULTER-KERNEL P3.5 P3.6 P3.7 --reviewer grok/grok-4.7/500k/high

Контекст: код P3.5–P3.7 уже написан предыдущей сессией и лежит в рабочем дереве (не закоммичен), пункты в `in_progress`.
Прочитай plans/2026.10.13-№1-VAULTER-KERNEL/artifacts/P3.5/handoff-review-and-runs.md (карта изменений, риски, коммиты),
затем `plan.py context` каждого пункта и `plan.py show` залогированных deviation/note. Код заново не пиши.

ЧАСТЬ А — ревью (grok 4.7, read-only, параллельно, БЕЗ длинных прогонов)
Запусти три независимых ревью через bridge `launch` (роль reviewer, модель/усилие ровно из селектора), каждое со своим брифом на stdin;
стримы и вердикты — в plans/…/artifacts/P3.5/review-<группа>.md; в `plan.py log --type note` фиксируй вердикт и фактическую сессию grok.
Группы (слияние пунктов по поверхности, не по номеру):
  R1 «ядро»: core-часть P3.5+P3.6 — NodeSnapshotMapper/NodeSnapshotContributor/DriveExtension/ExtensionRegistry::handlerClass, VaulterManager,
     PermissionDriverCatalog/PermissionDriverManager/ConfigValidator/ProfilePolicy/ProfileOption, DownloadGuard/DownloadGuards/ContentDelivery/
     ShareResolver/ShareContentGuard, FileHandlerRegistry/NodePresenter, удалённые CommentsFeature/NodeDocumentHeads.
  R2 «drive+media+azgard»: DriveModule, DriveHandle::extension/uploads/UploadsOf, validateScanner + алиас скан-ключа, Scan*Check/команды,
     UploadSessionManager/UploadInspection (порядок инспекторов), MediaModule (StorageDriver/ContentScanner), azgard (vaulter-azgard.guard, driver, doctor, docs).
  R3 «documents+фикстуры»: DocumentsModule (слоты/вклады/capabilities), реестры типов/экспортёров (дедуп config↔вклады), CollaborationDriverManager,
     ProfileOption-вклады, а также корректность фикстур: allowlists i1/i2/i4 + Allowlist block-режим, target-map rules, identities/ledger,
     tests/baseline/CHANGES.json (замены существуют, причины верны), тесты и docs en/ru.
Правила ревьюеров: только чтение; запрещены `scripts/t` целиком, `-p <pkg>` без пути, PHPStan, mutation/load/k6/coverage; разрешены `php -l`, точечные
`pest <файл>` (до ~30 с), `scripts/php84 php scripts/inventory/inventory.php <имя> --check`. Каждая находка — с воспроизведением (падающий тест/вывод команды),
иначе максимум «plausible». Формат: severity, файл:строка, сценарий, воспроизведение. Особое внимание — список рисков в handoff-файле (п. 1–10).
Ревьюеры не правят код. Не более двух раундов и одного owning-исправления на пункт (reviewer-selection.md).

ЧАСТЬ Б — прогоны и фиксы (исполнитель, ты)
1. Каждую находку ревью подтверди или отклони собственным прогоном/тестом; подтверждённые чини в коде (минимально), отклонённые — запиши причиной в note.
2. Перегенерируй то, что обязано быть сгенерировано: public-api snapshots (core/documents/azgard — после прогона увидишь красные Arch/PublicApiSnapshotTest),
   `scripts/t baseline --write -p <затронутые>` + `--compare`, inventory `--write` при красном `--check`.
3. Прогони acceptance-проверки пунктов (по одному разу на изменение; длинный вывод — в файл):
   - P3.5: V1 `scripts/php84 php scripts/inventory/inventory.php scan-i4 --check`; V2 `scripts/t sqlite -p repo tests/Repo/Arch/BoundaryScanTest.php`;
     V3 `scripts/t sqlite -p core packages/core/tests/Modules packages/core/tests/Drive`; V4–V6 `scripts/t sqlite -p drive|documents|azgard`;
     V7–V10 pint --test + phpstan для core (из корня) и drive/documents/azgard (из пакета); V11 inventory `--check`; V12 `scripts/t baseline --compare`.
   - P3.6: V1 `scripts/t sqlite -p core packages/core/tests/Access packages/core/tests/Content packages/core/tests/Profiles packages/core/tests/Operations`;
     V2 `scripts/t sqlite -p core`; V3–V5 drive/documents/azgard; V6–V9 pint+phpstan; V10 inventory; V11 baseline.
   - P3.7: V1 `-p media`, V2 `-p documents`, V3 `-p core packages/core/tests/Modules`, V4/V5 pint+phpstan media/documents, V6 scan-i4, V7 baseline.
   Прогоняй так, чтобы не дублировать: полный `-p core/drive/documents/azgard/media` покрывает V-проверки всех трёх пунктов; добавь `-p filament` один раз
   (SpyDriveHandle/слоты) и `-p repo`. Сначала быстрые (pint, inventory, baseline), затем сьюты. Красное — чини и перезапускай только затронутое.
4. Закрой пробел из риска №10: добавь рантайм-тест вызова DownloadGuard на путях download / share-download / direct-signed (spy-модуль в drive-сьюте или
   эквивалент без нарушения «только нужные прогоны»), либо запиши осознанный пробел через `plan.py log --type deviation`.
5. Закрытие: `plan.py finish` для P3.5, P3.6, P3.7 по порядку (`--check Vn=0` только для реально прошедших, `done_with_deviations` + `--deviation` для
   залогированных отклонений), затем единый коммит по правилам из handoff-файла (без `git add -A`, `--no-verify`, amend, push; `OUTSIDE_SCOPE` — `--leave-outside`).
6. Финальный отчёт: результат по каждому пункту, вердикты ревью (R1–R3) и принятые/отклонённые находки, точная строка `plan.py next <plan>`.
```
