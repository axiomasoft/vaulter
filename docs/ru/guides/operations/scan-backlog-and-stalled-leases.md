---
title: Очередь сканирования и зависшие lease
---

# Очередь сканирования и зависшие lease

**Симптом:** файлы остаются неопубликованными; `vaulter:doctor` сообщает `drive.scan.obligations` как stuck или unscanned.

1. Диагностика (только чтение):

<!-- snippet: shell -->

```bash
php artisan vaulter:doctor --check=drive.scan
```

2. Посмотрите, что восстановит reconciler, ограничив одним тенантом или диском:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --dry-run --tenant=acme --drive=1
```

3. Запустите по-настоящему. Работа ограничена `--limit` и `--max-seconds`, повторяйте, пока проверка doctor не станет зелёной:

<!-- snippet: shell -->

```bash
php artisan vaulter:scan:reconcile --tenant=acme --drive=1 --reason="stalled leases after deploy"
```

Истёкший lease подхватывает следующий запуск; ручная правка базы не нужна. Если обязательства
продолжают зависать, проверьте, что воркер очереди обслуживает очередь `uploads.scan.queue` и что
`uploads.scan.scanner_timeout_seconds` меньше `lease_seconds`.
