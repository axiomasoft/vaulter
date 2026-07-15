# HANDOFF — 2026-07-15 — after P2.8

**Next:** **P8-минимум** — гейт P3–P7 (D9); фаза P2 закрыта (8/8). Порядок §4:
`P8-минимум → {P3, P4, P5, P6} → P7`.

```
/task:plan-design VLT-LOAD P8
```
Развернуть скелет P8 в items: 37 метрик-seam'ов из `slo-gates.md` §5 (histogram/counter/gauge) +
`/up` (liveness/readiness). Это гейт: без них 66/96 строк гейта `not-checkable` (подтверждено
прогонами на S0 — P2.7, и тире S — P2.8).

| Параметр | Значение |
|:--|:--|
| Model | opus/high для дизайна метрик-seam'ов (§3 Routing P8); `vaulter-slice-builder`/`php:laravel-implementer` для кода seam'ов |
| Суть next | P8-минимум = 37 метрик-seam'ов из `slo-gates.md` §5 (histogram/counter/gauge + `/up`), без которых 66/96 строк гейта `not-checkable` — подтверждено прогонами и на S0 (P2.7), и на тире S (P2.8) |

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` · `operations-catalog.md` · `slo.md` (81 SLO) ·
  `slo-gates.md` (96 гейтов) · `slo-signoff.md`.
- **P2 🟢 Done (8/8: 5 🟢 + P2.6/P2.7/P2.8 🟠).** Харнесс построен и провалидирован живыми прогонами
  на S0 (CI, `ubuntu-latest`) и **тире S (lab-box, cpuset)**.
  - P2.1–P2.5 🟢 (harness.md · стенд `load/` · seed · k6-сценарии · двухплоскостной гейт+baseline).
  - P2.6 🟠 — `load.yml` (smoke-perf/regression/baseline-guard); красный по S0-калибровке.
  - P2.7 🟠 — 5 прогонов на `ubuntu-latest` → CV 26–56 % ⇒ **D29** (класс B advisory на S0/CI).
  - **P2.8 🟠 Done with deviations** (2026-07-15, manual opus/high): прогон на **cpuset-стенде тира S**
    (L-1…L-7: governor=performance+turbo off, swap off, тихий хост, cpuset app=0,2/pg=4/minio=6). →
    **D30**. Три результата:
    1. **tus стримит** — app cgroup `anon` (истинный heap) плоский **40–48 MiB** при файле {5 MiB,
       500 MiB, 5 ГиБ} ⇒ SLO-40 «RSS ≤ 64 MiB» выполнено. Рост `current`/`peak` — reclaimable
       page-cache от staged-temp, не heap.
    2. **CV латентности на lab-box: плотные метрики 2.0–2.7 %** (тот же датасет
       `vlt-load-414fae92477d07f8`, что и P2.7 — изолирована ровно переменная окружения) — в 13–28×
       тише shared-раннера ⇒ **δ=10 % на `lab` подтверждён защитимым**. Выброс `ttfb_ms` p95 101.5 % —
       артефакт разрежённого Trend. **НО класс B ещё не гейтит в `lab`** — плумбинг-блокеры F-P2.8-2/3.
    3. **L-01 доказан ПРОГОНОМ** — s3-`complete()` на 500 MiB → HTTP 500, `Allowed memory size of
       536870912 exhausted at S3MultipartHandler.php:165` (тот же файл через tus → 201, anon 46 MiB).
    Артефакты: `load/baselines/lab-S.json` (медиана-5), `findings/P2.8-baseline-S.md`.

**Remaining:**
1. **P8-минимум** (следующий, гейт P3–P7): 37 seam'ов `slo-gates.md` §5 + `/up`. Без него 66/96 строк
   гейта `not-checkable` (подтверждено и на S0, и на тире S).
2. **P3–P7** — скелеты; порядок `P8-минимум → {P3,P4,P5,P6} → P7`. **P3/P4 наследуют F-P2.8-1**
   (throughput одиночного стрима тира S ниже пола SLO-40 — боттлнек TBD).
3. **Вне плана (dev-срез):** L-01 (`S3MultipartHandler::complete()` в RAM) — **доказан прогоном**,
   срез ждёт **D11** (VLT-MUT drive ≥ 95).

**Sources of truth:**
- `plan.md` — §4 доска (P2 🟢 8/8), §5 D1–D30, §7 Findings-долг (L-01 «доказано прогоном», F-P2.8-1).
- `phases/P2.md` — P2.8 Completion Notes + **Phase Handoff P2** (фаза закрыта).
- `findings/P2.8-baseline-S.md` — RSS-тренд потоковости, CV-таблица 5 прогонов, лог fatal'а L-01.
- `slo-gates.md` §4.3/§4.4 — δ на `lab` подтверждён; предусловие-плумбинг F-P2.8-3.
- `load/baselines/lab-S.json` · `load/baselines/smoke-perf-S0.json`.

**Open risks / deviations (P2.8):**
- **Абсолютный baseline на ПОЛНОМ объёме тира S не снят** — hot-set 20 ГиБ байт не помещается + время
  seed'а; замер на S0-объёме, но тире-S конверте железа. CV (свойство окружения) переносится;
  absolute-числа baseline — provisional. Полный baseline — когда будет диск/время.
- **Класс B не гейтит в `lab`** — per-scenario сабметрики §3.1 не материализуются (F-P2.8-3:
  `thresholdsFor()` не объявляет порогов + `scenario`-тег = имя файла, не слаг §3.1). Правка — это
  изменение СЦЕНАРИЕВ (P2.4 Files), харнесс-item/владелец, не δ.
- **F-P2.8-1** — throughput одиночного стрима тира S ~1.9–22 MiB/s < пол SLO-40 25; боттлнек TBD → P3/P4.
- **`run.sh` не имеет ветки `lab`** — замер снят `smoke-perf`-обвязкой на TIER=S (сравнимо с P2.7);
  ветка `lab` — тот же класс работы, что F-P2.8-3.
- **Тир M — публичное обещание без прогона (Q4/D25).** Полный тир S тоже не прогнан (только S0-объём
  на S-конверте).

**Бокс (lab-box) после P2.8 — требует восстановления владельцем:**
- Остановлены и удалены 23 сторонних контейнера (agelto/vintera/dterema/corex/sova) — **данные в
  volume'ах целы**. Восстановить: `docker compose up -d` в working_dir каждого (снапшот
  `scratchpad/running-containers-before-P2.8.txt`).
- `sudo` изменения: governor→performance, `swapoff -a`, `intel_pstate/no_turbo=1` — вернуть reboot'ом
  или обратными командами.
- `~/.docker/config.json` `credsStore=desktop` сломан (docker-credential-desktop нет в PATH) —
  обходили временным `DOCKER_CONFIG`; сам конфиг не трогали.
- Нагрузочный стенд `load/` тира S — снести `TIER=S ./load/scripts/down.sh` (или оставлен поднятым).

**Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision pending`).
