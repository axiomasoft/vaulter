---
paths:
  - "**/database/migrations/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.2
Template: rules/package-migrations.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

Миграции пакета (глубина — скилл `php-packages:laravel-packages`). Risk-слой:
**схема потребителя** — миграция исполняется в чужой БД, отзыва нет.

- Раздача — `loadMigrationsFrom` в `boot()` и/или publishable (`publishes` с tag):
  потребитель выбирает, запускать из vendor или публиковать к себе.
- Только anonymous-class миграции (`return new class extends Migration`) — именованный
  класс коллидирует с одноимённой миграцией потребителя.
- `down()` корректен и обратим.
- Уже применённые (зарелизенные) миграции не менять — правка структуры идёт НОВОЙ
  миграцией: у потребителей старая версия уже выполнена.
