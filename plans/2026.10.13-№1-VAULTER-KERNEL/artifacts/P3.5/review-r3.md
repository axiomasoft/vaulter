<!-- grok session 01a139aa-3ad6-7ae0-bbcd-3914c8f67f6e -->
Группа R3: **FINDINGS 4**. Риски 8 и 9 не подтвердились. `scan-i1`, `scan-i2`, `scan-i4`, `target-map`, `identities-config` — `ok`. Обёртки `scripts/t` и `scripts/php84` не смогли писать в `build/php84/bin` и `var/quality` (Permission denied); те же проверки прогнаны через `/usr/bin/php8.4`.

## Находки

### 1. MED — `packages/documents/tests/Arch/__snapshots__/public-api.txt:55`

`DocumentsConfig::enabled()` удалён, золотой снимок публичного API всё ещё требует этот метод. Риск 6.

Сценарий: `PublicApiSnapshotTest` сравнивает рефлексию `DocumentsConfig` с золотым файлом и падает на удалённом методе.

Воспроизведение:

```text
cd packages/documents && /usr/bin/php8.4 vendor/bin/pest tests/Arch/PublicApiSnapshotTest.php --no-coverage
```

```text
FAIL Tests\Arch\PublicApiSnapshotTest
  -  enabled(): bool
Failed asserting that two strings are identical.
tests/Arch/PublicApiSnapshotTest.php:251
Tests: 1 failed
```

Тот же прогон azgard падает на новом `AzgardConfig::guard(): ?string` и на конструкторе `AzgardPermissionDriver` (`Kernel`, `AzgardConfig` вместо контейнера). Core-снимок не содержит новые публичные контракты `ProfileOption`, `NodeSnapshotContributor`, `DownloadGuard`, `DriveExtension` (вывод рефлексии ниже). Pest у core в этом дереве не установлен (`packages/core/vendor/bin/pest` нет), поэтому core-тест самим Pest не гонялся.

```text
/usr/bin/php8.4 -r '…сравнить FQCN из packages/core/src/Contracts с public-api.txt…'
missing from golden:
Vaulter\Contracts\Profiles\ProfileOption
Vaulter\Contracts\Drive\NodeSnapshotContributor
Vaulter\Contracts\Drive\DownloadGuard
Vaulter\Contracts\Drive\DriveExtension
```

### 2. MED — `packages/documents/src/Providers/BindingsRegistrar.php:112`

Вклады слота `DocumentType` регистрируются всегда, до списка `vaulter-documents.types`. Этот ключ входит в `ConfigNormalizer::REPLACEMENT_MAPS`: явный список хоста заменяет пакетный и не дополняется дефолтами. Реестр всё равно получает `vaulter/rich-text` и `vaulter/markdown`.

Сценарий: хост задаёт `vaulter-documents.types` только своим типом и `default_type` = `acme/kanban`. Приложение поднимается, в реестре остаются оба встроенных типа.

Воспроизведение: свежий boot с тем же хелпером, что `InvalidDefaultDocumentTypeTest` (`types` = только `AcmeKanbanType`). Ключи реестра:

```text
acme/kanban,vaulter/markdown,vaulter/rich-text
```

Существующий тест проверяет только `has('acme/kanban')`, поэтому сьют этот возврат типов не ловит.

### 3. MED — `packages/azgard/src/Configuration/AzgardConfig.php:28`

Снимок `packages/azgard/tests/Arch/__snapshots__/public-api.txt` не обновлён после `guard()` и смены конструктора драйвера. Тот же риск 6.

Воспроизведение: Pest azgard, тест `vaulter-azgard public API surface matches the frozen golden`, 1 failed. Дифф: `+ guard(): ?string` и конструктор `AzgardPermissionDriver` с `Kernel` и `AzgardConfig`.

### 4. LOW — `tests/Repo/Arch/Fixtures/allowlists/i2.json:12482`

Три новые записи `BindingsRegistrar` → `Vaulter\Kernel` (use файла и `bootDocumentExporters` / `bootDocumentTypes`) лежат в `packages/documents`, а `reason` говорит, что это импорт пакета collaboration и что P4 добавит `require core` в composer collaboration. Соседние записи того же файла формулируют причину как documents. `scan-i2 --check` зелёный: текст причины сканер не проверяет.

Воспроизведение: строки 12482, 12493 и 12504 — `file` = `packages/documents/src/Providers/BindingsRegistrar.php`, `reason` содержит `composer collaboration`.

## Риски 8 и 9

Риск 8 не находка. `ProfileOption` — Handler с ключом-именем опции: так устроен контракт (`name` / `enabled` / `validate`) и правило target-map. `SlotKind::Contributor` — это `contribute(Batch)`, у опции такого метода нет. `AllowedDocumentTypesProfileOption::validate` пустой, потому что `ConfigValidator` идёт в `bootModuleKernel` до `BindingsRegistrar::boot`, где наполняется реестр типов. Раньше ключи профиля с реестром тоже не сверялись.

Риск 9 не находка. `scan-i4 --check` — ok. В block-списке 24 записи: `config-validation`/`host-lifecycle`/`explicit-adapter` с `removed_in: never` и три `jwt-optional` с `P5`. Категории `activation` нет. Проба `Allowlist::fromArray` в режиме block: `activation` + `P3`/`P5`/`never` отвергается; `never` + `config-validation` и `jwt-optional` + `P5` принимаются. `jwt-optional` + `P4` тоже принимается, в текущем `i4.json` таких записей нет, и срок жизни проб это не удлиняет.

Замены в `tests/baseline/CHANGES.json` по documents есть в `documents.list`, причины совпадают с переименованиями. Документация en/ru по file-handlers и permission-drivers обновлена парой.

FINDINGS 4