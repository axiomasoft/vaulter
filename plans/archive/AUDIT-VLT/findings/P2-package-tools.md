# P2 — RAG-выжимка: spatie/laravel-package-tools и семантика `mergeConfigFrom`

**Дата верификации:** 2026-07-10 · **Автор:** issue-planner/opus (design-time RAG фазы P2)
**Инструменты:** context7 `/spatie/laravel-package-tools` (README, llms.txt) ·
чтение исходника `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php`
**Вердикт:** ✅ подтверждено. Нормы `PT1–PT6` дополняют `E1–E7` (`P0-embeddable-laravel.md`)
и являются нормативными для items P2.2, P2.3, P2.5 (D16 — правке в ходе исполнения не подлежат).

Причина существования файла: все шесть провайдеров vaulter (кроме `permissions-azgard`)
наследуют `Spatie\LaravelPackageTools\PackageServiceProvider`, а не голый Laravel-провайдер.
Чеклист «register-vs-boot / publishables» нельзя писать против голого Laravel API:
publish-теги, хуки и порядок вызовов задаёт пакет-тулкит.

---

## PT1. Имена publish-тегов выводятся из `->name(…)`

- PT1.1 `->name('X')->hasConfigFile()` регистрирует `config/X.php` и делает его публикуемым
  тегом **`X-config`**. Публикация: `php artisan vendor:publish --tag=X-config`.
  RAG:✅ 2026-07-10 — context7 `/spatie/laravel-package-tools`.
- PT1.2 `->discoversMigrations()` подхватывает ВСЕ файлы из `./database/migrations` пакета,
  регистрирует их и делает публикуемыми (тег **`X-migrations`**).
- PT1.3 Тег — часть публичного контракта пакета: переименование `->name(…)` ломает
  `vendor:publish --tag=…` у каждого потребителя (пересечение с E4.2 — major-изменение).
- PT1.4 Провайдер без `hasConfigFile()` не публикует свой конфиг **вообще**: `mergeConfigFrom`
  даёт дефолты, но потребитель не может получить файл для правки.

## PT2. `runsMigrations()` vs публикация

- PT2.1 `->runsMigrations()` заставляет Laravel исполнять миграции пакета **из вендора**,
  без `vendor:publish`. Это осознанный режим «конфиг-фри установки» (согласуется с E7.1).
- PT2.2 Одновременная публикуемость (`discoversMigrations`) + автозапуск (`runsMigrations`)
  создаёт риск **двойного прогона**: опубликованная копия и вендорный файл имеют одно имя
  класса/таблицы, но разные пути. Каноничность режима обязана быть объявлена в доках (E2.4).

## PT3. `hasInstallCommand()` — это artisan-команда `X:install`

- PT3.1 `->hasInstallCommand(fn (InstallCommand $c) => …)` регистрирует команду
  **`php artisan X:install`**; для `->name('vaulter')` это `vaulter:install`.
- PT3.2 Доступные шаги: `startWith`, `publishConfigFile`, `publishAssets`,
  `publishMigrations`, `askToRunMigrations`, `copyAndRegisterServiceProviderInApp`,
  `askToStarRepoOnGitHub`, `endWith`.
- PT3.3 Команда публикует только то, что провайдер объявил (`publishMigrations` без
  `discoversMigrations`/`hasMigration` не публикует ничего).

## PT4. `mergeConfigFrom` — мелкий merge, и он молча пропускается при кешированном конфиге

Источник — исходник Laravel (`Illuminate/Support/ServiceProvider::mergeConfigFrom`,
прочитан 2026-07-10 в `vendor/`):

```php
if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
    $config = $this->app->make('config');
    $config->set($key, array_merge(require $path, $config->get($key, [])));
}
```

- PT4.1 Merge **мелкий** (`array_merge`), не рекурсивный: вложенные массивы потребителя
  не сливаются с вложенными дефолтами пакета — они заменяются целиком.
- PT4.2 При `php artisan config:cache` вызов **не выполняется вообще**. Дефолты попадают в
  кеш только потому, что `config:cache` бутстрапит приложение (провайдеры регистрируются
  до дампа). Любое чтение конфига **вне** `register()`/`config`-файла и любая зависимость
  от повторного merge в рантайме — дефект (`config-cache safety`).
- PT4.3 `mergeConfigFrom($path, 'pkg.subkey')` с **точечным** ключом — легальный приём, но
  он: (а) сливает мелко в под-ключ, (б) при частично опубликованном `config/pkg.php`
  потребителя сохраняет ключи потребителя (они идут вторым аргументом `array_merge`),
  (в) **невидим** для потребителя: файл не публикуется, если провайдер не объявил
  `hasConfigFile()`.

## PT5. Порядок хуков `PackageServiceProvider`

- PT5.1 `register()` тулкита вызывает `configurePackage()` → `mergeConfigFrom` → затем
  пользовательский `packageRegistered()`. Значит `packageRegistered()` = зона `register()`:
  только дешёвые биндинги, никакого I/O, никакого чтения БД (правило репозитория
  `.claude/rules/service-provider.md`).
- PT5.2 `boot()` тулкита выполняет `publishes`, `loadRoutesFrom`, `loadMigrationsFrom`,
  `loadViewsFrom`, регистрацию команд → затем `packageBooted()`. Значит `Gate::policy`,
  `Event::subscribe`, `Route::bind` легальны только там.
- PT5.3 Чтение `config(…)` внутри `packageRegistered()` допустимо (конфиг уже смержен),
  но резолв сервисов/БД — нет.

## PT6. Проекция на vaulter (факты репозитория на 2026-07-10, вердикты — предмет P2)

| Провайдер | `->name()` | Ожидаемые теги | Факт |
|:--|:--|:--|:--|
| `VaulterServiceProvider` | `vaulter` | `vaulter-config`, `vaulter-migrations` | `hasConfigFile()`, `discoversMigrations()`, `runsMigrations()`, `hasRoute('api')`, `hasInstallCommand(...)` → `vaulter:install` |
| `VaulterDriveServiceProvider` | `vaulter-drive` | — | `hasRoute('drive')`; конфиг **не публикуется**, `mergeConfigFrom(vaulter-drive.php, 'vaulter.models')` |
| `VaulterMediaServiceProvider` | `vaulter-media` | — | конфиг **не публикуется**, `mergeConfigFrom(vaulter-cas.php, 'vaulter.cas')` |
| `VaulterDocumentsServiceProvider` | `vaulter-documents` | — | `hasRoute('documents')`, конфига нет |
| `VaulterFilamentServiceProvider` | `vaulter-filament` | `vaulter-filament-config` | `hasConfigFile()` |
| `VaulterAzgardServiceProvider` | — (голый `ServiceProvider`) | собственный `publishes([...])` | не тулкит; теги задаются вручную |

Проверить в P2.2/P2.5: реальные имена тегов (`vendor:publish --list`), последствия PT1.4
для `vaulter-drive`/`vaulter-media`, риск PT2.2, соответствие PT5.1/PT5.2.

---

## Источники

1. context7 `/spatie/laravel-package-tools` — README + llms.txt (2026-07-10):
   `hasConfigFile`, `discoversMigrations`, `runsMigrations`, `hasInstallCommand`, publish-теги.
2. https://github.com/spatie/laravel-package-tools/blob/main/README.md
3. `vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:163-172` —
   тело `mergeConfigFrom` (факт репозитория, прочитан 2026-07-10).
