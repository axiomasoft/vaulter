---
id: D30
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Метрики-seam = узкий vaulter-owned контракт `Vaulter\Contracts\Observability\MetricsRecorder` (Null-дефолт + Fake), БЕЗ хард-зависимости на OTel/Prometheus.** Хост биндит реальный адаптер; OTel-мост опционален (против `open-telemetry/api`, сам no-op без SDK). Пакет собирается и PHPStan зелёный при полном отсутствии backend'а — как `AzgardGuard`.

## Почему

Imported noncanonical legacy decision row
