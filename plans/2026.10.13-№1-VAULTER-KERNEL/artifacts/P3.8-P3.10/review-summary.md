# Сводка ревью P3.8–P3.10

Модель ревьюеров: Sonnet 5.5 (не Grok 4.7, как в промпте). Проверки только точечные, sqlite; pgsql/mysql не запускались.

| Пункт | Ревью | Вердикт | Находки |
| --- | --- | --- | --- |
| P3.8 | review-R1.md | APPROVE_WITH_NITS | 1 LOW, 3 вопроса |
| P3.9 | review-R2.md | APPROVE_WITH_NITS | 3 MEDIUM, 5 LOW, 3 вопроса |
| P3.10 | review-R3.md | CHANGES_REQUESTED | 1 HIGH, 4 MEDIUM, 5 LOW, 2 вопроса |

## Чинить в Части Б (после подтверждения на pgsql/mysql — часть находок выведена чтением кода)

- **P3.10 F1 (HIGH)** — корневая транзакция раннера читает до блокировки дерева; на MySQL REPEATABLE READ возможен устаревший
  sibling-read и дубликаты имён. Не воспроизведено: сначала проверка на mysql, затем фикс порядка/уровня.
- **P3.9 F1 (MEDIUM)** — исключение deadlock/serialization из afterCommit-колбэка повторно запускает уже закоммиченную попытку.
- **P3.9 F3 (MEDIUM)** — readiness на pgsql через `current_schema()` падает при таблицах вне первой схемы `search_path`.
- **P3.10 F3 (MEDIUM)** — `WebhookEndpointWrites` строит `ScopeRef` из сырого route id до Gate: длинный id → 500 вместо 403/404.
- **P3.10 blocker** — `LifecycleBoundaryTest` (2 кейса): тестовый артефакт, гонка откатывается вместе с попыткой.
  Форма: применять конкурентную правку из слушателя `TransactionRolledBack` на базовом уровне; pgsql-вариант через `ForkRace`.
  Ожидаемое исключение не меняется.

## Рекомендации / вопросы владельцу

- P3.9 F2 (MEDIUM): `$depth` синглтона раннера при Octane-корутинах.
- P3.10 F2 (MEDIUM): upload/addRevision авторизуются вне попытки (как в HEAD) — осознанное отклонение, зафиксировать.
- P3.10 F4 (MEDIUM): несколько `write()` под одним idempotency key конфликтуют с D25 после P3.12.
- P3.10 F5 (MEDIUM): S3 initialise/complete/abort внутри попытки без записанного deviation.
- LOW: P3.8 F1; P3.9 F4–F8; P3.10 F6–F9.
- Вопросы: P3.8 Q1–Q3; P3.9 readiness по замыканию зависимостей, запись внутри транзакции хоста, телеметрия отказов;
  P3.10 Q1 (гостевая политика Gate → `MissingActorException`), Q2 (логирование отказа purge).
