---
title: Dedup off, понижение и preflight
---

# Dedup off, понижение и preflight

`vaulter-media.dedup.scope` — `tenant` (по умолчанию), `drive` или `off`. Переход к более узкой
области (`drive`, `off`), пока блобы разделены в более широкой, оставил бы общие блобы без владельца,
поэтому сначала запустите preflight. Он ничего не меняет:

<!-- snippet: shell -->

```bash
php artisan vaulter:blobs:repair-scopes --dry-run
```

Если он показывает legacy-блобы смешанной области, скопируйте их в собственные области. Команда
атомарно перенаправляет рёбра ревизий:

<!-- snippet: shell -->

```bash
php artisan vaulter:blobs:repair-scopes --execute
```

Только затем меняйте настройку и проверьте:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --production
```

Никогда не понижайте область без чистого preflight.
