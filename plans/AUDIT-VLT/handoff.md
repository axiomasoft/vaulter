# HANDOFF — 2026-07-10 — after P2.5

**Next:** развернуть фазу P3 (Гибкость и расширяемость) из скелета — 6 items,
`/task:plan-design AUDIT-VLT P3`, затем исполнять items phase-first.

| Model | Thinking | Context | Суть |
|:--|:--|:--|:--|
| opus | high | fresh session | Развернуть скелет фазы P3 в 6 items с закрытыми чеклистами (Обязательные оси в Phase Context P3 — вход развёртывания), закрыть DoR |

````
/task:plan-design AUDIT-VLT P3
````

Альтернатива — сквозной прогон фаз через `plans/AUDIT-VLT/audit-vlt.js` (D19):
`Workflow({scriptPath: "plans/AUDIT-VLT/audit-vlt.js"})` — без args это P2→P3→P4→P5 подряд
(P2 уже закрыта, следующей будет P3); `args: {phases: ["P3"]}` — одна фаза.
Конвейер: probe → design+DoR (для скелет-фаз) → items волнами → ревью честности → код-ревью
доказательств (file:line/цитата/severity) → адверсариальная верификация Blocker/Major →
закрытие фазы одним коммитом. Item-агенты пишут только свои `findings/P3.*-*.md`.

**Done:**
- Фаза P0 (Research & Baseline) — 🟢 Done (3/3), 0 находок.
- Фаза P1 (Безопасность) — закрыта: P1.1 🟢 Done, P1.2–P1.12 🟠 Done with deviations.
  53 находки в `findings/REGISTER.md` (3 Blocker, 18 Major, 30 Minor, 2 Nit).
- Фаза P2 (Встраиваемость и DX) — **закрыта**: P2.1–P2.5 🟠 Done with deviations
  (13/13, 13/13, 13/13, 12/12, 13/13 вердиктов чеклиста соответственно, ни один item не
  эскалирован). 20 находок в `findings/REGISTER.md` (12 Major, 7 Minor, 1 Nit).
  Опись публичной поверхности (P2.1), таблица «точка стыковки → ключ → дефолт» (P2.4) и
  матрица «миграция → down() → идемпотентность» (P2.3) — deliverable-опоры для P3/P5.
  F-P2.3-01 и F-P2.3-03 опровергнуты адверсариальной верификацией большинством скептиков —
  в REGISTER НЕ внесены, помечены в `findings/P2.3-schema-embeddability.md` ссылкой на
  `artifacts/repro/verify-<id>/`.
- Read-only соблюдён (D2): `git status` по `packages/ docs/ e2e/ hocuspocus-server/` чист.

**Remaining:**
- P3 (6), P4 (5), P5 (3) — скелеты; каждую фазу перед исполнением разворачивать
  `/task:plan-design AUDIT-VLT Pn`. Блок «Обязательные оси» в Phase Context — вход развёртывания.
- План AUDIT-VLT остаётся 🟡 In progress: есть подтверждённые Blocker'ы (D7), закрытие
  в 🟢/🟠 — только после решения владельца в P5.2.

**Sources of truth:** `plans/AUDIT-VLT/plan.md` · `plans/AUDIT-VLT/phases/P2.md` (Phase Handoff) ·
`plans/AUDIT-VLT/phases/P1.md` (Phase Handoff) · `plans/AUDIT-VLT/findings/REGISTER.md` ·
`plans/AUDIT-VLT/findings/P0-*.md`, `findings/P2-package-tools.md` (нормативные RAG-выжимки, D16) ·
`plans/AUDIT-VLT/artifacts/finding-template.md`

**Open risks:**
- **Blocker F-P1.4-01** — гонка `Upload-Offset` в tus без row-lock: два конкурентных PATCH
  тихо портят собранный файл (`TusUploadHandler.php:80-108`, U2.1/U2.2).
- **Blocker F-P1.7-01** — исходящие webhook без SSRF-защиты: пользовательский endpoint-URL
  бьёт в приватную сеть/`169.254.169.254` (`RegisterWebhookEndpointRequest.php:27`, U6.1).
- **Blocker F-P1.11-01** — серверный TipTap-рендер не фильтрует схему URL в `href`:
  stored XSS через `javascript:`-ссылку (`TipTapRenderer.php:93`, G2.2/G2.4).
- 18 Major из P1 и 12 Major из P2 — детали в REGISTER; ниже дальше не дублируются, ссылка
  на `F-P1.*`/`F-P2.*` в REGISTER.
- Наиболее значимые Major из P2 для ремедиации в P5.2: F-P2.1-02 (доки описывают удалённый
  ADR-0014 API), F-P2.1-06 (split-пакеты без CHANGELOG), F-P2.2-01/02 (config-cache-небезопасный
  провайдер, риск двойного прогона миграций), F-P2.3-02 (нет параметризации `connection`),
  F-P2.4-01 (мёртвый `permission_resolver`, подтверждённый реальным хостом FlexCRM),
  F-P2.5-01/02/03/06/07 (пробелы reference-доки, README-обещания расходятся с фиче-флагами,
  dual-instance риск `@vaulter/vue`).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация фаз P3–P5 (скелеты); ремедиация Blocker/Major — в P5.2 (D2, D7).
- open questions: нет открытых (Q1–Q4 закрыты D11–D13, D15).
