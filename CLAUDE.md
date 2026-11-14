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
| `azguard` | PermissionResolver для AzGuard |
| `vue` | TypeScript/Vue 3 UI |
| `filament` | Admin (Laravel 13, optional) |

## Качество

- Per-package Pest: `packages/<pkg>/tests/`; стиль — существующие тесты пакета.
- Тесты — только через `scripts/t`; что запускать на шаге плана, перед коммитом, пушем и релизом — таблица
  «Which run when» в `tests/README.md#which-run-when` (единственный источник). `make test-full`, load/k6 и
  coverage — только по отдельной задаче владельца.
- Pint / PHPStan — команды пакета или корневой harness; пропуск или падение ≠ успех.
- Для активного PLAN4 (`2026.10.18-№1-VAULTER-STABLE`) действует D2: только лёгкие проверки из `validation` конкретного пункта/unit или явно назначенного checkpoint батча; общие проверки выполняются один раз в этом checkpoint. Автоматический package/`--changed`/DB-прогон перед каждым коммитом не требуется. Лимиты: ≤300 с на команду, ≤10 мин на пункт/unit (P8.5 — ≤20 мин). Тяжёлые DB/full/serial/stand/coverage, установки архивов и сборки образов — отдельный runbook владельца P8.2; load этим планом не разрешён. Удалённые средства mutation testing не восстанавливать (M16). Этот overlay имеет приоритет над обычной таблицей «Which run when» и другими планами.
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
<!-- swissknifeman:registry sha=695f52f0312f skills=209 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
