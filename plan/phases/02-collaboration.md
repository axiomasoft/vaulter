# 2. Комплект совместного редактирования

[Паспорт `vaulter-collaboration`](../repos/vaulter-collaboration.md) · [План разработки](../dev-plans/vaulter-collaboration.md) · [PHP, Hub, Vue и их тесты](../spec/implementation/vaulter.md) · [Стенд и процессы](../spec/implementation/processes.md) · [Контракт](../spec/collaboration.md).

### 2.0 Вынести коллаборацию в закрытый репозиторий

**После** — 0.1.

1. Создать закрытый репозиторий `vaulter-collaboration` и перенести в него `packages/collaboration` (`axiomasoft/vaulter-collaboration`), `packages/vue-collaboration` (`@vaulter/vue-collaboration`), `packages/collaboration/hub` → `packages/hub` (`ghcr.io/axiomasoft/vaulter-collaboration-hub`) и коллаборационный стенд `e2e/` (`CollabEditor.vue`, `collab.spec.ts`, `docker-compose.yml`).
2. Проверить установку пары архивов «публичный `vaulter` + закрытый `vaulter-collaboration`» в изолированном потребителе. После подтверждения удалить приватные пакеты коллаборации, legacy-каталоги `hocuspocus-server/` и `packages/azgard/` (если не в `release/packages.json`) и приватные записи из `release/packages.json` в `vaulter`.
3. Обновить `composer.json`, `package.json` и `scripts/release/archive-check.php` в `vaulter`, чтобы базовый репозиторий не содержал приватного кода коллаборации и собирался полностью автономно для FlexCms.

- **2.0-A** Базовый `vaulter` собирается и проходит тесты без закрытого кода коллаборации; в `release/packages.json` `vaulter` остаются только публичные пакеты.
- **2.0-B** Закрытый `vaulter-collaboration` собирает все три артефакта (`axiomasoft/vaulter-collaboration`, `@vaulter/vue-collaboration`, образ Hub), проходит собственные unit-тесты (Vue Collaboration + Hub) и устанавливается поверх публичных архивов `vaulter`.

### 2.1 Исправить версию при открытии WebSocket

**После** — 2.0.

1. На исходной ревизии повторить характеристику `VAULTER_HANDSHAKE_EXPECT=missing node node_modules/vitest/vitest.mjs run plan/checks/collaboration/protocol.test.ts`.
2. В штатном транспорте закрытого Vue сформировать URL с ровно одним `protocol=2` и сохранить path и существующие параметры. Использовать сессию PHP и действующую передачу токена. Случаи теста — пустой query, tenant в URL, повторный или неверный protocol.
3. Выполнить `node node_modules/vitest/vitest.mjs run plan/checks/collaboration/protocol.test.ts` на изменённой сборке. Два редактора и читатель подключаются к реальному Hub.

- **2.1-A** В реальном HTTP upgrade путь `/editor`, `tenant=sample` и `protocol=2` встречаются по одному разу. Несовместимый клиент получает предусмотренный режим.
- **2.1-B** Оба редактора сохранили по своей правке и увидели чужую; читатель не может оставить изменение. Открытие третьим клиентом восстанавливает обе.

### 2.2 Проверить долговечность и поколения

**После** — 1.0, 2.1.

1. Поднять пакетный стек PHP + PostgreSQL + Hub на [стенде коллаборации](../spec/implementation/hosts.md). Зафиксировать head, поколение, seq, sha и содержимое двух абзацев. Устранить известный дефект исходного E2E-адаптера (`restore-epoch.e2e.js`): привести формат сессии (`mode: write/read`, `generation`, `options.room`), добавить обязательный `If-Match` на restore и согласовать morph type субъекта в fixture с Hub resolver (устранить HTTP 404 `node_not_found` на `hub/authenticate`).
2. В отдельных запусках потерять ACK, завершить Hub после подтверждения, отозвать доступ перед очередным append, выполнить restore при открытой старой вкладке (с проверкой отсечения stale update и нового пустого `Y.Doc`). После каждого случая открыть независимый клиент.
3. Сопоставить двоичный журнал, материализованный текст, квитанции и состояние индикатора сохранения. Между чтением компактором хвоста до 41 и публикацией checkpoint принять правку 42. Проверить отдельно точную и завышенную границу, затем открыть новый браузер. [Сценарий аренды](../checks/collaboration/lease-schema.sql)

- **2.2-A** Рестарт сохраняет оба подтверждённых изменения. Повтор update с тем же хешем возвращает прежнюю позицию, с другим хешем отклоняется. Потеря ACK не дублирует текст.
- **2.2-B** Отзыв доступа закрывает append. После restore старое поколение теряет право записать append или checkpoint. Аренда до 41 позволяет сохранить checkpoint 41 и оставляет правку 42 доступной после рестарта. Попытка той же аренды объявить 42 до публикации 41 завершается отказом и сохраняет исходную позицию и весь хвост.

2.2 проверяет долговечность подтверждённых правок. Порядок «сначала SQL commit, затем применение и рассылка» проверяется для всех писателей строгой комнаты в [3.5](03-text-sdk.md#35-атомарная-команда-в-живой-комнате).
