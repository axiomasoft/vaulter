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
- Тесты — только через `scripts/t`: `scripts/t --changed` (затронутые зоны), `scripts/t <engine> -p <pkg> [путь]`;
  зоны/группы/политика — `tests/README.md`. Мутации, load/k6 и coverage — только по отдельной задаче владельца.
- Pint / PHPStan — команды пакета или корневой harness; пропуск или падение ≠ успех.
- Правила провайдеров, публичного API и миграций — `.claude/rules/` (path-scoped).

## Скиллы и контекст

- Marketplace-профиль — `.swissknife.json` (компактный набор как CoreX: `core`, `php`, `php-packages`, `quality`, `task`, …).
- Не дублируй marketplace скиллами «на всякий случай»; подгружай скилл только по триггеру задачи.
- Локальный Cursor/Codex vendor-path `.agents/skills/` — **только CORE** (7 symlink + `ecosystem-*`), не полный `php-package` vendor. При раздувании: `bash scripts/sync-agent-skills.sh`.

<!-- maind:healthcheck hash=66b71fd2ffc8 (managed by maind — do not edit manually) -->
## mAInd — temporary project context and graph

Memory defaults to this project's private scope `project:vaulter`. Read `ns:*` or `global`
only for an explicit cross-project request and when the scope is authorized.

Store only short useful temporary context: 48-hour default TTL, 72-hour maximum,
800 characters, at most 8 live entries per scope. Use one replaceable `active-handoff`
for continuity, then forget it when work finishes. Keep plans, decisions, progress,
commit history and durable rules in repository artifacts. Empty memory is normal.

Use `maind health --cwd` for connection symptoms or setup changes; repair with
`maind health --cwd --fix`. A memory outage does not block ordinary repository work.
Project connections: `maind graph --project vaulter`.
<!-- /maind:healthcheck -->

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=4a7f58cd2b84 skills=209 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
