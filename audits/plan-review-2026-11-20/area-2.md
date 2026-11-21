# Участок 2 — запись в живую комнату и падение между SQL и Y.Doc (журнал прогресса)

Статус: ГОТОВО (2026-11-20). Окружение: PostgreSQL 18.4, Node v24.12.0 (Hub объявляет engines >=26 — не проверено на 26), yjs 13.6.31, @hocuspocus/server 4.3.0.

## Факты (исходники)

- Hub `packages/collaboration/hub/src/index.js` `createHooks().beforeHandleMessage`: токен, лимиты, `persistence.beforeHandleMessage` (гейт размера/остановки). Общего порядка комнаты нет.
- `persistence.js`: `replayLog(..., origin=LOAD_ORIGIN)`; `LOAD_ORIGIN` помечен модульным `OWN` → `originKind()`='own' → `capture()` не дописывает. Любой иной origin (кроме redis/connection) = 'server' → `capture()` дописывает под новым update_id (823-853). `handleEnvelope` применяет до commit (1055). `serverWrite` = `document.transact(change, {source:'vaulter-server'})` до commit (1252). `onLoadDocument` повторно применяет незафиксированные записи ledger (964-968).
- Hocuspocus 4.3.0 (установленный исходник и dist): `Connection.ts processMessages` — очередь на соединение, `beforeHandleMessage` → `MessageReceiver.apply` → `afterHandleMessage` (finally; не вызывается, если before бросил). `MessageReceiver.readSyncMessage` отбрасывает sync step 2/update при `connection?.readOnly` (214, 256). `DirectConnection.transact` — origin `local`, без hook. `extension-redis` `handleIncomingMessage` — `new MessageReceiver(message, this.redisTransactionOrigin).apply(document, void 0, …)`: без соединения, hook и readOnly. `Hocuspocus.createDocument` применяет результат onLoadDocument без origin.

## Решения

- Барьер строгой комнаты = room sequencer, не hook: все соединения readOnly, запись только envelope → commit → применение сохранённых байтов с OWN-origin. Обходы: DirectConnection.transact, Redis → критерий выключения live capability (Redis URL в строгом профиле; наблюдатель update увидел чужой origin → fence).
- Владелец — настоящая PG18 (BOUNDARY: SQL-функции вместо PHP CommandJournal/RoomOwnership/UpdateLog), каждая операция — отдельный psql-процесс.
- Дерево — через @hocuspocus/transformer (y-prosemirror) + схема Hub rich-text + проектируемый BlockId (3.1), `Node.check()`.

## Файлы

- plan/examples/reference/live-write/{package.json,package-lock.json,room-sequencer.mjs,owner-psql.mjs,schema.mjs,room-process.mjs,hocuspocus-paths.mjs}
- plan/checks/collaboration/{live-write.sh,live-write.test.mjs,live-write-owner.sql}
- Правки: plan/spec/implementation/vaulter.md (3.5: строка «Последовательная обработка», абзац критерия + аренды + Образец; подраздел «Внутренние сообщения»), plan/spec/collaboration.md («Подготовка», «Доставка», последний абзац «Приёмки»), plan/phases/03-text-sdk.md (Образец 3.5), plan/spec/implementation/ai.md (строка 3.5). Фикстура и verify-fixtures не менялись.

## Команды

`bash plan/checks/collaboration/live-write.sh` (exit 0):

