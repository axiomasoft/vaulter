---
id: D04
date: 2026-09-29
status: accepted
item: P0
items: [P0, P0.1, P0.2, P0.3, P1, P2, P3, P4, P5, P6, P7, P8]
supersedes: []
superseded_by: null
---
# D04 — Окружение исполнения (accepted)

**Actor:** author-choice (claude-opus-5-5/high).
**Evidence:** RAG:— хост 2026-09-29: `php -v` → 8.3.35; `/usr/bin/php8.4` есть, модули включают `pcntl posix pdo_pgsql
pdo_mysql pdo_sqlite pcov gd intl`; `packages/*/composer.json` → `"php": "^8.4"`; `pg_isready` → 127.0.0.1:5432 accepting
(PostgreSQL 16.15); роль `vaulter` (CI: `DB_USERNAME=vaulter`, `DB_PASSWORD=secret`, `DB_DATABASE=vaulter_test`) —
`password authentication failed`; MySQL 127.0.0.1:3306 слушает, root без пароля — `Access denied`; Docker Desktop не запущен,
системный сокет — `permission denied`; `vendor/` в репозитории отсутствует. Образец — `corex:scripts/php84`.

## Solution

1. Все PHP-команды — через обёртку **`scripts/php84`** (кладёт php8.4 первым в `PATH`; `--check` проверяет версию и
   расширения). Запуск на 8.3 запрещён: при отсутствии 8.4 — стоп.
2. **PostgreSQL** — локальный 16 на `127.0.0.1:5432`, координаты как в CI: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1
   DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret`. Имя БД обязано оканчиваться на `_test`
   (проверяет `scripts/db-preflight.sh`). Создание роли и БД — **owner gate O-1**:
   `sudo -u postgres psql -c "CREATE ROLE vaulter LOGIN PASSWORD 'secret' CREATEDB" && sudo -u postgres createdb -O vaulter vaulter_test`.
3. **MySQL 8** — локальный `127.0.0.1:3306`, `vaulter`/`secret`, БД `vaulter_test` — **owner gate O-2** (нужен с P0.3):
   `sudo mysql -e "CREATE USER 'vaulter'@'localhost' IDENTIFIED BY 'secret'; CREATE DATABASE vaulter_test; GRANT ALL ON vaulter_test.* TO 'vaulter'@'localhost';"`.
4. Docker не обязателен до P4.5 (хаб в docker-compose); тогда — отдельный owner gate при дизайне P4.
5. Тесты конкурентности — только PostgreSQL (OPUS 13, правило 6) через харнесс P0.2.

## Why

Хост уже содержит PHP 8.4 со всеми нужными расширениями и нативные PG/MySQL — это быстрее и детерминированнее Docker.
Роли БД требуют прав суперпользователя, которых у агента нет.

## Consequences

P0.1 начинается с `scripts/php84 --check` и `scripts/db-preflight.sh`; при красном preflight item останавливается с
точной командой owner gate, не обходит его SQLite-прогоном.
