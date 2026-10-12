---
title: Эксплуатация
---

# Эксплуатация

Runbooks для ситуаций, с которыми оператор встречается в продакшене. Каждая команда ниже существует;
у каждого деструктивного или массового шага есть форма `--dry-run` (или явный `--execute`) —
запускайте её первой. Любое расследование начинайте с `vaulter:doctor`.

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

| Runbook | Когда |
| --- | --- |
| [Очередь сканирования и зависшие lease](/ru/guides/operations/scan-backlog-and-stalled-leases) | загрузки ждут вердикта сканера |
| [Ошибки, неподдерживаемые и защищённые паролем файлы](/ru/guides/operations/scan-failures-unsupported-and-password-files) | исходы сканирования, кроме clean |
| [Переход политики сканирования](/ru/guides/operations/scan-policy-cutover-legacy) | legacy-контент переходит на `all_content` |
| [Сбои доставки вебхуков и повторная отправка](/ru/guides/operations/webhook-delivery-failures-and-redelivery) | получатель пропустил или отклонил события |
| [Лимиты direct URL](/ru/guides/operations/direct-url-limits) | скачивание `direct_signed` и отзыв доступа |
| [Dedup off, понижение и preflight](/ru/guides/operations/dedup-off-downgrade-and-preflight) | смена `vaulter-media.dedup.scope` |
| [Purge и recount дисков](/ru/guides/operations/drives-purge-and-recount) | удаление дисков, расхождение объёма |
| [Планировщик и блокировки](/ru/guides/operations/scheduler-and-locks) | задачи обслуживания не запускаются или идут дважды |
