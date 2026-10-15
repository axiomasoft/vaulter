---
title: Вебхуки
---

# Вебхуки

## Как событие попадает в outbox

Каждое изменение Vaulter записывает своё событие через `EventRecorder` последним шагом
собственной транзакции базы данных (см. [События](/ru/reference/events)). Recorder пишет одну
строку `v_outbox` на событие **в этой транзакции**, какие бы модули ни были активны (см.
[Outbox](/ru/concepts/events-outbox)); при включённом opt-in модуле вебхуков
(`vaulter.modules.webhooks` = `true`, по умолчанию выключен) он также пишет ожидающую строку
доставки consumer'а outbox `webhooks.fanout` для каждого внешнего (`external`) типа события:

- изменение и его строки outbox коммитятся вместе, поэтому закоммиченное изменение никогда не
  остаётся без вебхука, даже если процесс умер сразу после коммита;
- если строку outbox записать нельзя, изменение откатывается и операция завершается ошибкой —
  Vaulter не коммитит изменение, о котором не может сообщить;
- ваши слушатели Laravel работают только после коммита; упавший слушатель логируется и никогда не
  отменяет изменение.

Затем relay ядра, `vaulter:relay`, передаёт каждое событие `webhooks.fanout`, который
разворачивает его в доставки вебхуков.

## Гарантии доставки

Consumer `webhooks.fanout` превращает каждое внешнее событие в одну строку `v_webhook_deliveries`
на каждый подходящий эндпоинт и ставит в очередь задачу на доставку:

- **Каждый подходящий эндпоинт получает ровно одну строку доставки на событие.** Relay арендует
  доставку события в outbox (`outbox.lease_seconds`, по умолчанию 60); consumer пишет по
  `webhooks.relay.fanout_chunk` эндпоинтов (по умолчанию 100) за транзакцию и сохраняет последний
  как continuation доставки — запись, ограждённая арендой. Доставка в outbox завершается только
  после последнего чанка. Если relay умер, следующий запуск забирает доставку после истечения
  аренды и продолжает после continuation; воркер, потерявший аренду, ничего не пишет, а
  `UNIQUE(event_id, endpoint_id)` это страхует. Можно запускать несколько relay одновременно.
- **Задачи ставятся в очередь после коммита строк.** Задачу, потерянную после этого, заново
  ставит retry-sweep (`vaulter:webhooks:retry`), когда доставка прождала
  `webhooks.retry_claim_seconds`, никем не взятая.
- **HTTP — at-least-once.** Доставка повторяется при ошибке и может прийти дважды, если ваше
  подтверждение потерялось: дедуплицируйте по `id` конверта. Доставки не упорядочены.
- **Выключенный модуль ничего не теряет молча.** Пока `vaulter.modules.webhooks` выключен,
  `webhooks.fanout` приостановлен: его ожидающие доставки ждут, и он удерживает retention outbox
  не дольше `outbox.max_consumer_hold_seconds`. Затем он отсоединяется с явным разрывом (gap),
  о котором `vaulter:doctor` сообщает, пока `vaulter:relay:replay` его не переотправит или не
  подтвердит.
- **Prune удаляет только завершённое.** `vaulter:webhooks:prune` удаляет доставки
  `delivered`/`dead` старше `webhooks.retention_days`; доставка `pending`/`failed` остаётся,
  сколько бы ей ни было. У outbox своё хранение — `vaulter:outbox:prune`. Удалённые строки
  исчезают навсегда — их нельзя переотправить.

## Кто получает событие

Эндпоинт получает событие, если на момент записи его порции:

- он активен и подписан на тип события;
- его арендатор равен арендатору события — эндпоинт без арендатора получает только события без
  него;
- у него нет `drive_id` или событие произошло в этом drive;
- он уже существовал в момент события (его `created_at` не позже события). Эндпоинт,
  зарегистрированный позже, никогда не получает старые события.

Эндпоинт, отключённый во время разворачивания события, пропускает оставшуюся часть. Регистрируйте
и меняйте эндпоинты в их арендаторе: при строгой изоляции эндпоинт получает текущего арендатора, а
`drive_id` должен называть drive этого арендатора (любой другой id даёт 404).

## Конверт

Payload outbox — это тело вебхука. Он собирается один раз на событие и копируется в каждую
доставку:

```json
{
  "id": "01JA2Z4P6V8Y0C1D2E3F4G5H6J",
  "type": "node.renamed",
  "version": 1,
  "source": "vaulter",
  "time": "2026-10-09T12:00:00.123Z",
  "tenant_id": null,
  "drive_id": "42",
  "subject": {"type": "node", "id": "1337"},
  "sequence": 7,
  "actor": {"type": "App\\Models\\User", "id": "5"},
  "correlation_id": "01JA2Z4P6V8Y0C1D2E3F4G5H6K",
  "changes": {"seq": 128},
  "data": {}
}
```

- `id` идентифицирует событие: это `event_id` строки активности, одинаковый при каждом повторе
  каждой доставки. **Дедуплицируйте по `id`** — доставка at-least-once.
