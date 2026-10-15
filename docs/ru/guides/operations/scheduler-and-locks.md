---
title: Планировщик и блокировки
---

# Планировщик и блокировки

Vaulter ставит свои команды обслуживания в планировщик Laravel. Важны два переключателя:

- `vaulter.schedule.enabled` — `false`, если хост планирует команды сам;
- ключ каждой задачи (`outbox_relay`, `uploads_sweep`, …) — `null` выключает задачу.

Сервер должен запускать `php artisan schedule:run` каждую минуту. При нескольких серверах
`onOneServer` работает, только если кеш общий и поддерживает атомарные блокировки (redis, database,
memcached — не `file` и не `array`). Проверка:

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.scheduler
```

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=core.cache
```

Задача, идущая дважды, обычно означает неразделяемое хранилище блокировок; задача, которая не идёт
никогда, — что планировщик не запускается или `schedule.enabled` равен `false`.
