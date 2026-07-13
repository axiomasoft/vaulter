---
paths:
  - "**/tests/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: laravel-package@0.2.2
Template: rules/tests.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/vaulter-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack laravel-package --target .
-->

При работе с тестами пакета (глубина — скилл `php-packages:laravel-package-testing`):

- Прогон только `composer test` (хост-специфика проекта), не bare `php`.
- Тест-фреймворк проекта — `pest`: прочитай 1–2 существующих теста и зеркаль их стиль.
- Инфра — Orchestra Testbench: тесты наследуют пакетный `TestCase` с `getPackageProviders()`;
  БД — in-memory sqlite (`:memory:`), без внешнего окружения.
- Пакетный тест не зависит от приложения: никаких `use App\...` — стабы/модели для
  тестов живут в `tests/` (Workbench/fixtures).
- Матрично-чувствительные места (`version_compare`-ветки, проверки версии `illuminate/*`) —
  кейсы на ОБЕ ветки: CI-матрица PHP×Laravel исполнит обе, локальный прогон — одну.
- На изменённое поведение — тест; существующие проверки не ослаблять.
- **Честность тестов:** любая обходка/форс ради зелёного (форс `config(...)`, ручная
  регистрация биндинга вместо провайдера, sibling-класс в обход `final`) — пометь
  `[POSSIBLE-DEFECT]` в отчёте: за обходкой почти всегда реальный баг.
