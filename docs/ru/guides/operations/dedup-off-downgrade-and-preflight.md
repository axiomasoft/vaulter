---
title: Dedup off, понижение и preflight
---

# Dedup off, понижение и preflight

`vaulter-media.dedup.scope` — `tenant` (по умолчанию), `drive` или `off`. Каждый блоб сохраняет `scope_key`, под
которым был закоммичен, поэтому смена настройки оставляет существующие блобы валидными и влияет только на новые
загрузки: при `drive` или `off` байты, уже лежащие в более широкой области, не разделяются с новыми загрузками.

Смените настройку и проверьте доктором:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

Перед понижением области в production смотрите проверку `media.dedup.scope` в отчёте доктора.
