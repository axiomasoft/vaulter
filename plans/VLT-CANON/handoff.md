# HANDOFF — 2026-07-13 — after P0.5

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — owner-развилка D8 (модель упаковки фронт-движков), гейт для P1.2/P1.3 |
| Context | continue (/clear) — ручной item |
| Суть | P1.1: закрыть D8 owner'ом (§8.2 — рекомендация research'а уже готова: единый `@vaulter/vue`, per-engine npm лениво) — подтвердить или выбрать альтернативу; при альтернативе P1.2/P1.3 передетализировать ДО старта |

```
/task:plan-exec VLT-CANON P1.1
```

> ⚠ P1.1 — гейт для P1.2/P1.3 (Execution Rules плана): если исполнитель НЕ подтвердит
> D8 буквально «оставить рекомендацию A», а выберет альтернативу из §8.2, P1.2/P1.3
> нужно эскалировать в `/task:plan-design VLT-CANON P1` ДО их исполнения — их текущая
> детализация в `plan.md` написана под D8=A.

**Done:** P0.5 🟢 Done — **фаза P0 терминальна (5/5, все items 🟢/🟠)**. `HandlerCapabilities::
$richText` дефолт `true`→`false` (kind-нейтраль: незаявленная capability не выводится).
`TipTapHandler` объявляет `richText: true` явно (поведение не менялось, декларация — да).
`NodeExportService::contentOf()` стал kind-нейтральным — резолвит хендлер узла, non-rich-text
узел (Univer) теперь возвращает `OpaqueDocumentContent` вместо decay в пустой TipTap-документ;
существующий guard экспортёров (`UnsupportedDocumentContentException`) реально достижим —
закрывает Pending Work, тянувшийся с P0.2/P0.3/P0.4. `CreateDocumentNodeData::$format` дефолт
`'tiptap'` (невалидный `DocumentType`) → `DocumentType::RichText->value`. Новая DX-база
`Vaulter\Engines\Handlers\AbstractFileHandler` (arch-тест несёт точечный carve-out, инвариант
«core-engine internals = final» не ослаблен) + гайд `docs/guide/writing-an-engine.md` (+ru,
в vitepress-сайдбаре) с чек-листом capability-policy/payload-асимметрии/schemaVersion/
Univer-лицензий. Capability-policy тесты (аддитивность осей, priority-конфликт). Фронт:
`HandlerCapabilities` в `@vaulter/vue` получил `rich_text` (реальный пробел — бэкенд его уже
сериализовал, TS-тип не знал) + открытую индекс-сигнатуру. D11 закрыт = вариант A (`yjs_state`
остаётся, без кода). BC: UPGRADE.md новая секция, CHANGELOG 2 буллета. Гейты зелёные:
pint · phpstan max 0 errors · core pest 496/496 · documents pest 214/1 skipped · type-cov 100%
обе стороны · vue-tsc/vitest/build чистые. Ревью: `vaulter-api-surface` — surface diff
перекрёстно сверен с UPGRADE/CHANGELOG, 0 находок; `vaulter-reviewer` — **PASS**, явно
верифицировал byte-for-byte TipTap read-path, точечность arch carve-out, честность тестов.
Коммиты: `2c40793` (реализация), `a0121fe` (закрытие плана).

**Remaining:** P1 (Часть 1: упаковка фронт-движков — D8 гейт в P1.1, формализация
фронт-реестра P1.2, скелет-гайд P1.3) → P2 (структурный канон: azgard-rename P2.1, media→drive
развилка D9 в P2.2, catch-all P2.3, Access/Uploads P2.4) → P3 (docs/ROADMAP/arch-тесты/supersede
VLT-HSEAM). Открытые owner-развилки: **D8** (P1.1, гейт для P1.2/P1.3), **D9** (media→drive,
P2.2). D7/D10/D11 — закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P0.1–P0.5 Completion Notes, §5
Decision Log D7/D10/D11 закрыты, §4 Phase Status Board P0=5/5 🟢) · `UPGRADE.md` (SSOT миграции:
FQCN-таблицы P0.1/P0.3/P0.4 + секции сигнатуры экспортёра P0.2, `format` P0.4, richText/export
P0.5) · `findings/*` (4 файла) · `docs/guide/writing-an-engine.md` (+ru) — новый DX-гайд ·
наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4 ревью,
   MED): колонка хранит и `DocumentType`-значения, и handler-id (`univer`). НЕ унифицировано
   P0.5 (вне Scope Included — item решал richText/export/DX, не словарь `format`). Кандидат
   в отдельное решение вне текущего P0-скоупа плана — нет назначенного item'а.
2. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major, не почищено): XSS-барьер
   (`TipTapContent`/`LinkSchemeValidator`) в `packages/documents` не несёт эквивалентных
   enforcing-порогов mutation/coverage, какие были у него в `packages/core`
   (`.github/workflows/mutation.yml --min=95`, `tests.yml --min=80`). Нужен отдельный
   CI/gate-item — предложить в следующем `/task:plan-design` заходе (ни в одном текущем
   P1.x/P2.x/P3.x его нет).
3. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига (со времён
   P0.1/P0.4 renames: `Vaulter\Documents\Handlers\TipTapHandler` вместо актуального
   `Vaulter\Documents\Documents\Handlers\TipTapHandler`; `Vaulter\Enums\Documents\Capability`
   вместо `Vaulter\Enums\Handlers\Capability`). P0.5 не редактировал этот файл (только добавил
   новый `writing-an-engine.md` и ссылки на него) — дрейф не мой, но и не почищен. Кандидат
   в P3.1 (docs-item) или отдельную мелкую правку.
4. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1–P0.5 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases
   (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана.
   Структуру плана правит plan-design, не exec.
5. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
   агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`)
   — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: `format` value-space унификация — нет назначенного item'а (Open risk 1). CI
  gate-scope gap → отдельный item (Open risk 2). `document-handlers.md` стухшие FQCN →
  кандидат в P3.1 или мелкую правку (Open risk 3). D8/D9 — до своих items (P1.1/P2.2).
- open_questions: —
