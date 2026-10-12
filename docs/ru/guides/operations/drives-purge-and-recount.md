---
title: Purge и recount дисков
---

# Purge и recount дисков

Диск в статусе `deleting` удаляется плановой задачей. Если сообщается `drive.drives.deleting_stuck`,
посмотрите и выполните purge вручную:

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:purge --dry-run --drive=7
```

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:purge --drive=7 --sync
```

Использованные байты — счётчик. `drive.drives.usage_drift` значит, что он расходится с сохранёнными
ревизиями; сначала сообщите о расхождении, потом исправляйте:

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:recount --dry-run --drive=7
```

<!-- snippet: shell -->

```bash
php artisan vaulter:drives:recount --drive=7
```

Без `--drive` обе команды действуют на все диски — такие запуски всегда сначала в режиме просмотра.
