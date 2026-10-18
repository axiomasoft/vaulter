---
title: Outbox
---

# Outbox

Каждое доменное событие Vaulter записывается в транзакционный outbox, `v_outbox`, в транзакции
операции, которая его вызвала, — какие бы модули ни были активны. Consumer'ы outbox получают
события после коммита, хотя бы один раз, каждый — по своим строкам доставки. Модуль вебхуков —
один из consumer'ов, `webhooks.fanout`; модуль коллаборации — другой, `collaboration.control`, *ephemeral*: он превращает
`drive.access.effective_permissions_changed` в control-сообщение хабам
([Совместное редактирование](/ru/guides/collaboration#ревокация-и-граница-экспозиции)); любой модуль может внести свой
через слот `OutboxConsumer`.
Ни одно событие не теряется молча: для durable consumer'а каждое событие либо доставлено, либо
в DLQ, либо входит в явный разрыв (gap).

## Таблицы

| Таблица | Строка на | Хранит |
|---|---|---|
| `v_outbox` | событие | `outbox_seq` (монотонный порядок, не зависит от стратегии id), `event_id` (уникальный), тенант, `scope_kind`/`scope_id` (диск или тенант), агрегат и его `sequence`, `type`, `external`, payload |
| `v_outbox_consumers` | consumer | класс доставки (`durable`, `ephemeral`), состояние (`active`, `paused`, `detached`), `activation_seq`, `reconciled_through_seq`, аренда reconciler'а, разрывы |
| `v_outbox_deliveries` | событие × consumer | состояние (`pending`, `leased`, `done`, `dead`), попытки, `next_attempt_at`, аренда доставки и её поколение, continuation consumer'а, `last_error` |

## Запись

`EventRecorder` пишет событие последним, после sink'ов (лента активности и журнал изменений
drive), поэтому payload несёт seq журнала. В той же транзакции он пишет строку доставки
`pending` для каждого consumer'а графа модулей, подписанного на тип. Откаченная операция не
оставляет ни того, ни другого; повтор того же наступления (тот же `event_id`) ничего не пишет.
Без consumer'ов пишется только событие, и его убирает retention.

## Relay

`vaulter:relay` запускается каждую минуту (`vaulter.schedule.outbox_relay`) без наложений.
Один проход:

1. **Регистрация.** Каждый consumer графа модулей получает свою строку: новый начинает с
   текущего seq, приостановленный продолжает с места, отсоединённый начинает заново с текущего
   seq. Consumer, которого граф больше не содержит (его модуль выключен), приостанавливается.
2. **Сверка.** Процесс со старым графом модулей писал события без строки для consumer'а, о
   котором не знал. Под арендой consumer'а relay вставляет недостающие строки для событий до S —
   наибольшего seq, записанного раньше чем `outbox.settle_window_seconds` назад, — и сдвигает
   `reconciled_through_seq` до S. Дальше он не сдвигается: долгая транзакция ещё может
   закоммитить меньший seq внутри окна. Держите окно больше самой долгой транзакции Vaulter;
   `vaulter:doctor` (`core.outbox.settle_window`) сравнивает его с лимитами транзакций базы.
3. **Доставка.** Подошедшие строки арендуются (`FOR UPDATE SKIP LOCKED` на PostgreSQL и MySQL),
   consumer работает вне транзакции, а его результат пишется только под поколением аренды, с
   которым строку взяли: воркер, чью истёкшую аренду перехватили, ничего не пишет. Повтор ждёт
   случайную задержку до min(`backoff_cap_seconds`, `backoff_base_seconds` · 2^attempts) (full
   jitter); после `outbox.max_attempts` строка становится `dead`.
4. **Удержание.** Durable consumer, приостановленный дольше `outbox.max_consumer_hold_seconds`,
   отсоединяется: его открытые строки становятся `dead`, а пропущенный диапазон записывается как
   разрыв, учитывается в `vaulter_outbox_consumer_gap_events_total` и выдаётся `vaulter:doctor`
   как ошибка, пока его не закроют. Ephemeral consumer отсоединяется без разрыва.

Закройте разрыв командой `vaulter:relay:replay --consumer= --from= --to=`: события диапазона,
ещё лежащие в outbox, снова становятся ожидающими для этого consumer'а (включая done и dead), а
покрытые диапазоном разрывы подтверждаются; код выхода 1, если часть диапазона уже не в outbox.
`--ack-only` подтверждает потерю без повтора.

## Хранение

`vaulter:outbox:prune` запускается ежечасно (`vaulter.schedule.outbox_prune`). Событие старше
`outbox.retention_seconds` удаляется, только если ни одна его доставка не открыта и каждый
неотсоединённый durable consumer сверился дальше него — приостановленный consumer держит его до
отсоединения. Строки done уходят вместе с событием; строки dead живут
`outbox.dead_retention_seconds`.

## Порядок и дубли

Порядка между событиями нет. Payload несёт агрегат и его `sequence`, поэтому consumer может
упорядочивать в пределах агрегата. Доставка — хотя бы один раз: дедуплицируйте по id события.

## Как написать consumer

Реализуйте `Vaulter\Events\Outbox\OutboxConsumer` и внесите его из манифеста модуля под его id
(`$module->slot(OutboxConsumer::class, MyConsumer::class, 'my.consumer')`). `subscribes()`
выбирает типы событий, `deliveryClass()` говорит, держит ли consumer retention, а `consume()`
возвращает done, retry или dead. Consumer, работающий шагами, сохраняет курсор через
`DeliveryControl::saveContinuation()` — запись, ограждённую арендой: если она вернула false,
доставку перехватили, и consumer останавливается. Таблицы ядра consumer меняет только через
`DeliveryControl`.
