---
id: D29
date: 1970-01-01
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**На lab-box'е (тир S, cpuset) δ = 10 % ПОДТВЕРЖДЁН защитимым; L-01 доказан прогоном.** Пять прогонов `smoke-perf`-обвязки на cpuset-стенде (governor=performance, turbo off, swap off, тихий хост; **тот же датасет `vlt-load-414fae92477d07f8`, что и P2.7** — изолирована ровно переменная окружения) дали CV **плотной** латентности `http_req_duration`/`http_req_waiting`/`iteration_duration` p95 = **2.0–2.7 %** — в 13–28× тише shared-раннера P2.7 (34.6–56.3 %) и **≪ 10 %**. Это ровно число, которое D29 оставил «числом профиля `lab`, подлежащим измерению в P2.8». Выброс `ttfb_ms` p95=101.5 % — артефакт разрежённого кастомного Trend (<30 сэмплов/окно), не шум окружения. **δ=10 % на `lab` защитим по оси шума**; НО класс B ещё не гейтит в `lab` из-за плумбинг-блокеров (per-scenario сабметрики не материализуются: `thresholdsFor()` не объявляет латентных порогов + сценарии тегируют `scenario`=именем файла, не слагом §3.1 — F-P2.8-3), не из-за δ. Параллельно **L-01 доказан прогоном** (было арифметикой D7): s3-`complete()` на 500 MiB → HTTP 500, `Allowed memory size of 536870912 exhausted at S3MultipartHandler.php:165`; тот же файл через tus → 201 при anon 46 MiB (стримит).

## Почему

Imported noncanonical legacy decision row
