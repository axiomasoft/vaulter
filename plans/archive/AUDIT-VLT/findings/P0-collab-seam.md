# P0 — RAG-выжимка: швы realtime-collab (Hocuspocus/Yjs JWT + webhooks)

**Дата верификации:** 2026-07-10 · **Автор:** issue-planner/opus (design-time RAG)
**Инструмент:** perplexity-web `search_advanced` (web)
**Запрос:** «Securing webhook and JWT seams for realtime collaboration servers
(Hocuspocus, Yjs): HS256 pitfalls, alg=none, replay protection, timing-safe webhook
signature comparison, room authorization binding, token expiry and readOnly enforcement»

**Вердикт:** ✅ подтверждено. Требования C1–C5 — чеклист item P1.6 (и частично P1.7).

---

## C1. HS256 и управление секретом

- C1.1 Один секрет подписывает и проверяет: утечка = возможность выпустить любой токен с любыми claims и сроком.
- C1.2 **Разделение ключей по назначению**: секрет collab-JWT ≠ секрет входящих webhook ≠ секрет пользовательской аутентификации. У каждого своя ротация.
- C1.3 Тенант-специфичные секреты не выводятся из tenant-id; только длинные случайные значения из KMS/env.
- C1.4 Ротация с окном двух ключей (принимаем старый и новый), иначе живые сессии рвутся.
- C1.5 Секрет никогда не покидает сервер; клиент получает только подписанный токен.

## C2. Алгоритм: pinning и key confusion

- C2.1 Всегда явный allow-list алгоритмов при верификации (`['HS256']`); **никогда** не доверять `alg` из заголовка токена.
- C2.2 `none` (в любом регистре) обязан отвергаться; часть библиотек до сих пор трактует его как валидный «unsecured JWS».
- C2.3 Если в системе есть и RS*, и HS* — разные code-path и разные конфиги верификации (иначе RSA-публичный ключ можно скормить как HMAC-секрет — key confusion).
- C2.4 Регрессионный тест: токены с `alg: none`, пустой подписью, `None`/`NONE` обязаны отвергаться.

## C3. Webhook: подпись, replay, сравнение

- C3.1 HMAC считается по **сырому телу** (raw bytes), не по разобранному/нормализованному JSON.
- C3.2 Сравнение подписи — **constant-time** (`hash_equals` в PHP); нормализовать кодировку (hex vs base64); при разной длине — короткое замыкание до timing-safe вызова.
- C3.3 В подписываемый контент входит **timestamp** (и опционально nonce); запросы вне окна допуска (±5 мин) отвергаются.
- C3.4 Хранить виденные nonce/message-id на время окна и отвергать дубли (защита от replay).
- C3.5 Секреты webhook — инфраструктурные ключи; ротация с grace-периодом; не логировать, не показывать в UI.
- C3.6 Провал верификации → **никакого** касания состояния Yjs/документа.

## C4. Привязка авторизации к комнате

- C4.1 JWT несёт `room_id`/`docId`, `user_id`, `tenant_id`, `readOnly`.
- C4.2 На handshake проверять, что **запрошенная комната совпадает с `room_id` из токена**; не полагаться на query-параметры клиента.
- C4.3 Hocuspocus **не выпускает** токены — только верифицирует выпущенные основным API, который знает ACL.
- C4.4 Обязательны `wss://` и валидация `Origin` при WebSocket-upgrade (cross-site WebSocket hijacking).
- C4.5 Не класть токен в query-string (утечка в логи); либо очень короткий TTL, либо аутентификация первым сообщением в канале.

## C5. Expiry и readOnly

- C5.1 `exp` проверяется на handshake **и периодически** в течение долгоживущего соединения; по истечении — закрытие сокета.
- C5.2 Для чувствительных комнат — короткий `exp` + refresh через реконнект вместо длинных токенов.
- C5.3 В Yjs read-only **не встроен**: реализуется фильтрацией входящих update/SyncStep2-сообщений от соединений с `readOnly: true`.
- C5.4 `readOnly` берётся только из проверенных claims и кладётся в контекст соединения; клиентскому флагу доверять нельзя.
- C5.5 Изменение ACL в середине сессии → refresh+reconnect либо серверный «upgrade» соединения после проверки нового токена.

---

## Проекция на vaulter (для P1.6)

Известная реализация (память проекта, M4b): `HocuspocusNodeCollaborationDriver` чеканит
HS256 JWT `{room, sub, readOnly, iat, exp}` через `firebase/php-jwt`; `room =
channelPrefix.nodeId`; входящие webhook `collab/authenticate` и `collab/store`
проходят через `Http/Middleware/ResolveCollaborationCall` с проверкой
`webhook_secret` ИЛИ JWT. Точки, которые чеклист обязан проверить:

- альтернатива «secret ИЛИ JWT» — не создаёт ли она обход (C2.1, C3.6);
- используется ли `hash_equals` (C3.2), есть ли timestamp/nonce-окно (C3.3–C3.4);
- один ли секрет на JWT и webhook (C1.2);
- проверяется ли соответствие `room` ↔ запрошенный node на стороне Node-сервера (C4.2);
- как enforce'ится `readOnly` на стороне hocuspocus-server (C5.3).

---

## Источники

1. https://www.vaadata.com/en/blog/jwt-json-web-token-vulnerabilities-common-attacks-and-security-best-practices/
2. https://justappsec.com/guides/webhook-replay-attack-protection
3. https://hooque.io/guides/webhook-security
4. https://daksh.be/blog/2026/02/10/timing-attacks-and-timing-safe-compare/
5. https://github.com/ueberdosis/hocuspocus/discussions/134
6. https://discuss.yjs.dev/t/client-server-setup-with-auth-and-permission/841/5
