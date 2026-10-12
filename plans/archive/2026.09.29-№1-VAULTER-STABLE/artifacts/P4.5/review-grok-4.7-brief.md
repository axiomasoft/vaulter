# Ревью PLAN2.P4.5 — бриф для независимого read-only ревьюера (Grok 4.7)

Ты — независимый ревьюер. Репозиторий: `/home/vostrikov/projects/packages/vaulter` (монорепо Laravel-пакетов Vaulter
+ Node-хаб `hocuspocus-server/` + клиент `packages/vue`). Пиши только отчёт: файлы репозитория не меняй (запись
запрещена ядром). Ответ — на русском.

## Что ревьюим

Коммит `45e0f4ae` (диапазон `c77b15ba..45e0f4ae`, `git show 45e0f4ae`, `git diff c77b15ba 45e0f4ae`).
Item P4.5 «Коллаборация: серверная загрузка, поколение комнаты, storage fence и read-only».

Нормы (читать первыми):
- `plans/2026.09.29-№1-VAULTER-STABLE/phases/P4/P4.5.json` — scope, rules, validation;
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P4-design.json` разделы Collaboration, WorkingState, Validators, Errors;
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P4-invariants.json` (P4-I5…I8, порядок блокировок T→N→D);
- `plans/2026.09.29-№1-VAULTER-STABLE/decisions/D36.json`, `D44.json`, `D51.json`;
- `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.5/completion-notes.md`, `check-profile.json`, `negative-controls.md`;
- журнал отклонений: `plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` (строки с `"item": "P4.5"`).

## Существенные изменения и рискованные швы

1. **Storage fence** — `packages/documents/src/Actions/Collaboration/PersistCollaborationStateAction.php`: сравнение epoch
   комнаты с `collab_epoch` под T(shared)→N→D FOR UPDATE, `seed` только при `yjs_state IS NULL`, лимит
   `max_state_bytes`, `SupportsCollaboration`. Писатель — `Content/DocumentStateWriter.php` (не менялся в P4.5).
   Restore — `Actions/Revisions/RestoreDocumentRevisionAction.php` (+ событие `Events/Revisions/DocumentRestored`).
2. **Аутентификация вызовов хаба** — `Collaboration/CollaborationCallResolver.php`, `CollaborationCall.php`,
   `Http/Middleware/ResolveCollaborationCall.php`: теперь обязательны И room-JWT, И HMAC тела (shared-secret как
   личность удалён), replay-кэш в Cache.
3. **Живые проверки** — `Collaboration/CollaborationHub.php` (`open/load/store`), `HubRoom.php`,
   `Http/Controllers/Collaboration/NodeCollaborationController.php`, маршрут `collab/load` в `routes/documents.php`.
4. **Сессия** — `Operations/DocumentOperations::collaborationSession`, `Collaboration/HocuspocusCollaborationDriver.php`,
   `Data/Collaboration/CollaborationSession.php`, `Contracts/CollaborationDriver.php`,
   `Configuration/DocumentsConfig.php` (`collaborationRoom`, `parseRoom`, `token_ttl`, `driver=reverb`).
5. **Хаб** — `hocuspocus-server/src/index.js` (`createHooks`: onAuthenticate, connected, beforeHandleMessage,
   onLoadDocument с сидом, onStoreDocument с fence/4409, afterUnloadDocument), `src/webhook.js`, `src/schema.js`,
   `package.json`. Hocuspocus v4 API (форма payload хуков, `connectionConfig`, `Document.getConnections()`,
   проброс `error.reason`) исполнителем локально не проверен — проверь по коду пакета, если он доступен.
6. **Клиент** — `packages/vue/src/nodeCollaboration.ts`, `composables/useNodeCollaboration.ts`,
   `components/VaulterDocumentNode.vue`, `types.ts`, `client.ts`.
7. **Тесты** — `packages/documents/tests/Collaboration/{HubProtocolTest,CollaborationWebhookTest,HubTestHelpers,TipTapSchemaFixtureTest,NodeCollaborationTokenTest}.php`,
   `tests/Concurrency/CollaborationFenceRaceTest.php` (PG, отложен в барьер P4.7), `hocuspocus-server/test/*.test.js`,
   `test/e2e/restore-epoch.e2e.js`, `scripts/collab-e2e.sh`, `packages/vue/tests/*`.
8. Механика (низкий риск): golden `tests/Arch/__snapshots__/public-api.txt`, docs en/ru, UPGRADE/CHANGELOG.

## На что смотреть

Корректность и безопасность прежде стиля: обходы fence (поздний store, другой инстанс хаба, seed после restore,
update-replace), гонки и порядок блокировок, может ли ro/просроченный/чужой токен писать или читать, подмена комнаты
(узел/диск/тенант/epoch), replay/подпись, утечка токенов в логи/ошибки, поведение хаба на 409/404/4xx/5xx
(бесконечный ретрай, потеря данных, задвоение контента при сидировании), клиент на 4409 (не мёрджит старый Y.Doc),
соответствие нормам P4-design/D36 и scope item, публичный API/BC (golden, UPGRADE), полнота тестов для заявленных
сценариев (V11, V12, ro, seed conflict, лимиты, archived/trashed/tenant).

## Как проверять

Разрешены чтение, grep, git и короткие точечные прогоны. Не запускай полные сьюты, e2e-скрипт, npm ci/install,
PostgreSQL и mutation-инструменты.
- PHP: `cd packages/documents && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <файл> [--filter <имя>]`
- Vue: `cd packages/vue && npx vitest run <файл>`
- Хаб: зависимости `@hocuspocus/transformer`, `@tiptap/extension-image|table` не установлены — тесты, импортирующие
  `src/index.js`, не запустятся; `node --test test/webhook.test.js` работает.

Для каждой находки дай воспроизведение: падающий тест/команду с выводом, либо точную цепочку вызовов
с файлами и строками. Находка без воспроизведения помечается как «plausible».

## Формат ответа

```
# P4.5 — внешнее ревью Grok 4.7 (read-only)

## Находки
| # | Severity (critical/high/medium/low) | Файл:строка | Суть | Сценарий отказа | Воспроизведение | Рекомендация |

## Что проверено и чисто
(кратко по швам 1–7)

## Вердикт
approve | request changes — одной фразой почему.
```

Если находок нет — так и напиши, с перечнем проверенного.
