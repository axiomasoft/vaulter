# Закрытие находок двойного ревью P4 (R1–R13), 2026-10-09

Исполнение `fix-prompt.md`. Изменения — в рабочем дереве, без коммитов и push. `plans/**/views/**` и состояние items не менялись.

## Статусы

| ID | Sev | Статус | Что сделано | Красный → зелёный (D51) |
|---|---|---|---|---|
| R1 | Blocker | fixed+test | `TipTapSchema::ATTRIBUTES`: `link` +`title`, `image` +`width`,`height`, `tableCell`/`tableHeader` +`align` (атрибуты, которые сериализуют расширения хаба). Фикстура `tiptap-schema.json` теперь пинит и **атрибуты**; hub `schema-parity` сравнивает атрибуты расширений с фикстурой; тест «проекция хаба для link/image/table проходит store+seed» (PHP) и тест seed с link-маркой (хаб). Вариант «не отдавать null из проекции» отвергнут: image/table-атрибуты и host-расширения всё равно несут объявленные атрибуты, правило docblock — allow-list = `addAttributes()` расширения. | PHP: без `title` → `422` (проверено). Хаб: без `attributes` в фикстуре parity-тесты красные. |
| R2 | Blocker | fixed+test | `onLoadDocument`: `shared=true` и ro-коннект к документу без `yjs_state` → `Error('Collaboration document is not initialised yet…')`, Y.Doc не кэшируется. Тест `load-seed`: ro-load отказ, пустой doc, 0 store; затем rw-load сидирует реальным текстом. | Без правки тест красный. |
| R3 | Major | fixed+test | `AzgardPermissionDriver::guardFor`: актор → host-bound guard (не дефолтный request-bound `AzgardGuardAdapter`/`NullAzgardGuard`) → `AzgardGuardAdapter` над `AzgardBackedUser`-актором с `panel_id` → иначе pass-through. Новый `tests/ActorGuardTest.php` (7 тестов): actor ≠ пользователь запроса (нет эскалации), нет пользователя запроса, не-backed актор, panel_id, request-user, host-bound guard. README моста, `permission-drivers` (en/ru). | 5 из 6 тестов красные без правки. |
| R4 | Major | fixed+test | `onStoreDocument`: 401→`4001`, 403→`4403 forbidden`, 413/422→`4422 store_refused` — комната закрывается, хук **бросает** (состояние не считается сохранённым), комната не fenced; `webhook.storeDocument`: 408/425/429 ретраятся как 5xx. Клиент: `FORBIDDEN_CODE`, `STORE_REFUSED_CODE`, `NodeCollaborationEnd` +`forbidden`/`store_refused`, `store_refused` не переоткрывает и ставит `error`. Тесты хаба на каждый статус + vue. Доки: collaboration (en/ru) — таблица кодов. | Красные без правки. |
| R5 | Major | fixed+test | `state_version` в seed: хаб шлёт `stateVersion` из `collab/load`, контроллер валидирует (`422` для мусора), Action сверяет под D-lock → `CollaborationSeedConflictException` (+`expected/actual_state_version`). Хаб при конфликте грузит заново и сидирует из актуального JSON (до 3 попыток). `HubProtocolTest`: REST-update между load и seed → 409, текст не потерян, повторный load+seed OK. `concurrency.md` (en/ru), UPGRADE. | Красный без проверки в Action (`200` вместо `409`). |
| R6 | Major | fixed+test | Fail-closed (вариант «отклонять»): `buildSeed` + `lostAttributes()` (множество `type|attr|value` входа ⊆ проекции) → `seed refused` с именами `node.attribute` (не значений). Тест с `textAlign`; тест, что дефолты/порядок марок не дают ложного отказа. Доки: `hocuspocus-server/README.md`, `docs/guide/hocuspocus-server.md` (en/ru). Вариант «конфигурировать схему хаба из extra_*» не делался. | Красный без правки. |
| R7 | Major | fixed+test | `onAuthenticate`: `exp` проверяется до вызова backend → ошибка с `reason: 'Collaboration token expired'`. Тест хаба; vue: `sessionEnd(undefined, 'Collaboration token expired')`. e2e реконнекта провайдера не прогонялся (см. ниже). | Красный без правки. |
| R8 | Major | fixed+test | `VaulterDocumentNode`: автосохранение только при `409 collaboration_unavailable`; иначе read-only на загруженном контенте, событие `collaboration-failed`, `sessionFailed` экспонируется; `VaulterApiError.code`. Тесты: network/429/500/503/другой 409 → read-only, `update` не вызывается. | 5 из 5 красные без правки. |
| R9 | Minor | fixed+test; **часть → владельцу** | `DocumentsConfig::assertDistinctHubSecrets()` + вызов в `packageBooted` (любое окружение, при `enabled`); unit и boot-тест. `CollaborationConfigException`, README/доки. **`hocuspocus-server/.env.example` не изменён**: запись заблокирована правилом доступа (Read-deny) — см. «Владельцу». | Boot-тест красный без вызова в провайдере. |
| R10 | Minor | fixed | `DocumentExporterRegistry` добавлен в `PublicApiSnapshotTest`; снапшот регенерирован `UPDATE_SNAPSHOT=1` (AMEND), diff — только аддитивный (+registry, +`assertDistinctHubSecrets`, +`DocumentExport::$lossy`). `ExportDocumentInput` не публичный (Data для Action) — не включён. | Arch-сьют зелёный. |
| R11 | Minor | fixed+test | Метка `format` — известный реестру формат, иначе `unknown`. Тест со spy-`MetricsRecorder`. Согласование с `load/app/Observability/RedisMetricsRegistry.php:92` (`['html','markdown']`) — **владельцу load/** (см. ниже). | Красный без правки. |
| R12 | Minor | fixed+test | `DocumentExport::$lossy` (+ `X-Vaulter-Export-Lossy: 1`), смысл `sourceEpoch` документирован (epoch документа на момент экспорта, в т.ч. для ревизии). Снапшот обновлён; доки `export` (en/ru), `rest-api` (en/ru). | Красные без правки. |
| R13 | Nit | fixed | `docs/ru/guide/collaboration.md` приведён к структуре en (14/14 заголовков, 12/12 блоков кода, «Extending», `VaulterDocumentNode`); `noVerifier()` удалён (нет вызовов); формулировка arch-правила экспортёров («только встроенные») в `export` (en/ru) и UPGRADE. | — |

## Изменённые файлы

Код: `packages/documents/src/{Actions/Collaboration/PersistCollaborationStateAction,Actions/Export/ExportDocumentAction,Collaboration/CollaborationHub,Configuration/DocumentsConfig,Content/TipTap/TipTapSchema,Data/Collaboration/PersistCollaborationStateInput,Exceptions/CollaborationConfigException,Exceptions/CollaborationSeedConflictException,Http/Controllers/Collaboration/NodeCollaborationController,Http/Controllers/Documents/DocumentNodeController,ValueObjects/DocumentExport,VaulterDocumentsServiceProvider}.php`; `packages/azgard/src/AzgardPermissionDriver.php`; `hocuspocus-server/src/{index,schema,webhook}.js`; `packages/vue/src/{client.ts,nodeCollaboration.ts,composables/useNodeCollaboration.ts,components/VaulterDocumentNode.vue}`.

Тесты: `packages/documents/tests/{Arch/PublicApiSnapshotTest.php,Arch/__snapshots__/public-api.txt,Collaboration/{HubProtocolTest,HubTestHelpers,TipTapSchemaFixtureTest}.php,Fixtures/tiptap-schema.json,Configuration/DocumentsConfigTest.php,Export/{DocumentExportHttpTest,DocumentExportSourceTest}.php,Providers/InvalidDefaultDocumentTypeTest.php}`; `packages/azgard/tests/{ActorGuardTest.php (новый),Pest.php}`; `hocuspocus-server/test/{epoch-fence,load-seed,readonly,schema-parity,webhook}.test.js`; `packages/vue/tests/{engine-registry,node-collaboration}.test.ts`.

Доки: `CHANGELOG.md`, `UPGRADE.md`, `docs/guide/{collaboration,export,hocuspocus-server,permission-drivers}.md` и `docs/ru/guide/*` (те же), `docs/reference/{concurrency,rest-api}.md` и `docs/ru/reference/*`, `hocuspocus-server/README.md`, `packages/azgard/README.md`.

## Проверки (команда → exit code)

| Команда (cwd) | Результат |
|---|---|
| `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci` (`packages/documents`) | exit 0: 640 passed, 11 skipped (все — PG-only `ForkRace`/FK-cascade, как и до правок) |
| то же (`packages/azgard`) | exit 0: 56 passed |
| `../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress` (documents, azgard) | exit 0, `[OK] No errors` |
| `../../scripts/php84 vendor/bin/pint --test <изменённые PHP>` (documents, azgard) | `passed` оба |
| `npm test` (`hocuspocus-server`, = `node --test test/*.test.js`) | exit 0: 91 pass, 0 fail (было 74) |
| `npm run check` (`hocuspocus-server`) | exit 0 |
| `npx vitest run` (`packages/vue`) | exit 0: 11 файлов, 133 passed |
| `npx vue-tsc --noEmit` (`packages/vue`) | exit 0, без вывода |

`node --test` без аргументов подбирает `test/e2e/restore-epoch.e2e.js` (нужен запущенный стек) и падает — это существующее поведение; штатный `npm test` ограничен `test/*.test.js`.

## Владельцу

1. **`hocuspocus-server/.env.example`**: запись заблокирована правилом доступа. Нужна правка одним действием: удалить `VAULTER_COLLAB_SECRET` и (неиспользуемый хабом) `VAULTER_COLLAB_APP_ID`, добавить `VAULTER_COLLAB_WEBHOOK_SECRET=your-webhook-secret` (хаб читает именно его, `src/config.js`).
2. **`load/`** (вне зоны правок): `RedisMetricsRegistry.php:92` допускает `format ∈ {html, markdown}`; метка теперь может быть `unknown` и форматом хост-экспортёра. Если реестр отвергает неизвестное значение — расширить список (проверить на load-стенде). Также `load/docker-compose.load.yml:287` отдаёт хабу `VAULTER_COLLAB_SECRET` как `VAULTER_COLLAB_WEBHOOK_SECRET`; приложение стенда задаёт `jwt_secret` из того же значения — если и `webhook_secret` приложения будет задан тем же значением, новая проверка R9 не даст загрузиться (нужны разные значения).
3. **AMEND**: регенерация `public-api.txt` по процедуре требует D# и синхронизации `plan.md §7` — это решение плана (владелец), в коде/снапшоте изменения аддитивны.
4. `docs/reference/rest-api.md` (en/ru), раздел `POST collab/store`, описывает устаревшее тело (`yjs_state`, `node_id`); реальный контракт — `{room, state, content, seed, state_version}` (описан в `docs/guide/collaboration.md`). Вне находок, не переписывал.

## Не проверялось — тяжёлые проверки (запрещены в этом проходе)

- Допущение R4: «бросок из `onStoreDocument` оставляет документ в памяти» опирается на уже существующую логику хаба (повтор 5xx) и исходники Hocuspocus; доступ к его `dist` закрыт. Проверить: `cd hocuspocus-server && npm run test:e2e` (через `bash scripts/collab-e2e.sh` из корня) со сценарием: store → 403 → закрытие 4403 → переподключение того же клиента → состояние сохраняется.
- R7 (поведение провайдера 4.3 при авто-реконнекте тем же токеном) и R1/R2 end-to-end: `bash scripts/collab-e2e.sh` с link-маркой и shared=true (Redis).
- PostgreSQL: `cd packages/documents && env APP_ENV=testing DB_CONNECTION=pgsql … ../../scripts/php84 vendor/bin/pest --ci tests/Concurrency` (ForkRace, `DegradedContentRaceTest`, V4) — в этом проходе пропущены (11 skipped).
- Мутационное тестирование и CI dispatch — не запускались.
