# VLT-LOAD — Гейт-политика: SLO → пороги k6 / perf-assert → P8-минимум (P1.4)

> Артефакт item'а **P1.4**. Превращает 81 обязательство `slo.md` (P1.3) в **машинно-проверяемые**
> выражения и выводит из них **список P8-минимума** — набор метрик-seam'ов, без которых фазы P3–P7
> не имеют опоры для вердикта «SLO нарушен» (D9/D16).
>
> **Чего здесь нет (по scope):** k6-скриптов и YAML workflow — это **фаза P2**; реализации
> метрик в `packages/*/src` — это **фаза P8**. Здесь — спецификация: имя метрики, выражение,
> политика, класс гейта.
>
> **Синтаксис k6 — только верифицированный:** `findings/P1-rag-slo-ci-gate.md` (RAG-6 — встроенные
> метрики, теги, `abortOnFail`, exit 99) и `findings/P1.4-rag-k6-threshold-syntax.md` (RAG-8 —
> агрегации кастомных метрик, границы применимости порогов k6).

---

## 0. Рамки гейта

### 0.1 Два класса (D14) — и почему не один

| Класс | Что в нём | Порог | На `ubuntu-latest` |
|:--|:--|:--|:--|
| **A** | error-rate, checks-rate, доли 409/429, throughput при **фиксированной** конкуренции, потолок пиковой памяти (RSS), счётчики unbounded-scan (батч/lock-set/backlog) | **абсолютный, жёсткий** | **блокирующий** — метрики шумоустойчивы (не зависят от такта CPU раннера) |
| **B** | латентность `p50/p95/p99`, TTFB, queue-time | **широкий абсолютный guardrail** (≈150 % цели тира) **плюс** относительная регрессия к baseline `p95 ≤ baseline × (1 + δ)` | **advisory** (D15) — пока нет perf-раннера |

Соблазн «объявить всё класс A и гейтить строго» отклонён: RAG-6 прямо говорит, что абсолютный
латентный порог на shared-раннере флапает, а флапающий гейт через месяц стоит с
`continue-on-error: true`. Честный advisory лучше декоративного блокирующего.

### 0.2 Две плоскости оценки — и почему их две (RAG-8b)

**k6 видит только клиентскую сторону.** Ни RSS PHP-воркера, ни размер батча sweeper'а, ни длина
lock-set'а `claimDue()`, ни backlog outbox клиенту не наблюдаемы — а это **весь серверный класс A**
по D14. Значит гейт физически не помещается в один инструмент:

| Плоскость | Инструмент | Что вычисляет | Провал |
|:--|:--|:--|:--|
| **k6-thresholds** | `options.thresholds` в сценарии | клиентские метрики: латентность, ошибки, checks, 409/429, throughput на стрим и агрегатный | **exit code 99** — шаг Actions падает сам [RAG:✅ 2026-07-14 RAG-6] |
| **perf-assert** | отдельный шаг после прогона: PromQL по Prometheus-снимку + `summary.json` | серверные метрики: RSS, батч за тик, lock-set, backlog, длительность тика фоновой команды; **сравнение с baseline** (класс B) | свой exit-code |

Обе плоскости в классе A — **блокирующие**. Разделение — не ослабление D14, а его честная
реализация: иначе половина класса A оказалась бы «гейтом», который никто не вычисляет.
**D-запись-кандидат** (в Decision Log — через P1.5/plan-design): «Гейт нагрузки = две плоскости
(k6-thresholds + perf-assert по Prometheus); серверные обязательства класса A порогами k6
невыразимы (RAG-8b)».

### 0.3 Где что гоняется (следствие Q1/Q3)

- **CI (`ubuntu-latest`), профиль `S0`** — только **класс A**, и только те его строки, объёмы которых
  влезают в раннер (`reference-env.md` §4.1: без cpuset, объёмы ≈ 1/50 от S, файл ≤ 256 MiB). Класс B
  на S0 **пишет baseline и не валит PR** (advisory).
- **Lab-box (тиры S/M, cpuset-конверт)** — авторитетные латентные числа; здесь класс B становится
  блокирующим (условие перехода — §4.4).
- **Тир L** — экстраполяция (`reference-env.md` §3); гейта в CI не имеет вовсе.

---

## 1. Механика k6 — верифицированный синтаксис

Всё ниже — из RAG-6/RAG-8; ничего «по памяти».

```js
// иллюстрация, НЕ файл в load/ — скрипты пишет фаза P2
export const options = {
  thresholds: {
    // тегированный порог: сценарий × тир
    'http_req_duration{scenario:tree_list,tier:M}': ['p(95)<380'],
    // checks САМИ не меняют exit-code — без этого порога провал check() не валит CI
    checks: ['rate>0.99'],
    // ранний обрыв только с задержкой: иначе ramp-up-всплеск убивает прогон до steady state
    'http_req_failed{scenario:tree_list}': [
      { threshold: 'rate<0.005', abortOnFail: true, delayAbortEval: '30s' },
    ],
  },
};
```

| Факт | Значение для гейта |
|:--|:--|
| Брейч порога → **exit code 99** | glue-код не нужен, шаг Actions падает сам [RAG-6] |
| `checks` сами **никогда** не меняют exit-code | порог `checks: ['rate>0.99']` — **обязателен** в каждом профиле [RAG-6] |
| `abortOnFail` — всегда с `delayAbortEval: '30s'` | без задержки rate-пороги убивают прогон на разгоне [RAG-6] |
| Агрегации по типу метрики: `Counter` → `count`/`rate`; `Gauge` → `value`; `Rate` → `rate`; `Trend` → `avg`/`min`/`med`/`max`/`p(N)` | throughput выражается **`Trend`** (`med>30`), а не `Counter` — у Counter нет медианы [RAG-8] |
| Теги в ключе порога одинаково работают для встроенных и кастомных метрик | ось «сценарий × тир» — тегами, а не копированием сценариев [RAG-8] |
| Ключи агрегатов в JSON-сводке — те же строки: `metrics.<m>.values["p(95)"]` | контракт baseline-артефакта (§4) [RAG-8] |
| `handleSummary(data)` вместо `--summary-export` | позволяет дописать fingerprint окружения и commit SHA в тот же JSON [RAG-8] |

**Кастомные метрики, на которые ссылается таблица §3** (объявляются в P2, здесь — контракт имён):

