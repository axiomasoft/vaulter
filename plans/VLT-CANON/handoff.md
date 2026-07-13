# HANDOFF — 2026-07-13 — after P1.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — closed-enum эскалация-триггер: массовый namespace-refactor `permissions-azgard→azgard` |
| Context | continue (/clear) — ручной item |
| Суть | P2.1: переименование `packages/permissions-azgard/` → `packages/azgard/`, composer `vaulter-permissions-azgard`→`vaulter-azgard`, namespace `Vaulter\Permissions\Azgard\`→`Vaulter\Azgard\` — по `php:namespace-refactor-safety`, FQN-anchored find-replace, обновить composer path-repos/root composer.json/CI-lane/testbench.yaml/arch-guard'ы/docs; контракт-шов `Vaulter\Contracts\Access\AzgardGuard` (core) НЕ трогать |

```
/task:plan-exec VLT-CANON P2.1
```

**Done:** P1.3 🟢 Done — **фаза P1 терминальна (3/3 items, 1🟠)**. Выбран вариант «гайд» (не
example-npm-пакет) — согласуется с D8. §7 «Mounting it on the client» в
`docs/guide/writing-an-engine.md` (+ru) расширен конкретным клиентским скелетом:
`AppMyEditor.vue` типизирован реальным экспортом `DocumentNodeEditorProps`
(`VaulterDocumentNode.vue`), сужает `handler.ui.props` до своей формы (`MyEditorProps`); блок
регистрации `registerEngineComponent(id, component)` в `main.ts`. Явно зафиксировано: отдельный
npm-пакет не нужен (D8) — движок ставляется как код хост-приложения. `pnpm install` понадобился
(root `node_modules` не нёс `vitepress`/`vue-tsc`) — `pnpm-lock.yaml` создан, НЕ закоммичен (вне
Scope item'а). `docs:build` упал на pre-existing dead link `./activity` в
`guide/search-trash.md`/ru-зеркале (не мой файл, не регресс) — верифицировал свой дифф обходом
(`ignoreDeadLinks: true` временно, откачен) → build прошёл чисто. `@vaulter/vue` typecheck
(фоновый прогон) → exit 0, без дрейфа (item не трогал `packages/vue/src/**`). `vaulter-reviewer`
не вызывал — item не трогает публичный контракт/фасад/config/дескриптор (Execution Rules
критерий не сработал).
Commits: `2b9f2f7`.

**Remaining:** P2 (структурный канон: azgard-rename P2.1 opus/high · media→drive развилка D9 в
P2.2 · catch-all P2.3 · Access/Uploads P2.4) → P3 (docs/ROADMAP/arch-тесты/supersede VLT-HSEAM).
Открытая owner-развилка: **D9** (media→drive, P2.2). D7/D8/D10/D11 — закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P1.3 Completion Notes, §4 Phase
Status Board P1=3/3 (1🟠) 🟢 Done) · `docs/guide/writing-an-engine.md` §7 (+ru) ·
`docs/guide/document-handlers.md` §«Mounting the resolved handler in Vue» (+ru) ·
`packages/vue/src/engineRegistry.ts` · `findings/*` (4 файла, вкл.
`frontend-engine-packaging-research.md` — основание D8) · наследие:
`plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4 ревью, MED):
   колонка хранит и `DocumentType`-значения, и handler-id (`univer`). Нет назначенного item'а —
   кандидат в отдельное решение вне текущего скоупа плана.
2. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major, не почищено): XSS-барьер
   (`TipTapContent`/`LinkSchemeValidator`) в `packages/documents` не несёт эквивалентных
   enforcing-порогов mutation/coverage, какие были у него в `packages/core`. Нужен отдельный
   CI/gate-item — ни в одном текущем P2.x/P3.x его нет.
3. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига (со времён
   P0.1/P0.4 renames). Кандидат в P3.1 (docs-item) или отдельную мелкую правку.
4. **Pre-existing dead link `./activity`** в `guide/search-trash.md`/`ru/guide/search-trash.md`
   ломает `docs:build` (обнаружено в P1.3, не мой файл, не регресс этого item'а) — блокирует
   docs-CI-гейт независимо от VLT-CANON. Кандидат в отдельный docs-fix item (или P3.1).
5. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1–P1.3 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
   (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
   Структуру плана правит plan-design, не exec.
6. **Реестр `registerEngineComponent` не реактивен** (наследуется из P1.2 ревью, 2 LOW, не
   блокирующие): регистрация ПОСЛЕ монтирования узла не подхватывается уже смонтированным
   `VaulterDocumentNode`; `watch` на warn — без `immediate: true`. Задокументировано в
   `document-handlers.md` (en+ru), кода не меняли.
7. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
   агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`,
   `pnpm-lock.yaml` от P1.3) — не мой, не трогал (кроме `pnpm-lock.yaml`, который сам создал
   установкой зависимостей для docs:build-верификации — не закоммичен).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: `format` value-space унификация (Open risk 1). CI gate-scope gap (Open risk 2).
  `document-handlers.md` стухшие FQCN (Open risk 3). Pre-existing dead link `search-trash.md`
  (Open risk 4). D9 — до P2.2.
- open_questions: —
