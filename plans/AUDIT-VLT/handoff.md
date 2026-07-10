# HANDOFF — 2026-07-10 — after P1.12

**Next:** развернуть скелет фазы P2 (Встраиваемость и DX) командой `/task:plan-design AUDIT-VLT P2` (D17).

| Model | Thinking | Context | Суть |
|:--|:--|:--|:--|
| opus | high | fresh session | Развёртка скелета фазы P2: refine + DoR-гейт, детализация 5 items из «Обязательных осей» Phase Context |

````
/task:plan-design AUDIT-VLT P2
````

**Done:**
- Фаза P0 (Research & Baseline) — 🟢 Done (3/3), 0 находок.
- Фаза P1 (Безопасность) — закрыта: P1.1 🟢 Done, P1.2–P1.12 🟠 Done with deviations.
  53 находки в `findings/REGISTER.md` (3 Blocker, 18 Major, 30 Minor, 2 Nit); каждый item —
  собственный `findings/P1.*-*.md` с закрытым чеклистом и repro в `artifacts/repro/P1.*/`.
- Адверсариальная верификация (D19г): F-P1.1-03 опровергнута 2/3 скептиками, в REGISTER
  не внесена, помечена в `findings/P1.1-access-resolver.md`.
- Read-only соблюдён (D2): `git status` по `packages/ docs/ e2e/ hocuspocus-server/` чист.

**Remaining:**
- P2 (5), P3 (6), P4 (5), P5 (3) — скелеты; каждую фазу перед исполнением разворачивать
  `/task:plan-design AUDIT-VLT Pn`. Блок «Обязательные оси» в Phase Context — вход развёртывания.
- План AUDIT-VLT остаётся 🟡 In progress: есть подтверждённые Blocker'ы (D7), закрытие
  в 🟢/🟠 — только после решения владельца в P5.2.

**Sources of truth:** `plans/AUDIT-VLT/plan.md` · `plans/AUDIT-VLT/phases/P1.md` (Phase Handoff) ·
`plans/AUDIT-VLT/findings/REGISTER.md` · `plans/AUDIT-VLT/findings/P1.*-*.md` ·
`plans/AUDIT-VLT/findings/P0-*.md` (нормативные RAG-выжимки, D16) ·
`plans/AUDIT-VLT/artifacts/finding-template.md`

**Open risks:**
- **Blocker F-P1.4-01** — гонка `Upload-Offset` в tus без row-lock: два конкурентных PATCH
  тихо портят собранный файл (`TusUploadHandler.php:80-108`, U2.1/U2.2).
- **Blocker F-P1.7-01** — исходящие webhook без SSRF-защиты: пользовательский endpoint-URL
  бьёт в приватную сеть/`169.254.169.254` (`RegisterWebhookEndpointRequest.php:27`, U6.1).
- **Blocker F-P1.11-01** — серверный TipTap-рендер не фильтрует схему URL в `href`:
  stored XSS через `javascript:`-ссылку (`TipTapRenderer.php:93`, G2.2/G2.4).
- 18 Major — presigned-download в обход D15, confused-deputy и бессрочный TTL share,
  усечённый S3-commit, прямая запись в CAS-ключ, collab без фиче-флага и без подписи webhook,
  открытый signing_secret, outbox после коммита, отсутствие rate-limit, утечка листингов панели,
  доступ к trashed-узлу, неудаляемый контент версий, отсутствие nosniff. Детали — REGISTER.
- Все входные P0-риски (Flysystem `move` не атомарен, currency зависимостей,
  adjacency-list DoS) остаются актуальны и питают P4.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация фаз P2–P5 (скелеты; разворачиваются перед исполнением); ремедиация
  Blocker/Major вынесена в P5.2 (D2, D7).
- open_questions: — (Q1–Q4 закрыты владельцем → D11, D12, D13, D15).
