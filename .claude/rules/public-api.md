---
paths:
  - "**/src/Contracts/**"
  - "**/src/**/Facades/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.2
Template: rules/public-api.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

Публичный контракт пакета (глубина — скилл `php-packages:laravel-package-compatibility`).
Risk-слой: **BC/SemVer** — это обещание чужим потребителям, не внутренний код.

- Контракты стабильны: интерфейс в `Contracts/` и фасад — публичное API. Удаление/
  переименование метода, сужение типов параметров — breaking → **MAJOR** + upgrade-заметка.
- Новое `public` — осознанное SemVer-обязательство: сперва спроси, нельзя ли оставить
  внутренним.
- Внутреннее помечай `@internal` (+`final` для классов) — это выводит его из BC-обещания.
- Фасад — над контейнер-биндингом (`getFacadeAccessor` → контракт), не над конкретным классом.
