---
title: Переход политики сканирования
---

# Переход политики сканирования

У контента, загруженного до обязательного сканирования, нет вердикта (`new_content_only`). Чтобы
требовать вердикт для всего контента (`all_content`):

1. Задайте идентичность сканера и версию политики в `vaulter-drive.uploads.scan`.
2. Посмотрите backfill:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --dry-run --tenant=acme --all-tenants
```

3. Запишите запросы на сканирование (порциями; повторяйте с `--after`, пока не останется):

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --rescan --tenant=acme --reason="policy cut-over"
```

4. Когда `vaulter:doctor` не показывает остатка backfill, активируйте политику:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:rescan --activate-policy
```

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=drive.scan.policy
```

Активируйте, только когда doctor не показывает непросканированного legacy-контента.
