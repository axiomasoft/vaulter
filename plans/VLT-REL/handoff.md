# HANDOFF — 2026-07-15 — after P5.1

**Next:** «ЗАПУСК ВРУЧНУЮ: opus/high» — P5.2 (необратимый акт релиза `v0.2.0`). Требует ДВУХ
внешних предусловий, которые item только ВЕРИФИЦИРУЕТ, не выполняет: (1) релизный коммит P5.1
(`835bcfc`, `CHANGELOG [0.2.0]`) слит владельцем в `main` (D10 — тег режется из main, не из
feature-ветки `remediation/audit-vlt`); (2) явный approve владельца В ТОЙ сессии на `git tag` +
push + `gh release` (Routing §3 — approve не наследуется). Эргономичный путь плана —
`/model opus` + `/effort high` + `/task:plan-run VLT-REL P5.2` (D18). Иначе — бэйр-промпт ниже.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — необратимый внешний акт (git tag + push + release), нужен approve владельца |
| Context | continue (/clear) — ручной item |
| Суть | P5.2: `git tag -a v0.2.0` на main HEAD + `git push origin v0.2.0` + `gh release create` из release-notes-0.2.0.md; monorepo-split ожидаемо падает (D9 deferred, безвреден); Packagist — deferred |

```
Исполни VLT-REL P5.2 строго по ТЗ (plans/VLT-REL/phases/P5.md, item P5.2, статус ⬜) под opus/high.
НЕОБРАТИМО: НЕ режь тег без явного «да» владельца В ЭТОЙ сессии (approve не наследуется).
Cold-start reads по порядку:
  1. plans/VLT-REL/plan.md §5 D12 (номер v0.2.0) + D9 (Packagist deferred) + D10 (тег из main) + §7 Contracts;
  2. plans/VLT-REL/phases/P5.md — item P5.2 (Scope/Validation/Implementation Rules/Code Guidance);
  3. plans/VLT-REL/root/cut-line.md §4 (версия/тег, база 0.2.0 — SUPERSEDED-баннер) + §5;
  4. .github/workflows/monorepo-split.yml (:9-24 prereqs + :25-28 trigger on push tag);
  5. plans/VLT-REL/artifacts/release-notes-0.2.0.md (тело GitHub release).
Прекондиции (СТОП, если не выполнены): релизный коммит 835bcfc слит в main (git merge-base --is-ancestor);
дерево чистое; тега v0.2.0 ещё нет (git tag --list v0.2.0 пусто). Целевой коммит НЕ на main → СТОП (D10),
НЕ тегать remediation/audit-vlt. Тег уже есть → СТОП, не перетегивать.
Действия после approve: git tag -a v0.2.0 -m "vaulter 0.2.0 — minimal release" на main HEAD;
git push origin v0.2.0; gh release create v0.2.0 --notes-file plans/VLT-REL/artifacts/release-notes-0.2.0.md.
НЕ создавать v0.1.0 (D12). НЕ провижить split-инфру, НЕ чинить падение monorepo-split (D9 deferred) —
зафиксировать статус прогона (gh run list --workflow="Monorepo split (PHP)") + Packagist=deferred в
Completion Notes + Open risks. После P5.2 план терминален → /task:plan-close archive VLT-REL.
```

**Done:** P1 (1/1 🟢). P2 (2/2 🟢 — поверхность заморожена golden-снапшотом). P3 (1/1 🟢 —
`root/known-limitations.md`). P4 (1/1 🟢 — `root/upgrade-path.md`). **P5.1 🟢 закрыт** (item-коммит
`835bcfc`): снапшот-гейт зелёный БЕЗ `UPDATE_SNAPSHOT` (core/drive/documents/azgard PublicApiSnapshot
по 1 passed, media Arch 5, filament Arch 8 — дрейфа контракта нет); `CHANGELOG [Unreleased]`→`## [0.2.0]
— 2026-07-15` (6 composer + строка `@vaulter/vue` OUT [D3], легаси `## [0.1.0]` сохранена дословно,
link-refs по D12 — `[0.1.0]:` удалён, `[0.2.0]:` добавлен, `[Unreleased]:`→`v0.2.0...HEAD`);
`artifacts/release-notes-0.2.0.md` (176 строк) собран `vaulter-release-conductor`.

**Remaining:** P5.2 (необратимый `git tag v0.2.0` на main + push + `gh release create`, approve
владельца + мерж релизного коммита в main). После P5.2 план терминален → `/task:plan-close archive VLT-REL`.

**Sources of truth:** `plan.md` (Meta v0.8.0 / **D12** version-коллизия / **D9** Packagist deferred /
**D10** тег из main / Routing §3 P5.*=opus/high manual / Status Board P1–P4 🟢, P5 🟡 1/2 / §7 Contracts
frozen → релиз `v0.2.0`) · `phases/P5.md` (P5.1 🟢 Completion Notes; P5.2 ⬜ — тег `v0.2.0`) ·
`CHANGELOG.md` (`## [0.2.0] — 2026-07-15` + пустой `## [Unreleased]` сверху + легаси `## [0.1.0]`;
link-refs `:342-343`) · `artifacts/release-notes-0.2.0.md` (тело GitHub release) · `root/cut-line.md`
§4/§5 (под SUPERSEDED-баннером→D12) · `.github/workflows/monorepo-split.yml` (:9-28 prereqs+trigger,
база D9) · `.claude/agents/vaulter-release-conductor.md`.

**Open risks:**
- **Тег из main (D10):** P5.2 гейтит «релизный коммит `835bcfc` слит в main»; мерж делает владелец,
  item только верифицирует (`git merge-base --is-ancestor v0.2.0 main`). Тег на feature-ветке = дефект.
- **Packagist deferred (D9):** push тега `v0.2.0` ожидаемо запустит и уронит `monorepo-split.yml`
  (one-time prereqs — 6 split-репо + `GH_ACCESS_TOKEN` + Packagist-регистрация — не провижены). Read-only,
  безвреден; git-релиз не блокирует. Провижн + перезапуск split — follow-up вне плана.
- **Фантомный `0.1.0` (D12, owner-принято):** легаси `## [0.1.0]` — never-tagged исторический черновик;
  тег `v0.1.0` НЕ создаётся никогда (висячий `[0.1.0]:`-link удалён в P5.1).
- **Version re-base debt (bookkeeping):** `cut-line.md` §4/§5, `upgrade-path.md`, `AMEND.md` несут числа
  от старой базы `0.1.0` под SUPERSEDED-баннерами→D12 (числа = класс бампа, живая база `0.2.0`). Полный
  литеральный re-base — follow-up до `plan-close archive`. Авторитетный номер — D12.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: Packagist-публикация 6 пакетов (провижн split-инфры + перезапуск monorepo-split, D9);
  содержательные фиксы L-01…L-08 — dev-срезы вне плана (D-LOAD-5 / D11-гейт VLT-MUT drive ≥95);
  литеральный version re-base cut-line/upgrade-path/AMEND (0.1.0→0.2.0) до archive.
- open_questions: — (version-коллизия закрыта D12; блокеров P5.2 нет, только owner-approve + мерж в main).

**Посторонний дифф в дереве (НЕ P5):** `.claude/.maind-feature-log.last`, `plans/VLT-LOAD/{plan.md,
handoff.md,phases/P8.md,findings/P8-rag-otel-health.md}`, `scratchpad/` — работа VLT-LOAD/mAInd, не тронуто.
