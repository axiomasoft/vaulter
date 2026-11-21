# Изолированный Tiptap Pro lab

Стенд исследования; исходные пакеты Vaulter не изменяет. Секреты читает только Node-сервер из корневого `.env`.

```bash
cd plan/tiptap-pro/lab
node scripts/npm.mjs ci
npm run check:config
npm run dev
# Другой терминал
npm test  # по умолчанию системный Chrome; переопределить PLAYWRIGHT_CHANNEL
npm run build
```

Открыть <http://127.0.0.1:5193>. Выбрать **Local** для проверки OSS Hocuspocus, либо **Cloud** после настройки Pro registry и параметров окружения. Порты: Vite 5193, сервер JWT 5194, локальный Hocuspocus 5195. Все серверы слушают `127.0.0.1`. Локальная память не переживает рестарт Node: проверка fresh load подтверждает восстановление пока процесс жив, а не durable persistence.

## Параметры

В корневом `.env`: `TIPTAP_ENVIRONMENT_ID`, `TIPTAP_APP_ID` (Document server ID), `TIPTAP_PRIVATE_KEY`, `TIPTAP_PRO_TOKEN`; `TIPTAP_WS_URL` — необязательный публичный адрес для внешних инструментов. Frontend не получает приватный ключ и npm-токен. `/api/token` выдаёт ES256 JWT на 10 минут с `Documents:Write` только для точного имени `tiptap-pro-lab-*`. Параметр `readOnly` выдаёт `Documents:Read`. Токены с `Documents:Api:All` этот сервер не выдаёт.

`.npmrc` содержит только placeholder `${TIPTAP_PRO_TOKEN}`. Для установки закрытых пакетов загрузить этот токен в окружение процесса npm, затем установить пакет и перезапустить Vite. Наличие ключа подписи JWT не даёт доступа к npm registry.

```bash
npm run install:pro -- --list   # подтверждённые официальными docs имена пакетов
npm run install:pro -- --check  # наличие npm-токена и локальных пакетов, без сети
npm run install:pro            # отдельная установка каждого пакета, затем shrinkwrap
```

Installer читает токен из корневого `.env` через dotenv; не требует ручного экспорта ключа/токена в shell. Ошибка одного пакета (включая отдельные add-on entitlement) не блокирует остальные. Он не выводит npm stdout/stderr или тела registry-ответов, а сохраняет только код статуса/версию в исключённом `artifacts/pro-install-report.json`. Установленный пакет ещё не доказывает доступность соответствующей Cloud-функции.

Cloud использует `@tiptap-pro/provider`. Local использует `@hocuspocus/provider`. В редакторе можно выбрать Comments, Snapshot, Snapshot Compare и Tracked Changes перед подключением. Само tracking включается отдельно кнопкой или методом. Для чистого Cloud-сценария передать `features: []`. Версии и доступность перечисленных в исследовании пакетов приведены в [каталоге](../module-catalog.md).

Lab регистрирует собственный `blockId` (default `null`) у paragraph/heading и сохраняет его в HTML как `data-block-id`. Это позволяет читать свежим клиентом исследовательские документы с адресами `p42`/`p71`. Это атрибут стенда, не готовая реализация адресации Vaulter.

## Playwright/API

`/?mode=local&room=tiptap-pro-lab-example` выбирает параметры. `&auto=1` сразу вызывает подключение. Cloud: `?mode=cloud&room=tiptap-pro-lab-example&auto=1`.

`window.lab` содержит `editors`, `providers`, `docs`, `events`, `states`. Методы:

- `await connect({ mode: 'local' | 'cloud', room, readOnly?, features?, clientCount?, clientOffset? })` по умолчанию создаёт Alice и Bob. При `clientCount: 1` параметр `clientOffset: 0` выбирает Alice, `clientOffset: 1` выбирает Bob. `features` принимает `comments`, `snapshot`, `snapshotCompare`, `trackedChanges`. Если массив опущен, Cloud использует флаги из UI. `features: []` отключает расширения схемы.
- `snapshot()` возвращает тексты/JSON обоих редакторов, статусы и размер Yjs state.
- `setText(index, text)`, `appendText(index, text)` изменяют клиента 0 или 1.
- `disconnect(index)`, `reconnect(index)`, `destroy()` управляют клиентами.

Pro API для синтетических исследовательских документов:

- `createThread(index, { content, from, to, data?, commentData? })` → `{ ok, threadId, thread }`; `threads(index, options?)` возвращает записи с ranges.
- `reply(index, threadId, content)`, `resolveThread(index, id)`, `unresolveThread(index, id)`, `removeThread(index, id, deleteThread?)`.
- `saveVersion(index, name, { force?, meta? }?)` только запрашивает сохранение; `await fetchVersions(index)` ждёт список до 12 s; `versions(index)` читает cache.
- `previewVersion(index, version)` запрашивает preview; затем читать `previewContent(index)` до появления JSON. `revertVersion(index, version, providerOptions?)`, `versioning(index, enabled)`.
- `track(index, enabled)`, `suggestions(index)`, `accept(index, id)`, `reject(index, id)`, `acceptAll(index)`, `rejectAll(index)`.
- `trackedInsert(index, from, content)`, `trackedDelete(index, from, to)`, `trackedReplace(index, from, to, content)` создают явные proposals независимо от tracking-mode.

Готовность: `states.every(s => s.authenticated && s.synced && s.status === 'connected')`. Очередь пуста: `states.every(s => s.unsynced === 0)`. Это показатели provider; они не доказывают отдельную SQL-фиксацию Cloud.

В журнал попадают только имена событий, статусы и размеры Yjs updates. JWT, сообщения auth, приватные ключи и npm-токен туда не записываются. Raw-captures, traces, `.auth` и результаты браузера исключены из Git.

## Официальные контракты

- [JWT и permissions](https://tiptap.dev/docs/authentication)
- [Cloud provider](https://tiptap.dev/docs/collaboration/provider/integration)
- [Установка и отключение default undo](https://tiptap.dev/docs/collaboration/getting-started/install)
- [Hocuspocus local server](https://tiptap.dev/docs/hocuspocus/server/configuration)
