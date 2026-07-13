# HANDOFF — 2026-07-13 — after P3.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — расширение arch-suite (dependency-direction поверх результатов azgard-rename/media-решения), не чистая механика |
| Context | continue (/clear) — ручной item |
| Суть | P3.2: расширить arch-suite финального канона — «0 tiptap-ссылок в core», «движок-контракт kind-нейтраль», dependency-direction после azgard-rename (P2.1)/media-решения (D9) |

```
/task:plan-exec VLT-CANON P3.2
```

**Done:** P3.1 🟢 Done. ROADMAP получил секцию «`vaulter-filament` — engine-aware resources»
(под «## Admin UI»), фиксирующую D6 (Filament вне scope VLT-CANON) как задел. Docs: найдены и
починены 3 реальных стухших FQCN/ссылки, накопленные P0.1–P0.4 (закрывают Open risks 2/5/6 из
предыдущего handoff — частично, см. Remaining):
1. `docs/reference/contracts.md` — таблица + раздел `NodeHandlerResolver` утверждали «concrete
   resolver ships in vaulter-documents» / «fails loudly without vaulter-documents» — неверно
   с P0.4/D10 (резолвер безусловно в core) → переписано на факт.
2. `docs/guide/document-handlers.md`+ru — пример импортировал несуществующий
   `Vaulter\Documents\NodeHandlerResolver` → `Vaulter\Contracts\Handlers\NodeHandlerResolver`.
3. `docs/guide/document-handlers.md`+ru — `Vaulter\Enums\Documents\Capability` (не существует
   с P0.4) → `Vaulter\Enums\Handlers\Capability`.
4. `docs/guide/search-trash.md`+ru — dead-link `./activity.md` (страница никогда не
   существовала, ломала `docs:build`) → `/reference/rest-api#activity-feed` (ru: якорь
   «лента-активности»).
`docs/adr/index.md` сознательно НЕ тронут — строка на новый ADR добавляется вместе с созданием
самого файла в P3.3 (иначе dead-link сломал бы `docs:build`). Гейт: `pnpm run docs:build` →
`build complete`, 0 ошибок. Commits: `950ae25`.

**Remaining:** P3.2 (arch-тесты финального канона) → P3.3 (ADR канона движков + supersede
VLT-HSEAM, туда же — строка в `docs/adr/index.md`). Фаза P3 — последняя, план после неё
терминален.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P3.1 Completion Notes, §4 Status
Board P3=1/3) · `docs/adr/0017-package-structure-canon.md` (нормативный канон структуры) ·
`findings/structure-canon-inventory.md` · `UPGRADE.md`/`CHANGELOG.md` `[Unreleased]` ·
наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md` (supersede — предмет P3.3).

**Open risks:**
1. **Required status checks на `main` не переименованы** (MED, наследуется из P2.1, **вне
   дерева репо**): job id/display-name azgard-лейна сменились; чинит owner в настройках
   репозитория, не коммитом.
2. **`docs/plan/*` держат старые FQCN в настоящем времени** (LOW, наследуется из P2.1/P2.3,
   рассмотрено и осознанно оставлено в P3.1 — эти файлы не в `docs:build`-навигации/sidebar,
   историческая калька design-фазы): `00-master-plan.md:54,539`, `p2-5-option-b-topology.md`
   (permissions-azgard); `p2-5-namespace-strategy-drive-docdrive.md`,
   `p2-5-option-b-topology.md` (`Documents\Documents`, `Drive\Uploads\StagedUpload`). Правка —
   отдельное решение, не назначен ни в один item.
3. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4, MED).
   Назначенного item'а нет.
4. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major): XSS-барьер в `packages/documents`
   не несёт enforcing-порогов mutation/coverage. Нужен отдельный CI/gate-item — ни в одном
   P2.x/P3.x его нет.
5. **`docs/ru/reference/contracts.md` заметно отстал от en** (LOW, найдено в P3.1): нет секций
   `HandlerRegistry`/`NodeHandlerResolver` вообще, местами держит устаревшую номенклатуру
   (`PermissionResolver`). Полная синхронизация — контент-объём вне docs-item'а sonnet/low;
   кандидат в отдельный docs-item.
6. **`docs/adr/index.md` без строки на новый ADR** — намеренно, закрывается в P3.3 вместе с
   созданием самого файла (см. P3.1 Completion Notes).
7. `plan-lint.py plans/VLT-CANON` — красный (не регресс): линтер требует `phases/P0..P3.md` +
   `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases (детализация)` (Update Log —
   `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана. Структуру плана правит
   plan-design, не exec.
8. **Реестр `registerEngineComponent` не реактивен** (наследуется из P1.2, 2 LOW, не
   блокирующие): регистрация ПОСЛЕ монтирования узла не подхватывается уже смонтированным
   `VaulterDocumentNode`. Задокументировано в `document-handlers.md` (en+ru).
9. **`packages/azgard/README.md` расходится с `azgard.yml`** (пред-существующее, не регресс
   P2.1): README говорит, что `tests/RealAzgard/` не гоняется в standalone-матрице, а workflow
   гоняет `pest --ci` без фильтра — т.е. гоняет.
10. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
    агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`,
    `pnpm-lock.yaml`) — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: required checks в branch protection (Open risk 1, owner). `docs/plan/*` стухшие
  имена (2). `format` value-space (3). CI gate-scope gap (4). ru contracts.md синхронизация (5).
  `docs/adr/index.md` строка (6, → P3.3). README-vs-CI расхождение azgard (9).
- open_questions: —