| Метрика k6 | Тип | Смысл | Теги |
|:--|:--|:--|:--|
| `stream_throughput_mibs` | `Trend` | MiB/s **на один стрим** (считается в скрипте: байты / длительность) | `scenario`, `tier` |
| `upload_conflict_total` | `Counter` | 409 на NOWAIT advisory-lock — **не ошибка** (§0.4 `slo.md`) | `scenario`, `tier` |
| `throttled_total` | `Counter` | 429 `throttle:vaulter-public` — **не ошибка** | `scenario`, `tier` |
| `ttfb_ms` | `Trend` | время до первого байта стрима/экспорта | `scenario`, `tier` |
| `broadcast_lag_ms` | `Trend` | коммент → приход по WS соседу (queue-time fan-out) | `tier` |

Агрегатный throughput берётся из **встроенных** `data_sent` / `data_received` (`Counter` → `rate`,
байт/с) — отдельная метрика не нужна.

---

## 2. Сквозные пороги класса A (действуют в каждом профиле, не дублируются построчно)

Они реализуют error-budget §0.4 `slo.md` — включая то, что **409 и 429 не ошибки**, а протокол.

| # | k6-выражение | Почему абсолютный порог законен |
|:--|:--|:--|
| G-1 | `'http_req_failed{class:interactive}': [{threshold:'rate<0.005', abortOnFail:true, delayAbortEval:'30s'}]` | доля 5xx не зависит от такта CPU раннера |
| G-2 | `'http_req_failed{class:streaming}': ['rate<0.01']` | то же |
| G-3 | `checks: ['rate>0.99']` | без него провалившиеся `check()` не валят CI [RAG-6] |
| G-4 | `'upload_conflict_total{scenario:tus_patch}': ['count<...']` — порог = 5 % от числа PATCH профиля | 409 — норма протокола, но **бюджетируемая**: рост доли = контеншн, а не «клиент так захотел» |
| G-5 | `'throttled_total{scenario:share_download}': ['count<...']` — порог = 1 % от числа запросов профиля | доля 429 выше 1 % означает, что **сценарий врёт про профиль клиента** |
| G-6 | perf-assert: `vaulter_failed_jobs_total` прирост за прогон ≤ 0,1 % доставок | `failed_jobs` [RAG:✅ 2026-07-13 RAG-5] |

> 409/429 **не входят** в `http_req_failed`: в сценариях P2 они обязаны быть помечены
> `responseCallback`-ом как ожидаемые, иначе G-1/G-2 будут валить гейт на штатном протоколе.

---

## 3. Таблица гейтов: SLO → выражение

**Строк больше, чем SLO** — и это осознанно: одно обязательство может распадаться на две метрики
**разных классов** (у стриминговой операции throughput/RSS — класс A, латентность чанка — класс B).
Паритет `SLO ↔ гейты` держится по **множеству ID**, а не по числу строк.

Обозначения: `δ` — допуск относительной регрессии, **`δ = 10 %` `[гипотеза, уточняется в P2 по факту
CV]`** (§4.3). «Guardrail» — широкий абсолютный порог ≈150 % от цели тира (ловит катастрофу, не
флапает). Prometheus-метрика в последней колонке — **вход в P8-минимум** (§5), с типом и лейблами.

### 3.1 Интерактивные операции (SLO-01…39) — класс B

Все несут `tier`-тег; сквозные пороги §2 применяются поверх. Baseline-политика у всех одна:
`p95 ≤ baseline × (1 + δ)`, медиана из N=3 прогонов, CV-guard (§4.3) — в колонке она названа коротко.

