---
id: D35
date: 2026-09-20
status: accepted
item: P8.1
items: [P8.1, P8.2, P8.3, P8.4, P8.5, P8.7]
supersedes: []
superseded_by: null
---
# D35 — Public MetricsRecorder contract and metric registry

**Actor:** designer/gpt-5.6-sol/frontier/high

**Evidence:** RAG:✅ — OpenTelemetry PHP library guidance confirms API-only instrumentation is no-op
without an SDK/provider; Prometheus guidance requires stable names/base units and bounded labels.
Primary sources: https://opentelemetry.io/docs/languages/php/libraries/ ·
https://opentelemetry.io/docs/languages/php/instrumentation/ ·
https://prometheus.io/docs/practices/naming/ ·
https://prometheus.io/docs/instrumenting/writing_exporters/.

## Решение

`Vaulter\Contracts\Observability\MetricsRecorder` — публичный host-facing контракт:

```php
counter(string $name, int|float $amount = 1, array $labels = []): void;
gauge(string $name, int|float $value, array $labels = []): void;
histogram(string $name, int|float $value, array $labels = []): void;
timing(string $name, callable $operation, array $labels = []): mixed;
```

`timing()` записывает длительность в секундах как histogram и возвращает результат callback либо
пробрасывает его исключение без изменения. Null-реализация исполняет callback, но ничего не записывает.
`gauge()` принимает абсолютное наблюдаемое значение, не delta; `+1/-1` нельзя выдавать за gauge value.
В публичной сигнатуре нет типов OpenTelemetry/Prometheus, фасада или service locator. Хост владеет
SDK/exporter и может заменить Null через DI; ошибка backend'а не должна менять бизнес-результат.

Реестр `slo-gates.md` §5.1–§5.3 содержит **50 уникальных metric families в 40 строках**:
12 + 10 + 28. `observability-spec.md` хранит отдельную строку на каждое имя и указывает type, labels,
owner (`package|host|harness`), symbol/anchor и owning item. Package-owned labels имеют конечный словарь;
raw URL, ID/UUID, user/tenant/email и тексты исключений запрещены. Метрики P8-минимума не семплируются.

## Почему

Узкий собственный контракт сохраняет тестируемость и независимость reusable package от exporter SDK.
Полный по именам реестр нужен, чтобы gate не принимал частичную инструментацию за покрытие §5.

## Альтернативы

- Экспортировать OTel-типы из контракта — отклонено: связывает SemVer пакета с backend SDK.
- Статический facade — отклонено: скрывает зависимость и ухудшает тестируемость.
- Считать 40 строк таблицы или прежние 37 «метриками» — отклонено: строки с несколькими именами теряются.

## Последствия

P8.1 обязан машинно доказать parity 50/50. P8.2 реализует контракт дословно; P8.3/P8.4/P8.5 отвечают
за 12/10/28 families соответственно. Изменение имени, типа, labels или сигнатуры — отдельное решение.
