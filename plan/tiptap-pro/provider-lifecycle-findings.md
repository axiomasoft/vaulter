# Cloud provider lifecycle: установленная граница нестабильности

2026-11-21. Проверены `@tiptap-pro/provider@3.12.9`, `@hocuspocus/provider@4.9.0`, Playwright `1.65.1`. Исследование не изменяет vendor, `.env`, `lab/src/main.mjs` или существующие tests.

**Подтверждено зависание до отправки WebSocket handshake одним из клиентов Chrome. Повторное использование разрушенного socket в этом прогоне исключено. Конкретная причина задержки в сетевом пути пока не установлена.**

Evidence: [`results/provider-lifecycle.json`](results/provider-lifecycle.json). Runner: [`scripts/provider-lifecycle.mjs`](scripts/provider-lifecycle.mjs), запускается при работающем lab на `127.0.0.1:5193`. Он создаёт не более трёх Cloud providers: два исходных, затем одну замену после уничтожения второго. Каждое ожидание ограничено 10 секундами; содержимое документа не меняется.

## Что показал один ограниченный прогон

| Клиент | Native socket | Результат |
| --- | --- | --- |
| A | ID 3, свой websocket wrapper ID 2 | `Created`; через примерно 6.44 с — `WillSendHandshakeRequest`, затем HTTP 101; `isSynced=true`, native `OPEN=1` |
| B | ID 6, wrapper ID 5 | `Created`, но за 10 с нет `WillSendHandshakeRequest` и response; native `CONNECTING=0` |
| Замена B после `destroy()` | Новый ID 9, новый wrapper ID 8 | Снова `Created`, за следующие 10 с нет handshake request/response, native `CONNECTING=0` |

У обоих зависших объектов одновременно: `isAttached=true`, `manageSocket=true`, `autoConnect=true`, `shouldConnect=true`, `providerMap.size=1`, routing key указывает на правильный provider, `connectionAttempt` существует. Значит, ни отсутствие attach, ни отключённый autoconnect не объясняют этот прогон.

После `B.destroy()` зафиксированы `isAttached=false`, `shouldConnect=false`, `webSocket=null`, map size 0. У нового B wrapper и native object отличаются от старых. Первый A остался подключённым и синхронизированным во время ожидания нового B. Это не общий отказ всех соединений Cloud.

CDP ошибки `WebSocket is closed before the connection is established` у B и его замены возникли **при нашем cleanup/destroy**. Их нельзя объявлять исходной причиной: до cleanup соответствующего frame-error в capture нет.

Исходная страница один раз перезагрузилась при Vite startup до создания Cloud providers. Во время двух измеренных Cloud-ожиданий новая навигация или HMR reload не зафиксированы. Startup reload следует учитывать при других captures, но текущие два pending sockets он не объясняет.

В исходном evidence поле `isAuthenticated` скрыто универсальным sanitizer по имени ключа. Остальные lifecycle flags и sync/handshake события сохранены. Runner для будущих запусков именует эту безопасную boolean-сводку `permissionAccepted`; сохранённое evidence задним числом не изменялось и повторный Cloud прогон ради имени поля не выполнялся.

## Что следует из установленного кода

В `@tiptap-pro/provider/dist/index.js` конструктор без явного `websocketProvider` создаёт **новый** `TiptapCollabProviderWebsocket`, после Hocuspocus constructor выставляет own socket management и выполняет `attach()`. Static/global map общего Cloud socket здесь нет.

В Hocuspocus `src/HocuspocusProviderWebsocket.ts` `providerMap = new Map()` находится в instance configuration. Static Set относится только к deduplication awareness/query messages, а не к pooling. `destroy()` прекращает попытку подключения, отключает socket, снимает listeners и чистит native socket. Это согласуется с нашим snapshot после destroy.

Важное ограничение default timeout:

- `configuration.timeout=0`: retry-wrapper не задаёт deadline одной connection attempt.
- `checkConnection()` пропускает всё, что ещё не `connected`, и состояние без первого входящего сообщения.
- Поэтому `messageReconnectTimeout=30000` **не является 30-секундным лимитом native CONNECTING/handshake**.
- `connect()` resolve связан с первым входящим сообщением; native open, authentication и document sync — разные этапы.

JWT callback вызывается из provider open-handling. В зафиксированном pending состоянии native socket ещё не дошёл до отправки handshake, поэтому ошибка document JWT/ACL не подтверждается и не нужна для объяснения именно этого ожидания. У иных неуспешных подключений с HTTP 101 и auth reply причина может быть другой.

## Почему обычный Playwright capture мог показывать «нет CloudWS»

В установленном `playwright-core/lib/coreBundle.js` FrameManager сначала сохраняет CDP `webSocketCreated` внутренне. Пользовательский `page.on('websocket')` обычно возникает при `webSocketWillSendHandshakeRequest`. Если до handshake дело не дошло, notification может появиться только позднее из error/close path.

