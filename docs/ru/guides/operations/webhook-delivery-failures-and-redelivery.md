---
title: Сбои доставки вебхуков и повторная отправка
---

# Сбои доставки вебхуков и повторная отправка

Сначала отличите **нет доставки** от **неуспешной доставки**:

- нет строки `v_webhook_deliveries` для события: relay не разложил его — проверьте `core.webhooks.outbox_lag` и что `vaulter:webhooks:relay` в расписании;
- строка `dead`: endpoint отвечал ошибкой или таймаутом, пока не кончились попытки.

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.webhooks
```

Отправьте dead-доставки снова. Повтор сохраняет **тот же `webhook-id`**, поэтому получатель,
дедуплицирующий по нему, остаётся корректным:

<!-- snippet: shell -->

```bash
php artisan vaulter:webhooks:redeliver --endpoint=12 --since="2026-10-01" --status=dead
```

Старые строки outbox, раскладку которых доказать нельзя (записаны до поэндпоинтных доставок),
сначала выводятся списком, затем переигрываются по запросу:

<!-- snippet: shell -->

```bash
php artisan vaulter:webhooks:legacy-fanout --since="2026-09-01"
```

<!-- snippet: shell -->

```bash
php artisan vaulter:webhooks:legacy-fanout --since="2026-09-01" --replay --dry-run
```

Уберите `--dry-run` только после чтения списка. Получатели должны принимать повторное событие.
