# HANDOFF — 2026-07-13 — after P3.3

**Next:** план закрыт — все 4 фазы (P0–P3, 15 items) терминальны (🟢, 2 🟠); Meta Status →
🟠 Done with deviations; `plans/ACTIVE.md` → `—`; заархивирован (`archive VLT-CANON`,
коммиты `6102638`/`27992a2`). Следующий план — по решению owner'а, вне протокола.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — plan-closure/архивация, не design/exec решение |
| Context | continue (/clear) — ручной шаг |
| Суть | план закрыт и заархивирован; следующий шаг вне протокола |

```
план закрыт
```

**Done:** P3.3 🟢 Done — последний item плана. Создан `docs/adr/0018-editor-engine-canon.md`
(Status: accepted), консолидирующий Decision Log D1–D12 (Context/Decision/Consequences/
Supersession) + сводку исполнённых пунктов P0–P2 (kind-нейтральные capability-дефолты,
опаковый exporter, изоляция TipTap, снятие DB-дефолта `format`, DX-каркас, финальный
структурный канон). `docs/adr/index.md` получил строку на ADR-0018 (закрыт Pending Work из
P3.1). `plans/VLT-HSEAM/plan.md` формально помечен: Meta `Status` → `⛔ Superseded — see
VLT-CANON P3.3 / ADR-0018`; верхний notice-блок переписан на «SUPERSEDED формально» + ссылка
на ADR. `plans/ACTIVE.md` не тронут (уже указывал `VLT-CANON` с 2026-07-12). Гейт:
`pnpm run docs:build` → `build complete`, 0 ошибок, 0 dead-link warnings. Commits: `c8d3136`.

**Remaining:** Нет открытых item'ов в VLT-CANON. План целиком терминален
(`## 4. Phase Index & Status Board`: P0 🟢, P1 🟢, P2 🟢, P3 🟢). Остаётся:
1. plan-closure/архивация плана (не exec-протокол) — синхронизировать `## 0. Meta` `Status`
   с фактом «все фазы 🟢», решить перенос в `plans/archive/`.
2. Residual-риски задокументированы в `docs/adr/0018-editor-engine-canon.md` §Consequences и
   ниже в Open risks — ни один не назначен в отдельный item плана VLT-CANON.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P3.3 Completion Notes, §4 Status
Board все фазы 🟢) · `docs/adr/0018-editor-engine-canon.md` (новый консолидирующий ADR,
единственный SSOT для D1–D12 canon-нарратива) · `docs/adr/0017-package-structure-canon.md`
(нормативный канон структуры, ADR-0018 его не меняет) · `plans/VLT-HSEAM/plan.md`
(superseded — исторический design-артефакт, не исполняется).

**Open risks:** (без изменений по существу с P3.2 — ADR-0018 §Consequences их фиксирует как
задокументированный residual, ни один не закрыт этим item'ом)
1. **Required status checks на `main` не переименованы** (MED, наследуется из P2.1, вне дерева
   репо): job id/display-name azgard-лейна сменились; чинит owner в настройках репозитория.

   **Дополнено 2026-07-13** (`/task:dev`, хвосты VLT-REMED, вне дерева VLT-CANON): к тому же
   классу долга — mutation-гейты `vaulter-{documents,drive,media,filament} (mutation gate)`
   (все четыре поднимаются на `pull_request`) не входят в required status checks `main`.
   **Причина — не права**: токен несёт scope `repo`+`workflow`, `viewerPermission = ADMIN`, но
   org `axioma-studio` на плане **free**, а репозиторий **private** — и
   `repos/:owner/:repo/branches/main/protection`, и `repos/:owner/:repo/rulesets` отвечают
   `403 Upgrade to GitHub Pro or make this repository public to enable this feature`. В этой
   конфигурации required checks на `main` **невозможны в принципе** — что делает неисполнимым и
   исходный пункт про «переименовать». Разблокирует владелец: план Team/Pro на org — либо
   публичный репозиторий.

   Хуже: сделать их required сейчас было бы **бессмысленно**. Тем же прогоном вскрыто, что все пять
   mutation-гейтов не могут упасть в принципе — флаг `--parallel` заставляет Pest считать убитым
   каждого мутанта (детали и воспроизведение: `plans/VLT-MUT/plan.md` §1 и
   `plans/VLT-MUT/findings/P0.1-parallel-defect.md`). Порядок работ: сначала VLT-MUT чинит измерение
   и доводит Score до реальных ≥ 95, и только потом имеет смысл требовать эти чеки на `main`.

   Отдельный факт (не про права, а про триггер): пятый гейт — core — это job
   `Mutation Testing / Mutation (advisory)` (`.github/workflows/mutation.yml`), поднятый только
   на `push: [main]` + `workflow_dispatch`. На PR он не исполняется, поэтому required-check'ом не
   стал бы и при доступном branch protection — для этого ему нужен триггер `pull_request`.
   Оставлен как есть: шапка workflow объявляет его advisory by design, смена контракта — решение
   владельца, не побочный эффект этого item'а.
2. ~~**`docs/plan/*` держат старые FQCN в настоящем времени** (LOW, наследуется из P2.1/P2.3).~~
   **Closed 2026-07-13** (`/task:fix`, вне дерева VLT-CANON): `00-master-plan.md:539` —
   хеджированное «`vaulter-permissions-azgard` или внутри media/core drivers» заменено на
   фактический пакет+FQCN (`Vaulter\Permissions\Azgard\AzgardPermissionResolver`);
   `p2-5-namespace-strategy-drive-docdrive.md:214` — `Drive\Uploads\StagedUpload` заменён на
   актуальный `Drive\Support\Uploads\StagedUpload` (P2.3); `p2-5-option-b-topology.md` — decision-лог
   намеренно не переписан (см. его же пре-существующее примечание про DocDrive→vaulter-documents),
   примечание расширено пойнтером на D10 engine-ownership (registry/handlers ушли в
   `Vaulter\Engines\*`) и на `UPGRADE.md` [Unreleased] с актуальными FQCN. Не в `docs:build`-навигации.
3. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4, MED).
   Задокументировано в ADR-0018 §Consequences. Назначенного item'а нет.
