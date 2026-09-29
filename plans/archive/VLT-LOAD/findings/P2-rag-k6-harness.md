# RAG-провенанс P2 — харнесс: стоковый k6 vs кастомный бинарник

> Верификация внешних фактов, на которых стоял блупринт P2 (D2/D3/D4, зафиксированы в P0 2026-07-13).
> Дата верификации — **2026-07-14**, движок — `perplexity-web` (RAG-лестница: context7 → perplexity →
> WebSearch). Итог: **два решения блупринта опровергнуты** (D3, D4) — см. D22/D23/D24 в `plan.md` §5.

---

## RAG-9 — стоковый k6 не стримит тело запроса с диска (D3 в этой части подтверждён)

**Запрос:** «k6 v1.x/2.x: можно ли отправить 5-ГиБ тело, стримя его с диска — `k6/experimental/fs`,
`k6/experimental/streams`?»

**Вердикт:** **нет.** `k6/http` не принимает ни файловый дескриптор, ни `ReadableStream` как body;
тело материализуется в памяти. Модули `fs`/`streams` решают задачу «прочитать/обработать большой
локальный файл», а не «стримить тело HTTP-запроса»; интеграции с HTTP-клиентом у них нет. Открытые
issue grafana/k6 (#2311 «problems and deficiencies around uploading big files», «Improving handling of
large files») не закрыты.

**Источники:** `grafana.com/docs/k6/latest/javascript-api/k6-experimental/streams/`,
`github.com/grafana/k6/issues/2311`.

**Следствие:** RAG-2 (P0) остаётся в силе. Но см. RAG-12 — для vaulter это **не имеет значения**:
у пакета нет ни одного эндпоинта, принимающего multi-GB тело одним запросом.

---

## RAG-10 — `k6/experimental/fs` держит ПОЛНУЮ копию файла в памяти (ловушка, которую блупринт не видел)

**Запрос:** «k6 `discardResponseBodies` — буферизует ли k6 весь ответ в RAM? `k6/experimental/fs` —
грузится ли файл целиком в память?»

**Вердикт (две части):**

1. **`discardResponseBodies: true`** — тело **не удерживается**: `res.body === null`, k6 не хранит
   payload в JS-heap. Для 5-ГиБ **скачивания** стокового k6 достаточно.
2. **`k6/experimental/fs`** — файл грузится **один раз целиком** и **шарится между VU** (в отличие от
   `open()`, который копирует на каждый VU). «Одна копия вместо N» — но это **всё равно одна полная
   копия в RAM**: 5-ГиБ фикстура на диске = 5 ГиБ RSS генератора при лимите 2/4/8 ГиБ
   (`reference-env.md` §1.5).

**Источники:** `grafana.com/docs/k6/latest/javascript-api/k6-experimental/fs/`,
`github.com/grafana/k6/issues/3281`.

**Следствие (→ D23):** требование `reference-env.md` §1.5 «фикстуры на диске, **не** через
`open()`/`SharedArray`» **ловушку не снимает** — она перекладывается с `open()` на `fs`. Правильный
ответ — **не иметь 5-ГиБ фикстуры вообще**: чанк генерируется в памяти (общий 8/64-MiB `ArrayBuffer`)
и переиспользуется, уникальность sha256 обеспечивается salt'ом в первом чанке сессии.

---

## RAG-11 — xk6-fasthttp теряет ВСЕ метрики `http_req_*` (опровержение D3)

**Запрос:** «domsolutions/xk6-fasthttp: эмитит ли стандартные метрики k6 (`http_req_duration`,
`http_req_failed`), поддерживает ли PATCH, совместим ли с k6 v1.x?»

**Вердикт:** расширение **не эмитит** стандартные HTTP-метрики — README прямо перечисляет
«потерянные» из-за ограничений наблюдаемости fasthttp:

`http_req_duration` · `http_req_failed` · `http_req_blocked` · `http_req_connecting` ·
`http_req_receiving` · `http_req_sending` · `http_req_tls_handshaking` · `http_req_waiting` ·
**`data_received`** · **`data_sent`**

Остаются только `http_reqs`, `checks`, `iterations`, `iteration_duration`, `vus`. Пороги/теги
работают — но **только на тех метриках, что существуют**. Стриминг с диска (`FileStream`) есть,
`client.get`/`client.post` документированы, **PATCH — не документирован**. Последний релиз —
2024-09-15.

**Источник:** `github.com/domsolutions/xk6-fasthttp` (README).

**Следствие (→ D22):** это **несовместимо с гейт-политикой P1.4**. `slo-gates.md` строит на
`http_req_duration{scenario:…}` / `http_req_failed{class:…}` / `data_sent` / `data_received`:
сквозные пороги G-1, G-2 и **все** строки класса B §3.1–§3.2, плюс агрегатный throughput §1. Перевод
стриминговых сценариев на fasthttp обнулил бы половину гейта — и ещё не даёт PATCH, на котором
целиком стоит tus (`PATCH resumable-uploads/{upload_id}`, OP-02).

