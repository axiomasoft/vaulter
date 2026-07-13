# HANDOFF — 2026-07-13 — after P1.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — публичный фронт-шов (`componentMap`→registry API), DX-обязательство |
| Context | continue (/clear) — ручной item |
| Суть | P1.2: формализовать фронт-реестр компонентов (`VaulterDocumentNode.vue` `componentMap`) как публичный API — explicit `registerEngineComponent(id, component)` (не implicit-глобал), dev-mode warning на незарегистрированный `handler.id`, экспортировать типы; view не переписывать, только формализовать шов; prop `componentMap` остаётся обратно совместимым |

```
/task:plan-exec VLT-CANON P1.2
```

**Done:** P1.1 🟠 Done with deviations — D8 закрыт owner'ом буквально как вариант A
(§8.2, прямой вопрос в сессии, Dmitry Vostrikov, 2026-07-13): единый `@vaulter/vue` остаётся
shell/core, TipTap-движок живёт внутри него на одном version-train с PHP; per-engine npm
(`@vaulter/vue-engine-<name>`) вводится лениво — только при реальном внешнем потребителе.
Альтернатива не выбрана → P1.2/P1.3 передетализация НЕ требуется, их текущая детализация в
plan.md уже написана под D8=A. Кода/структуры пакетов item не менял (D8 — решение о будущей
политике). Known Deviation: effort исполнен `opus/medium` вместо заявленного `opus/high`
(дефолт `/task:plan-exec`) — риск низкий, item решал готовую owner-развилку без техреализации.

**Remaining:** P1.2 (формализация фронт-реестра — публичный API) → P1.3 (скелет-пакет/гайд
стороннего движка, sonnet/low) → P2 (структурный канон: azgard-rename P2.1, media→drive
развилка D9 в P2.2, catch-all P2.3, Access/Uploads P2.4) → P3 (docs/ROADMAP/arch-тесты/
supersede VLT-HSEAM). Открытая owner-развилка: **D9** (media→drive, P2.2). D7/D8/D10/D11 —
закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P1.1 Completion Notes, §5
Decision Log D8 закрыт, §4 Phase Status Board P1=0/3 (1🟠) 🟡) · `UPGRADE.md` (SSOT миграции
P0.1–P0.5, P1.1 кода не меняла) · `findings/*` (4 файла, вкл.
`frontend-engine-packaging-research.md` — основание D8) · `docs/guide/writing-an-engine.md`
(+ru) · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4 ревью,
   MED): колонка хранит и `DocumentType`-значения, и handler-id (`univer`). Нет назначенного
   item'а — кандидат в отдельное решение вне текущего скоупа плана.
2. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major, не почищено): XSS-барьер
   (`TipTapContent`/`LinkSchemeValidator`) в `packages/documents` не несёт эквивалентных
   enforcing-порогов mutation/coverage, какие были у него в `packages/core`. Нужен отдельный
   CI/gate-item — ни в одном текущем P1.x/P2.x/P3.x его нет.
3. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига (со времён
   P0.1/P0.4 renames). Кандидат в P3.1 (docs-item) или отдельную мелкую правку.
4. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1–P1.1 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
   (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
   Структуру плана правит plan-design, не exec.
5. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
   агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`)
   — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: `format` value-space унификация (Open risk 1). CI gate-scope gap (Open risk 2).
  `document-handlers.md` стухшие FQCN (Open risk 3). D9 — до P2.2.
- open_questions: —
