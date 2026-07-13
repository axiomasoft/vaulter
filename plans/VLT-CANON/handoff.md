# HANDOFF — 2026-07-13 — after P2.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — открытая owner-развилка D9 + возможный BC-перенос namespace |
| Context | continue (/clear) — ручной item |
| Суть | P2.2: закрыть развилку D9 (§8.3) — сливать ли `vaulter-media` в `vaulter-drive`. Рекомендация плана — **оставить media standalone** (чистый CAS-движок, drive и так `requires media`, слияние ломает topology-инвариант ADR-0008 без продуктовой выгоды). Решение принимает owner (прецедент D7/D8 — закрывались прямо в plan-exec). Если owner выберет A (оставить) — item закрывается записью D9 без кода; если B (слить) — перенос `media/src/{Cas,Console}` → `drive/src/`, ребиндинг `Contracts\Cas\*`, снятие `vaulter-media` из require, CI-lane, BC-запись |

```
/task:plan-exec VLT-CANON P2.2
```

> Переисполнитель, желающий пересмотреть саму развилку (а не исполнить её), может вместо этого
> позвать `/task:plan-design VLT-CANON P2` — D9 задевает топологию пакетов (closed-enum-триггер
> «неясная архитектура»). Но текст P2.2 уже несёт оба варианта с плюсами/минусами и явную
> рекомендацию — прямой exec достаточен.

**Done:** P2.1 🟠 Done with deviations. Пакет-мост AzGuard переименован:
`packages/permissions-azgard/`→`packages/azgard/`, composer
`axioma-studio/vaulter-permissions-azgard`→`axioma-studio/vaulter-azgard`, namespace
`Vaulter\Permissions\Azgard\*`→`Vaulter\Azgard\*` (hard rename, пре-1.0, `git mv` — renames
трекаются). CI: `permissions-azgard.yml`→`azgard.yml` + `monorepo-split.yml` matrix. Core:
новый arch-гвард `core source never references the vaulter-azgard package` (через существующий
`coreReferencesTo('Azgard')`) + путь пакета в `DocsDriftGuardTest`. **Контракт-шов
`Vaulter\Contracts\Access\AzgardGuard` в core НЕ тронут** (`git diff HEAD -- packages/core/src`
→ пусто), config/publish-тег байт-идентичны. Гейты: pint passed · phpstan max 0 errors · root
pest 497/497 (1281 assertion) · type-cov 100% · собственный гейт пакета 20 passed. Ревью:
`vaulter-api-surface` → PASS (clean rename-only diff), `vaulter-reviewer` → approve с 2 MED +
2 LOW; MED/LOW-в-скоупе закрыты отдельным коммитом (честный CHANGELOG — пакет ни разу не
публиковался, значит это не слом релиза; ADR-0017 на новое имя; осиротевший split-таргет).
Commits: `2c3b9cb`, `987cb3a`.

**Remaining:** P2 — 1/4 (P2.2 media-развилка D9 · P2.3 catch-all `Documents/`/`Uploads/`/`Support/` ·
P2.4 Access/Uploads-сверка) → P3 (docs/ROADMAP/arch-тесты/supersede VLT-HSEAM).
Открытая owner-развилка: **D9** (P2.2). D7/D8/D10/D11 — закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P2.1 Completion Notes, §4 Status
Board P2=1/4, §5 Decision Log, §8.3 — обе стороны развилки D9) · `docs/adr/0017-package-structure-canon.md`
(нормативный канон структуры + amendment P2.1) · `UPGRADE.md`/`CHANGELOG.md` `[Unreleased]` ·
`findings/structure-canon-inventory.md` · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **Required status checks на `main` не переименованы** (MED, ревью P2.1, **вне дерева репо**):
   job id и display-name azgard-лейна сменились (`vaulter-permissions-azgard (SQLite lane, …)` →
   `vaulter-azgard (…)`). GitHub матчит required checks по имени → старые имена в branch
   protection повесят PR'ы в pending навсегда; их отсутствие молча выкинет azgard-гейт из
   merge-gate. Чинит owner в настройках репозитория, не коммитом.
2. **`docs/plan/*` держат `permissions-azgard` в настоящем времени** (LOW, ревью P2.1):
   `00-master-plan.md:54,539`, `p2-5-option-b-topology.md`. Формально исторический срез, но
   master-plan читается как живой источник состояния — решить осознанно (кандидат в P3.1).
3. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4, MED): колонка
   хранит и `DocumentType`-значения, и handler-id. Назначенного item'а нет.
4. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major): XSS-барьер
   (`TipTapContent`/`LinkSchemeValidator`) в `packages/documents` не несёт enforcing-порогов
   mutation/coverage, какие были у него в `packages/core`. Нужен отдельный CI/gate-item — ни в
   одном P2.x/P3.x его нет.
5. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига (P0.1/P0.4 renames).
   Кандидат в P3.1.
6. **Pre-existing dead link `./activity`** в `guide/search-trash.md`/ru-зеркале ломает `docs:build`
   независимо от VLT-CANON. Кандидат в отдельный docs-fix item (или P3.1).
7. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1–P2.1 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
   (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
   Структуру плана правит plan-design, не exec.
8. **Реестр `registerEngineComponent` не реактивен** (наследуется из P1.2, 2 LOW, не блокирующие):
   регистрация ПОСЛЕ монтирования узла не подхватывается уже смонтированным `VaulterDocumentNode`.
   Задокументировано в `document-handlers.md` (en+ru).
9. **`packages/azgard/README.md` расходится с `azgard.yml`** (пред-существующее, не регресс P2.1):
   README говорит, что `tests/RealAzgard/` не гоняется в standalone-матрице, а workflow гоняет
   `pest --ci` без фильтра — т.е. гоняет.
10. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
    агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`,
    `pnpm-lock.yaml`) — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: required checks в branch protection (Open risk 1, owner). `docs/plan/*` стухшие имена
  (Open risk 2). `format` value-space (3). CI gate-scope gap (4). `document-handlers.md` FQCN (5).
  Dead link `search-trash.md` (6). README-vs-CI расхождение azgard (9).
- open_questions: **D9 — media→drive слияние?** Решается в P2.2 owner'ом; рекомендация плана
  (§8.3) — оставить media standalone.
