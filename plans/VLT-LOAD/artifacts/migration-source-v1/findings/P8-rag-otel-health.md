# P8 RAG — OTel-шов наблюдаемости + health (провенанс)

Верификация двух несущих внешних фактов дизайна P8 (перед фиксацией D31/D33). Инструмент:
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

**Как лёг в план:** **D31** — vaulter-owned узкий `MetricsRecorder` (Null-дефолт + Fake), БЕЗ хард-OTel;
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

**Как лёг в план:** **D33** — liveness = SUT-level Laravel-native `/up` (пакет не владеет `/up`); readiness
= опциональные vaulter Check-классы за spatie через `suggest` (не `require`, package-hygiene) + wiring в SUT
`load/`. Гейт §5.4 (харнесс отличает «упало» от «не поднялось») удовлетворён без хард-зависимости.

## Источники

| RAG | URL | Дал |
|:--|:--|:--|
| RAG-15 | opentelemetry.io/docs/languages/php (+/sdk, /concepts/instrumentation/libraries) | API-only dep no-op без SDK; нет PSR-метрик; узкий контракт + опц. мост |
| RAG-16 | github.com/spatie/laravel-health, packagist, docs/* | 1.34.x актуален L12/PHP8.4; `/up` Laravel-native; S3-check — custom |
