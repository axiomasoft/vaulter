# HANDOFF — 2026-07-12 — after P0.2

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — XSS-барьер переезжает между пакетами, ослабление = регресс безопасности |
| Context | continue (/clear) — ручной item |
| Суть | P0.3: `TipTapContent`/`LinkSchemeValidator`/`OpaqueDocumentContent`/`TiptapExtensions`/`InvalidTipTapContentException` уезжают из core внутрь TipTap-драйвера (`documents/src/`), core перестаёт знать форму TipTap; allow-list и read-time decay переносятся БАЙТ-В-БАЙТ |

```
/task:plan-exec VLT-CANON P0.3
```

**Done:** P0.2 🟠 Done with deviations. Контракт экспортёра опаковый:
`DocumentExporter::export(TipTapContent)` → `export(DocumentContent)` (интерфейс уже существовал —
новый тип не вводился). `HtmlExporter`/`MarkdownExporter` сужают через `instanceof` и бросают новое
`Vaulter\Exceptions\Documents\UnsupportedDocumentContentException` (в CORE — контракт core-шов, и
core-only хост со своим экспортёром обязан уметь его бросать без vaulter-documents).
`NodeExportService::contentOf()` возвращает `DocumentContent`; `FakeExporter` приведён к сигнатуре.
Новый `ExporterContentGuardTest` (3 кейса) пиннит guard. Доки (7 файлов en+ru) + UPGRADE + CHANGELOG
синхронны. Гейт зелёный: pint · phpstan max 0 errors · core 487/487 · documents 209 passed ·
type-cov 100% обе стороны. Коммиты: `03b302c` (контракт) + `5b3a352` (6 находок `vaulter-reviewer`,
включая перенос исключения в core и покрытие guard'а тестами).

**Remaining:** P0.3 (TipTap-валидатор/рендер/расширения из core в documents, утечка L2 — ОСТОРОЖНО:
XSS-барьер VLT-REMED R-01/D27–D29, allow-list и decay сохранить байт-в-байт; точка правки —
`core/tests/E2E/E2eServiceProvider.php`) → P0.4 (схема без `default('tiptap')` + решает D10:
реестр→core?) → P0.5 (richText-дефолт + capability-policy + DX-каркас, решает D11) → P1–P3.
Открытые owner-развилки: D8 (фронт-упаковка, P1.1), D9 (media→drive, P2.2), D10 (P0.4), D11 (P0.5).

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P0.1/P0.2 Completion Notes) ·
`UPGRADE.md` (SSOT миграции: FQCN-таблица P0.1 + секция сигнатуры экспортёра P0.2) ·
`findings/*` (4 файла) · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:** **Read-path экспорта всё ещё TipTap-only** (Pending Work P0.2, ЗНАЙ ПРИ P0.3–P0.5):
`NodeExportService::contentOf()` гонит любую версию через `TipTapContent::decayFrom()`, который при
`type !== 'doc'` возвращает `empty()`, а не бросает. Не-TipTap-узел (Univer/spreadsheet) экспортируется
в ПУСТОЙ документ вместо отказа; guard в экспортёрах через `GET .../export` недостижим (доки это
честно оговаривают). Не регресс P0.2 — так было и до него; чинить в P0.4/P0.5 вместе со схемой/
`format`-дискриминатором.
P0.3 трогает XSS-барьер (`TipTapContent` allow-list + `LinkSchemeValidator` + `withSanitizedUrls`) —
переносить как есть, НЕ «улучшать по дороге»; ослабление = регресс VLT-REMED R-01/R-11/R-12.
`plan-lint.py plans/VLT-CANON` — **красный ДО и ПОСЛЕ P0.1/P0.2** (не регресс): линтер требует
`phases/P0..P3.md` и `## 6. Update Log`, а план держит фазы инлайном в `## 6. Phases (детализация)`
(Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана. Развернуть фазы в `phases/Pn.md`
— работа для `/task:plan-design VLT-CANON`, не для exec-исполнителя.
Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**` агент-пак,
`.swissknife.json`, `plans/VLT-HSEAM/`, `plans/VLT-CANON/{findings,workflows}/`) — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: kind-нейтральный `contentOf()` → P0.4/P0.5 (см. Open risks). `TipTapContent`/
  `OpaqueDocumentContent`/`LinkSchemeValidator` всё ещё в core `ValueObjects\Documents` — их переезд
  это P0.3. D8/D9/D10/D11 — до своих items (P1.1/P2.2/P0.4/P0.5).
- open_questions: —
