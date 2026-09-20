<!-- plan-projection
projection: status-view
projection_version: v1
source_scope: plan core + phases + journal
through: 2026-09-20
inputs_sha256: 11b611ccea2a46213522b9a3f649437ecd8c83c21a1f56e920c90605301cd109
generated_by: task plan-views status
-->

# Статус плана

> Не править руками: это генерируемый вид.

## 0. Meta

| Поле | Значение |
|:--|:--|
| Version | 0.4.0 |
| Status | 🟡 In progress |
| Last Updated | 2026-09-20 |

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Разведка, RAG-провенанс и мастер-план | 1/1 | 🟢 Done |
| P1 | Целевые числа: явные SLO (гейт P2–P8) | 4/5 | 🟠 Done with deviations |
| P2 | Нагрузочный харнесс: инструмент + профили трафика (гейт P3–P7) | 5/8 | 🟠 Done with deviations |
| P3 | Профилирование запросов: EXPLAIN + ревизия индексов под объёмом | 0/0 | ⬜ Not started |
| P4 | Память и стриминг: бенчмарки ≤5 ГиБ | 0/0 | ⬜ Not started |
| P5 | Конкуренция в масштабе: сотни контендеров | 0/0 | ⬜ Not started |
| P6 | Семантика очередей под завалом | 0/0 | ⬜ Not started |
| P7 | Деградация инфраструктуры (chaos) | 0/0 | ⬜ Not started |
| P8 | Наблюдаемость: метрики + трейсы (seam'ы с нуля) (гейт P3–P7 по минимуму) | 1/8 | 🟡 In progress |

## Phase P0

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P0.0 | Рекогносцировка + RAG-провенанс + мастер-план | 🟢 Done | 1970-01-01 |

## Phase P1

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P1.1 | Reference-профиль: тиры S/M/L (железный конверт + объёмы данных) | 🟠 Done with deviations | 1970-01-01 |
| P1.2 | Каталог операций и размерных профилей (OP-nn, с якорями в коде) | 🟢 Done | 1970-01-01 |
| P1.3 | SLO-таблица: latency/throughput/error-budget по OP × тир (SLO-nn) | 🟢 Done | 1970-01-01 |
| P1.4 | Гейт-политика: SLO → k6-пороги + Prometheus-метрики + P8-минимум | 🟢 Done | 1970-01-01 |
| P1.5 | Sign-off владельца: числа из «гипотеза» → «утверждено» | 🟢 Done | 1970-01-01 |

## Phase P2

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P2.1 | `harness.md` — инструмент, стенд, контракт метрик, раскладка профилей | 🟢 Done | 1970-01-01 |
| P2.2 | Стенд: `docker-compose.load.yml` + SUT-образ (nginx+php-fpm) + load-хост | 🟢 Done | 1970-01-01 |
| P2.3 | Seed: объёмный конверт и «грязные» датасеты (`dataset_id`, снапшот) | 🟢 Done | 1970-01-01 |
| P2.4 | k6-сценарии: библиотека + 7 сценариев + контрактный smoke | 🟢 Done | 1970-01-01 |
| P2.5 | Гейт: thresholds-модуль + perf-assert (PromQL) + baseline-артефакт | 🟢 Done | 1970-01-01 |
| P2.6 | CI-лейн: `.github/workflows/load.yml` (smoke-perf на PR, regression nightly) | 🟠 Done with deviations | 1970-01-01 |
| P2.7 | CV на `ubuntu-latest` (5 прогонов) → δ и вердикт по классу B | 🟠 Done with deviations | 1970-01-01 |
| P2.8 | DoD-прогон на тире S: baseline 1× + доказательство потоковости 5 ГиБ | 🟠 Done with deviations | 1970-01-01 |

## Phase P3

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P4

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P5

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P6

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P7

| ID | Title | Status | Updated |
|:--|:--|:--|:--|

## Phase P8

| ID | Title | Status | Updated |
|:--|:--|:--|:--|
| P8.1 | Исполнимая спека наблюдаемости и публичный контракт `MetricsRecorder` | 🟢 Done | 2026-09-20 |
| P8.2 | Машинерия шва: контракт + Null-дефолт + Fake + config-wiring | ⬜ Not started | — |
| P8.3 | Seam'ы §5.1 — HTTP и горячие пути (12 metric families) | ⬜ Not started | — |
| P8.4 | Seam'ы §5.2 — стриминг и память (10 metric families, сердце P8-минимума) | ⬜ Not started | — |
| P8.5 | Seam'ы §5.3 — фон/очереди/unbounded-scan (28 metric families) | ⬜ Not started | — |
| P8.6 | Health в load SUT: `/up` liveness + `/ready` DB/S3/queue | ⬜ Not started | — |
| P8.7 | Гейт-верификация P8-минимума + замер overhead инструментации | ⬜ Not started | — |
| P8.8 | Полное закрытие: трейсы (OTel-спаны) + отложенные метрики (после P3–P7) | ⬜ Not started | — |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-green · Layout v2 current-state import from legacy status 🟢 Done; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/current-state-v1 | plan-run · closed-deviations · Layout v2 current-state import from legacy status 🟠 Done with deviations; chronology: artifacts/migration-source-v1/journal.jsonl |
| 1970-01-01 | migration/v2 | plan-design · in-progress · Layout v2 migration activation; source tree archived in artifacts/migration-source-v1. |
| 2026-09-20 | gpt-5.6-sol/frontier/high | plan-exec · in-progress · — |
| 2026-09-20 | gpt-5.6-sol/frontier/high | plan-exec · closed-green · observability-spec.md: exact public contract, 50/50 registry parity (12/10/28), ownership/cardinality/overhead and /up-/ready boundary |
| 2026-09-20 | gpt-5.6-sol/frontier/high | plan-exec · local-repair · write-site: pre_mutation: allow · pre_final: allow |
| 2026-09-20 | gpt-5.6-sol/frontier/xhigh | plan-audit · in-progress · P8.1 observability design audit; Perplexity Deep Research + primary-source web verification |
| 2026-09-20 | gpt-5.6-sol/frontier/xhigh | plan-audit · audit-red · RED: no durable FPM-to-sink verification path; ownership and stream semantics conflict; histogram PromQL/buckets invalid; DI, labels, and queue readiness incomplete |
| 2026-09-20 | cursor-grok-4.6-high/frontier/high | plan-run · in-progress · — |
| 2026-09-20 | cursor-grok-4.6-high/frontier/high | plan-run · closed-green · observability-spec.md + D37: F1–F4 transport/ownership/PromQL/DI/queue; P8.2–P8.7 Files aligned; owner-granted cursor-grok-4.6-high |
| 2026-09-20 | gpt-5.6-sol/frontier/xhigh | plan-audit · in-progress · Повторный design audit после D37; проверяю исполнимость reader, shared registry, host wiring, signal sources и readiness protocol. |
| 2026-09-20 | gpt-5.6-sol/frontier/xhigh | plan-audit · audit-red · RED after D37: perf-assert still queries bare histogram names; Redis/run lifecycle and host wiring are incomplete; item Files omit required seams; readiness ACK can be stale. |
| 2026-09-20 | cursor-grok-4.6-high/frontier/high | plan-run · in-progress · — |
| 2026-09-20 | cursor-grok-4.6-high/frontier/high | plan-run · closed-green · note сокращена; полный текст: journal.jsonl:26 |

## Owner Gates

- P1.1: known deviation
- P2.6: known deviation
- P2.7: known deviation
- P2.8: known deviation
