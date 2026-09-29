# HANDOFF — 2026-07-15 — after P8

**Next:** **P8.1** (Exec=manual) — ЗАПУСК ВРУЧНУЮ: Opus/high. Спека наблюдаемости + дизайн контракта-шва
`MetricsRecorder`. Фаза P8 развёрнута на 8 items; порядок внутри: `P8.1 → P8.2 → {P8.3, P8.4, P8.5} →
P8.6 → P8.7 → P8.8`. P8.1 пишет SSOT (`observability-spec.md`), который читают все seam-items.

```
/task:plan-run VLT-LOAD P8.1
```
P8.1 — Exec=manual (opus/high), модель текущей сессии, без ритуала `/model`. После P8.1 seam-items
P8.2–P8.6 идут дешёвой моделью (`vaulter-dev-loop` или `/task:plan-exec` по-item'но; §3 Routing), P8.7 —
снова manual/opus (гейт-вердикт), P8.8 — после P3–P7.

| Параметр | Значение |
|:--|:--|
| Model | opus/high (P8.1 дизайн, P8.7 вердикт); sonnet/medium + `vaulter-slice-builder`→`vaulter-reviewer`/`test-writer` (P8.2–P8.6) |
| Суть next | P8.1 = `observability-spec.md`: форма `MetricsRecorder` (public seam, D31) + маппинг всех 37 метрик `slo-gates.md` §5 → пакет/файл:строка → тип/лейблы. Без спеки три параллельных seam-item'а разойдутся по именам и гейт не сойдётся |
| Гейт-факт | P8-минимум (P8.2–P8.7) снимает блокировку P3–P7: сейчас 66/96 строк гейта `not-checkable` (P2.7/P2.8) |

**Done:**
- **P0 🟢** (2026-07-13) — рекогносцировка, RAG-провенанс, мастер-план (D1–D11).
- **P1 🟢 Done (5/5)** — `reference-env.md` · `operations-catalog.md` · `slo.md` (81 SLO) ·
  `slo-gates.md` (96 гейтов, §5 = 37 метрик P8-минимума) · `slo-signoff.md`.
- **P2 🟢 Done (8/8: 5 🟢 + P2.6/P2.7/P2.8 🟠).** Харнесс построен, провалидирован на S0 (CI) и тире S
  (lab-box, cpuset). D29 (класс B advisory на S0), D30 (δ=10 % на `lab` защитим; L-01 доказан прогоном).
- **P8 спроектирована (design, 0/8 исполнено)** — 8 items, DoR пройден. D31/D32/D33 + `findings/P8-rag-otel-health.md`.

**Remaining:**
1. **P8-минимум (P8.1→P8.7)** — гейт P3–P7. Единственный код-выход VLT-LOAD в пакетный `src/` (37
   seam'ов + `/up`/readiness). Порядок: P8.1 спека → P8.2 машинерия → P8.3/4/5 seam'ы (§5.1/§5.2/§5.3) →
   P8.6 health → P8.7 гейт-верификация.
2. **P8.8** (хвост — трейсы + отложенные метрики) — открывается только ПОСЛЕ P3–P7 (не гейт).
3. **P3–P7** — скелеты; порядок `P8-минимум → {P3,P4,P5,P6} → P7`. P3/P4 наследуют F-P2.8-1.
4. **Вне плана (dev-срез):** L-01 — доказан прогоном P2.8; срез ждёт **D11** (VLT-MUT drive ≥ 95).
   **NB:** инструментация L-01-файла в P8.4 — аддитивна (D32), НЕ ждёт D11; фикс — ждёт.

**Sources of truth:**
- `plan.md` — §3 Routing (P8 по-item'но), §4 доска (P8 0/8), §5 D1–D33, §7 Findings-долг.
- `phases/P8.md` — Phase Context + 8 items (полные ТЗ).
- `slo-gates.md` §5 — **SSOT 37 метрик P8-минимума** (тип/лейблы каждой строки, входы SLO).
- `findings/P8-rag-otel-health.md` — RAG-15 (OTel API-only no-op) / RAG-16 (spatie 1.34.x, `/up` native).
- `packages/core/src/Contracts/Access/AzgardGuard.php` + `Testing/FakeAzgardGuard.php` — прецедент шва.

**Open risks / deviations:**
- **OTel/FPM overhead инструментации сам искажает замер** — митигация: Null-recorder дёшев (нет
  `if(config)`-развилок), отдельный with/without-прогон в P8.7 меряет δ. If overhead > бюджета B → находка.
- **Публичный контракт `MetricsRecorder`** — MINOR-SemVer-обязательство (`.claude/rules/public-api.md`);
  P8.2 обязателен review-гейт `vaulter-reviewer` (BC). Форма замораживается в P8.1.
- **Health-владение** — `/up` app-уровня (SUT), readiness за spatie через `suggest`; хард-зависимости нет
  (D33). Проверка readiness обязана тестировать *провал* при отвале зависимости, не только happy-path.
- **Тир M — публичное обещание без прогона (Q4/D25).** Не трогается P8.

**Open questions:** **Q4** — где снимаются авторитетные числа тира M (`Decision pending`, P8 не блокирует).
