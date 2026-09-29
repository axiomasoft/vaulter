---
id: D36
date: 2026-09-20
status: accepted
item: P8.1
items: [P8.1, P8.6, P8.7]
supersedes: [D32]
superseded_by: null
---
# D36 — Health endpoints belong to the load SUT

**Actor:** designer/gpt-5.6-sol/frontier/high

**Evidence:** RAG:✅ — Laravel's configured health route dispatches `DiagnosingHealth`, returns 200
unless a listener fails, and does not itself prove DB/S3/queue readiness. Primary sources:
https://api.laravel.com/docs/12.x/Illuminate/Foundation/Configuration/ApplicationBuilder.html ·
https://api.laravel.com/docs/12.x/Illuminate/Foundation/Events/DiagnosingHealth.html.

## Решение

`/up` остаётся Laravel-native process-liveness endpoint приложения `load/`. Отдельный `/ready` в том же
SUT выполняет дешёвые ограниченные по времени проверки DB, S3 sentinel и настроенного queue connection;
отвечает 200 только при успехе всех трёх, иначе 503 с машинно-читаемым состоянием компонентов.

Переиспользуемый `vaulter-core` не добавляет Spatie Check-классы, `suggest` или health route. Хост может
использовать `spatie/laravel-health` независимо, но P8 не делает его частью API или compile-time surface.

## Почему

Только приложение знает реальные connections, credentials и deployment semantics. Такое владение даёт
харнессу честный readiness signal и оставляет core устанавливаемым без необязательного health package.

## Альтернативы

- Опциональные классы, наследующие Spatie `Check`, в core — отклонено: PHP всё равно должен разрешать
  внешний родительский тип при загрузке класса, что противоречит сборке без зависимости.
- Считать `/up` readiness — отклонено: дефолтный endpoint не доказывает доступность DB/S3/queue.
- Поместить `/ready` в пакет — отклонено: выбор инфраструктуры и credentials принадлежит приложению.

## Consequences

P8.6 изменяет только `load/` и его тесты/compose-конфигурацию. Негативные проверки обязаны отдельно
доказать 503 при недоступности DB, S3 и queue; `/up` при этом остаётся проверкой процесса.
