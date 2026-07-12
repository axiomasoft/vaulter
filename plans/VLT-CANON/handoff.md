# HANDOFF — 2026-07-13 — after P0.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — Q1/D10 решает архитектурный вопрос (реестр движков → core?), ревизия H.5+T5 |
| Context | continue (/clear) — ручной item |
| Суть | P0.4: убрать `default('tiptap')` у `v_node_document_versions.format` (PanelTablesBuilder); решить D10 (§8.4) — движок-реестр (`HandlerRegistry`/`NodeHandlerResolver`/`DownloadOnlyHandler`/`PreviewHandler`) переезжает в core или остаётся в documents |

```
/task:plan-exec VLT-CANON P0.4
```

**Done:** P0.3 🟠 Done with deviations. 5 TipTap-специфичных классов (`TipTapContent`,
`LinkSchemeValidator`, `OpaqueDocumentContent`, `#[TiptapExtensions]`,
`InvalidTipTapContentException`) переехали `git mv` из `packages/core` в `packages/documents`
(`Vaulter\Documents\{ValueObjects,Attributes,Exceptions}\*`) — core больше не импортирует ни одной
конкретной формы контента, только контракт `DocumentContent` (докблок `Contracts/Handlers/
DocumentContent.php` очищен от `use`-импортов). XSS-барьер (store-time allow-list, read-time
decay, URL-scheme allow-list, R-01/R-11/R-12) перенесён байт-в-байт — подтверждено `vaulter-reviewer`
построчным диффом против pre-move git-blob'ов. Гейты зелёные: pint · phpstan max 0 errors ·
core 487/487 · documents 209/1 skipped · type-cov 100% обе стороны. `vaulter-api-surface` (второй
прогон, первый ошибочно сравнил с `main` и дал false-positive) → clean rename-only diff, 65 файлов.
Доки (UPGRADE.md/CHANGELOG.md/architecture.md + ru-зеркала) синхронны, включая ретро-фикс стухших
P0.1-формулировок. Коммит: `a1f2f7c`.

**Remaining:** P0.4 (схема без `default('tiptap')` + решает D10: реестр движков → core?) → P0.5
(richText-дефолт + capability-policy + DX-каркас, решает D11) → P1–P3. Открытые owner-развилки:
D8 (фронт-упаковка, P1.1), D9 (media→drive, P2.2), D10 (P0.4), D11 (P0.5).

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P0.1–P0.3 Completion Notes) ·
`UPGRADE.md` (SSOT миграции: FQCN-таблицы P0.1/P0.3 + секция сигнатуры экспортёра P0.2) ·
`findings/*` (4 файла) · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **Read-path экспорта всё ещё TipTap-only** (наследуется из P0.2 Pending Work, не тронуто P0.3):
   `NodeExportService::contentOf()` гонит любую версию через `TipTapContent::decayFrom()`, который
   при `type !== 'doc'` возвращает `empty()`, а не бросает. Чинить в P0.4/P0.5 вместе со
   схемой/`format`-дискриминатором.
2. **Gate-scope gap (найдено ревью P0.3, Major, не почищено — вне Scope Included P0.3):**
   `TipTapContent`/`LinkSchemeValidator` (XSS-барьер) покинули `packages/core/src`, вынеся с собой
   покрытие enforcing-порогов `.github/workflows/mutation.yml` (`--min=95`, path=`packages/core/src`)
   и `tests.yml` coverage (`--min=80`). Новый дом `packages/documents` в CI (`documents.yml`) не
   несёт эквивалентных enforcing-порогов (`pest --ci` без `--coverage --min=N`, infection `|| true`).
   Итог: будущее ослабление allow-list-теста в `documents`-сьюте не покраснит ни один гейт. Нужен
   отдельный CI/gate-item (вне текущих P0.x/P1.x/P2.x/P3.x) — предложить в следующем `/task:plan-design`
   заходе на план, если ещё не заведён.
3. **Известное отклонение приёмки P0.3** (Known Deviations #2): `grep -ri tiptap core/src core/tests`
   не даёт буквально 0 — остаются `Config::tiptap*()` аксессоры (host-facing config, вне Scope) и
   `default('tiptap')` (явно передан в P0.4). Формулировка приёмки в plan.md шире фактического Scope
   item'а — правка формулировки предмет `plan-design`, не exec.
4. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1/P0.2/P0.3 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases (детализация)`
   (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
5. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**` агент-пак,
   `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`) — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: kind-нейтральный `contentOf()` → P0.4/P0.5 (Open risk 1). CI gate-scope gap → отдельный
  item (Open risk 2). `Config::tiptap*`/`default('tiptap')` — P0.4 (Open risk 3). D8/D9/D10/D11 — до
  своих items (P1.1/P2.2/P0.4/P0.5).
- open_questions: —
