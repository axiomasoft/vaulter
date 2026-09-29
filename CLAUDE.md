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

<!-- maind:healthcheck hash=d597b39af21f (managed by maind — не редактировать вручную) -->
## ⚠ ПРИОРИТЕТ №1 — связь с mAInd (общая память + граф)

Перед началом работы убедись, что проект подключён к хабу **mAInd**. Это критично:
без связи изменения этой сессии **не попадут** в общую память и граф — в истории
разработки появятся невосстановимые пробелы, и дальнейшие связи будет не вывести.

1. Проверь связь: `maind health --cwd`
2. Если статус `partial` / `standalone` — почини: `maind health --cwd --fix`
   (поднимет демон памяти и допишет MCP-серверы хаба в этот проект).
3. Приступай к задаче только при статусе `connected`.

Память **общая** (демон mAInd), scope этого проекта — `project:vaulter`; факты всей экосистемы — в
scope `global`. Связи проекта с остальными: `maind graph --project vaulter`.
<!-- /maind:healthcheck -->

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — скиллы и task-команды подключаются нативно; индекс и правила приоритета здесь не дублируются. -->
<!-- swissknifeman:registry sha=5b8274432dfe skills=232 -->
<!-- Полный индекс: `skiller status` или generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
