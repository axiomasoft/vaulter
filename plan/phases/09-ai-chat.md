# 9. Возобновляемый AI-чат (vaulter-ai)

[Паспорт `vaulter-ai`](../repos/vaulter-ai.md) · [План разработки](../dev-plans/vaulter-ai.md) · [Проект чата](../tiptap-pro/backend-design.md#5-возобновляемый-чат) · [DDL чата](../spec/database/sql/ai-chat.sql) · [Матрица приёмки](../tiptap-pro/acceptance-matrix.md).

### 9.1 Backend чата, SSE `Last-Event-ID` и tool call ledger

**После** — 3.3, 7.1.

1. В `vaulter-ai/packages/core` добавить миграции `v_ai_chat_sessions`, `v_ai_chat_messages`, `v_ai_chat_events` и `v_ai_tool_calls` по [DDL](../spec/database/sql/ai-chat.sql).
2. Реализовать атомарный `POST /office/chat/sessions/{id}/messages` (уникальный `message_id` → ровно один `run_id`, другой digest даёт конфликт), транзакционный счётчик `last_seq` на строке сессии и поток `GET /office/chat/sessions/{id}/events` (SSE с `id=seq` и восстановлением по `Last-Event-ID` / cursor).
3. Реализовать ledger вызовов инструментов с lease/fencing, scoped `input_digest` и связью с `proposal_id`/`command_id`/`receipt_id`. При рестарте worker после owner commit восстановление идёт через `status`/`verify` без повторной записи; проверка актуального ACL выполняется перед выдачей дельт и результатов.

- **9.1-A** Детерминированный прогон (без LLM) с обрывом SSE и SIGKILL worker после owner commit возвращает тот же `run_id`, один `tool_call`, одну квитанцию и непрерывный поток событий по `seq` без дублей.
- **9.1-B** Неполный JSON tool input отвергается; две транзакции потока в одной сессии строго упорядочены без блокировки чужой сессии; после отзыва прав защищённые фрагменты скрываются при reconnect.

### 9.2 UI панели чата, Laravel AI / AgenTools streaming и внешний канал

**После** — 3.4, 7.2, 9.1.

1. В `vaulter-ai/packages/vue` (`@vaulter/ai-vue`) реализовать панель диалога с привязкой к `run_id`, передачей ограниченного выделения редактора, подпиской на SSE и восстановлением по курсору, раздельными статусами (`assistant.interrupted`, `waiting approval`, `owner committed`, `verified`) и сворачиванием фрагментов при отзыве доступа.
2. Подключить потоковую генерацию и tool calls в `packages/laravel-ai` и опциональном `packages/agentools` с серверным secret store, каталогом tools по установленным версиям/правам и лимитами/retry. Для пишущих tools в live journal требовать прохождения 3.5.
3. Связать внешний канал (эмулятор из 7.2) с сессией чата: промежуточное сообщение не запускает run, финальное и его дубликат ведут к одному `run_id`. Отдельной строкой выполнить smoke с реальным ключом провайдера.

- **9.2-A** В двух браузерных контекстах и при закрытых вкладках чат доводит поручение до approval, commit и verify; обрыв и reload восстанавливают состояние по `Last-Event-ID`.
- **9.2-B** Каталог инструментов строится по актуальным правам и версиям; секреты провайдера отсутствуют в браузере, событиях и логах; внешний канал идемпотентно привязан к `run_id`.
