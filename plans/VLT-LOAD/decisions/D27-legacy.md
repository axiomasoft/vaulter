---
id: D27
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**SUT — nginx + php-fpm, а не `vendor/bin/testbench serve`.** `testbench serve` = `php artisan serve` = однопоточный dev-сервер: на нём `pm.max_children` (8/32/96) и вся раскладка FPM-пула (`slo.md` §0.1 — интерактив/upload/download делят один пул) физически не воспроизводятся, а именно она задаёт конкуренцию всех SLO. Существующий `e2e/docker-compose.yml` переиспользуется как **образец провайдера хоста** (`packages/core/tests/E2E/E2eServiceProvider.php`), а не как SUT.

## Почему

Imported noncanonical legacy decision row
