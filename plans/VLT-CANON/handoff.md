# HANDOFF — 2026-07-13 — after P1.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — механический скелет/гайд по уже закрытому шву (D8, P1.2) |
| Context | continue (/clear) — ручной item |
| Суть | P1.3: скелет-пакет/гайд стороннего фронт-движка — по D8 (единый `@vaulter/vue`, engine внутри) показать, как хост регистрирует компонент по имени движка (`registerEngineComponent`, только что формализован в P1.2) и получает `HandlerUi.props`; гайд/скелет проходит собственный build |

```
/task:plan-exec VLT-CANON P1.3
```

**Done:** P1.2 🟢 Done — фронт-реестр компонентов (`VaulterDocumentNode.vue` `componentMap`)
формализован как публичный API: новый модуль `packages/vue/src/engineRegistry.ts`
(`registerEngineComponent`/`unregisterEngineComponent`/`resolveEngineComponent`/
`registeredEngineComponentIds`/`clearEngineComponents`), explicit ES-module import вместо
implicit-глобала (урок Nextcloud Viewer). Реестр — фолбэк ПОСЛЕ `componentMap` prop (BC
bit-for-bit, подтверждено `vaulter-api-surface`). Dev-mode `console.warn` на unmapped
`ui.component`/`id` — в `watch`, не в `computed` (находка первого прогона ревью MED,
исправлено). Доки (en+ru) + CHANGELOG `[Unreleased] Added` синхронны; UPGRADE.md НЕ тронут
(чисто аддитивно, без обязательного действия потребителя). `vaulter-reviewer` первый прогон —
needs-work (1 HIGH задублированный ru-блок доков, 1 MED side-effect в computed, 1 LOW
отсутствие теста на warn) — все три закрыты коммитом `ea7eacb`. Второй прогон (после фикса) —
**approve**, 2 LOW не блокирующих (реестр не реактивен; `watch` без `immediate`) —
задокументированы явной фразой в доках (en+ru) коммитом `7304721`, кода не меняли. Гейты
зелёные: `vue-tsc` clean, `vitest` 112/112, `vite build` ✓.
Commits: `879d370`, `ea7eacb`, `e02dfc9` (закрытие плана, первая версия), `7304721`
(докдополнение по 2-му прогону ревью — Completion Notes/handoff требуют финального
sync-коммита плана).

**Remaining:** P1.3 (скелет-пакет/гайд стороннего движка, sonnet/low) → P2 (структурный канон:
azgard-rename P2.1 opus/high, media→drive развилка D9 в P2.2, catch-all P2.3, Access/Uploads
P2.4) → P3 (docs/ROADMAP/arch-тесты/supersede VLT-HSEAM). Открытая owner-развилка: **D9**
(media→drive, P2.2). D7/D8/D10/D11 — закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P1.2 Completion Notes, §4 Phase
Status Board P1=1/3 (1🟠) 🟡) · `CHANGELOG.md [Unreleased] Added` (P1.2 запись) ·
`docs/guide/document-handlers.md` §«Mounting the resolved handler in Vue» + §Reference (+ru) ·
`docs/guide/writing-an-engine.md` §7 (+ru) · `packages/vue/src/engineRegistry.ts` ·
`findings/*` (4 файла, вкл. `frontend-engine-packaging-research.md` — основание D8) · наследие:
`plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

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
4. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1–P1.2 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
   (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
   Структуру плана правит plan-design, не exec.
5. **Реестр `registerEngineComponent` не реактивен** (наследуется из P1.2 ревью, 2 LOW, не
   блокирующие): регистрация ПОСЛЕ монтирования узла не подхватывается уже смонтированным
   `VaulterDocumentNode`; `watch` на warn — без `immediate: true` (полагается на асинхронное
   заполнение `handler` через `loadAll()`). Задокументировано в `document-handlers.md` (en+ru),
   кода не меняли — штатный сценарий («регистрируй до монтирования приложения») их не задевает.
6. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
   агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`)
   — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: `format` value-space унификация (Open risk 1). CI gate-scope gap (Open risk 2).
  `document-handlers.md` стухшие FQCN (Open risk 3). D9 — до P2.2.
- open_questions: —
