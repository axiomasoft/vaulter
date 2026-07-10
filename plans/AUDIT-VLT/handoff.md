# HANDOFF — 2026-07-10 — after P0.0

**Next:** /task:plan-exec AUDIT-VLT P0.1

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium |
| Context | fresh session |
| Суть | Baseline-инвентаризация репозитория и тулинга vaulter → `findings/P0.1-baseline-inventory.md` |

````
/task:plan-exec AUDIT-VLT P0.1 — читать cold-start: plans/AUDIT-VLT/plan.md, plans/AUDIT-VLT/phases/P0.md, plans/AUDIT-VLT/artifacts/finding-template.md. Аудит read-only (D2): менять файлы вне plans/AUDIT-VLT/ запрещено; repro — только в plans/AUDIT-VLT/artifacts/repro/. PHP-бинарь — /usr/bin/php8.4.
````

**Done:** План AUDIT-VLT создан (6 фаз, 34 items). P0 и P1 детализированы до исполняемого ТЗ
(15 items со всеми 16 полями и закрытыми чеклистами). P2–P5 — скелеты со скелет-маркером
и блоком «Обязательные оси» в Phase Context.
Владелец ответил на все открытые вопросы → D11 (много хостов, мерим гибкость настройки),
D12 (минимум PHP 8.4), D13 (realtime-collab — опциональная надстройка, не базовая поставка),
D15 (скачивание только через PHP, прямой раздачи из бакета нет). D14 добавил item P3.6.
Внешний аудит плана разобран построчно → `findings/P0-audit-gaps.md`: принято 10
рекомендаций, отклонено 3 с доказательством, принято частично 2 (D16, D17). Собственный RAG
нашёл оси, пропущенные и планом, и внешним аудитом (контентная безопасность, remanence) →
D18 добавил items P1.9–P1.12.
Design-time RAG выполнен: 4 выжимки в `findings/P0-*.md` (perplexity-web ×4, context7
`/websites/tus_io`). Pre-mortem по Klein проведён → `findings/P0-premortem.md`, митигации
зафиксированы решениями D2–D7. Схема находок и severity-шкала — `artifacts/finding-template.md`.
`plans/ACTIVE.md` создан, Active = AUDIT-VLT (ранее активного плана в репо не было).

**Remaining:**
- P0 (3 items) → P1 (12 items) — детализированы, готовы к исполнению.
- P2 (5), P3 (6), P4 (5), P5 (3) — скелеты; каждую фазу перед исполнением разворачивать
  командой `/task:plan-design AUDIT-VLT Pn` (refine-цикл + DoR-гейт). Блок «Обязательные
  оси» в Phase Context — обязательный вход развёртывания, не пожелание.

**Sources of truth:** `plans/AUDIT-VLT/plan.md` · `plans/AUDIT-VLT/phases/P0.md` ·
`plans/AUDIT-VLT/phases/P1.md` · `plans/AUDIT-VLT/artifacts/finding-template.md` ·
`plans/AUDIT-VLT/findings/P0-audit-gaps.md` · `plans/AUDIT-VLT/findings/REGISTER.md` ·
`plans/AUDIT-VLT/open-questions.md` ·
код: `packages/core/src/Access/AccessResolver.php`, `packages/core/config/vaulter.php`,
`docs/adr/index.md`

**Open risks:**
- Аудит ещё не начат — ни одна находка не подтверждена; риски пакета неизвестны.
- Наибольшая априорная концентрация риска (по проектированию чеклистов): P1.4 `C4`
  (row-lock при инкременте `Upload-Offset`), P1.5 `C4` (путь к блобу в обход ACL узла),
  P1.6 `C6`/`C13` (альтернатива «secret ИЛИ JWT»; enforcement `readOnly` на Node-стороне),
  P1.7 `C1` (SSRF через пользовательский endpoint-URL), P1.8 `C11` (дефолтный
  `middleware: ['api']` без `auth`).
- D13 (collab — опциональная надстройка) — продуктовое требование, а не описание текущего
  кода: вероятно, часть collab уже вшита в базовый путь. P1.6 `C18`–`C20` это измерит;
  масштаб расхождения станет известен только после исполнения.
- D15 (скачивание только через PHP) — при больших файлах даёт нагрузку на PHP-воркеры;
  производительность download-пути меряется в P4.1/P4.2, безопасность — в P1.3 `C16`.
- Два конкретных внешних факта, найденных design-time RAG и требующих проверки в первую
  очередь: **CVE-2025-14284** (`javascript:` в `href` TipTap-link до 2.10.4, а
  `packages/vue/package.json` объявляет `^2.10` — P1.11 `C8`) и **CORS по умолчанию**
  (`config/cors.php` не публикуется, дефолтный `paths: ['api/*']` покрывает
  `route_prefix: api/vaulter` с `allowed_origins: ['*']` — P1.8 `C16`).
- Пакет идёт к первому релизу: Blocker в P1.11 (stored XSS) или P1.9 (tenant-изоляция)
  означает откладывание тега, а не запись в бэклог.
- Дешёвая модель может выродить аудит в пересказ кода — митигация D3 (закрытые чеклисты)
  проверяется только по факту первого закрытого item'а P1.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: детализация фаз P2–P5 (сознательно отложена до закрытия P0/P1 — чеклисты этих
  фаз опираются на `findings/P0.1-baseline-inventory.md` и `findings/P0.2-rag-supplement.md`).
- open_questions: — (Q1–Q4 закрыты владельцем 2026-07-10 → D11, D12, D13, D15;
  `plans/AUDIT-VLT/open-questions.md` не содержит вопросов в статусах `Exploration` /
  `Decision pending`).
