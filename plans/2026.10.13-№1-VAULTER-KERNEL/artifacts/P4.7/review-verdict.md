# RC2 verdict (codex gpt-6.1-sol xhigh, diff via stdin, 69216af0..1dd1649d)

- **P1 · plausible** — `packages/media/database/migrations/0001_01_01_000102_create_v_blob_references_table.php:12`  
  При обновлении существующей БД таблица pins создаётся пустой; переноса существующих revision edges в показанных миграциях нет. Reconcile обнулит их `ref_count`, а mark/sweep, читающие теперь только pins, смогут удалить байты живых ревизий. Перед переключением GC нужен backfill.

- **P1 · plausible** — `packages/media/src/Cas/BlobPinStore.php:165`  
  Для `Pending` проверяется только `exists()`, после чего строка становится `Committed`. После сбоя записи может остаться частичный canonical object — такой случай допускает комментарий `sweepPending()`. Повторный `commitStaged()` тоже пропускает promotion при существующем объекте. В результате pin закрепляет повреждённые байты без проверки размера/хеша или восстановления из staged.

- **P2 · plausible** — `packages/drive/src/Actions/Files/RestoreFileRevisionAction.php:69`  
  Restore последовательно берёт CAS-locks через отдельные `attach()`, тогда как пакетные mark/reconcile используют порядок `hashtext` в `acquireMany()`. На PostgreSQL restore может держать A и ждать B, а GC — держать B и ждать A. Получается deadlock с отменой одной транзакции. Все адреса операции нужно захватывать в общем порядке до первого pin.

- **P2 · plausible** — `packages/media/src/Cas/BlobReconciler.php:142`  
  На MySQL `countsFor()` выполняется до фактического `FOR UPDATE`: блокирующий запрос запускается только последующим `$query->get()`. Старый положительный count поэтому может перезаписать уже пересчитанный другим reconcile ноль после последнего unpin. Mark пропустит такой blob до следующего пересчёта. Сначала нужно выполнить захват строк, затем читать counts; также учитывать consistent-read snapshot MySQL.
## Проверка (opus, чтение кода)
1 REJECTED (D21: нет апгрейд-путей). 2 PARTIAL: Pending недостижим; hardening — pin на Pending отказывать (D23 его не разрешает) → fix. 3 PARTIAL: латентно, у ревизии 1 blob → nits (pinMany под манифесты). 4 CONFIRMED (P3): countsFor до FOR UPDATE → fix: сначала get(), затем countsFor; поправить докблок.
