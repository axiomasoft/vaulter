# Отчёт аудита `plan/` и перечень закрытых дыр

Дата аудита: 2026-11-22. Проверены все фазы (`00–07`), спецификации (`spec/`), схемы БД (`spec/database/sql/`), скрипты проверок (`checks/`) и материалы исследования Tiptap Pro (`tiptap-pro/`).

## Найденные и закрытые дыры

| # | Обнаруженная дыра / противоречие | Как закрыто |
| --- | --- | --- |
| 1 | **Два параллельных несогласованных плана:** `plan/phases/` (00–07) и `plan/tiptap-pro/development-plan.md` (V1–V5) жили отдельно; V3 (Comments/Compare/Tracked Changes), V4 (AI Chat SSE) и V5 (DOCX) не входили в граф фаз и не проверялись `verify-plan.py` | Добавлены фазы [08-editor-pro-review.md](phases/08-editor-pro-review.md), [09-ai-chat.md](phases/09-ai-chat.md) и [10-docx-exchange.md](phases/10-docx-exchange.md); обновлён [verify-plan.py](checks/verify-plan.py) (теперь валидирует 11 фаз, 30 задач, 56 критериев) |
| 2 | **Размытая граница репозиториев и риск утечки приватного кода в `vaulter`:** коллаборация лежала внутри публичного `vaulter`, а для V3/V5 не было репозитория-владельца | Зафиксирована чёткая топология из **5 репозиториев** (`vaulter`, `vaulter-collaboration`, `vaulter-sheets`, `vaulter-ai`, `vaulter-editor-pro`). Созданы 5 коротких паспортов в [`plan/repos/`](repos/README.md) и добавлена задача `2.0` по выносу коллаборации и очистке `vaulter` (включая legacy `hocuspocus-server/` и `packages/azgard/`) |
| 3 | **Расхождение спецификации и SQL в `3.5`:** `spec/implementation/vaulter.md` требовал профиль поколения на `v_collaboration_heads` и реестр `clientID` по `(document_id, generation, client_id)`, но в `collaboration-agent.sql` их не было | В [collaboration-agent.sql](spec/database/sql/collaboration-agent.sql) добавлены колонка `profile` (`standard`/`strict`) на `v_collaboration_heads` и таблица `v_collaboration_client_ids` |
| 4 | **Отсутствующие исполняемые SQL-схемы для V3, V4 и V5:** таблицы чата, range anchors, suggestions, revision pins и `document_exchange_jobs` существовали только в тексте `tiptap-pro/backend-design.md` | Созданы исполняемые DDL [ai-chat.sql](spec/database/sql/ai-chat.sql), [editor-pro-review.sql](spec/database/sql/editor-pro-review.sql), [editor-pro-exchange.sql](spec/database/sql/editor-pro-exchange.sql) и добавлены в [schema.sh](checks/schema.sh) с проверкой на чистом PostgreSQL 18 |
| 5 | **Недокументированный в фазе 2 сломанный restore E2E checkout:** `restore-epoch.e2e.js` падал на несовместимом формате сессии (`rw/ro` vs `write/read`, `epoch` vs `generation`), отсутствии `If-Match` и 404 `node_not_found` (morph type mismatch) | Явно внесено обязательное исправление в задачу `2.2` ([02-collaboration.md](phases/02-collaboration.md)) и [dev-plans/vaulter-collaboration.md](dev-plans/vaulter-collaboration.md) |
| 6 | **Устаревшее противоречие про trial Tiptap Pro в `host-integrations.md` (H3):** требовало сравнить trial Pro до расширения review, хотя исследование `tiptap-pro/` уже зафиксировало HTTP 403 на Client AI Toolkit / Compare / Import DOCX | Обновлён раздел H3 в [spec/host-integrations.md](spec/host-integrations.md) с явной привязкой к `vaulter-editor-pro` (фазы 08, 10) и `vaulter-ai` (фаза 09) |
| 7 | **Отсутствие оркестрации моделей, волн и отдельных планов по репозиториям:** исполнителям приходилось собирать контекст из 10+ файлов, не были назначены модели-исполнители и ревьюеры | Создана матрица назначения моделей (**Astra**, **GPT-6.2 Sol**, **Gemini Neon**, **Gemini Carbon**, **Gemini Argon**), 8 параллельных волн и протокол handoff в [execution/models-and-orchestration.md](execution/models-and-orchestration.md), а также 5 автономных планов разработки в `plan/dev-plans/` |
| 8 | **Незафиксированная точка старта по общему порту `AtomicDocumentMutation`:** его могли независимо начать делать и в `3.3`, и в `4.2` | В [models-and-orchestration.md](execution/models-and-orchestration.md) и [dev-plans/vaulter.md](dev-plans/vaulter.md) явно закреплено: порт выпускается в `vaulter` (GPT-6.2 Sol → Astra) на старте волны W3, до параллельного запуска `4.2` и `3.3` |

## Проверка после закрытия дыр

Все автоматические проверки плана, фикстур и целевых SQL-схем (включая новые модули `ai-chat`, `editor-pro-review`, `editor-pro-exchange` и обновлённый `collaboration-agent`) должны завершаться с кодом `0`:

```bash
python3 plan/checks/verify-plan.py
python3 plan/checks/verify-fixtures.py
PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh
```