---

## RAG-12 — Prometheus remote-write встроен в ядро k6 с v0.42 (опровержение D4)

**Запрос:** «нужен ли `xk6-output-prometheus-remote` в 2026, или remote-write уже в ядре?»

**Вердикт:** расширение **влито в k6** и с **v0.42** доступно как
**`--out experimental-prometheus-rw`** (env: `K6_PROMETHEUS_RW_SERVER_URL`,
`K6_PROMETHEUS_RW_TREND_STATS`, `K6_PROMETHEUS_RW_TREND_AS_NATIVE_HISTOGRAM`). Кастомный бинарник ради
вывода метрик собирать **не нужно**. Репозиторий расширения сегодня — набор compose-примеров и
дашбордов, не механизм вывода.

**Источники:** `grafana.com/docs/k6/…/results-output/real-time/prometheus-remote-write/`,
`pkg.go.dev/github.com/…/xk6-output-prometheus-remote`.

---

## RAG-13 — актуальная линия k6 и сборка кастомного бинарника (если бы понадобился)

**Вердикт:** актуальная линия — **k6 2.x** (2026); 1.0.0 — веха 2025 г. Кастомная сборка — `xk6`
(`go install go.k6.io/xk6/cmd/xk6@latest`, либо официальный образ `grafana/xk6`, multi-arch,
`xk6 build --with …`); совместимость расширения с версией k6 определяется его `go.mod` и **ломается на
этапе `go build`**, а не в рантайме.

**Источники:** `grafana.com/docs/k6/latest/release-notes/`, `hub.docker.com/r/grafana/xk6`,
`github.com/grafana/xk6`.

**Следствие:** зафиксировано «на будущее»: если распределённый прогон/расширение всё же понадобятся
(P7, k6-Operator), путь известен. В P2 — **не требуется**: используется официальный образ
`grafana/k6` с пином минорной версии.

---

## Свод: что из блупринта P2 выжило, что заменено

| Блупринт (P0) | Судьба | Основание |
|:--|:--|:--|
| **D2** — инструмент k6 | **подтверждён** | RAG-1 (P0) + RAG-11/12: у стокового k6 есть всё, что требует гейт |
| **D3** — кастомный бинарник + `xk6-fasthttp` для стриминга тела | **опровергнут → D22** | RAG-11: потеря `http_req_*`/`data_*` = потеря гейта; PATCH не документирован. RAG-12/RAG-10: истинный стриминг тела vaulter'у не нужен (RAG-14 ниже) |
| **D4** — `xk6-output-prometheus-remote` в кастомный бинарник | **опровергнут → D24** | RAG-12: remote-write в ядре с v0.42 (`--out experimental-prometheus-rw`) |
| Фикстуры на диске (`reference-env.md` §1.5) | **уточнено → D23** | RAG-10: `fs` держит полную копию в RAM — дисковая фикстура ловушку не снимает |
| Locust как резерв «под SigV4-гимнастику» | **не нужен** | RAG-14 ниже: клиент SigV4 не подписывает |

---

## RAG-14 — (факт из кода, не внешний) почему стриминг тела вообще не нужен

Проверено чтением каталога операций (`operations-catalog.md`, P1.2) и границ SLO (`slo.md`, P1.3) —
внешнего источника не требует, но это то, на чём стоит D22:

| Путь | Максимальное тело ОДНОГО запроса | Якорь |
|:--|:--|:--|
| tus PATCH (OP-02) | **чанк**, референс 8 MiB | `slo.md` §0.2; `TusUploadHandler.php:83-137` |
| S3-multipart putPart (OP-06) | **часть ≤ 64 MiB** (`uploads.max_chunk_bytes`) | `S3MultipartHandler.php:230-247` |
| single-shot (OP-17) | **≤ 32 MiB** — контрактная граница, sign-off владельца (D19, вопрос 4) | `slo.md` §2.4 |
| download (OP-08…10) | тело **ответа** (до 5 ГиБ) → `discardResponseBodies` | RAG-10 |

**Ни один эндпоинт vaulter не принимает multi-GB тело одним запросом.** 5-ГиБ файл всегда приезжает
чанками. Значит «истинный стриминг тела с диска», ради которого блупринт вводил кастомный бинарник,
решает задачу, которой у этого пакета нет.

Отдельно: **S3-multipart у vaulter — это СВОИ HTTP-роуты** (`POST resumable-uploads/{id}/parts`,
`.../complete` — `S3MultipartController.php:56,85`), а не прямые вызовы к S3. Клиент **не подписывает
SigV4** — подписывает сервер. Значит и аргумент «Locust дешевле для boto3/SigV4-гимнастики» (§8.1
`plan.md`) к vaulter неприменим: резерв Locust не нужен.