Наш capture это воспроизводит: B создан в CDP сразу, но в списке Playwright sockets появляется только в момент его закрытия нами. Таким образом, отсутствие `page.on('websocket')` в 30-секундном окне нельзя трактовать как отсутствие вызова `new WebSocket()` или как доказательство lifecycle bug provider.

Для следующих исследований достаточно сохранять CDP metadata `Created → WillSendHandshake → Response(status) → native OPEN → authenticated → synced`. Headers, frame payloads и JWT для такого диагноза не требуются и данным runner не записываются.

## Falsifiable диагноз и оставшаяся неопределённость

| Гипотеза | Статус / способ отличить |
| --- | --- |
| Новый provider повторно использует уничтоженный pooled socket | Для нашего recreate-run опровергнута: новый wrapper/native ID; pool в исследованном пути отсутствует |
| Provider не attached или autoconnect выключен | Для pending snapshots опровергнута соответствующими flags и routing map |
| Идёт JWT/ACL rejection | Для pending-before-handshake не подтверждается; искать HTTP 101 и последующий auth response для такой гипотезы |
| Перезагрузка Vite уничтожила runtime посреди ожидания | Во время измеренного pending interval не наблюдалась |
| Chrome ожидает DNS/TCP/TLS/proxy/доступный connection slot до отправки handshake | Совместимо с evidence, но конкретный слой **не установлен** |
| Hosted сервер потерял document state | Этим прогоном не исследовалось; pending-before-handshake ничего не говорит о persistence |

Для независимого повторения диагноз считается тем же только при сочетании CDP `Created`, отсутствия `WillSendHandshakeRequest`, native state 0 и корректного attachment. Если `WillSendHandshakeRequest` появился, границу нужно передвинуть и исследовать response/open/auth/sync отдельно, а не переиспользовать этот вывод.

## Ограниченная mitigation для стенда

Можно ограничить длительность ожидания через **явный** `TiptapCollabProviderWebsocket({ appId, timeout: 5000, maxAttempts: 3 })`. У Pro 3.12.9 настройки retry, переданные только document provider с `appId`, не настраивают автоматически созданный wrapper: в auto-generated constructor path передаются appId/baseUrl. Поэтому timeout должен задаваться самому websocket object.

У external websocket provider другой lifecycle: document provider требует `attach()`, а владелец должен при cleanup уничтожить и document provider, и собственный websocket. Эту схему сначала нужно проверить тестом teardown. Она ограничивает hanging attempt и улучшает диагностику, но **не является доказанным исправлением неизвестной DNS/TLS/network причины** и не гарантирует удачное подключение за 5 секунд. В нашем успешном A первый handshake начался позднее 5 секунд, поэтому такой deadline может прервать и медленное, но потенциально успешное соединение.

Cloud-перезапуски ради статистики здесь не делались. Дальнейшие сетевые/инфраструктурные расследования находятся за границами текущего reverse-engineering; поведенческие результаты, полученные после успешного подключения, сохраняют свою силу с явно указанной нестабильностью establishment.

## Дополнительный Node-only transport probe

После браузерного наблюдения выполнен один опыт без Chromium: [`scripts/node-provider-probe.mjs`](scripts/node-provider-probe.mjs), [`results/node-provider-probe.json`](results/node-provider-probe.json). Использованы установленный Pro provider, `ws` как `WebSocketPolyfill`, две новые Y.Doc и explicit websocket wrapper с `timeout=5000`, `maxAttempts=2`. Scoped credentials получены локальным `/api/token`, их значения не выводились и не сохранялись. Корневой `.env` runner не читает.

Оба token endpoint запроса прошли успешно, но оба native Node sockets остались `CONNECTING=0` до bounded attempt failure. Ни один клиент не дошёл до auth/sync; запланированные двунаправленные document assertions **не запускались**. Статус evidence — `failed-or-blocked`, 0 выполненных checks, а не PASS.

Первый wrapper сообщил `ATTEMPT_TIMEOUT` примерно через 5.04 с; второй был создан чуть позднее и уничтожен общим cleanup до собственного timeout. В установленном `@lifeomic/attempt` timeout-ветка отклоняет внешний Promise непосредственно, тогда как обычная ошибка attempt проходит через retry handler. Поэтому `maxAttempts=2` нельзя интерпретировать как доказательство двух последовательных попыток после timeout: этот run доказывает bounded failure, а не испытанную стратегию повторов.

Этот результат не позволяет назвать проблему исключительно Chromium Network Service: другой transport также не подключился в отведённом окне. Но 5 секунд — более короткий deadline, чем наблюдавшиеся 6.44 секунды до успешного Chrome handshake A, поэтому сравнение не доказывает постоянную недоступность сервиса или общую причину задержки. Raw DNS/TCP/TLS диагностика не выполнялась. Увеличение таймаута и повторные подключения ради поиска успешного результата здесь не производились.