4. ~~**Gate-scope gap CI** (наследуется из P0.3/P0.4, Major): XSS-барьер в `packages/documents`
   не несёт enforcing-порогов mutation/coverage.~~ **Closed 2026-07-13** (`/task:dev`, вне дерева
   VLT-CANON), коммиты `6ca1b2a`/`7a3c89f`/`e3dd60e`.

   > **Отменяет более раннюю запись того же дня** (`/task:fix`), объявлявшую риск закрытым снятием
   > `|| true` с Infection-шага `documents.yml` при провизорных `minMsi: 50`/`minCoveredMsi: 70`.
   > Это было **ложное закрытие**: шаг физически не мог отработать — job поднят с `coverage: none`,
   > а Infection 0.33.3 не несёт Pest-адаптера (`testFramework` = phpunit/phpspec/codeception/testo),
   > ровно та причина, по которой VLT-REMED D38/P5.9 сняли Infection с корня. Снятие `|| true` не
   > включило гейт, а сделало лейн вечно-красным.

   Гейт закрыт по-настоящему и тем же инструментом, что корень: `pest --mutate --path=src
   --covered-only --parallel --min=95` в отдельном job'е с coverage-драйвером, без `|| true` —
   Pest роняет процесс сам при `Score < --min`. Порог = `floor(Score) − 5` по измерению 2026-07-13
   (pcov, PHP 8.5): `documents` — **Score 100.00 %, 1472 мутанта**. Гейт проверен на падение.
   Заодно закрыт тот же gap в трёх соседних лейнах (`drive` 100.00 %/1569, `media` 100.00 %/380,
   `filament` 100.00 %/189) — они несли ту же неработающую Infection-ступень. Четыре
   `infection.json5` удалены, `infection/infection` снят из require-dev, `make mutate` починен.
   Отдельно: `composer analyse` пакета `documents` был **красным** (2 × `nullsafe.neverNull`) —
   починено в `0247c53`, иначе гейт не на чем было бы строить. См. `CHANGELOG.md`/`UPGRADE.md`
   [Unreleased] и `plans/archive/VLT-REMED/findings/P5.3-closure-matrix.md` §6.
5. **`docs/ru/reference/contracts.md` заметно отстал от en** (LOW, найдено в P3.1): нет секций
   `HandlerRegistry`/`NodeHandlerResolver`, местами устаревшая номенклатура. Кандидат в
   отдельный docs-item вне VLT-CANON.
6. **Реестр `registerEngineComponent` не реактивен** (наследуется из P1.2, 2 LOW): регистрация
   ПОСЛЕ монтирования узла не подхватывается уже смонтированным `VaulterDocumentNode`.
   Задокументировано в `document-handlers.md` (en+ru).
7. **`packages/azgard/README.md` расходится с `azgard.yml`** (пред-существующее, не регресс
   P2.1): README говорит, что `tests/RealAzgard/` не гоняется в standalone-матрице, а workflow
   гоняет `pest --ci` без фильтра.
8. `plan-lint.py plans/VLT-CANON` — красный (не регресс, наследуется из P0.1–P3.2): линтер
   требует `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
   (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
   Структуру плана правит plan-design/plan-close, не exec.
9. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
   агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, остальная
   `plans/VLT-HSEAM/` кроме `plan.md`, `pnpm-lock.yaml`) — не мой, не трогал.
10. `plans/VLT-HSEAM/` (кроме `plan.md`) остаётся untracked в git (пред-существующее с момента
    создания плана, не регресс P3.3) — `handoff.md`/`phases/P0.md`/
    `root/adr-file-handler-seam.md`/`findings/*` вне Scope Included этого item'а, не коммитил.

**Archive migration checklist (2026-07-13, `archive VLT-CANON`):**
- `root/` — пуст (каталог не создавался за жизнь плана) → миграция в docs пропущена, переносить нечего.
- `artifacts/`, `findings/`, `20_research/` — только `findings/` (4 файла) присутствует, остаётся в
  архиве как есть (`plans/archive/VLT-CANON/findings/`), в docs НЕ мигрирует (канон §12 п.3).
- `workflows/` (`wf-vlt-canon-audit.js`, `wf-vlt-canon-research.js`) — остаются в архиве.
- Ссылки на `plans/VLT-CANON/` в живых доках обновлены на `plans/archive/VLT-CANON/`:
  `ROADMAP.md:82`, `docs/adr/0018-editor-engine-canon.md:5,133`, `plans/VLT-HSEAM/plan.md:5`.
- `plans/ACTIVE.md` уже указывал `—` (сброшен на предыдущем шаге, 2026-07-13) — без изменений.
- Осталось в архиве целиком: `plan.md`, `handoff.md`, `findings/*`, `workflows/*` (перенесённая
  директория `plans/archive/VLT-CANON/`, коммит `27992a2`).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: required checks в branch protection (Open risk 1, owner). ~~`docs/plan/*` стухшие
  имена (2)~~ — closed 2026-07-13. `format` value-space (3). ~~CI gate-scope gap (4)~~ — closed 2026-07-13. ru
  contracts.md синхронизация (5). Реактивность `registerEngineComponent` (6). README-vs-CI
  расхождение azgard (7).
  `plans/VLT-HSEAM/` частичная незакоммиченность (10, вне Scope P3.3).
- open_questions: —
