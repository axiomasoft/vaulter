---
id: D21
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Кастомный k6-бинарник отменён: харнесс — СТОКОВЫЙ k6.** `xk6-fasthttp` **не эмитит** `http_req_duration`, `http_req_failed`, `http_req_*`, `data_sent`, `data_received` (README расширения перечисляет их как «потерянные» из-за ограничений fasthttp) — а на этих метриках стоит ВЕСЬ гейт P1.4: сквозные G-1/G-2 (`http_req_failed{class:…}`), агрегатный throughput (`data_sent`/`data_received`) и все строки класса B §3.1–§3.2 `slo-gates.md`. Сборка на fasthttp обнулила бы половину гейта; вдобавок PATCH (на котором целиком стоит tus, OP-02) в его API не документирован, последний релиз — 09.2024. **И главное: стриминг тела vaulter'у не нужен вовсе** — ни один эндпоинт не принимает multi-GB тело одним запросом (tus-чанк 8 MiB · S3-часть ≤ 64 MiB · single-shot ≤ 32 MiB по D19 · download — тело ОТВЕТА, снимается `discardResponseBodies`).

## Почему

Imported noncanonical legacy decision row
