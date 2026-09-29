---
id: D9
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Наблюдаемость строим с нуля (P8), и P8 — ГЕЙТ для P3–P7, не параллельная фаза.** OpenTelemetry PHP SDK → OTLP Collector → Prometheus/Tempo; метрики очереди/GC/sweep/upload/collab и тайминги горячих путей — seam'ы, которых в пакете нет. Без базового набора метрик прогоны P3–P7 не имеют опоры для вердикта («SLO нарушен» нечем измерить) — P8 обязана поставить минимальный набор seam'ов ДО того, как P3–P7 фиксируют результат, а не просто «стартовать сразу за P2» на своих рельсах. **Уточнено P1 (D16):** сам список P8-минимума выводится не в P8, а в item'е P1.4 — из колонки «Prometheus-метрика» таблицы `slo-gates.md`.

## Почему

Imported noncanonical legacy decision row
