# Review P3.3 (3eda09b2) — grok

Проверено чтением git show / grep (тесты не запускались). Эквивалентность подтверждена для: теги problem codes, config validators,
doctor-ключи (core.routes.auth, core.access.driver), расписания (changes_prune: featureEnabled == `features.changes === true`),
rate limiters (bind-if-absent сохранён), booted-колбэк validateScanner, команды, публикуемые теги `vaulter-drive-config`/`vaulter-media-config`.
I6: при drive=false нет маршрутов (route middleware `vaulter.module:drive`), биндингов, команд (DriveDisabledTest). Пересечений URL между
core/routes/drive.php и drive/routes/drive.php нет, смена порядка загрузки (drive, потом core) безопасна.

## F1 — HIGH — биндинги модулей применяются в booting-фазе и перекрывают register()-биндинги хоста
- Файлы: packages/core/src/VaulterServiceProvider.php:65 (applier->register внутри `$app->booting`),
  packages/core/src/Providers/DriveSliceRegistrar.php:48-120, packages/drive/src/Providers/BindingsRegistrar.php:46-60,
  packages/media/src/Providers/BindingsRegistrar.php (bind/singleton без *If).
- Прежде core/drive/media регистрировали биндинги в своём register(); package-провайдеры идут раньше App-провайдеров, поэтому
  `bind()` хоста в AppServiceProvider::register() побеждал. Теперь ModuleBindings::register() выполняется после register() ВСЕХ
  провайдеров, и безусловные `bind/singleton` (NodePresenter, FileHandlerRegistry-контракт, NodeLocator, ProfileRegistry,
  VaulterManager, ShareTokenGenerator, DriveHandleFactory-контракт, ScanPolicyResolver, StorageDriver/ContentStore/BlobStore)
  затирают хостовые.
- Сценарий: хост в register() делает `$this->app->bind(NodePresenter::class, MyPresenter::class)` → до P3.3 побеждал хост, теперь
  работает дефолт пакета; ошибки нет, поведение молча меняется. Хост, переопределяющий в boot() или тестом после boot, не затронут.
- Правка: для seam'ов, которые хост вправе подменять, использовать bindIf/singletonIf (как уже сделано для CommentsFeature и
  ScanPolicyResolver) либо зафиксировать порядок «хост в boot()» в docs/concepts/modules.md и покрыть тестом (host provider register()
  rebinding vs manifest).

## F2 — LOW — media использует drive-срезы, но requires = только core
- packages/media/src/MediaModule.php (requires), packages/media/src/Doctor/DiskSet.php, DiskProbeCheck.php, DedupScopeCheck.php,
  Console/GcBlobsCommand.php:38 (VaulterManager).
- ProfileRegistry/VaulterManager теперь биндит DriveSliceRegistrar. При `vaulter.modules.drive=false` (media остаётся активным —
  проверено DriveDisabledTest) ProfileRegistry автосоздаётся пустым (`__construct(?Container = null)`), поэтому media-doctor
  молча проверяет только дефолтный диск. Падения нет, деградация без сигнала.
- Правка: либо принять и задокументировать (профилей без drive нет), либо резолвить ProfileRegistry в media только при isActive('drive').
