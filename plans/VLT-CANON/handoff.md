# HANDOFF — 2026-07-12 — after D7 owner-approval (prep P0.1)

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — публичный контракт/SemVer-переименование, каскадит на фронт-типы (`vaulter-reviewer`+`vaulter-api-surface` обязательны) |
| Context | continue (/clear) — ручной item |
| Суть | Исполнить P0.1 целиком: rename `DocumentHandler`→`FileHandler` в `Contracts/Handlers/` (+ ValueObjects в `ValueObjects/Handlers/`), обновить все `use`/config-ключи/docs/фронт-типы, гейт+`vaulter-api-surface`+`vaulter-reviewer`, BC-запись UPGRADE/CHANGELOG |

```
/task:plan-exec VLT-CANON P0.1
```

**Done:** D7 (§8.1 plan.md) закрыт owner'ом — вариант A (`FileHandler` + `Contracts/Handlers/`,
kind-нейтраль) зафиксирован в Decision Log (approve получен запуском исходной команды). Сам
рефакторинг (переименование/перенос кода) ещё НЕ начат — это отдельный шаг исполнения P0.1.

**Remaining:** P0.1 (сам rename) → P0.2 → P0.3 → P0.4 (решает D10) → P0.5 (решает D11) → P1–P3.
Открытые owner-развилки: D9 (media→drive, P2.2), D10 (реестр→core, P0.4), D11 (yjs_state, P0.5),
D8 (фронт-упаковка, P1.1) — не в скоупе этой команды.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary, D7 теперь closed) · `findings/*` (4 файла) ·
`workflows/wf-vlt-canon-{research,audit}.js` · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:** параллельный VLT-REMED может тронуть те же файлы (контракты, PanelTablesBuilder) —
перед стартом P0.1 сверить дифф ветки; Filament вне scope (D6, только ROADMAP-пункт P3.1).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: D9/D10/D11/D8 остаются открытыми до своих items (P2.2/P0.4/P0.5/P1.1).
- open_questions: —
