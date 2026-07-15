# HANDOFF — 2026-07-15 — after P2.2

**Next:** Фаза P2 закрыта (2/2 🟢) — публичная поверхность заморожена. Следующее: развернуть
скелет **P3** (условия внедрения L-01…L-06) через `/task:plan-design VLT-REL P3`; **P4**
(upgrade-путь/BC-классификация) параллельно — обе требуют только P2, друг друга не требуют.
P3/P4 — ещё скелеты (16 полей не заполнены), поэтому следующий шаг — `plan-design`, не `plan-exec`
(упадёт на предусловии). Routing P3/P4 = sonnet/medium plan-exec для ИСПОЛНЕНИЯ, но детализацию
скелета пинит `plan-design` (opus/high).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — `plan-design` пинит opus/high (детализация фазы, архитектурные решения каталога) |
| Context | NEW SESSION — шаг-не-item (`plan-design` читает план с диска заново) |
| Суть | Развернуть скелет P3 (условия внедрения по рискованным поверхностям L-01…L-06 из `plans/VLT-LOAD` §7 + `slo.md` §5) в детализированные items; затем аналогично P4 (upgrade-путь: PATCH/MINOR-классификация будущих фиксов по `cut-line.md` §4). |

```
/task:plan-design VLT-REL P3
```

**Done:** P1 закрыта (1/1 🟢). **P2 закрыта (2/2 🟢).**
- **P2.1 🟢** (item-коммит `79d10fa`): `root/contracts/public-api-surface.md` — SSOT 26 контрактов +
  фасад + config/ресурсы/биндинги, drift-check `clean`.
- **P2.2 🟢** (item-коммит `2ded9e4`): исполнимая заморозка. 4 per-package Pest snapshot-теста
  `packages/{core,drive,documents,azgard}/tests/Arch/PublicApiSnapshotTest.php` + закоммиченные golden
  `tests/Arch/__snapshots__/public-api.txt` морозят рефлексией **26** интерфейсов-контрактов
  (core 21 / drive 1 / documents 3 / azgard 1) + фасад `Vaulter\Facades\Vaulter` (12 `@method`).
  Полная сигнатура (имя+тип+union/nullable/variadic/default+return), `@internal` исключён,
  детерминированный порядок. media/filament — empty-surface гвард в существующих `ArchTest.php`.
  Env-gated `UPDATE_SNAPSHOT` (НЕ в CI). Amend-процедура — `root/contracts/AMEND.md`. **Red-before
  доказан на всех 4** (инъекция метода роняет тест). `§7 Contracts` Exported → **frozen (v0.1.0)**.

**Remaining:** {P3 условия внедрения, P4 upgrade-путь} параллельно → P5 тег+публикация. P3–P5 — скелеты.

**Sources of truth:** `plan.md` (Meta v0.4.0/Routing §3/D-лог D1–D6/§7 Contracts frozen/Status Board
P2 2/2 🟢) · `phases/P2.md` (P2.1+P2.2 закрыты 🟢, Phase Handoff заполнен) · `root/contracts/public-api-surface.md`
(SSOT поверхности) · `root/contracts/AMEND.md` (**процедура изменения замороженной поверхности**) ·
`root/cut-line.md` (§2 граница, §4 bump-rule) · `.claude/rules/public-api.md` (правило границы) ·
`packages/{core,drive,documents,azgard}/tests/Arch/PublicApiSnapshotTest.php` (гейт) ·
`plans/VLT-LOAD/plan.md` §7 + `slo.md` §5 (вход для P3 — L-01…L-06, условия внедрения).

**Open risks:**
- `UPDATE_SNAPSHOT` env-gated и НЕ в CI — регенерация golden ВНЕ amend-процедуры (без D#) =
  `[POSSIBLE-DEFECT]`; дисциплина держится `AMEND.md` + review-правилом `public-api.md`.
- Тест-файлы вне phpstan-гейта пакетов (`phpstan.neon paths: src`) — `composer analyse` их не покрывает;
  прямой анализ satellite-теста даёт 1 larastan-нот сужения (не баг). Гейт проекта зелёный.
- Core тестируется КОРНЕВЫМ pest (root `composer.json`/`phpunit.xml`), НЕ `cd packages/core` — команды
  прогона/регенерации core-snapshot идут из корня репо (зафиксировано в `AMEND.md`).
- `MetricsRecorder` (VLT-LOAD P8, D31) появится ПОСЛЕ снапшота — MINOR через `AMEND.md`.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: config-ключи/публикуемые ресурсы/биндинги морозятся доком+review-правилом, НЕ автотестом
  (D5); `@vaulter/vue` TS-поверхность вне composer-заморозки (D3).
- open_questions: —

**Посторонний дифф в дереве (НЕ P2.2):** `.claude/.maind-feature-log.last`, `plans/VLT-LOAD/{plan.md,
handoff.md,phases/P8.md,findings/P8-rag-otel-health.md}`, `scratchpad/` — работа VLT-LOAD/mAInd, не тронуто.
Полный `composer test` пакета drive в песочнице падает по env-праву каталога testbench-storage
(`disks/local/tmp: Permission denied`) — не регресс P2.2 (snapshot-тест в том же прогоне PASS).
