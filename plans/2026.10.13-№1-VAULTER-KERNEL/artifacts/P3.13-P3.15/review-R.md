**Вердикт: changes.** Тихая потеря после detach и сломанный контракт `vaulter:relay:replay` (ack без `--ack-only`, exit 0 при дыре в диапазоне). Репро ниже не запускались.

## critical

**События за время `detached` не попадают ни в доставку, ни в gap.** `packages/core/src/Events/Outbox/OutboxStore.php:82` и `:280`.

`detachExpired` пишет gap один раз: `to_seq = max(outbox_seq)` на этот момент, открытые строки становятся `dead`. Пока consumer остаётся `detached`, новые события коммитятся без delivery (модуля нет в графе). Первый же `register` (`vaulter:relay` раз в минуту и `OutboxRelay::replay` в `OutboxRelay.php:115`) делает `activation_seq = reconciled_through_seq = max`. Reconcile берёт только `seq > reconciled`. Doctor (`OutboxConsumerGapCheck.php:47`) советует replay ровно старого `[from, to]`. Хвост не в gap, не в метрике, курсор уже пройден; retention его потом удалит (detached в `min(reconciled)` не входит). Пауза до detach сюда не входит: на detach события `seq > reconciled` в gap попадают.

Репро: consumer reconciled = 100; убрать из графа; события 101–150; промотать `max_consumer_hold`; `pass()` → gap `[101,150]`, `detached`; события 151–200 без delivery; вернуть consumer; `pass()`. Ожидание по O4: 151–200 в том же gap (или отдельной записью) до прыжка курсора. Факт: `reconciled_through = 200`, gap по-прежнему `[101,150]`, строк delivery на 151–200 нет.

## high

**Replay подтверждает gap до решения об exit code, в том числе когда переигрывать нечего.** `OutboxStore.php:369`, `RelayReplayCommand.php:56`.

`replay()` всегда вызывает `acknowledge` внутри своей транзакции. Если в `[from, to]` не осталось событий, store всё равно помечает целиком лежащие в диапазоне gaps и возвращает `covered = null`. Команда печатает «use --ack-only» и выходит с 1 уже после коммита. Doctor гаснет, хотя `--ack-only` не передавали. То же при неполном префиксе (`covered[0] > from`): exit 1 есть, запись gap уже снята.

Репро: detach с gap `[10,100]`; удалить эти события (`prune` их отпускает, detached retention не держит); `vaulter:relay:replay --consumer=webhooks.fanout --from=10 --to=100` без `--ack-only`. Факт: exit 1 и `gaps[0].acknowledged_at` уже заполнен.

**Дыра не в начале диапазона даёт exit 0.** `RelayReplayCommand.php:63`.

Проверяется только `covered[0] > from`. `covered` — это min/max живых seq, не сплошной диапазон. Хвост и середина, снятые retention, не видны.

Репро: seq 10, 11, 12 старше retention; у 10 delivery `pending` (prune его не берёт); у 11 и 12 нет открытых строк и `seq ≤ hold`. `prune` удаляет 11 и 12. `replay --from=10 --to=12`. Факт: `covered = [10,10]`, exit 0, gap внутри `[10,12]` подтверждён, 11 и 12 не становятся `pending`.

## medium

**Запись `gaps` целиком теряется при гонке detach и acknowledge.** `OutboxStore.php:288` и `:404`.

Оба читают JSON в PHP и пишут массив обратно. `acknowledge` строку не блокирует. На Read Committed: ack прочитал `[]`, detach закоммитил новый gap, ack записал свой старый массив. Doctor больше не показывает разрыв.

Репро: две сессии. Сессия A: `detachExpired` после чтения `gaps`, до UPDATE. Сессия B: `acknowledge` того же consumer. A коммитит, затем B. В строке нет gap, который записала A.

**`OutboxSettleWindowCheck` считает отсутствие лимита нормой.** `OutboxSettleWindowCheck.php:78` и `:86`.

`statement_timeout` и `idle_in_transaction_session_timeout` равные 0 (дефолт PostgreSQL, «без лимита») выкидываются. Пустой список лимитов даёт `core.outbox.settle_window_ok`. Транзакция может жить дольше `settle_window_seconds`, и проверка, которая стережёт O2, молчит. Если задан только `statement_timeout`, а idle-таймаут 0, сравнение тоже зелёное: idle in transaction лимитом не ограничен.

Репро: PostgreSQL с обоими таймаутами в 0, `vaulter:doctor`. Факт: ok. Нужен warning, что сервер не ограничивает длину транзакции.

## low

**Каталог классов в architecture устарел.** `docs/concepts/architecture.md:131`, `docs/ru/concepts/architecture.md:133`.

Таблица по-прежнему отдаёт `Webhooks/` классы `OutboxRecorder` и `OutboxRelay`. Их в этом коммите нет: запись в `Events/Outbox/OutboxWriter`, relay в `Events/Outbox/OutboxRelay`.

## Без замечаний по фокусу

O2: `settledSeq` — это `max(seq)` при `created_at < now − settle`, спаны по 1000 с откатом вставки, если fenced UPDATE `lease_generation` и `reconciled_through_seq` не сдвинул строку. O3: `complete` только при `state=leased` и том же `lease_generation`, 0 строк не пишет метрику успеха. Claim: `FOR UPDATE SKIP LOCKED` на pgsql/mysql/mariadb и условный UPDATE по поколению. O6: один `increment` аренды consumer, второй relay получает 0 строк. Удержание retention: `min(reconciled_through)` по durable не-`detached`, предикат повторяется в DELETE. Ephemeral: gap не пишется. Jitter `random(0, min(cap, base·2^attempts))`, при `attempts ≥ max_attempts` строка `dead` и `vaulter_outbox_dead_total`. `WebhookFanoutConsumer`: транзакция чанка `attempts: 1`, `saveContinuation` до `createDeliveries`, eligibility как у старого relay (active, tenant null-safe, drive-фильтр, тип, `created_at ≤ event`).