| SLO | Класс | k6-метрика + тег | Выражение порога (guardrail) | abortOnFail / delayAbortEval | Baseline-политика | Prometheus-метрика (P8-минимум) | Гейт |
|:--|:--|:--|:--|:--|:--|:--|:--|
| SLO-01 | B | `http_req_duration{scenario:point_read,tier:S}` | `p(95)<120` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds` (histogram; `operation`,`tier`,`outcome`) | advisory |
| SLO-02 | B | `http_req_duration{scenario:point_read,tier:M}` | `p(95)<90` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds` | advisory |
| SLO-03 | B | `http_req_duration{scenario:point_read,tier:L}` | `p(95)<120` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds` | advisory (лаб. — не CI) |
| SLO-04 | B | `http_req_duration{scenario:tree_list,tier:S}` | `p(95)<300` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_tree_query_duration_seconds` (histogram; `query_kind`,`tier`) + `vaulter_tree_cte_anchors` (histogram; `tier`) | advisory |
| SLO-05 | B | `http_req_duration{scenario:tree_list,tier:M}` | `p(95)<380` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_tree_query_duration_seconds`, `vaulter_tree_cte_anchors` | advisory |
| SLO-06 | B | `http_req_duration{scenario:tree_list,tier:L}` | `p(95)<750` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_tree_query_duration_seconds`, `vaulter_tree_cte_anchors` | advisory (лаб.) |
| SLO-07 | B | `http_req_duration{scenario:search,tier:S}` | `p(95)<600` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_tree_query_duration_seconds{query_kind="search"}` | advisory |
| SLO-08 | B | `http_req_duration{scenario:search,tier:M}` | `p(95)<750` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_tree_query_duration_seconds{query_kind="search"}` | advisory |
| SLO-09 | B | `http_req_duration{scenario:search,tier:L}` | `p(95)<1350` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_tree_query_duration_seconds{query_kind="search"}` | advisory (лаб.) |
| SLO-10 | B | `http_req_duration{scenario:tree_mutate,tier:S}` | `p(95)<180` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="tree_mutate"}` | advisory |
| SLO-11 | B | `http_req_duration{scenario:tree_mutate,tier:M}` | `p(95)<150` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="tree_mutate"}` | advisory |
| SLO-12 | B | `http_req_duration{scenario:tree_mutate,tier:L}` | `p(95)<225` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="tree_mutate"}` | advisory (лаб.) |
| SLO-13 | B | `http_req_duration{scenario:subtree_mutate,tier:S}` | `p(95)<900` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_subtree_nodes_touched` (histogram; `operation`) + `vaulter_http_request_duration_seconds` | advisory |
| SLO-14 | B | `http_req_duration{scenario:subtree_mutate,tier:M}` | `p(95)<750` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_subtree_nodes_touched`, `vaulter_http_request_duration_seconds` | advisory |
| SLO-15 | B | `http_req_duration{scenario:subtree_mutate,tier:L}` | `p(95)<1050` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_subtree_nodes_touched`, `vaulter_http_request_duration_seconds` | advisory (лаб.) |
| SLO-16 | B | `http_req_duration{scenario:activity_feed,tier:S}` | `p(95)<225`; с JSON-фильтром `http_req_duration{scenario:activity_feed_filtered,tier:S}: p(95)<600` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_activity_feed_duration_seconds` (histogram; `filtered`,`tier`) + `vaulter_activity_write_total` (counter) | advisory |
| SLO-17 | B | `http_req_duration{scenario:activity_feed,tier:M}` | `p(95)<225`; фильтр: `p(95)<675` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_activity_feed_duration_seconds`, `vaulter_activity_write_total` | advisory |
| SLO-18 | B | `http_req_duration{scenario:activity_feed,tier:L}` | `p(95)<375`; фильтр: `p(95)<1350` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_activity_feed_duration_seconds`, `vaulter_activity_write_total` | advisory (лаб.) |
| SLO-19 | B | `http_req_duration{scenario:doc_read,tier:S}` | `p(95)<225` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="doc_read"}` | advisory |
| SLO-20 | B | `http_req_duration{scenario:doc_read,tier:M}` | `p(95)<180` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="doc_read"}` | advisory |
| SLO-21 | B | `http_req_duration{scenario:doc_read,tier:L}` | `p(95)<270` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="doc_read"}` | advisory (лаб.) |
| SLO-22 | B | `http_req_duration{scenario:doc_write,tier:S}` | `p(95)<300` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="doc_write"}` | advisory |
| SLO-23 | B | `http_req_duration{scenario:doc_write,tier:M}` | `p(95)<240` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="doc_write"}` | advisory |
| SLO-24 | B | `http_req_duration{scenario:doc_write,tier:L}` | `p(95)<375` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_http_request_duration_seconds{operation="doc_write"}` | advisory (лаб.) |
| SLO-25 | B | `ttfb_ms{scenario:doc_export,tier:S}` + `http_req_duration{scenario:doc_export,tier:S}` | `p(95)<600` (TTFB) и `p(95)<2250` (полный) | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_export_duration_seconds` (histogram; `format`,`tier`) | advisory |
| SLO-26 | B | `ttfb_ms{scenario:doc_export,tier:M}` + `http_req_duration{…,tier:M}` | `p(95)<525` и `p(95)<1800` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_export_duration_seconds` | advisory |
| SLO-27 | B | `ttfb_ms{scenario:doc_export,tier:L}` + `http_req_duration{…,tier:L}` | `p(95)<750` и `p(95)<3000` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_export_duration_seconds` | advisory (лаб.) |
| SLO-28 | B | `http_req_duration{scenario:collab_issue,tier:S}` | `p(95)<150` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_collab_session_issued_total` (counter; `driver`,`ability`) + `vaulter_http_request_duration_seconds` | advisory |
| SLO-29 | B | `http_req_duration{scenario:collab_issue,tier:M}` | `p(95)<120` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_collab_session_issued_total`, `vaulter_http_request_duration_seconds` | advisory |
| SLO-30 | B | `http_req_duration{scenario:collab_issue,tier:L}` | `p(95)<180` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_collab_session_issued_total`, `vaulter_http_request_duration_seconds` | advisory (лаб.) |
| SLO-31 | B | `http_req_duration{scenario:collab_persist,tier:S}` | `p(95)<375` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_collab_snapshot_duration_seconds` (histogram) + `vaulter_collab_room_active` (gauge) | advisory |
| SLO-32 | B | `http_req_duration{scenario:collab_persist,tier:M}` | `p(95)<300` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_collab_snapshot_duration_seconds`, `vaulter_collab_room_active` | advisory |
| SLO-33 | B | `http_req_duration{scenario:collab_persist,tier:L}` | `p(95)<450` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_collab_snapshot_duration_seconds`, `vaulter_collab_room_active` | advisory (лаб.) |
| SLO-34 | B | `http_req_duration{scenario:comment,tier:S}` + `broadcast_lag_ms{tier:S}` | `p(95)<225` (HTTP) и `p(95)<1500` (lag) | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_broadcast_queue_wait_seconds` (histogram) + `vaulter_http_request_duration_seconds` | advisory |
| SLO-35 | B | `http_req_duration{scenario:comment,tier:M}` + `broadcast_lag_ms{tier:M}` | `p(95)<180` и `p(95)<1200` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_broadcast_queue_wait_seconds` | advisory |
| SLO-36 | B | `http_req_duration{scenario:comment,tier:L}` + `broadcast_lag_ms{tier:L}` | `p(95)<300` и `p(95)<1800` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_broadcast_queue_wait_seconds` | advisory (лаб.) |
| SLO-37 | B | `http_req_duration{scenario:upload_session,tier:S}` | `p(95)<180` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_session_active` (gauge; `protocol`) + `vaulter_http_request_duration_seconds` | advisory |
| SLO-38 | B | `http_req_duration{scenario:upload_session,tier:M}` | `p(95)<150` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_session_active`, `vaulter_http_request_duration_seconds` | advisory |
| SLO-39 | B | `http_req_duration{scenario:upload_session,tier:L}` | `p(95)<225` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_session_active`, `vaulter_http_request_duration_seconds` | advisory (лаб.) |

### 3.2 Стриминговые операции (SLO-40…54) — throughput/RSS класс A, латентность чанка класс B

**Две строки на обязательство.** Абсолютный порог throughput при фиксированной конкуренции —
законный класс A (D14): он не зависит от такта CPU раннера. RSS — тоже класс A, но **вычисляется в
плоскости perf-assert** (RAG-8b): k6 не видит память сервера.

| SLO | Класс | k6-метрика + тег | Выражение порога | abortOnFail / delayAbortEval | Baseline-политика | Prometheus-метрика (P8-минимум) | Гейт |
|:--|:--|:--|:--|:--|:--|:--|:--|
| SLO-40 | A | `stream_throughput_mibs{scenario:tus_patch,tier:S}` + `data_sent` + perf-assert RSS | `med>25`, `min>15`; `'data_sent': ['rate>52428800']` (≥ 50 MiB/s агрегат); perf-assert: `max_over_time(vaulter_request_peak_memory_bytes{operation="tus_patch"}[run]) < 67108864` (64 MiB) | да / `30s` | — (абсолютный) | `vaulter_request_peak_memory_bytes` (gauge; `operation`,`tier`) + `vaulter_upload_bytes_total` (counter; `protocol`) | **блокирующий** |
| SLO-40 | B | `http_req_duration{scenario:tus_patch,tier:S}` (8-MiB чанк) | `p(95)<600` (guardrail) | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` (histogram; `protocol`,`tier`) | advisory |
| SLO-41 | A | `stream_throughput_mibs{scenario:tus_patch,tier:M}` + `data_sent` + perf-assert RSS | `med>30`, `min>18`; `'data_sent': ['rate>209715200']` (≥ 200 MiB/s); RSS < 64 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_upload_bytes_total` | **блокирующий** |
| SLO-41 | B | `http_req_duration{scenario:tus_patch,tier:M}` | `p(95)<525` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` | advisory |
| SLO-42 | A | `stream_throughput_mibs{scenario:tus_patch,tier:L}` + `data_sent` + perf-assert RSS | `med>35`, `min>21`; `'data_sent': ['rate>524288000']` (≥ 500 MiB/s); RSS < 64 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_upload_bytes_total` | блокирующий (лаб.) |
| SLO-42 | B | `http_req_duration{scenario:tus_patch,tier:L}` | `p(95)<600` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` | advisory (лаб.) |
| SLO-43 | A | `stream_throughput_mibs{scenario:s3_putpart,tier:S}` + perf-assert RSS | `med>25`, `min>15`; RSS < 160 MiB (`167772160`) | да / `30s` | — | `vaulter_request_peak_memory_bytes{operation="s3_putpart"}`, `vaulter_upload_bytes_total{protocol="s3"}` | **блокирующий** |
| SLO-43 | B | `http_req_duration{scenario:s3_putpart,tier:S}` (64-MiB часть) | `p(95)<4500` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds{protocol="s3"}` | advisory |
| SLO-44 | A | `stream_throughput_mibs{scenario:s3_putpart,tier:M}` + perf-assert RSS | `med>30`, `min>18`; RSS < 160 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_upload_bytes_total` | **блокирующий** |
| SLO-44 | B | `http_req_duration{scenario:s3_putpart,tier:M}` | `p(95)<3750` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` | advisory |
| SLO-45 | A | `stream_throughput_mibs{scenario:s3_putpart,tier:L}` + perf-assert RSS | `med>35`, `min>21`; RSS < 160 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_upload_bytes_total` | блокирующий (лаб.) |
| SLO-45 | B | `http_req_duration{scenario:s3_putpart,tier:L}` | `p(95)<3750` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` | advisory (лаб.) |
| SLO-46 | A | perf-assert RSS + `stream_throughput_mibs{scenario:s3_complete,tier:S}` | **`vaulter_request_peak_memory_bytes{operation="s3_complete"} < 167772160` — независимо от размера файла** (⚠ L-01: сегодня RSS ≈ размер файла); `med>100` | да / `30s` | — | `vaulter_request_peak_memory_bytes{operation="s3_complete"}` (gauge) + `vaulter_assemble_bytes_total` (counter) | **блокирующий** (после фикса L-01; до фикса — известный красный, §6.4) |
| SLO-46 | B | `http_req_duration{scenario:s3_complete,tier:S}` | `p(95)<9000` (500 MiB) / `p(95)<90000` (5 GiB) | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_assemble_duration_seconds` (histogram; `size_bucket`) | advisory |
| SLO-47 | A | perf-assert RSS + `stream_throughput_mibs{scenario:s3_complete,tier:M}` | RSS < 160 MiB независимо от размера файла; `med>200` | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_assemble_bytes_total` | **блокирующий** (см. §6.4) |
| SLO-47 | B | `http_req_duration{scenario:s3_complete,tier:M}` | `p(95)<6000` / `p(95)<45000` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_assemble_duration_seconds` | advisory |
| SLO-48 | A | perf-assert RSS + `stream_throughput_mibs{scenario:s3_complete,tier:L}` | RSS < 160 MiB независимо от размера файла; `med>300` | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_assemble_bytes_total` | блокирующий (лаб.) |
| SLO-48 | B | `http_req_duration{scenario:s3_complete,tier:L}` | `p(95)<4500` / `p(95)<30000` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_assemble_duration_seconds` | advisory (лаб.) |
| SLO-49 | A | `stream_throughput_mibs{scenario:single_shot,tier:S}` + perf-assert RSS | `med>25`, `min>15`; RSS < 64 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes{operation="single_shot"}`, `vaulter_upload_bytes_total{protocol="single"}` | **блокирующий** |
| SLO-49 | B | `http_req_duration{scenario:single_shot,tier:S}` (5 MiB) | `p(95)<1800` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds{protocol="single"}` | advisory |
| SLO-50 | A | `stream_throughput_mibs{scenario:single_shot,tier:M}` + perf-assert RSS | `med>30`, `min>18`; RSS < 64 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_upload_bytes_total` | **блокирующий** |
| SLO-50 | B | `http_req_duration{scenario:single_shot,tier:M}` | `p(95)<1500` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` | advisory |
| SLO-51 | A | `stream_throughput_mibs{scenario:single_shot,tier:L}` + perf-assert RSS | `med>35`, `min>21`; RSS < 64 MiB | да / `30s` | — | `vaulter_request_peak_memory_bytes`, `vaulter_upload_bytes_total` | блокирующий (лаб.) |
| SLO-51 | B | `http_req_duration{scenario:single_shot,tier:L}` | `p(95)<1800` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_upload_chunk_duration_seconds` | advisory (лаб.) |
| SLO-52 | A | `stream_throughput_mibs{scenario:download,tier:S}` + `data_received` + perf-assert RSS и потолок стримов | `med>30`, `min>18`; `'data_received': ['rate>62914560']` (≥ 60 MiB/s); RSS < 16 MiB (`16777216`); `vaulter_download_stream_active ≤ pm.max_children` | да / `30s` | — | `vaulter_download_stream_active` (gauge) + `vaulter_download_bytes_total` (counter) + `vaulter_request_peak_memory_bytes{operation="download"}` | **блокирующий** |
| SLO-52 | B | `ttfb_ms{scenario:download,tier:S}` | `p(95)<375` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_download_ttfb_seconds` (histogram; `tier`) | advisory |
| SLO-53 | A | `stream_throughput_mibs{scenario:download,tier:M}` + `data_received` + perf-assert | `med>40`, `min>24`; `rate>262144000` (≥ 250 MiB/s); RSS < 16 MiB | да / `30s` | — | `vaulter_download_stream_active`, `vaulter_download_bytes_total`, `vaulter_request_peak_memory_bytes` | **блокирующий** |
| SLO-53 | B | `ttfb_ms{scenario:download,tier:M}` | `p(95)<300` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_download_ttfb_seconds` | advisory |
| SLO-54 | A | `stream_throughput_mibs{scenario:download,tier:L}` + `data_received` + perf-assert | `med>50`, `min>30`; `rate>629145600` (≥ 600 MiB/s); RSS < 16 MiB | да / `30s` | — | `vaulter_download_stream_active`, `vaulter_download_bytes_total` | блокирующий (лаб.) |
| SLO-54 | B | `ttfb_ms{scenario:download,tier:L}` | `p(95)<450` | нет / — | `baseline`×(1+δ), медиана-3 | `vaulter_download_ttfb_seconds` | advisory (лаб.) |

> **TTFB стримов — класс B, а не A**, хотя соблазн обратный: TTFB — это латентность, и на shared-раннере
> она шумит ровно так же, как p95 интерактива. Обязательство «TTFB не деградирует, пока активных
> стримов ≤ квоты §0.1 `slo.md`» проверяется **в плоскости perf-assert** через
> `vaulter_download_stream_active` (класс A): это счётчик, а не время.

### 3.3 Фоновые операции (SLO-55…81) — класс A, плоскость perf-assert

У фоновых операций **нет клиентского входа** — k6 их не запускает и не измеряет (кроме SLO-55…57,
встроенных в мутацию). Гейт целиком в плоскости perf-assert: PromQL по снимку прогона. Все —
профиль `regression`/`lab` (объёмы «грязи» не помещаются в CI, §6.2).

| SLO | Класс | k6-метрика + тег | Выражение порога (perf-assert, PromQL) | abortOnFail / delayAbortEval | Baseline-политика | Prometheus-метрика (P8-минимум) | Гейт |
|:--|:--|:--|:--|:--|:--|:--|:--|
| SLO-55 | A | — (встроена в мутацию; косвенно `http_req_duration{scenario:tree_mutate}`) | `histogram_quantile(0.95, vaulter_outbox_stage_duration_seconds) < 0.010` | нет / — | — | `vaulter_outbox_stage_duration_seconds` (histogram) | **блокирующий** |
| SLO-56 | A | — | `… < 0.008` | нет / — | — | `vaulter_outbox_stage_duration_seconds` | **блокирующий** |
| SLO-57 | A | — | `… < 0.012` | нет / — | — | `vaulter_outbox_stage_duration_seconds` | блокирующий (лаб.) |
| SLO-58 | A | — | `histogram_quantile(0.95, vaulter_outbox_relay_duration_seconds) < 1.5` **и** `deriv(vaulter_outbox_backlog[30m]) <= 0` **и** `rate(vaulter_outbox_relayed_total[5m]) > 200` | нет / — | — | `vaulter_outbox_backlog` (gauge), `vaulter_outbox_relay_duration_seconds` (histogram), `vaulter_outbox_relayed_total` (counter), `vaulter_outbox_endpoints_scanned_total` (counter — seam L-05) | блокирующий (regression) |
| SLO-59 | A | — | `… < 1.0`; `rate(vaulter_outbox_relayed_total[5m]) > 800`; backlog не растёт | нет / — | — | те же | блокирующий (regression) |
| SLO-60 | A | — | `… < 1.0`; `rate(...) > 2000`; дренаж 2 млн ≤ 20 мин | нет / — | — | те же | блокирующий (лаб.) |
| SLO-61 | A | — | `histogram_quantile(0.95, vaulter_webhook_queue_wait_seconds) < 5` **и** `histogram_quantile(0.95, vaulter_webhook_delivery_duration_seconds) < 2` **и** `rate(vaulter_webhook_delivered_total[5m]) > 50` | нет / — | — | `vaulter_webhook_queue_wait_seconds` (histogram), `vaulter_webhook_delivery_duration_seconds` (histogram; `outcome`), `vaulter_webhook_delivered_total` (counter), `vaulter_failed_jobs_total` (counter) | блокирующий (regression) |
| SLO-62 | A | — | `queue_wait p95 < 3`; `delivery p95 < 2`; `rate > 200` | нет / — | — | те же | блокирующий (regression) |
| SLO-63 | A | — | `queue_wait p95 < 2`; `delivery p95 < 2`; `rate > 600` | нет / — | — | те же | блокирующий (лаб.) |
| SLO-64 | A | — | `histogram_quantile(0.95, vaulter_webhook_retry_tick_seconds) < 2.0` **и** `max_over_time(vaulter_webhook_retry_lockset_rows[run]) <= 1000` **и** `max_over_time(vaulter_command_peak_memory_bytes{command="webhook:retry"}[run]) < 268435456` | нет / — | — | `vaulter_webhook_retry_tick_seconds` (histogram), **`vaulter_webhook_retry_lockset_rows` (histogram — seam L-04)**, `vaulter_command_peak_memory_bytes` (gauge; `command`) | блокирующий (regression) |
| SLO-65 | A | — | `tick p95 < 1.5`; lock-set ≤ 1 000; RSS < 256 MiB | нет / — | — | те же | блокирующий (regression) |
| SLO-66 | A | — | `tick p95 < 1.5`; lock-set ≤ 1 000; RSS < 256 MiB (на 500 тыс. due-строк) | нет / — | — | те же | блокирующий (лаб.) |
| SLO-67 | A | — | `vaulter_prune_duration_seconds < 60` **и** `max(vaulter_pg_lock_wait_seconds) < 1` | нет / — | — | `vaulter_prune_duration_seconds` (histogram), `vaulter_prune_rows_total` (counter), `vaulter_pg_lock_wait_seconds` (histogram) | блокирующий (regression) |
| SLO-68 | A | — | `< 60`; блокировок > 1 с — ноль | нет / — | — | те же | блокирующий (regression) |
| SLO-69 | A | — | `< 120`; блокировок > 1 с — ноль | нет / — | — | те же | блокирующий (лаб.) |
| SLO-70 | A | — | `histogram_quantile(0.95, vaulter_sweep_tick_seconds) < 5` **и** `max_over_time(vaulter_sweep_batch_rows[run]) <= 1000` **и** `vaulter_command_peak_memory_bytes{command="uploads:sweep"} < 268435456` | нет / — | — | `vaulter_sweep_tick_seconds` (histogram), **`vaulter_sweep_batch_rows` (histogram — seam L-02)**, `vaulter_sweep_expired_total` / `vaulter_sweep_skipped_total` (counter), `vaulter_command_peak_memory_bytes` | блокирующий (regression) |
| SLO-71 | A | — | `tick p95 < 10`; батч ≤ 5 000; RSS < 256 MiB | нет / — | — | те же | блокирующий (regression) |
| SLO-72 | A | — | `tick p95 < 15`; батч ≤ 10 000; RSS < 256 MiB; дренаж 1 млн ≤ 2 ч при cron 1/мин | нет / — | — | те же | блокирующий (лаб.) |
| SLO-73 | A | — | `vaulter_purge_duration_seconds < 60` **и** `max_over_time(vaulter_purge_batch_nodes[run]) <= 1000` | нет / — | — | `vaulter_purge_duration_seconds` (histogram), `vaulter_purge_batch_nodes` (histogram), `vaulter_purge_nodes_total` (counter) | блокирующий (regression) |
| SLO-74 | A | — | `< 90`; батч ≤ 5 000 | нет / — | — | те же | блокирующий (regression) |
| SLO-75 | A | — | `< 120`; батч ≤ 10 000 | нет / — | — | те же | блокирующий (лаб.) |
| SLO-76 | A | — | `vaulter_gc_duration_seconds < 300` **и** `max_over_time(vaulter_gc_batch_rows[run]) <= 1000` **и** `rate(vaulter_gc_rows_scanned_total[1m]) > 2000` **и** `rate(vaulter_gc_objects_deleted_total[1m]) > 100` **и** `vaulter_command_peak_memory_bytes{command="blobs:gc"} < 268435456` | нет / — | — | `vaulter_gc_duration_seconds` (histogram; `phase`), **`vaulter_gc_batch_rows` (histogram — seam L-03)**, `vaulter_gc_rows_scanned_total` / `vaulter_gc_objects_deleted_total` (counter), `vaulter_command_peak_memory_bytes` | блокирующий (regression) |
| SLO-77 | A | — | `< 1800`; батч ≤ 5 000; обход > 5 000/с; удаление > 200/с; RSS < 256 MiB | нет / — | — | те же | блокирующий (regression) |
| SLO-78 | A | — | дренаж 1,5 млн ≤ 6 ч; батч ≤ 10 000; обход > 10 000/с; удаление > 400/с; RSS < 256 MiB | нет / — | — | те же | блокирующий (лаб.) |
| SLO-79 | A | — | `vaulter_reconcile_duration_seconds < 1800` **и** `rate(vaulter_reconcile_objects_total[1m]) > 1000` **и** `vaulter_command_peak_memory_bytes{command="blobs:reconcile"} < 536870912` | нет / — | — | `vaulter_reconcile_duration_seconds` (histogram), `vaulter_reconcile_objects_total` (counter), `vaulter_command_peak_memory_bytes` | advisory (§6.3) |
| SLO-80 | A | — | `< 7200`; > 2 000 объектов/с; RSS < 512 MiB | нет / — | — | те же | advisory (§6.3) |
| SLO-81 | A | — | `< 14400`; > 4 000 объектов/с; RSS < 512 MiB | нет / — | — | те же | advisory (§6.3) |

> `[run]` в PromQL — окно, равное длительности прогона (подставляется perf-assert-скриптом из
> `summary.json`: `state.testRunDurationMs`). Это не k6-синтаксис и в пороги k6 не попадает.

---

## 4. Baseline как версионированный артефакт (класс B)

### 4.1 Что это за файл

`load/baselines/<profile>-<tier>.json` — коммитится в репозиторий (а не живёт в GH-cache): смещение
baseline обязано проходить **code review**, иначе «регрессия» тихо становится новой нормой.

Содержимое — `handleSummary(data)`-вывод плюс шапка `meta` [RAG-8]:

| Поле `meta` | Смысл |
|:--|:--|
| `commit` | SHA коммита, на котором снят baseline |
| `profile` | `smoke-perf` / `regression` |
| `tier` | `S0` / `S` / `M` |
| `env_fingerprint` | хэш от: cpuset-раскладки, `mem_limit`, версий PHP/PG/MinIO/k6-бинарника (с xk6-расширениями), ID датасета seed'а |
| `dataset_id` | версия seed-набора (`reference-env.md` §2) |
| `runs` | сколько прогонов усреднено (медиана из N, §4.3) |
| `metrics` | `data.metrics` — агрегаты по тем же ключам, что в порогах: `values["p(95)"]`, `values.count`, `values.rate` |

### 4.2 Как сравнивается

Шаг `perf-assert`: для каждой строки класса B —
`p95(current) ≤ p95(baseline) × (1 + δ)`. **Baseline с другим `env_fingerprint` не сравнивается**:
вердикт «несравнимо», прогон помечается advisory-жёлтым, гейт не срабатывает ни в одну сторону.
Сравнить прогон на S0-раннере с baseline lab-box'а — значит получить случайное число.

### 4.3 δ и CV-guard

- **δ = 10 %** — `[гипотеза, уточняется в P2 по факту CV]`. Число не подобрано «чтобы ничего не
  падало»: оно обязано быть выведено из измеренного коэффициента вариации окружения, а CV на момент
  P1 **неизвестен**. Item P2 обязан его измерить (5 прогонов) и подтвердить или пересмотреть δ.
- **Медиана из N=3** прогонов [RAG:✅ 2026-07-14 RAG-6] — компромисс устойчивости и времени CI;
  при доказанном CV < 5–10 % достаточно одного прогона.
- **CV-guard:** если CV ключевой метрики за прогоны > 10 % — вердикт **«недостоверно»**, а не
  «провал». Гейт, который валит PR на собственном шуме, будет отключён — и вместе с ним уедет
  класс A, который шум как раз пережил бы.

### 4.4 Кто обновляет и когда класс B становится блокирующим

- Обновление baseline — **nightly-прогон на `main`** после зелёного гейта; PR-у для пере-базирования
  нужна явная метка (`perf-rebaseline`) и ревью диффа baseline-файла.
- **Переход класса B в блокирующий** (D15) — при выполнении любого из двух условий:
  1. появился dedicated / self-hosted perf-раннер (`open-questions.md` **Q1**), **или**
  2. доказан **CV < 10 %** по 5 прогонам на текущем раннере (измеряется в **P2**).

---

## 5. P8-минимум — метрики-seam'ы, без которых P3–P7 не могут вынести вердикт

Выведен **из колонки «Prometheus-метрика»** таблицы §3, а не изобретён фазой P8 (D9/D16). Это ТЗ для
P8: тип и лейблы обязательны. Отсутствие любой строки ⇒ соответствующие SLO **не проверяемы**, и
фазы P3–P7 по ним вердикт выносить не имеют права.

### 5.1 HTTP и горячие пути (опора SLO-01…39)

| Метрика | Тип | Лейблы | Питает |
|:--|:--|:--|:--|
| `vaulter_http_request_duration_seconds` | histogram | `operation`, `tier`, `outcome` | SLO-01…03, 10…12, 19…24, 28…30, 37…39 |
| `vaulter_tree_query_duration_seconds` | histogram | `query_kind` (`children`/`subtree`/`breadcrumb`/`search`), `tier` | SLO-04…09 |
| `vaulter_tree_cte_anchors` | histogram | `tier` | SLO-04…09 — **seam L-06**: сколько рекурсивных CTE породил один листинг |
| `vaulter_subtree_nodes_touched` | histogram | `operation` | SLO-13…15 — **seam L-06**: размер поддерева в `assertNoCycle()` |
| `vaulter_activity_feed_duration_seconds` | histogram | `filtered` (bool), `tier` | SLO-16…18 (цена GIN `context jsonb_path_ops` под записью) |
| `vaulter_activity_write_total` | counter | `verb` | SLO-16…18 (фоновая запись, без которой фид меряет фикцию) |
| `vaulter_export_duration_seconds` | histogram | `format`, `tier` | SLO-25…27 |
| `vaulter_collab_session_issued_total` | counter | `driver`, `ability` | SLO-28…30 (**нет cap на issuance** — вход в P5/P7) |
| `vaulter_collab_snapshot_duration_seconds` | histogram | `tier` | SLO-31…33 |
| `vaulter_collab_room_active` | gauge | — | SLO-31…33 |
| `vaulter_broadcast_queue_wait_seconds` | histogram | — | SLO-34…36 (комментарий доехал до соседа) |
| `vaulter_upload_session_active` | gauge | `protocol` | SLO-37…39 |

### 5.2 Стриминг и память (опора SLO-40…54) — **сердце P8-минимума**

| Метрика | Тип | Лейблы | Питает |
|:--|:--|:--|:--|
| **`vaulter_request_peak_memory_bytes`** | **gauge** | `operation`, `tier` | SLO-40…54. **Без этой метрики L-01 непроверяем**: именно она отличает «стримит» от «держит файл в RAM» (`memory_get_peak_usage(true)` на завершении запроса) |
| `vaulter_upload_bytes_total` | counter | `protocol` (`tus`/`s3`/`single`) | SLO-40…51 (throughput) |
| `vaulter_upload_chunk_duration_seconds` | histogram | `protocol`, `tier` | SLO-40…51 (класс B) |
| `vaulter_upload_conflict_total` | counter | `protocol` | error-budget §0.4 `slo.md` (409 — не ошибка, но бюджетируется) |
| `vaulter_assemble_bytes_total` / `vaulter_assemble_duration_seconds` | counter / histogram | `size_bucket` | SLO-46…48 (`complete()` — до и после фикса L-01) |
| `vaulter_download_bytes_total` | counter | `source` (`file`/`version`/`share`) | SLO-52…54 |
| **`vaulter_download_stream_active`** | **gauge** | — | SLO-52…54. Потолок = `pm.max_children` (`slo.md` §0.1): показывает **очередь за FPM-воркером**, которую p95 не показывает |
| `vaulter_download_ttfb_seconds` | histogram | `tier` | SLO-52…54 (класс B) |
| `vaulter_throttled_total` | counter | `route` | 429-бюджет §0.4 `slo.md` |

### 5.3 Фон, очереди, unbounded-scan'ы (опора SLO-55…81)

| Метрика | Тип | Лейблы | Питает |
|:--|:--|:--|:--|
| `vaulter_outbox_backlog` | gauge | — | SLO-58…60 («backlog не растёт») |
| `vaulter_outbox_stage_duration_seconds` | histogram | — | SLO-55…57 |
| `vaulter_outbox_relay_duration_seconds` / `vaulter_outbox_relayed_total` | histogram / counter | — | SLO-58…60 |
| **`vaulter_outbox_endpoints_scanned_total`** | counter | — | SLO-58…60 — **seam L-05**: сколько endpoint'ов PHP перебрал на одну outbox-строку |
| `vaulter_webhook_queue_wait_seconds` / `vaulter_webhook_delivery_duration_seconds` | histogram | `outcome` | SLO-61…63 [RAG-5] |
| `vaulter_webhook_delivered_total` / `vaulter_failed_jobs_total` | counter | `outcome` | SLO-61…63, G-6 |
| `vaulter_webhook_retry_tick_seconds` | histogram | — | SLO-64…66 |
| **`vaulter_webhook_retry_lockset_rows`** | histogram | — | SLO-64…66 — **seam L-04**: сколько строк `claimDue()` залочил за тик |
| `vaulter_sweep_tick_seconds` | histogram | — | SLO-70…72 |
| **`vaulter_sweep_batch_rows`** | histogram | — | SLO-70…72 — **seam L-02**: размер батча `SweepUploadsCommand` за тик |
| `vaulter_sweep_expired_total` / `vaulter_sweep_skipped_total` | counter | — | SLO-70…72 |
| `vaulter_gc_duration_seconds` | histogram | `phase` (`mark`/`sweep`) | SLO-76…78 |
| **`vaulter_gc_batch_rows`** | histogram | — | SLO-76…78 — **seam L-03**: чекпоинт `->cursor()` |
| `vaulter_gc_rows_scanned_total` / `vaulter_gc_objects_deleted_total` | counter | — | SLO-76…78 (**два числа**, `slo.md` §3.8) |
| `vaulter_purge_duration_seconds` / `vaulter_purge_batch_nodes` / `vaulter_purge_nodes_total` | histogram / histogram / counter | — | SLO-73…75 |
| `vaulter_prune_duration_seconds` / `vaulter_prune_rows_total` | histogram / counter | — | SLO-67…69 |
| `vaulter_reconcile_duration_seconds` / `vaulter_reconcile_objects_total` | histogram / counter | — | SLO-79…81 |
| **`vaulter_command_peak_memory_bytes`** | **gauge** | `command` | SLO-64…81 — потолок RSS **любой** фоновой команды пакета |
| `vaulter_pg_lock_wait_seconds` | histogram | — | SLO-67…69 («ни одной блокировки > 1 с») |

### 5.4 Health

| Seam | Смысл |
|:--|:--|
| **`/up` — liveness + readiness** (БД / S3 / очередь) | **часть P8-минимума**: без него харнесс P2 не отличает «упало под нагрузкой» от «ещё не поднялось». Отсутствует в пакете сегодня (`plan.md` §1.2). |

### 5.5 Сверка с блупринтом P8 (расхождения объяснены — требование Required Reads)

| Метрика блупринта `phases/P8.md` | Судьба в P8-минимуме |
|:--|:--|
| `upload_bytes_total`, `upload_session_active`, `patch_conflict_total`, `gc_swept_total`/`gc_duration_seconds`, `sweep_expired_total`/`sweep_skipped_total`, `outbox_backlog`, `webhook_delivery_seconds`, `tree_query_seconds`, `collab_session_active` | **вошли** (с префиксом `vaulter_`, единицами `_seconds`/`_bytes` и лейблами — конвенция Prometheus) |
| `blob_dedup_hit_total` | **выпала из минимума**: ни один SLO на неё не опирается (дедуп-хит — свойство датасета, не обязательство). Полезна фазе P5 (L-07, storm дедупа) ⇒ переезжает в **полное** закрытие P8, не в минимум |
| `webhook_dlq_total` | **выпала из минимума**: DLQ-семантика — предмет фазы **P6**, SLO-64…66 гейтятся по lock-set и throughput. Вернётся, если P6 заведёт обязательство на DLQ |
| — (в блупринте не было) | **добавлены 6 seam'ов, без которых L-долги непроверяемы**: `vaulter_request_peak_memory_bytes` (L-01), `vaulter_sweep_batch_rows` (L-02), `vaulter_gc_batch_rows` (L-03), `vaulter_webhook_retry_lockset_rows` (L-04), `vaulter_outbox_endpoints_scanned_total` (L-05), `vaulter_tree_cte_anchors` + `vaulter_subtree_nodes_touched` (L-06). Это и есть цена вывода списка **из SLO**, а не из ощущения фазы P8 |

---

## 6. Что НЕ гейтим и почему

### 6.1 Латентность (весь класс B) — advisory до perf-раннера (D15)

SUT и генератор у vaulter крутятся **на одном** GH-раннере (RAG-6: смягчающая развилка «SUT в
контролируемом окружении» не работает). Абсолютный латентный порог тут флапает; относительный —
требует стабильного baseline того же fingerprint'а, которого на shared-раннере нет. Класс B **пишет
baseline и не валит PR**. Условие перехода — §4.4 / **Q1**.

### 6.2 Всё, что не помещается в `ubuntu-latest` (D17-кандидат, Q3)

| Не гейтится в CI | Причина |
|:--|:--|
| Тиры **S / M / L** целиком | бенч-стенд S = 7 ядер, раннер даёт 4 vCPU; непересекающиеся cpuset'ы (E-4) не строятся (`reference-env.md` §4.1). CI гоняет **S0** |
| 5-ГиБ фикстуры (SLO-46…48 в max-профиле) | фикстура + её копия в MinIO не помещаются на ~14 ГиБ диска раннера. На S0 max файла — **256 MiB** |
| «Грязные» объёмы фоновых (1 млн просроченных сессий, 1,5 млн orphan, 2 млн outbox) | seed таких объёмов не помещается ни по диску, ни по времени лейна. SLO-58…81 — профиль `regression`/`lab` |
| Тир **L** вообще | экстраполяция без прогона (`reference-env.md` §3) |

### 6.3 Что не гейтится нигде — и почему это не лазейка

| SLO | Почему не блокирующий | Что вместо |
|:--|:--|:--|
| **SLO-79…81** (reconcile) | maintenance-операция с окном в часы: «провал» тут — не регрессия кода, а окно обслуживания хоста | advisory-тренд длительности; блокирующий только потолок RSS 512 MiB |
| Интерактивный **целевой RPS** (§1 `slo.md`) | RPS = конкуренция / p95 — то есть **та же латентность под другим именем**. Гейтить его «абсолютно, как класс A» = протащить латентный шум в блокирующий класс через заднюю дверь | конкуренция в сценарии **фиксируется** (executor'ы P2), а гейтится то, что от неё не зависит: error-rate, checks, throughput стримов |
| Сквозной лаг «мутация → webhook» (вопрос 3 §6 `slo.md`) | сумма включает воркеры очереди **хоста**, которых пакет не контролирует | advisory-метрика; обязательство фиксируется в RUNBOOK (L-08) |

### 6.4 Красный, который уже известен: SLO-46…48 (L-01)

RSS-гейт `complete()` **объявлен блокирующим классом A** — и **сегодня он красный** (`slo.md` §5:
RSS ≈ размер файла). Это не повод его ослабить и не повод его не заводить: гейт включается **вместе с
dev-срезом фикса L-01** (который сам ждёт D11 — VLT-MUT `vaulter-drive` ≥ 95). До включения строка
живёт в спеке как обязательство, а не как порог, который кто-то «временно» поставил на `|| true`.
Дисциплина взята из `mutation.yml` этого репо: порог ставится **по факту** (`--min=95`, измерено, с
номером прогона и SHA в комментарии), а не как декоративный `|| true`.

---

## 7. Профили прогона

| Профиль | Где | Когда | Тир/объёмы | Классы | Длительность | Что валит |
|:--|:--|:--|:--|:--|:--|:--|
| **`smoke-perf`** | `ubuntu-latest` | **на PR** | **S0** (объёмы ≈ 1/50 S, файл ≤ 256 MiB) | **A — блокирующий**; B — пишет baseline, advisory | **≤ 6 мин** (1 прогон, без медианы-3) | error-rate, checks, throughput стримов, RSS-потолки, счётчики батча/lock-set |
| **`regression`** | `ubuntu-latest` (nightly) → perf-раннер, когда появится | nightly на `main` + метка `perf` | S0 + «грязные» датасеты фоновых (усечённые) | A — блокирующий; B — **медиана-3**, относительное сравнение | 30–60 мин | всё из `smoke-perf` + фоновые SLO-58…78; обновляет baseline |
| **`lab`** | контролируемый lab-box (cpuset-конверт) | вручную / перед релизом | **S / M** (L — экстраполяция) | A и **B — блокирующие** | часы | авторитетные латентные числа; здесь и только здесь класс B имеет право валить |

Медиана-из-3 × длинный прогон в PR-лейн **не помещается** (Implementation Rules item'а) — поэтому
она живёт в `regression`, а PR получает один короткий прогон с широкими порогами.

---

## 8. Что этот документ отдаёт дальше

- **P1.5 (sign-off)** — §6.1/§6.2 (что реально гейтится) + δ=10 % `[гипотеза]` + два условия перехода
  класса B в блокирующий (§4.4) — в decision-sheet вместе с Q1/Q3.
- **P2 (харнесс)** — §1 (контракт имён кастомных метрик и тегов), §2 (сквозные пороги), §3 (пороги
  построчно), §7 (три профиля), **и обязанность измерить CV** (5 прогонов) ⇒ подтвердить δ (§4.3).
- **P8 (наблюдаемость)** — §5 целиком: это **ТЗ**, а не пожелание. Шесть seam'ов из §5.5 (peak-memory,
  batch-rows ×2, lock-set, endpoints-scanned, cte-anchors) — то, без чего L-01…L-06 остаются мнением.
- **P3–P7** — правило вердикта: SLO, чья Prometheus-метрика (§5) не реализована, **не проверяется**;
  «SLO нарушен» без метрики — не вердикт (D9).
