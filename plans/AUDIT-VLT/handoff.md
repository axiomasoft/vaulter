# HANDOFF — 2026-07-10 — after P3.6

**Next:** развернуть фазу P4 (Качество и производительность) из скелета — 5 items,
`/task:plan-design AUDIT-VLT P4`, затем исполнять items phase-first.

| Model | Thinking | Context | Суть |
|:--|:--|:--|:--|
| opus | high | fresh session | Развернуть скелет фазы P4 в 5 items с закрытыми чеклистами (Обязательные оси в Phase Context P4 — вход развёртывания), закрыть DoR |

````
/task:plan-design AUDIT-VLT P4
````

Альтернатива — сквозной прогон фаз через `plans/AUDIT-VLT/audit-vlt.js` (D19):
`Workflow({scriptPath: "plans/AUDIT-VLT/audit-vlt.js"})` — без args это P4→P5 подряд
(P0–P3 уже закрыты); `args: {phases: ["P4"]}` — одна фаза.
Конвейер: probe → design+DoR (для скелет-фаз) → items волнами → ревью честности → код-ревью
доказательств (file:line/цитата/severity) → адверсариальная верификация Blocker/Major →
закрытие фазы одним коммитом. Item-агенты пишут только свои `findings/P4.*-*.md`.

**Done:**
- Фаза P0 (Research & Baseline) — 🟢 Done (3/3), 0 находок.
- Фаза P1 (Безопасность) — закрыта: P1.1 🟢 Done, P1.2–P1.12 🟠 Done with deviations.
  53 находки в `findings/REGISTER.md` (3 Blocker, 18 Major, 30 Minor, 2 Nit).
- Фаза P2 (Встраиваемость и DX) — закрыта: P2.1–P2.5 🟠 Done with deviations.
  20 находок в `findings/REGISTER.md` (12 Major, 7 Minor, 1 Nit). F-P2.3-01/03 опровергнуты
  адверсариальной верификацией — в REGISTER НЕ внесены.
- Фаза P3 (Гибкость и расширяемость) — **закрыта**: P3.1/P3.2/P3.3/P3.6 🟠 Done with deviations,
  P3.4/P3.5 🟢 Done (6/6 items закрыты чеклистами, ни один не эскалирован, Blocked-item'ов нет).
  10 находок в `findings/REGISTER.md` (3 Major, 6 Minor, 1 Nit): F-P3.1-01..04, F-P3.2-01/02,
  F-P3.3-01/02, F-P3.5-01, F-P3.6-01. Черновая находка F-P3.5-02 (P3.5/C1b — «дефект конфига,
  ADR актуален») отклонена фазовым ревью как дубль с противоположной классификацией к F-P3.6-01
  («ADR-0001 устарел») и снята: git-факты (коммит `9200e51`) подтверждают, что переключение
  дефолта `reverb→hocuspocus` было осознанным архитектурным решением, задокументированным в
  коде, а ADR-0001 просто не был обновлён (D5) — в REGISTER внесена только F-P3.6-01.
  Матрица «6 драйверных швов × инвариант» (P3.5) и опись `Config::*Model()`↔ключ (P3.4) —
  deliverable-опоры для P5.
- Read-only соблюдён (D2): `git status` по `packages/ docs/ e2e/ hocuspocus-server/` чист.

**Remaining:**
- P4 (5), P5 (3) — скелеты; каждую фазу перед исполнением разворачивать
  `/task:plan-design AUDIT-VLT Pn`. Блок «Обязательные оси» в Phase Context — вход развёртывания.
- План AUDIT-VLT остаётся 🟡 In progress: есть подтверждённые Blocker'ы (D7), закрытие
  в 🟢/🟠 — только после решения владельца в P5.2.

**Sources of truth:** `plans/AUDIT-VLT/plan.md` · `plans/AUDIT-VLT/phases/P3.md` (Phase Handoff) ·
`plans/AUDIT-VLT/phases/P2.md`, `phases/P1.md` (Phase Handoff) · `plans/AUDIT-VLT/findings/REGISTER.md` ·
`plans/AUDIT-VLT/findings/P0-*.md`, `findings/P2-package-tools.md` (нормативные RAG-выжимки, D16) ·
`plans/AUDIT-VLT/artifacts/finding-template.md`

**Open risks:**
- **Blocker F-P1.4-01** — гонка `Upload-Offset` в tus без row-lock: два конкурентных PATCH
  тихо портят собранный файл (`TusUploadHandler.php:80-108`, U2.1/U2.2).
- **Blocker F-P1.7-01** — исходящие webhook без SSRF-защиты: пользовательский endpoint-URL
  бьёт в приватную сеть/`169.254.169.254` (`RegisterWebhookEndpointRequest.php:27`, U6.1).
- **Blocker F-P1.11-01** — серверный TipTap-рендер не фильтрует схему URL в `href`:
  stored XSS через `javascript:`-ссылку (`TipTapRenderer.php:93`, G2.2/G2.4).
- 18 Major из P1, 12 Major из P2 и 3 Major из P3 (F-P3.2-01 «validated on boot» не работает,
  F-P3.2-02 enrichment-хуки панели мёртвые, F-P3.6-01 install-гайд collab противоречит коду
  и ADR-0001 устарел) — детали в REGISTER; ниже дальше не дублируются, ссылка на `F-P1.*`/
  `F-P2.*`/`F-P3.*` в REGISTER.
- Наиболее значимые Major из P2 для ремедиации в P5.2: F-P2.1-02 (доки описывают удалённый
  ADR-0014 API), F-P2.1-06 (split-пакеты без CHANGELOG), F-P2.2-01/02 (config-cache-небезопасный
  провайдер, риск двойного прогона миграций), F-P2.3-02 (нет параметризации `connection`),
  F-P2.4-01 (мёртвый `permission_resolver`, подтверждённый реальным хостом FlexCRM),
  F-P2.5-01/02/03/06/07 (пробелы reference-доки, README-обещания расходятся с фиче-флагами,
  dual-instance риск `@vaulter/vue`).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация фаз P4–P5 (скелеты); ремедиация Blocker/Major — в P5.2 (D2, D7).
- open questions: нет открытых (Q1–Q4 закрыты D11–D13, D15).
