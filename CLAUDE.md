# Vaulter — контекст для агентов

Монорепо Laravel-пакетов (MIT): файловый Drive, документы (TipTap/Yjs), CAS-медиа,
интеграция AzGuard, клиент `@vaulter/vue`, опционально Filament. Публикуется split-пакетами
`axioma-studio/vaulter-*`.

## Границы работы

- Код и тесты — только в затронутом `packages/<name>/`; не трогай соседние пакеты и чужие правки.
- Ядро — `packages/core` (`Vaulter\`); feature flags и opt-in поведение — `config/vaulter.php`.
- Активный исполняемый план нагрузки/observability — `plans/VLT-LOAD/`; load-стенд — `load/`.

## Пакеты (кратко)

| Пакет | Назначение |
| --- | --- |
| `core` | Контракты, guards, shared kernel |
| `drive` | Папки/файлы, uploads (Tus/S3) |
| `documents` | Редактор, версии, комментарии, collab (opt-in) |
| `media` | Content-addressed storage |
| `azgard` | PermissionResolver для AzGuard |
| `vue` | TypeScript/Vue 3 UI |
| `filament` | Admin (Laravel 13, optional) |

## Качество

- Per-package Pest: `packages/<pkg>/tests/`; стиль — существующие тесты пакета.
- Pint / PHPStan — команды пакета или корневой harness; пропуск или падение ≠ успех.
- Правила провайдеров, публичного API и миграций — `.claude/rules/` (path-scoped).

## Скиллы и контекст

- Marketplace-профиль — `.swissknife.json` (компактный набор как CoreX: `core`, `php`, `php-packages`, `quality`, `task`, …).
- Не дублируй marketplace скиллами «на всякий случай»; подгружай скилл только по триггеру задачи.
- Локальный Cursor/Codex vendor-path `.agents/skills/` — **только CORE** (7 symlink + `ecosystem-*`), не полный `php-package` vendor. При раздувании: `bash scripts/sync-agent-skills.sh`.

<!-- maind:healthcheck hash=cce842a2c675 (managed by maind — do not edit manually) -->
## ⚠ PRIORITY №1 — connection with mAInd (shared memory + graph)

Before starting work, make sure that the project is connected to the hub **mAInd**. This is critical:
without communication changes this session **will not hit** to shared memory and the graph to history
development, irreparable gaps will appear, and further connections will not be drawn.

1. Check connection: `maind health --cwd`
2. If the status `partial` / `standalone` — fix: `maind health --cwd --fix`
   (will raise the memory daemon and add MCP-hub servers to this project).
3. Proceed with the task only when the status `connected`.

Memory **general** (demon mAInd), scope of this project - `project:vaulter`; facts about the entire ecosystem - in
scope `global`. Project connections with others: `maind graph --project vaulter`.
<!-- /maind:healthcheck -->

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=9ea5e5679c72 skills=232 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
