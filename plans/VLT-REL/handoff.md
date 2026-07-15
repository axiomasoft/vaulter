# HANDOFF — 2026-07-15 — after P3.1

**Next:** `/task:plan-design VLT-REL P4` — **P3 закрыта 🟢 (1/1)**. P4 (upgrade-путь/BC-классификация
будущих фиксов) остаётся скелетом — детализация нужна перед исполнением. P5 (тег+публикация) тоже
скелет, opus/high manual, требует только P2 (P4 — по решению владельца, не обязательный гейт для P5).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — фаза-дизайн пинится opus/high (`task:design`), не наследует P3/P4-роутинг items |
| Context | NEW SESSION — шаг-не-item |
| Суть | Детализировать фазу P4 (`phases/P4.md`) — разложить items, ТЗ по 16 полям, Routing-строку в `plan.md` §3 (P4 уже указан как sonnet/medium plan-exec для items — дизайн самой фазы пинится opus/high по протоколу) |

````
/task:plan-design VLT-REL P4
````

**Done:** P1 закрыта (1/1 🟢). P2 закрыта (2/2 🟢 — поверхность заморожена snapshot-гейтом v0.1.0).
**P3 закрыта (1/1 🟢)** — P3.1 собрал `root/known-limitations.md` (item-коммит `15dc883`): каталог
L-01…L-08 + F-P2.8-1, преамбула, разделы «доказано/факт» и «гипотезы», D11-гейт, связь с 0.1.0.

**Remaining:** P4 (скелет — развернуть `plan-design`, upgrade-путь и BC-классификация будущих
фиксов) · P5 (скелет — тег+публикация, opus/high manual). P3 закрыта; P4/P5 остаются.

**Sources of truth:** `plan.md` (Meta v0.5.0/Routing §3/D-лог D1–D7/Status Board P3 1/1 🟢) ·
`phases/P3.md` (P3.1 — Completion Notes, закрыт) · `root/known-limitations.md` (деливерабл P3.1) ·
`plans/VLT-LOAD/plan.md` §7 (L-01…L-08 + F-P2.8-1 + правило D11) · `plans/VLT-LOAD/slo.md` §5
(не выполняется/под риском) + §6 Q4 (граница 32 MiB) · `root/cut-line.md` (стиль деливерабла P1).

**Open risks:**
- P4 остаётся скелетом — если владелец хочет P4 перед тегом P5, развернуть через `plan-design`.
- `known-limitations.md` фиксирует ГИПОТЕЗЫ (L-02…L-07, F-P2.8-1) как «не проверено под нагрузкой» —
  их подтверждение/снятие — работа named-фаз VLT-LOAD (P3/P5/P6), НЕ этого плана.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: содержательные фиксы L-01…L-08 — dev-срезы вне плана (D-LOAD-5 / D11-гейт VLT-MUT drive ≥95);
  замер пост-фикс — VLT-LOAD P4; BC-классификация фиксов — VLT-REL P4.
- open_questions: —

**Посторонний дифф в дереве (НЕ P3.1):** `.claude/.maind-feature-log.last`, `plans/VLT-LOAD/{plan.md,
handoff.md,phases/P8.md,findings/P8-rag-otel-health.md}`, `scratchpad/` — работа VLT-LOAD/mAInd, не тронуто.