```
PostgreSQL 18.4 (Ubuntu 18.4-1.pgdg24.04+1) on x86_64-pc-linux-gnu, compiled by gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0, 64-bit
node v24.12.0

added 69 packages in 287ms
OK sample lock: 69 packages, all at Hub lock versions
OK register_intent — command row 01a1fa31-23cb-7210-8a95-d64c86122695, head 40
OK room process 1 opened — pid 1679870, owner_fence 7, applied 40
OK accept_human_edit — seq 41, room text ["Аванс в течение 15 дней","Банк Б"]
OK commit then SIGKILL before apply — owner head 42, room applied 41, room text ["Аванс в течение 15 дней","Банк Б"]
OK query_status_as_authorized_actor — receipt 01a1fa31-24d3-7d48-8bc6-c2ffc9ea9da1, marker {"position":42,"generation":3}
OK open_next_room_owner — pid 1680000, owner_fence 8, applied 41, mayEvaluate false
OK compaction lease ≠ room writer — writer fence 7→8 kept the compactor's prefix ≤ 41; the compactor cannot append (stale_owner); publication bounds: lease-schema.sql
OK replay_committed_prefix + outbox twice — applied then duplicate, applied 42
OK repeat_a42_and_deliver_outbox_twice — SQL {"updates":2,"head_seq":42,"owner_fence":8,"outbox_events":1,"update_receipts":2,"command_receipts":1,"commands_running":0,"commands_rejected":0,"commands_committed":1}
OK read_at_commit_and_current — ["Аванс в течение 30 дней","Основание: решение финансового директора","Банк Б"]
OK sensitivity: tampered bytes / other bytes for seq 42 / old-generation event refused — hash_mismatch, hash_mismatch, foreign_generation
OK sensitivity: owner_fence 7 after fence 8 — settle stale_owner, append stale_owner
OK sensitivity: same key, replacement 45 — key_conflict
OK sensitivity: re-executed A42 duplicates the paragraph — ["Аванс в течение 3030 дней","Основание: решение финансового директора","Основание: решение финансового директора","Банк Б"]
OK replay origin — COMMITTED_ORIGIN → own (not appended), serverWrite origin → server (Hub capture() would append a second record); re-applying held bytes emits no update
OK A43 waits for position 42 — room loaded 41; trace [{"type":"catch_up","from":41,"to":42},{"type":"evaluate","command":"A43","baseSeq":42,"appliedSeq":42},{"type":"committed","command":"A43","from":43,"to":43}]; at prefix 41 → precondition_failed, owner base 41 → base_moved
OK partial_patch_failure — room ["Аванс в течение 15 дней","Банк А"], SQL {"updates":0,"head_seq":40,"owner_fence":7,"outbox_events":0,"update_receipts":0,"command_receipts":0,"commands_running":1,"commands_rejected":0,"commands_committed":0}
OK sensitivity: same failure applied to a live Y.Doc keeps the first effect — Аванс в течение 30 дней
OK partial_patch_failure resumed from the stored intent — receipt 01a1fa31-2eb5-7ead-8e53-3e72f39262e6, head 41
OK live-write: crash between commit and apply, duplicate delivery, A43 ordering and partial failure
Hocuspocus 4.3.0, yjs 13.6.31, node v24.12.0
┌─────────┬───────────────────────────────────────────────┬──────────────────────────────────────────────────────────────────────────────┬─────────────────────┬───────────────────┬────────────────────────────────────────────────────────────────────────────────────────────────────┐
│ (index) │ path                                          │ symbol                                                                       │ beforeHandleMessage │ appliedToDocument │ note                                                                                               │
├─────────┼───────────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────────────┼─────────────────────┼───────────────────┼────────────────────────────────────────────────────────────────────────────────────────────────────┤
│ 0       │ 'sync step 2 (writable connection)'           │ 'server/src/Connection.ts processMessages → MessageReceiver.readSyncMessage' │ true                │ true              │ 'origin {source:"connection"}; applied before any commit'                                          │
│ 1       │ 'update (writable connection)'                │ 'server/src/Connection.ts processMessages → MessageReceiver.readSyncMessage' │ true                │ true              │ 'origin {source:"connection"}; applied before any commit'                                          │
│ 2       │ 'sync step 2 / update (readOnly connection)'  │ 'MessageReceiver.ts readSyncMessage: connection?.readOnly'                   │ true                │ false             │ 'SyncStatus 0 to the client; the strict-room barrier relies on this'                               │
│ 3       │ 'awareness'                                   │ 'MessageReceiver.ts case Awareness → beforeHandleAwareness'                  │ true                │ false             │ 'presence only, no Y.Doc content'                                                                  │
│ 4       │ 'stateless envelope'                          │ 'MessageReceiver.ts case Stateless → onStateless'                            │ true                │ false             │ 'the Hub envelope path; the strict room sends it to the sequencer'                                 │
│ 5       │ 'two connections'                             │ 'Connection.ts messageQueue is per connection'                               │ true                │ true              │ 'writer2 applied while writer waited in beforeHandleMessage: the hook alone is not a room barrier' │
│ 6       │ 'DirectConnection.transact / Hub serverWrite' │ 'server/src/DirectConnection.ts transact'                                    │ false               │ true              │ 'origin {source:"local"}; bypass'                                                                  │
│ 7       │ 'Redis pub/sub sync / update'                 │ 'extension-redis/src/Redis.ts handleIncomingMessage'                         │ false               │ true              │ 'connection undefined: readOnly cannot apply; bypass'                                              │
└─────────┴───────────────────────────────────────────────┴──────────────────────────────────────────────────────────────────────────────┴─────────────────────┴───────────────────┴────────────────────────────────────────────────────────────────────────────────────────────────────┘
OK hocuspocus-paths: bypasses of beforeHandleMessage = ["DirectConnection.transact / Hub serverWrite","Redis pub/sub sync / update"]

real	0m4.515s
user	0m1.684s
sys	0m0.925s

```

