# HANDOFF — 2026-07-13 — after P2.4

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — docs/ROADMAP-item по готовым материалам плана |
| Context | continue (/clear) — ручной item |
| Суть | P3.1: ROADMAP (пункт про `vaulter-filament`-задел, D6) + обновить `docs/guide/*`, `docs/reference/contracts.md`, `docs/adr/index.md` (новый ADR канона движков, см. P3.3); заодно кандидат на дочистку накопленных стухших FQCN в `docs/plan/*`/`document-handlers.md` (Open risks 2/5) |

```
/task:plan-exec VLT-CANON P3.1
```

**Done:** P2.4 🟢 Done, **фаза P2 терминальна (4/4, 1🟠)**. Три вердикта:
`documents/src/Access/HostUserResolver` → `Collaboration/HostUserResolver` (`git mv`, namespace
`Vaulter\Documents\Access`→`Vaulter\Documents\Collaboration`) — класс не был access-control, реализует
контракт коллаборационного вебхука («purely collaboration-webhook machinery» по собственному
докблоку), присоединился к `NodeCollaborationDriverManager`/драйверам в существующей папке
`Collaboration/`; core `Access/` и `Webhooks/` сверены с ADR-0017 — **no-op**, ADR называет их
эталонной формой самого паттерна domain-service folder. Попутно исправлен пред-существующий стухший
FQCN в доках (`docs/{reference,ru/reference}/contracts.md` несли `Vaulter\Access\HostUserResolver`
без сегмента `\Documents\`). Гейты (pint/phpstan max/pest×2 пакета/type-cov 100%) зелёные;
`vaulter-reviewer` не требовался Routing'ом; `vaulter-test-verifier` — documents suite зелёный
(214/1 skip), core suite упал внутри саб-агента по причине окружения (стандэлон-тестсьют core требует
запуска из корня монорепы — известное свойство, не регресс), перепроверено вручную из корня: 497/497.
Commits: `da3e64b`.

**Remaining:** P3.1 → P3.2 (arch-тесты финального канона) → P3.3 (ADR канона движков + supersede
VLT-HSEAM). Фаза P3 — последняя, план после неё терминален. Открытых owner-развилок больше нет:
D7/D8/D9/D10/D11 — все закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P2.4 Completion Notes, §4 Status
Board P2=4/4 терминальна) · `docs/adr/0017-package-structure-canon.md` (нормативный канон структуры) ·
`findings/structure-canon-inventory.md` §2.4 (источник вердиктов P2.4) · `UPGRADE.md`/`CHANGELOG.md`
`[Unreleased]` · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md` (supersede — предмет P3.3).

**Open risks:**
1. **Required status checks на `main` не переименованы** (MED, наследуется из P2.1, **вне
   дерева репо**): job id/display-name azgard-лейна сменились; чинит owner в настройках
   репозитория, не коммитом.
2. **`docs/plan/*` держат старые FQCN в настоящем времени** (LOW, наследуется из P2.1,
   расширено P2.3): `00-master-plan.md:54,539`, `p2-5-option-b-topology.md` (permissions-azgard);
   `p2-5-namespace-strategy-drive-docdrive.md`, `p2-5-option-b-topology.md` (`Documents\Documents`,
   `Drive\Uploads\StagedUpload`) — исторический срез, кандидат в P3.1.
3. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4, MED).
   Назначенного item'а нет.
4. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major): XSS-барьер в `packages/documents`
   не несёт enforcing-порогов mutation/coverage. Нужен отдельный CI/gate-item — ни в одном
   P2.x/P3.x его нет.
5. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига (P0.1/P0.4).
   Кандидат в P3.1.
6. **Pre-existing dead link `./activity`** в `guide/search-trash.md`/ru-зеркале ломает
   `docs:build` независимо от VLT-CANON. Кандидат в отдельный docs-fix item (или P3.1).
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
  имена (2). `format` value-space (3). CI gate-scope gap (4). `document-handlers.md` FQCN (5).
  Dead link `search-trash.md` (6). README-vs-CI расхождение azgard (9).
- open_questions: —
