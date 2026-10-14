# Review P3.4 (4d38b6e5) — grok

Проверено чтением git show / grep (тесты не запускались). Эквивалентность подтверждена для: documents (CommentsFeature bind,
problem codes, config validator tag, DocumentTypes/Exporters freeze в booted, Node::resolveRelationUsing('document'),
broadcast channel, команды, роуты, doctor-ключи), azgard (AzgardGuard bind, driver map `has()`-guard, config + tag
`vaulter-azgard-config`), filament (FilamentConfig, tag `vaulter-filament-config`). Версии `'1.0'` и `^1.0` согласованы.

## F1 — HIGH — azgard: manifest requires drive, а composer — только require-dev
- packages/azgard/composer.json (drive/media в require-dev), packages/azgard/src/AzgardModule.php:requires() = core + drive.
- Сценарий: хост ставит `axioma-studio/vaulter-azgard` (+ core) без drive: композер доволен (require: только core), провайдер
  предлагает AzgardModule (Auto) → ModuleCompiler: `module "azgard" requires "drive", which is not installed` → весь граф невалиден,
  ни один модуль (включая core-срезы) не регистрируется, роуты отвечают диагностикой. Прежний azgard работал на одном core.
  Для documents/filament drive правильно лежит в require.
- Правка: `"axioma-studio/vaulter-drive": "self.version"` перенести в require (media приходит транзитивно через drive); path-репо
  оставить; media из require-dev убрать/оставить для тестов.

## F2 — HIGH — register()-seam'ы documents перекрываются биндингами модуля (см. P3.3 F1)
- packages/documents/src/Providers/CollaborationRegistrar.php (`bind(HostUserResolver)`), BindingsRegistrar.php
  (`bind(ExportFilenameResolver)`, `bind(NodeDocumentHeads)`), применяются в booting-фазе после register() всех провайдеров.
- Комментарии кода называют их подменяемыми («a host (or a test) may swap it», «a host rebinds this contract»). Реальный пример в
  репо: packages/core/tests/E2E/E2eServiceProvider.php:55 биндит FakeHostUserResolver в register() — теперь затирается
  DefaultHostUserResolver (стенд collaboration-hub аутентифицирует через реального резолвера, а не фейк).
- Правка: bindIf для подменяемых seam'ов или перенос подмены в boot(); тест «host register() binding wins».

## F3 — MED — ProvidersDeclareOnlyTest имеет лазейки
- packages/core/tests/Arch/ProvidersDeclareOnlyTest.php: проверяются только register/boot/packageRegistered/packageBooted и
  configurePackage.
  - Хуки Spatie `registeringPackage()` и `bootingPackage()` не проверяются: провайдер может делать там
    `$this->app->singleton(...)`/`loadRoutesFrom(...)` — тест зелёный. Сценарий: через месяц кто-то вернёт регистрацию в
    bootingPackage(), and ADR-0027 «провайдер только предлагает манифест» нарушен без сигнала.
  - Любые другие методы класса (в т.ч. `__construct`, `provides`) и вызовы из них не анализируются; у core вся логика ушла в
    private registerModuleKernel/bootModuleKernel/registerDiagnosticCommands/registerInvalidGraphRoute — они вообще вне проверки
    (допустимое исключение для ядра, но не зафиксировано явным allowlist'ом тел).
  - glob `*/src/*ServiceProvider.php` не видит провайдеров в подкаталогах; negative-тест («refuses…») покрывает 3 вида
    нарушений, но не хуки выше.
- Правка: добавить `registeringPackage`/`bootingPackage` в PROVIDER_ALLOWED_CALLS (пусто), запретить любые методы кроме
  configurePackage/register/packageRegistered/packageBooted (для core — явный список), расширить negative-тест этими случаями.
