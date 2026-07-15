# HANDOFF — 2026-07-15 — after P5.1

**Next:** «ЗАПУСК ВРУЧНУЮ: opus/high» — P5.1 РАЗБЛОКИРОВАН (D12): version-коллизия решена owner'ом —
re-platform-релиз тегируется `v0.2.0`, легаси `## [0.1.0] - 2026-06-14` сохранена как историческая.
Исполнить P5.1 (обратимый prep релиза `0.2.0`) под opus/high (Exec=manual, Routing §3). Эргономичный
путь этого плана — `/model opus` + `/effort high` + `/task:plan-run VLT-REL P5.1` (routing-гейт
инвертирован: undershoot → ROUTING-BLOCKED). Иначе — бэйр-промпт ниже.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — релизная подготовка + SemVer, необратимый downstream (P5.2 тег) |
| Context | continue (/clear) — ручной item |
| Суть | P5.1: снапшот-гейт без UPDATE_SNAPSHOT + CHANGELOG Unreleased→0.2.0 (легаси 0.1.0 сохранить, drop висячего link-ref) + release-conductor → artifacts/release-notes-0.2.0.md |

```
Исполни VLT-REL P5.1 строго по ТЗ (plans/VLT-REL/phases/P5.md, item P5.1, статус ⬜, ре-дизайн по D12) под opus/high.
Cold-start reads по порядку:
  1. plans/VLT-REL/plan.md §5 D12 (номер 0.2.0 + судьба легаси [0.1.0] + link-ref-механика) + D3, и §7 Contracts;
  2. plans/VLT-REL/phases/P5.md — item P5.1 (Scope/Validation/Implementation Rules/Code Guidance);
  3. plans/VLT-REL/root/cut-line.md — SUPERSEDED-баннер, затем §5/§1/§4 (числа от базы 0.2.0);
  4. CHANGELOG.md — блок [Unreleased] (:8…), легаси ## [0.1.0] (:259…), link-refs (:342-343);
  5. plans/VLT-REL/root/contracts/AMEND.md §«Куда смотреть»; .claude/agents/vaulter-release-conductor.md.
Сделать 3 Scope-пункта P5.1: (1) прогнать P2-снапшот-гейт БЕЗ UPDATE_SNAPSHOT (зелёный = нет дрейфа);
(2) CHANGELOG [Unreleased]→## [0.2.0] — <date +%Y-%m-%d>, легаси ## [0.1.0] сохранить дословно, перечень выровнять на 6 composer + строка @vaulter/vue OUT, свежий пустой ## [Unreleased] сверху, link-refs: [Unreleased]→v0.2.0...HEAD, добавить [0.2.0]:, УДАЛИТЬ висячий [0.1.0]: link;
(3) субагент vaulter-release-conductor → plans/VLT-REL/artifacts/release-notes-0.2.0.md.
Validation зелёная (grep [0.2.0]=1, [0.1.0]=1, [Unreleased]=1, [0.1.0]:=0, release-notes непуст). Закрыть item двумя коммитами (item по Files + bookkeeping), переписать handoff. Тег НЕ резать — это P5.2.
Красный снапшот-гейт → СТОП, эскалация «public contract/SemVer», item 🔴, golden НЕ регенерировать.
```

**Done:** P1 (1/1 🟢). P2 (2/2 🟢 — поверхность заморожена golden-снапшотом). P3 (1/1 🟢 —
`root/known-limitations.md`). P4 (1/1 🟢 — `root/upgrade-path.md`). **P5.1 РЕ-ДИЗАЙН по D12,
разблокирован 🔴→⬜** (version-коллизия CHANGELOG решена owner'ом: релиз `v0.2.0`, легаси `[0.1.0]`
сохранена). Проверено при прошлой попытке P5.1: **P2-снапшот-гейт ЗЕЛЁНЫЙ** (без `UPDATE_SNAPSHOT`) —
дрейфа контракта между P2-заморозкой и тегируемым кодом нет (прекондиция «снапшот == код» доказана;
блокировала только коллизия, теперь снята D12).

**Remaining:** P5.1 (обратимый prep релиза `0.2.0` — готов к exec), затем P5.2 (необратимый
`git tag v0.2.0` на main + push + `gh release create`, approve владельца). После P5.2 план терминален
→ `/task:plan-close archive VLT-REL`.

**Sources of truth:** `plan.md` (Meta v0.8.0 / **D12** version-коллизия / Routing §3 P5.*=opus/high
manual / Status Board P1–P4 🟢, P5 🟡 In progress 0/2 / §7 Contracts frozen → релиз `v0.2.0`) ·
`phases/P5.md` (P5.1 ⬜ ре-дизайн по D12 — CHANGELOG→`[0.2.0]`, легаси сохранить, drop `[0.1.0]:`-link;
P5.2 ⬜ — тег `v0.2.0`) · `root/cut-line.md` §5/§1/§4 (под SUPERSEDED-баннером→D12) · `root/upgrade-path.md`
+ `root/contracts/AMEND.md` (SUPERSEDED-баннеры→D12) · `root/contracts/public-api-surface.md` (SSOT
заморозки) · `CHANGELOG.md` (`:8` `[Unreleased]`, `:259` легаси `[0.1.0]`, `:342-343` link-refs) ·
`.github/workflows/monorepo-split.yml` (:9-28 prereqs+trigger, база D9) ·
`.claude/agents/vaulter-release-conductor.md`.

**Open risks:**
- **Version re-base debt (bookkeeping):** `cut-line.md` §4/§5, `upgrade-path.md`, `AMEND.md` несут числа
  от старой базы `0.1.0` под SUPERSEDED-баннерами→D12 (числа = класс бампа, живая база `0.2.0`). Полный
  литеральный re-base текста — follow-up до `plan-close archive`. Авторитетный номер — D12.
- **Packagist deferred (D9):** push тега `v0.2.0` ожидаемо запустит и уронит `monorepo-split.yml`
  (one-time prereqs не провижены). Read-only, безвреден; git-релиз не блокирует. Follow-up вне плана.
- **Тег из main (D10):** P5.2 гейтит «релизный коммит P5.1 слит в main»; мерж делает владелец, item
  только верифицирует (`git merge-base --is-ancestor`). Тег на feature-ветке = дефект.
- **Фантомный `0.1.0` (D12, owner-принято):** легаси `## [0.1.0]` остаётся never-tagged историческим
  черновиком; тег `v0.1.0` НЕ создаётся никогда, висячий `[0.1.0]:`-link удаляется в P5.1.
- Снапшот-гейт на дереве P5.1 — зелёный (дрейфа контракта после P2-заморозки нет).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: Packagist-публикация 6 пакетов (провижн split-инфры + перезапуск monorepo-split, D9);
  содержательные фиксы L-01…L-08 — dev-срезы вне плана (D-LOAD-5 / D11-гейт VLT-MUT drive ≥95);
  литеральный version re-base cut-line/upgrade-path/AMEND (0.1.0→0.2.0) до archive.
- open_questions: — (version-коллизия закрыта D12; блокеров P5.1 нет).

**Посторонний дифф в дереве (НЕ P5):** `.claude/.maind-feature-log.last`, `plans/VLT-LOAD/{plan.md,
handoff.md,phases/P8.md,findings/P8-rag-otel-health.md}`, `scratchpad/` — работа VLT-LOAD/mAInd, не тронуто.
