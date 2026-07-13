# P0 — RAG: реестры/драйверы в Laravel-экосистеме (Manager, Flysystem, Filament, Nextcloud)

**Дата:** 2026-07-10 · **Метод:** perplexity-web + laravel.com/api.laravel.com + первоисточники · **Статус:** verified, флаги внизу

## Illuminate\Support\Manager

- База: `$drivers`-кэш (lazy singletons), `$customCreators`, `driver($name)` →
  `create{Studly}Driver` либо custom creator из `extend($name, Closure)`. Одна абстрактная
  `getDefaultDriver()`.
- **Нюанс:** базовый Manager мапит ИМЯ драйвера прямо на фабрику (name === factory suffix).
  Двухуровневая индирекция «имя инстанса → config-массив → `'driver'` → фабрика» — надстройка
  FilesystemManager/CacheManager, не база.
- Не все first-party — наследники Manager: FilesystemManager/AuthManager/QueueManager — да;
  CacheManager/MailManager — собственные Factory-реализации. (Утверждение «MailManager extends
  Manager» из одного синтеза — противоречиво, флаг.)
- Минусы как публичного API пакета: statefulness (кэш инстансов, `forgetDrivers()`),
  жёсткая связка с Container+Config, god-object дрейф через `__call()`.
- **Рекомендация источников:** публичный контракт — узкий framework-agnostic интерфейс реестра;
  Manager (если вообще) — внутренняя деталь Laravel-обвязки; биндить и документировать ИНТЕРФЕЙС.

## Flysystem / Storage::extend

- `Storage::extend('driverName', fn($app, $config) => new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config))`;
  ключ — имя драйвера, матчится по `'driver'` диска. Урок: **узкий адаптер-интерфейс +
  config-driven выбор + host регистрирует кастомы по имени**; диспатч и кэш — у фреймворка.

## Filament (v5 стабилен с 2026-01, v4 LTS; plugin-API стабилен v3→v5)

- `Filament\Contracts\Plugin`: `getId()`, `register(Panel)`, `boot(Panel)`; регистрация
  `->plugin(...)` на панели. Third-party = обычный composer-пакет: SP для ассетов + Plugin-класс.
- Вклад по **типизированным каналам** (`resources()`/`pages()`/`widgets()`), не одним generic-мешком.

## Nextcloud (шов «MIME → обработчик»)

- Viewer: `OCA.Viewer.registerHandler({id, group, mimes[], component})` — диспатч по MIME-списку.
- FileAction: ключ — не MIME, а предикат `enabled(nodes, view)`; конфликт дефолтов — «первый
  выигрывает», дубль id отвергается громко.
- Флаг: сигнатуры из community-примеров, не единой API-референс-страницы.

## Вердикт для vaulter

Гибрид: публичный контракт = framework-agnostic `HandlerRegistry` + `FileHandler`
(+ capability-декларации), MIME→handler-индирекция уровня Filesystem, внешняя регистрация
уровня `extend()`/config/tag. **Текущая архитектура vaulter уже совпадает с этим вердиктом**
(bespoke-реестр, узкий core-контракт, 3 канала регистрации: tag + config FQCN + runtime).
Переход на наследование `Illuminate\Support\Manager` — НЕ рекомендован (statefulness,
`$app`-coupling, `__call()`), см. D4.

## Источники

api.laravel.com/docs/12.x/Illuminate/Support/Manager.html · laravel.com/docs/13.x/filesystem ·
laravel.com/docs/13.x/configuration · pineco.de/drivers-and-managers-in-laravel ·
dev.to (Manager pattern in packages) · filamentphp.com/docs/5.x (version-support-policy) ·
filamentphp.com/docs/4.x/advanced/plugins · github.com/vimaec/nextcloud-viewer ·
help.nextcloud.com (custom mimetype) · stackoverflow #44104620 (driver-key pitfall)
