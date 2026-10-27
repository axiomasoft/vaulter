---
title: Чек-лист усиления защиты
---

# Чек-лист усиления защиты

Выполняйте перед запуском и после каждого обновления:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

Он должен завершиться без ошибок. Затем подтвердите каждый пункт:

- [ ] Маршруты Vaulter за вашим middleware аутентификации (`core.routes.auth` зелёный).
- [ ] Драйвер прав настоящий — не `null` (`core.access.*`).
- [ ] Сканирование **обязательно** для загрузок и политика актуальна (`drive.scan.policy`).
- [ ] Доставка вебхуков идёт через URL guard; `vaulter-webhooks.delivery.allowed_ip_ranges` задавайте только необходимым.
- [ ] См. руководство по защите коммерческого дополнения.
- [ ] Кеш общий и поддерживает атомарные блокировки (`core.cache`).
- [ ] Драйвер очереди не `sync` (`core.queue`).
- [ ] `vaulter-drive.downloads.direct_ttl_seconds` как можно короче, либо `delivery_mode` равен `strict`.
- [ ] Устаревших ключей конфигурации не осталось (`core.config.deprecated_keys`).
- [ ] БД — PostgreSQL, MySQL или MariaDB; SQLite только для разработки.
- [ ] `vaulter doctor` не показывает непринятых production-инвариантов (`core.production_invariants`: `drive.permission-driver`, `collaboration.secrets`, `collaboration.hub-url`, `webhooks.schemes`, `webhooks.blocked-ranges`, `core.tenancy`); каждая запись `vaulter.security.accepted_risks` содержит непустую причину ([ADR-0036](/adr/0036-secure-production-defaults)).