- `data` тонкий: id, перечисления, счётчики и флаги. Имена, пути, mime-типы, токены и метаданные
  запроса никогда не отправляются; читайте субъект своими учётными данными, если они нужны. Ключи
  по типам перечислены в [каталоге](/ru/reference/events).
- `sequence` — sequence узла после изменения (`null` для событий, не меняющих узел, и для
  содержимого документа, которое несёт `state_version` и `epoch` в `data`). Доставки не
  упорядочены; сравнивайте `sequence` (или `state_version`), чтобы отбросить устаревшее.
- `version` — версия схемы `data` для этого `type`.
- `changes.seq` — позиция события в [журнале изменений](/ru/concepts/events) drive (`null` для
  события вне журнала или при выключенном `features.changes`). Это подсказка, а не курсор: клиент,
  синхронизирующий drive, считает вебхук сигналом вызвать `changes()->list()` от сохранённого
  курсора и может не вызывать, если его курсор уже дальше `seq`.

## Заголовки и подпись

Каждая доставка — это `POST` конверта ровно с этими заголовками по
[Standard Webhooks](https://www.standardwebhooks.com/):

| Заголовок | Значение |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `Vaulter-Webhooks/1` |
| `webhook-id` | `id` конверта — одинаковый при каждом повторе и переотправке |
| `webhook-timestamp` | Unix-секунды этой попытки |
| `webhook-signature` | `v1,<base64>` — во время ротации секрета несколько подписей через пробел |

Подпись — `base64(HMAC-SHA256(key, "{webhook-id}.{webhook-timestamp}.{body}"))` по точным
отправленным байтам. Секрет — `whsec_` плюс base64 ключа; ключ — декодированная часть. Он
показывается один раз — при регистрации эндпоинта или ротации. Тип события — поле `type` тела
(отдельного заголовка нет).

## Проверка доставки

Доставки Vaulter проверяет любая библиотека Standard Webhooks — см.
[официальные библиотеки](https://github.com/standard-webhooks/standard-webhooks/tree/main/libraries).
В PHP это делает `Vaulter\Webhooks\StandardWebhookVerifier` без лишних зависимостей:

<!-- snippet: host -->

```php
use Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException;
use Vaulter\Webhooks\StandardWebhookVerifier;

// $request->getContent() — СЫРОЕ тело: проверяйте его до декодирования или перекодирования.
try {
    (new StandardWebhookVerifier)->verify(
        headers: $request->headers->all(),
        body: $request->getContent(),
        secrets: [config('services.vaulter.webhook_secret')], // whsec_… (во время ротации добавьте старый)
        toleranceSeconds: 300,
    );
} catch (InvalidWebhookSignatureException) {
    abort(400);
}
```

Проверке нужны все три заголовка, `webhook-timestamp` в пределах допуска (300 с по умолчанию, в
обе стороны — это и отсекает повтор) и подпись `v1`, совпадающая с одним из секретов (сравнение с
постоянным временем). Отвечайте `2xx` в пределах таймаута; всё остальное — включая редирект —
считается неудачной попыткой.

## Ротация секрета

`RotateWebhookSecretAction::execute($endpoint, ?int $overlapSeconds = null)` возвращает
`RotatedWebhookSecret` (`endpointId`, `secret`) — новый секрет, один раз. Старый секрет ещё
`webhooks.secret_rotation_overlap` секунд подписывает доставки (по умолчанию 86400, максимум
604800), поэтому `webhook-signature` несёт две подписи и получатель с любым из секретов проверит
доставку. Разверните новый секрет у получателя в этом окне. Перекрытие `0` сразу убирает старый
секрет — используйте его при утечке.

## Повторы и автоматическое отключение

Неудачная попытка (не 2xx, редирект, таймаут, ошибка соединения) повторяется через
`webhooks.retry_schedule` секунд (по умолчанию `5, 300, 1800, 7200, 18000, 36000, 50400, 72000,
86400`, каждое ±`webhooks.retry_jitter`, по умолчанию 10 %). Это десять попыток примерно за три
дня; ошибка после последней делает доставку `dead`. HTTP-таймаут — `webhooks.timeout` (по
умолчанию 15 с).

Первая неудачная попытка эндпоинта начинает *серию ошибок* (`failing_since`); любая успешная
доставка её завершает. Когда серии исполняется `webhooks.disable_after_days` (по умолчанию 5; `0` —
никогда не отключать), эндпоинт выключается (`is_active = false`, `disabled_reason = 'failing'`), а
его ожидающие и неудачные доставки становятся `dead` с `last_error = 'endpoint_disabled'`. После
коммита Laravel диспатчит `Vaulter\Webhooks\Events\WebhookEndpointDisabled` (`endpointId`,
`reason`, `failingSince`) — слушайте его, чтобы сообщить владельцу эндпоинта. Ручное выключение
(`disabled_reason = 'manual'`) так же убивает открытые доставки; включение очищает серию и причину.

## Повторная доставка

`RedeliverWebhookAction::execute($delivery)` отправляет доставку `delivered` или `dead` снова: та же
строка возвращается в `pending` с `attempts = 0`, поэтому получатель видит **тот же `webhook-id`**.
Её эндпоинт должен быть активен, а доставка, ещё `pending` или `failed`, отклоняется —
`WebhookDeliveryNotRedeliverableException`
([`webhook_delivery_not_redeliverable`](/ru/guides/errors#webhook-delivery-not-redeliverable), 409).
Пакетно: [`vaulter:webhooks:redeliver`](/ru/reference/commands).
Переотправить можно только доставки, ещё не вышедшие за `webhooks.retention_days`.

## Управление эндпоинтами по HTTP

Админ-маршруты [REST API v1](/ru/reference/rest-api#webhook-эндпоинты) подключаются при
включённом `vaulter.modules.webhooks`. Это глобальная поверхность, а не ресурс drive: каждый маршрут —
включая чтение — авторизуется `WebhookEndpointPolicy` по `webhooks.manage_ability` ещё до поиска по
id, поэтому тот, кто не может управлять вебхуками, ничего не узнаёт о существующих эндпоинтах.

| Маршрут | Что делает |
|---|---|
| `GET v1/webhook-endpoints` | эндпоинты, новые первыми, курсорной страницей |
| `POST v1/webhook-endpoints` | регистрация (`url`, `events`, `is_active`, `drive_id`) — ответ несёт `secret`, один раз |
| `GET v1/webhook-endpoints/{endpoint}` | один эндпоинт |
| `PATCH v1/webhook-endpoints/{endpoint}` | изменить `url`, `events`, `is_active`, `drive_id` |
| `POST v1/webhook-endpoints/{endpoint}/deactivate` | выключить; открытые доставки становятся `dead` |
| `POST v1/webhook-endpoints/{endpoint}/rotate-secret` | новый секрет, один раз; `overlap_seconds` оставляет старый действующим на это время |
| `DELETE v1/webhook-endpoints/{endpoint}` | удалить |
| `GET v1/webhook-endpoints/{endpoint}/deliveries` | его доставки, новые первыми, курсорной страницей |
| `POST v1/webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver` | `202`, см. [Повторная доставка](#повторная-доставка) |

Эндпоинт — `{id, url, drive_id, events, status, disabled_at, created_at}` со `status` `active` или
`disabled`; сохранённый секрет обратно не читается. Доставка —
`{id, event_id, event_type, status, attempts, next_retry_at, last_status_code, created_at}`, где
`last_status_code` — HTTP-статус последней неудачной попытки, если эндпоинт вообще ответил;
payload и сырые тексты ошибок остаются на сервере. Неизвестные id дают
`404 webhook_endpoint_not_found` или `404 webhook_delivery_not_found` (доставка другого эндпоинта
тоже неизвестна).

## Дедупликация и порядок

Доставка at-least-once и не упорядочена: дедуплицируйте по `webhook-id` (`id` конверта) и
используйте `sequence`, чтобы отбросить устаревшее. Клиент, которому нужно каждое изменение drive по
порядку, читает [журнал изменений](/ru/concepts/events), а вебхуками только просыпается.

## Имена событий

Каталог событий (`DriveEventType`, `DocumentEventType`) использует канонические имена 0.3. Миграция апгрейда №9
конвертирует `v_activity.verb`, `v_outbox.event`, `v_webhook_deliveries.event` и
`v_webhook_endpoints.events` вместе. Списки эндпоинтов обрабатываются порциями по 500; порядок,
дубли, `*` и нераспознанные сторонние имена сохраняются.

| Старое имя | Каноническое имя |
|---|---|
| `storage.created` | `drive.created` |
| `node.created` | `folder.created` |
| `version.created` | `file.revision_added` |
| `node.force_deleted` | `node.purged` |
| `upload_session.initiated` | `upload.initiated` |
| `upload_session.aborted` | `upload.aborted` |
| `upload_session.expired` | `upload.expired` |

`file.uploaded`, перемещение/переименование/копирование/корзина/восстановление узлов, события
грантов и ссылок сохраняют имена. События жизненного цикла загрузок остаются только для аудита.
Обновите потребителей вебхуков при развёртывании апгрейда схемы и кода. Id события активности и
агрегатные поля outbox заполнены для каждого события, записанного с появления конвейера событий
(типы событий 1.0 перечислены в [каталоге](/ru/reference/events)).

Каталог закрыт: каждый тип объявляет `EventSpec` (субъект, ключи и типы `data`, какие приёмники его
записывают), и таблица в [справочнике событий](/ru/reference/events) генерируется из него, поэтому
справочник не может разойтись с кодом. Каталог 1.0 добавляет `node.updated` (изменились
properties), `access.inheritance_changed`, `share.updated` и `share.rotated`; `share.updated`
сообщает только *имена* изменённых полей в `data.changed`, и ни один конверт не несёт токен ссылки,
его хеш или префикс, пароль, имя, путь или детали запроса.

Админ-ресурсы REST используют форму v1, описанную выше. См.
[UPGRADE.md](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) — проверки безопасности,
бэкапы и необратимые части отката.
