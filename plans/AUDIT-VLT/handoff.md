# HANDOFF — 2026-07-10 — after P1.12

**Next:** исполнить item P2.1 (Публичная поверхность и SemVer-контракт). Фаза P2 развёрнута,
DoR закрыт; скелет-маркера больше нет, `plan-exec` легален.

| Model | Thinking | Context | Суть |
|:--|:--|:--|:--|
| sonnet | medium | fresh session | Опись публичной поверхности шести PHP-пакетов + чеклист C1–C13 (E1/E3/E4 + PT1) → `findings/P2.1-public-surface.md` |

````
/task:plan-exec AUDIT-VLT P2.1
````

Альтернатива — сквозной прогон фаз через `plans/AUDIT-VLT/audit-vlt.js` (D19):
`Workflow({scriptPath: "plans/AUDIT-VLT/audit-vlt.js"})` — без args это P2→P3→P4→P5 подряд
(одна фаза за раз, items внутри фазы параллельно); `args: {phases: ["P2"]}` — одна фаза.
Конвейер: probe → design+DoR (для скелет-фаз) → items волнами → ревью честности → код-ревью
доказательств (file:line/цитата/severity) → адверсариальная верификация Blocker/Major →
закрытие фазы одним коммитом. Item-агенты пишут только свои `findings/P2.*-*.md`.

**Done:**
- Фаза P0 (Research & Baseline) — 🟢 Done (3/3), 0 находок.
- Фаза P1 (Безопасность) — закрыта: P1.1 🟢 Done, P1.2–P1.12 🟠 Done with deviations.
  53 находки в `findings/REGISTER.md` (3 Blocker, 18 Major, 30 Minor, 2 Nit).
- Фаза P2 (Встраиваемость и DX) — **спроектирована**: 5 items с закрытыми чеклистами
  (P2.1 C1–C13, P2.2 C1–C13, P2.3 C1–C13, P2.4 C1–C12, P2.5 C1–C13), DoR закрыт.
  Design-time RAG: `findings/P2-package-tools.md` (PT1–PT6, context7
  `/spatie/laravel-package-tools` + исходник `mergeConfigFrom`) — нормативен по D16.
  Новые решения: D20 (нормативная база P2), D21 (FlexCRM read-only, исполняемый сценарий —
  только Testbench).
- Read-only соблюдён (D2): `git status` по `packages/ docs/ e2e/ hocuspocus-server/` чист.

**Remaining:**
- P2 — 5 items к исполнению (P2.1 → P2.5, phase-first).
- P3 (6), P4 (5), P5 (3) — скелеты; каждую фазу перед исполнением разворачивать
  `/task:plan-design AUDIT-VLT Pn`. Блок «Обязательные оси» в Phase Context — вход развёртывания.
- План AUDIT-VLT остаётся 🟡 In progress: есть подтверждённые Blocker'ы (D7), закрытие
  в 🟢/🟠 — только после решения владельца в P5.2.

**Sources of truth:** `plans/AUDIT-VLT/plan.md` · `plans/AUDIT-VLT/phases/P2.md` ·
`plans/AUDIT-VLT/phases/P1.md` (Phase Handoff) · `plans/AUDIT-VLT/findings/REGISTER.md` ·
`plans/AUDIT-VLT/findings/P0-*.md` и `findings/P2-package-tools.md` (нормативные RAG-выжимки, D16) ·
`plans/AUDIT-VLT/artifacts/finding-template.md`

**Open risks:**
- **Blocker F-P1.4-01** — гонка `Upload-Offset` в tus без row-lock: два конкурентных PATCH
  тихо портят собранный файл (`TusUploadHandler.php:80-108`, U2.1/U2.2).
- **Blocker F-P1.7-01** — исходящие webhook без SSRF-защиты: пользовательский endpoint-URL
  бьёт в приватную сеть/`169.254.169.254` (`RegisterWebhookEndpointRequest.php:27`, U6.1).
- **Blocker F-P1.11-01** — серверный TipTap-рендер не фильтрует схему URL в `href`:
  stored XSS через `javascript:`-ссылку (`TipTapRenderer.php:93`, G2.2/G2.4).
- 18 Major из P1 — детали в REGISTER; в P2 они НЕ дублируются, ставится ссылка на `F-P1.*`.
- Ожидаемые оси риска P2 (гипотезы, не находки): нет ключа `connection` (E2.3);
  `Relation::morphMap` не регистрируется ни одним провайдером (E6.2); `vaulter-drive`/
  `vaulter-media` мержат конфиг в `vaulter.*`, но не публикуют его (PT1.4);
  `packages/vue` версии `0.0.0`.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация фаз P3–P5 (скелеты); ремедиация Blocker/Major — в P5.2 (D2, D7).
- open_questions: — (Q1–Q4 закрыты владельцем → D11, D12, D13, D15).
