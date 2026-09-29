---
id: D14
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**SLO машинно-проверяемы (CI-гейт на PR), и гейт — ДВУХКЛАССОВЫЙ.** Класс A (шумоустойчивый, абсолютный, блокирующий): error-rate, checks-rate, throughput при фиксированной конкуренции, потолок пиковой памяти, счётчики unbounded-scan. Класс B (латентность p95/p99): абсолютный порог — только широкий guardrail (~150 % цели), острый гейт — **относительная регрессия к baseline** (медиана из N=3, CV-guard). Один класс на всё → флапающий гейт, который отключат.

## Почему

Imported noncanonical legacy decision row
