# Репозиторий `vaulter-collaboration` (Универсальный движок совместных комнат)

- **Git-репозиторий:** `axiomasoft/vaulter-collaboration` (`https://github.com/axiomasoft/vaulter-collaboration`, приватный, клонирован в `/home/vostrikov/projects/packages/vaulter-collaboration`)
- **Канал поставки:** Private / Proprietary (`FlexCrm`, `Edufy`)
- **План разработки:** [dev-plans/vaulter-collaboration.md](../dev-plans/vaulter-collaboration.md)

## Что располагается в репозитории

| Путь | Пакет / Образ | Ответственность |
| --- | --- | --- |
| `packages/collaboration/` | `axiomasoft/vaulter-collaboration` | **Единый протокольно-нейтральный PHP-движок живых комнат для всей экосистемы** (`v_collaboration_heads.protocol`: `yjs/2`, `univer-sheets/1`, будущие `univer-docs/1` / `univer-slides/1`): `UpdateLog` (`payload bytea`), `Checkpoint`, `CompactionLeases` (`lease_through_seq`), `GenerationCloser`, `RoomOwnership` (`owner_fence`), `CommandJournal` (`v_collaboration_commands`), Room Capability JWT, `4409` epoch fencing, внутренние маршруты Hub и `openapi/hub.yaml` (плюс Yjs-специфичный реестр `v_collaboration_client_ids` для протокола `yjs/2`) |
| `packages/vue-collaboration/` | `@vaulter/vue-collaboration` | Закрытый адаптер для `@vaulter/vue`: `createCollaborationAdapter`, WS transport с `protocol=2`, локальная очередь по поколению, автомат статусов (`synchronized`/`pending`/`saved`/`error`), выдача и привязка `clientID` |
| `packages/hub/` | `ghcr.io/axiomasoft/vaulter-collaboration-hub` | Закрытый Node 26 сервис на базе Hocuspocus 4.3.0 + Yjs 13.6.31: хуки аутентификации, загрузка checkpoint+хвост, фоновая компактация, `room-sequencer.js` для строгого профиля (`3.5`), схема parity |
| `e2e/` | — | Стенд PostgreSQL 18 + Laravel + Hub + Playwright: два пишущих браузера, читатель, рестарт Hub, отзыв прав, исправленный `restore-epoch.e2e.js`, прогон 11 аварийных сценариев A42 и замеры строгого профиля |
| `release/packages.json` | — | Манифест сборки приватных PHP ZIP, npm tgz и Docker-образа Hub |

## Что запрещено в `vaulter-collaboration`

- Логика электронных книг (живёт в `vaulter-sheets`).
- Логика AI SDK, LLM-провайдеров и предложений P/Q (живёт в `vaulter-ai`; связь с AI — через `vaulter-ai/packages/collaboration`).
- Range Comments, Tracked Changes UI и DOCX (живут в `vaulter-editor-pro`).
- Штатный Redis sync в строгом профиле `3.5` (обходит общий барьер порядка).

## Зависимости

- **Входящие:** артефакты `vaulter` (`axiomasoft/vaulter-core`, `drive`, `documents` >= с `1.0` и `3.1`, `@vaulter/vue`).
- **Исходящие артефакты:** `axiomasoft/vaulter-collaboration`, `@vaulter/vue-collaboration`, образ `ghcr.io/axiomasoft/vaulter-collaboration-hub` (используются `vaulter-ai` в `3.5` и `vaulter-editor-pro` в `8.3` / `10.2`).
