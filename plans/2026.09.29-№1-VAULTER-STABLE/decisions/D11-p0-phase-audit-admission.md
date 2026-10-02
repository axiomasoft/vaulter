---
id: D11
date: 2026-10-02
status: accepted
item: P0
items: [P0]
supersedes: []
superseded_by: null
---
# D11 — Согласие на фазовый аудит P0; аудитор codex/gpt-6.1-sol/high (accepted)

**Actor:** repository owner; фиксирует claude-opus-5-5/medium.
**Evidence:** `brief/00-brief.md#2026-10-02-p0-phase-audit` (дословно: «да, аудит нужен дай команду и зафиксируй в плане. Кстате аудит может
сделать gpt-sol-6.1»); `artifacts/P0-O3CI/o3ci.md`; D03, D07 п.3, D10.

## Solution

1. Владелец разрешает один независимый фазовый аудит P0 (`task:plan-audit 2026.09.29-№1-VAULTER-STABLE P0`) по
   кандидату с fingerprint `9f0cd756788c777eebad822744b415017aa606df4516565a1ac8e0b7c1d3c85b` (HEAD при фиксации `32b3dce`, продукт = `5a7aac8`) и run `7024e4a82dae3ec8a4faba63017b2037de1a57871e8836bb2014dbab5d814e1a`.
   Остаточные риски и ограниченные вопросы — в блоке ниже; аудит read-only, продуктовые записи не допускаются.
2. **Маршрут аудитора:** `codex/gpt-6.1-sol/high` — замена `codex/gpt-6-sol/high` из roadmap (execution-sheet, строка 6)
   по выбору владельца («gpt-sol-6.1» = Codex-модель `gpt-6.1-sol`). Аудитор не исполнял items P0 в своей сессии.
3. **P0.1 🟠 не блокирует аудит P0 по решению владельца** («игнорируй прогоны 0.1», 2026-10-02): 4 mutation-вердикта
   P0.1 (core PG/SQLite, drive, documents; B-16) остаются принятым отклонением. Hosted mutation-прогоны
   36971114355 / 36971118680 / 36971122817 на `5a7aac8` запущены до этой реплики; их результат не является входом
   аудита и не меняет статус P0.1. Ограничение runtime: `phase_audit_policy` даёт `PHASE_ACCEPTANCE_NON_GREEN` для
   любого не-🟢 item, поэтому semantic Next в handoff — `waiting:` с явной командой аудита, а не `plan-audit` Next.
4. Одно согласие — один запуск: in-progress событие резервирует его, терминальное — потребляет. Повтор требует нового
   вопроса и нового согласия. PR/merge — только после GREEN, отдельным запросом владельца (D07 п.3).

```audit-admission/v2
{
  "schema_version": "audit-admission/v2",
  "target": {
    "phase_id": "P0",
    "candidate_fingerprint": "9f0cd756788c777eebad822744b415017aa606df4516565a1ac8e0b7c1d3c85b"
  },
  "run_id": "7024e4a82dae3ec8a4faba63017b2037de1a57871e8836bb2014dbab5d814e1a",
  "residual_risks": [
    {
      "id": "R-P0-1",
      "impact": "P1 доказывает T0-исправления гонок на ForkRace; ложно-зелёный харнесс скроет реальные race-дефекты",
      "evidence_gap": "харнесс проверен локально, B0-A review и hosted 4 passed/0 skipped, но не адверсариально на фазовом уровне",
      "bounded_question": "Воспроизводит ли ForkRace настоящую конкурентность (два процесса, два соединения, барьер) так, что пропуск барьера или последовательное исполнение даёт красный результат?"
    },
    {
      "id": "R-P0-2",
      "impact": "upgrade-сценарии V15 и P2-миграции опираются на фикстуру 0.2; неполная фикстура даст ложно-зелёный upgrade",
      "evidence_gap": "фикстура только PostgreSQL (D09), формы §4 проверены автором и B0-A review",
      "bounded_question": "Покрывает ли PG-фикстура v0.2.0 все формы данных OPUS 08 §3/§4, нужные upgrade 0.2→0.3, и детерминирована ли она?"
    },
    {
      "id": "R-P0-3",
      "impact": "ошибочная классификация baseline скроет регрессию в P1–P8",
      "evidence_gap": "классификация P0.1 (B-01…) и сверка hosted run сделаны исполнителями P0",
      "bounded_question": "Корректна ли классификация baseline P0.1 и сверка O-3-CI (B-01 единственный красный, B-03 закрыт D10), без замаскированных регрессий?"
    },
    {
      "id": "R-P0-4",
      "impact": "исполнители P1–P6 ссылаются на ADR 0019–0024; искажённый перенос закрепит неверные решения",
      "evidence_gap": "P0.4 — self-check 78/78 без независимой сверки",
      "bounded_question": "Переносят ли ADR 0019–0024 решения OPUS AD02, AD05–AD09, AD15–AD17, AD22–AD23, AD29–AD31 без новых решений и искажений?"
    }
  ],
  "owner_message": "brief/00-brief.md#2026-10-02-p0-phase-audit",
  "scope_sha256": "2bf33ad010b7c6c7ed24b1ce73cf76c63b93b834352a628fa66479ddf86ebb0a"
}
```

## Why

D03 и D07 п.3 требуют фазовый аудит каждой фазы по candidate/run-bound согласию после hosted-evidence O-3-CI; O-3-CI
выполнен (D10). Владелец явно согласился и выбрал модель аудитора.