`python3 plan/checks/verify-plan.py` → OK: фаз 8, задач 22, критериев 37
`python3 plan/checks/verify-fixtures.py` → OK (live-write: 10 ordered events, 11 fault variants)
`bash plan/checks/schema.sh` → OK (SQL спецификации не менялся)

## Осталось (вне участка)

- Интеграция в настоящий Hub (persistence.js/index.js) и PHP; два браузера; Redis-мост «факт позиции».
- (Сессия 1) не пройдены 8 из 11 вариантов; после сессии 2 — 5 из 11, см. «Следующий шаг».
- Незаконченных правок в общих файлах нет; все правки spec/phases завершены и проверены verify-plan/verify-fixtures.

## Следующий шаг (если продолжать)

**Сессия 2 (2026-11-21 07:35):** в `live-write.test.mjs` добавлены три варианта, каждый с мутантом; `bash plan/checks/collaboration/live-write.sh` → exit 0 три прогона подряд, ≈4,5 с.
- `concurrent_duplicate`: новый документ, человек → 41, два воркера (два psql-процесса) вызывают `settle_commit` для одной команды с разными байтами Yjs. Барьер — третье соединение держит строку `v_documents` FOR UPDATE, освобождение после того, как `pg_blocking_pids` показал оба ожидания. Итог: `duplicate=false/true`, одна квитанция, head 42, outbox 1, A42 один раз. Мутант `live_write_mutant.settle_commit` (состояние команды читается до блокировки) → поздний воркер получает `base_moved` вместо квитанции.
- `generation_restored`: restore (BOUNDARY: одна транзакция v_documents → retire head 3 → head 4 из checkpoint) после prepare. Писатель поколения 3 → `stale_owner`; владелец поколения 4 с намерением поколения 3 → `generation_changed`; записей в поколении 4 — 0. Мутант — собственный `settle_commit` без проверки поколения (выведен через `pg_get_functiondef`) → намерение записано в поколение 4.
- `late_delivery_after_restore`: A42 зафиксирована (3/42), restore, комната поколения 4 (fence 8, applied 40) получает старое событие outbox → `foreign_generation`, 0 записей; status → маркер {3,42} и та же квитанция. Чувствительность: правило «только по позиции» приняло бы to_seq 42 > 40.

Итого пройдено 6 из 11. Не пройдены: `precondition_changed`, `edit_revoked`, `read_revoked` (права не моделируются), `projection_behind`, `offline_target_edit_arrives_later` — перечислены в карточке 3.5-B.

Исходный план шага:
Расширить plan/checks/collaboration/live-write.test.mjs вариантами concurrent_duplicate (два room-process с одним намерением, барьер — блокировка строки команды в pg_locks) и generation_restored/late_delivery_after_restore (повысить collab_epoch и закрыть голову под блокировками, затем доставить старое событие → foreign_generation, 0 записей).
