<!-- plan-projection
projection: decision-archive
projection_version: v1
source_scope: decisions/
through: 2026-09-23/D42
inputs_sha256: a8295ba071150a9511050a0a6abbfeb0bf9f05901fffff3ed3d9f28008dcef78
generated_by: task plan-views decisions
-->

# Архив решений

> Не править руками: это генерируемый вид.

| ID | Date | Status | Items | Решение |
|:--|:--|:--|:--|:--|
| D32 | 1970-01-01 | superseded | — | **Health split: liveness = SUT-level Laravel-native `/up` (пакет не владеет `/up`); readiness (DB/S3/queue) = опциональные vaulter Check-классы за `spatie/laravel-health` через `suggest` (не `require`) + wiring в SUT `load/`.** Гейт §5.4 (харнесс отличает «упало» от «не поднялось») удовлетворён без хард-зависимости. |
| D34 | 2026-09-20 | superseded | P3.1, P4.1, P5.1, P6.1, P7.1, P8.1 | P3–P7 остаются фазами-скелетами с явным `design-phase: task:plan-design VLT-LOAD Pn`. Пустые миграционные item-файлы и их state-проекции удаляются из живого реестра: они были резервированием имён, а не исполняемыми стабильными items. Архив `artifacts/migration-source-v1/` сохраняет исходный след. При последующем проектировании фаз допустимо снова использовать P3.1–P7.1: идентификаторы не перенумеровываются и не присваиваются другой работе. До этого следующий runnable item — P8.1. После закрытия P8.7 проектирование P3–P7 возвращает обычный числовой порядок; P8.8 остаётся после них. |
| D40 | 2026-09-20 | superseded | P3, P4, P5, P6, P7, P8.8 | 1. **Детализация.** Все ещё скелетные фазы (`P3`–`P7`, плюс `P8.8` после них) детализируются **только** через `task:plan-design` с маршрутом **Codex `gpt-5.6-sol`**, frontier/high, по числовому порядку фаз. Пункты P8.2–P8.7 не переоткрываются; live-долг P8.7 (harness/overhead) остаётся в `findings/P8.7-gate-closure.md` до operator-run. 2. **Аудит.** После того как у каждой из перечисленных фаз есть исполнимые item-спеки (не «СКЕЛЕТ»), один **полный аудит плана** `task:plan-audit VLT-LOAD` (Sol / frontier/high) по всему оставшемуся scope — без параллельного `plan-exec` по скелетным фазам. 3. **Исполнение.** `task:plan-exec` / `task:plan-run` по фазам возобновляется **только после** принятого результата шага 2. Следующий runnable design — `P3` (D34). |
