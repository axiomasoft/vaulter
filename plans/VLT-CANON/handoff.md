# HANDOFF — 2026-07-12 — after P0.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — сужение публичного контракта экспортёра, SemVer-ось |
| Context | continue (/clear) — ручной item |
| Суть | P0.2: `DocumentExporter::export(TipTapContent)` → `export(DocumentContent)` (опаковый контракт), TipTap-специфика уезжает внутрь реализаций `documents/src/Export/*`; починить `core/tests/Fixtures/Documents/FakeExporter.php` (реализует старую сигнатуру) |

```
/task:plan-exec VLT-CANON P0.2
```

**Done:** P0.1 🟠 Done with deviations (deviation — только Routing: исполнено opus/**medium** вместо
opus/**high**; модель совпала, effort ниже — компенсировано обязательными `vaulter-api-surface` +
`vaulter-reviewer`, находки ревью найдены и закрыты). Контракт движка
kind-нейтрален: `DocumentHandler`→`FileHandler`, `Contracts\Documents\*`→`Contracts\Handlers\*`,
`ValueObjects\Documents\{DocumentCapabilities→HandlerCapabilities, HandlerDescriptor, HandlerUi,
FileDescriptor}`→`ValueObjects\Handlers\*`. Фронт-тип `@vaulter/vue` (`DocumentCapabilities`→
`HandlerCapabilities`), 14 doc-файлов (en+ru), UPGRADE.md (таблица 9 FQCN) + CHANGELOG синхронны.
Гейт зелёный: pint · phpstan max 0 errors · pest 487/487 · type-cov 100% · vue-tsc/vitest/vite build.
Коммиты: `e7db977` (rename) + `6088c50` (фиксы 3 находок ревью по докам). D7 закрыт (§8.1, вариант A).

**Remaining:** P0.2 (опаковый экспортёр, утечка L1) → P0.3 (TipTap-валидатор/рендер/расширения из
core в documents, утечка L2 — ОСТОРОЖНО: XSS-барьер VLT-REMED R-01/D27–D29, allow-list и decay
сохранить байт-в-байт) → P0.4 (схема без `default('tiptap')` + решает D10: реестр→core?) → P0.5
(richText-дефолт + capability-policy + DX-каркас, решает D11) → P1–P3.
Открытые owner-развилки: D8 (фронт-упаковка, P1.1), D9 (media→drive, P2.2), D10 (P0.4), D11 (P0.5).

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P0.1 Completion Notes — что именно
переименовано; §5 Decision Log D7 закрыт) · `UPGRADE.md` (таблица старых→новых FQCN — SSOT миграции) ·
`findings/*` (4 файла) · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:** `plan-lint.py plans/VLT-CANON` — **красный ДО и ПОСЛЕ P0.1** (не регресс item'а):
линтер требует `phases/P0..P3.md` и секцию `## 6. Update Log`, а план держит детализацию фаз инлайном
в `## 6. Phases (детализация)` (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с момента
создания плана. Развернуть фазы в `phases/Pn.md` (или починить нумерацию секций) — работа для
`/task:plan-design VLT-CANON`, не для exec-исполнителя. До этого lint-гейт плана красный.
P0.3 трогает XSS-барьер (`TipTapContent` allow-list + `LinkSchemeValidator` +
`withSanitizedUrls`) — переносить как есть, НЕ «улучшать по дороге»; ослабление = регресс VLT-REMED
R-01/R-11/R-12. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана
(`.claude/**` агент-пак, `.swissknife.json`, `plans/VLT-HSEAM/`, `plans/VLT-CANON/{findings,workflows}/`) —
не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: `TipTapContent`/`OpaqueDocumentContent`/`LinkSchemeValidator` остались в
  `ValueObjects\Documents` — их переезд в documents это P0.3, не P0.1 (зафиксировано в
  UPGRADE.md и в комментарии allow-list'а `core/tests/Arch/ArchTest.php`).
  D8/D9/D10/D11 — до своих items (P1.1/P2.2/P0.4/P0.5).
- open_questions: —
