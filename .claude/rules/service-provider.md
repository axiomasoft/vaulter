---
paths:
  - "**/*ServiceProvider.php"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.2
Template: rules/service-provider.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

ServiceProvider пакета (глубина — скилл `php-packages:laravel-package-service-provider`).
Risk-слой: **все потребители сразу** — провайдер исполняется у каждого на каждый запрос.

- `register()` — только дешёвые bindings (`bind`/`singleton`/`scoped`) и `mergeConfigFrom`.
  НИКАКОГО I/O (БД/HTTP/файловая система): контейнер ещё не готов, цена платится на
  каждый запрос каждого потребителя.
- `boot()` — `publishes`, `loadRoutesFrom`/`loadMigrationsFrom`/`loadViewsFrom`/
  `loadTranslationsFrom`, `Gate::`/`Event::listen`.
- Конфиг — `mergeConfigFrom` (не подмена целиком): частично опубликованный конфиг
  потребителя не должен терять дефолты пакета.
- Publish-tag существующих ресурсов не переименовывать — сломает
  `vendor:publish --tag=...` у потребителей.
