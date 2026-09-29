# P8 RAG — OTel-шов наблюдаемости + health (провенанс)

Верификация несущих внешних фактов дизайна P8 (перед фиксацией D35/D36). Инструмент:
perplexity-web (web), 2026-07-15. Ладдер `core:verify-claims`.

## RAG-15 — OTel PHP: library-инструментация без хард-зависимости на SDK

**Запрос:** может ли Laravel-библиотека инструментировать метрики против только `open-telemetry/api`
(без SDK), no-op по умолчанию; предпочтителен ли собственный узкий контракт вместо хард-OTel.

**Вердикт (источник: opentelemetry.io/docs/languages/php · /sdk · /concepts/instrumentation/libraries):**
- Зависимость только на `open-telemetry/api` **легальна и рекомендована** для библиотек: глобальный
  `MeterProvider` — **no-op**, пока хост не установил и не зарегистрировал SDK
  (`Sdk::builder()->setMeterProvider(...)->buildAndRegisterGlobal()`). «Нет SDK → нет телеметрии, без
  поломки» — заявленная цель дизайна OTel.
- **PSR-стандарта метрик нет** — библиотеки либо биндятся на OTel API, либо определяют собственные
  контракты. Для Laravel-пакета рекомендован **узкий framework-контракт** (counter/gauge/histogram) как
  публичное API, а OTel-backed реализация — опциональный мост; либо чистый custom-контракт с OTel только
  через отдельный bridge-пакет (максимальный декупляж).
- Хард-`require open-telemetry/sdk` в библиотеке — **против** library-guidelines OTel.

**Как лёг в план:** **D35** — vaulter-owned узкий `MetricsRecorder` (Null-дефолт + Fake), БЕЗ хард-OTel;
OTel-мост опционален (host/bridge против `open-telemetry/api`, сам no-op без SDK). Совпадает с прецедентом
`AzgardGuard` (пакет собирается без backend'а) и `.claude/rules/public-api.md` (без хард-зависимостей на шве).

## RAG-16 — spatie/laravel-health актуальность + `/up` vs readiness

**Запрос:** актуальная версия spatie/laravel-health, поддержка Laravel 12 / PHP 8.4; liveness/readiness,
custom Check для DB/S3/queue.

**Вердикт (источник: github.com/spatie/laravel-health · packagist · docs/installation-setup ·
docs/available-checks):**
- spatie/laravel-health — линия **1.x, активно поддерживается**, последний релиз к середине 2026 —
  **1.34.x** (июнь 2025+), Laravel 12 / PHP 8.4 совместим (проверить `composer.json` constraints перед
  lock — тренд однозначный).
- **`/up`** — уже **Laravel-native** liveness (200 при поднятом ядре), для k8s/docker probe; spatie для
  него не нужен.
- **Readiness** (DB/S3/queue) — `/health` spatie с Check-подклассами. **S3-check встроенного нет** —
  кандидат в custom Check (`Storage::disk('s3')->exists(...)` → `Result::ok()/failed()`). DB/Queue — есть
  встроенные, но custom допустим для строгой проверки.

**Как лёг в план:** историческая гипотеза была пересмотрена в RAG-17 и D36: оба endpoint'а принадлежат
load SUT; reusable package не экспортирует optional-parent классы.

## Источники

| RAG | URL | Дал |
|:--|:--|:--|
| RAG-15 | opentelemetry.io/docs/languages/php (+/sdk, /concepts/instrumentation/libraries) | API-only dep no-op без SDK; нет PSR-метрик; узкий контракт + опц. мост |
| RAG-16 | github.com/spatie/laravel-health, packagist, docs/* | 1.34.x актуален L12/PHP8.4; `/up` Laravel-native; S3-check — custom |

## RAG-16A — повторная проверка 2026-09-19 (Perplexity)

RAG-16 частично устарел по версии и форме API; вывод о package-owned Spatie-классах superseded D36.

- Актуальный стабильный `spatie/laravel-health` — **1.40.2** (2026-08-07); constraints: PHP `^8.2`,
  Illuminate `^11.0|^12.0|^13.0`.
- Документированный custom check наследует `Spatie\\Health\\Checks\\Check`, реализует
  `run(): Spatie\\Health\\Checks\\Result` и создаёт результат через
  `Result::make()->ok()` / `Result::make()->failed(...)`. Статические `Result::ok()/failed()` из
  старой формулировки не использовать.
- Laravel-native `/up` остаётся liveness приложения; dependency readiness остаётся отдельным health
  endpoint с 200/503. Ownership уточнён в D36.
- Для reusable instrumentation правило OTel также не изменилось: library зависит от API, а SDK/exporter
  конфигурирует приложение; без зарегистрированного provider вызовы остаются no-op.

**Проверенные первичные источники:**

| Источник | URL |
|:--|:--|
| Packagist package metadata | https://packagist.org/packages/spatie/laravel-health |
| Spatie custom checks | https://spatie.be/docs/laravel-health/v1/basic-usage/creating-custom-checks |
| Laravel 13 health route | https://laravel.com/docs/13.x/routing#health-checks |
| OpenTelemetry PHP library instrumentation | https://opentelemetry.io/docs/languages/php/instrumentation/ |

## RAG-17 — первичные источники перед финализацией P8.1 (Perplexity, 2026-09-20)

Повторная проверка выполнялась через настроенный `perplexity` MCP с требованием первичных официальных
источников.

- Laravel health route конфигурируется приложением, диспатчит `DiagnosingHealth` и сам по себе не
  является автоматической проверкой DB/S3/queue. Поэтому `/up` используется как process liveness, а
  `/ready` — отдельный SUT-owned агрегат с 200/503 (D36).
- OpenTelemetry рекомендует библиотекам API-инструментацию; без зарегистрированного SDK/provider она
  не экспортирует telemetry. SDK/exporter остаются ответственностью приложения (D35).
- Prometheus требует стабильные metric names/base units и прямо предостерегает от high-cardinality
  labels. Поэтому IDs, email, raw URL и exception messages запрещены, а §5 разворачивается в 50
  отдельных metric families (D35).

| Источник | URL | Использование |
|:--|:--|:--|
| Laravel `ApplicationBuilder::withRouting(... health:)` | https://api.laravel.com/docs/12.x/Illuminate/Foundation/Configuration/ApplicationBuilder.html | ownership `/up` |
| Laravel `DiagnosingHealth` | https://api.laravel.com/docs/12.x/Illuminate/Foundation/Events/DiagnosingHealth.html | семантика health event |
| OpenTelemetry PHP libraries | https://opentelemetry.io/docs/languages/php/libraries/ | API в library, SDK у application |
| OpenTelemetry PHP instrumentation | https://opentelemetry.io/docs/languages/php/instrumentation/ | provider/no-op semantics |
| Prometheus naming | https://prometheus.io/docs/practices/naming/ | `_total`, seconds/bytes, стабильные labels |
| Prometheus exporter guidance | https://prometheus.io/docs/instrumenting/writing_exporters/ | units и cardinality |
