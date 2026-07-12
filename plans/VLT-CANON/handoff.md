# HANDOFF — 2026-07-13 — after P0.4

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — механика L4/L5 дешёвая; Q2 (D11) — простая развилка с готовой рекомендацией |
| Context | continue (/clear) — ручной item |
| Суть | P0.5: `richText`-дефолт + policy эволюции capabilities (аддитивные оси) + DX-каркас (`AbstractFileHandler` + `docs/guide/writing-an-engine.md`) + решить D11 (`yjs_state`→`crdt_state`? рекомендация — оставить) |

```
/task:plan-exec VLT-CANON P0.5
```

> ⚠ P0.5 — последний item фазы P0. Его Scope в plan.md §6 уже несёт research-довесы
> (payload-асимметрия office/WOPI-класса, `schemaVersion`, тест конфликт-резолва, граница
> Univer-лицензий). Плюс к нему стекается накопленный Pending Work P0.2–P0.4 (см. Remaining) —
> исполнителю решить, что из этого реально входит в Scope Included, а что эскалировать в
> plan-design как новый item. НЕ расширять Scope молча.

**Done:** P0.4 🟠 Done with deviations. **D10 закрыт = вариант A** (superseding-запись к H.5 и
T5-аллокации Option B — обе названы явно в Decision Log). Движок реестра форматов
(`DocumentHandlerRegistry`, `NodeHandlerResolver`, `DocumentFileDescriptorFactory`, `Capability`,
baseline `PreviewHandler`/`DownloadOnlyHandler`) переехал `git mv` из `packages/documents` в
`packages/core` → `Vaulter\Engines\*` + `Vaulter\Enums\Handlers\Capability`. Публичные контракты
(`Contracts\Handlers\*`) НЕ двигались. Core вяжет `HandlerRegistry`/`NodeHandlerResolver`
безусловно + тегирует baseline в `'vaulter.handlers'`; `NodeResource::handler()` снял гвард
`bound()` — **drive-only хост получает реальный preview/download-дескриптор вместо `null`** (это
и есть выгода D10). `VaulterManager::handlers()` больше не бросает `MissingPackageException`.
Схема: `v_node_document_versions.format` без `->default('tiptap')`; автосейв и yjs-снапшот
(которые реально полагались на дефолт) несут формат головы вперёд — BC: у markdown/univer-документа
дописываемые строки штамповались `'tiptap'`, теперь реальным форматом. Гейты зелёные: pint ·
phpstan max 0 errors · core 491/491 · documents 212/1 skipped · type-cov 100% обе стороны.
Коммиты: `73b135e` (основной), `651b54c` (фиксы ревью).

**Remaining:** P0.5 (последний item P0: richText-дефолт + capability-policy + DX-каркас, решает
D11) → P1 (Часть 1: упаковка фронт-движков, гейт D8 в P1.1) → P2 (структурный канон, D9 в P2.2) →
P3 (docs/ROADMAP/arch/supersede). Открытые owner-развилки: **D8** (фронт-упаковка, P1.1 — гейт для
P1.2/P1.3), **D9** (media→drive, P2.2), **D11** (P0.5). D7 и D10 — закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P0.1–P0.4 Completion Notes, §5
Decision Log D7/D10 закрыты) · `UPGRADE.md` (SSOT миграции: FQCN-таблицы P0.1/P0.3/P0.4 + секция
сигнатуры экспортёра P0.2 + секция `format`) · `findings/*` (4 файла) · наследие:
`plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **`format` — смешанный value-space, backfill не шипается** (находка ревью P0.4, MED): колонка
   хранит и значения `DocumentType` (`richtext`/`markdown`/…), и handler-id (`univer`; исторически
   `'tiptap'` из снятого DB-дефолта — не валидный `DocumentType`). Старые строки сохраняют что
   записано. Задокументировано как advisory-label (хендлер резолвится от mime, не от колонки — D4),
   но унификация словаря — открытое решение. Кандидат в P0.5 (там живёт `format`-дискриминатор).
2. **`CreateDocumentNodeData::$format` дефолт = `'tiptap'`** — тот же класс проблемы, что и снятый
   DB-дефолт (не валидный `DocumentType`), бьёт по прямой программной конструкции DTO. Вне Scope
   P0.4 (смена дефолта публичного DTO = отдельное BC-решение). → P0.5.
3. **Read-path экспорта всё ещё TipTap-only** (наследуется из P0.2, не тронуто P0.3/P0.4):
   `NodeExportService::contentOf()` гонит любую версию через `TipTapContent::decayFrom()`, который
   при `type !== 'doc'` возвращает `empty()`, а не бросает. Не-TipTap-узел экспортируется в ПУСТОЙ
   документ вместо отказа. → P0.5.
4. **Gate-scope gap CI** (наследуется из P0.3, Major, не почищено): `TipTapContent`/
   `LinkSchemeValidator` (XSS-барьер) покинули `packages/core/src`, вынеся с собой покрытие
   enforcing-порогов `.github/workflows/mutation.yml` (`--min=95`, path=`packages/core/src`) и
   `tests.yml` coverage (`--min=80`). Новый дом `packages/documents` в CI (`documents.yml`) не
   несёт эквивалентных порогов (`pest --ci` без `--coverage --min=N`, infection `|| true`). Итог:
   будущее ослабление allow-list-теста не покраснит ни один гейт. Нужен отдельный CI/gate-item —
   предложить в следующем `/task:plan-design` заходе на план (ни в одном текущем P0.x/P1.x/P2.x/P3.x
   его нет).
5. `plan-lint.py plans/VLT-CANON` — красный ДО и ПОСЛЕ P0.1–P0.4 (не регресс): линтер требует
   `phases/P0..P3.md` + `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases (детализация)`
   (Update Log — `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана. Структуру плана правит
   plan-design, не exec.
6. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**` агент-пак,
   `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`) — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: `format` value-space + `CreateDocumentNodeData` дефолт → P0.5 (Open risks 1–2).
  Kind-нейтральный `contentOf()` → P0.5 (Open risk 3). CI gate-scope gap → отдельный item
  (Open risk 4). D8/D9/D11 — до своих items (P1.1/P2.2/P0.5).
- open_questions: —
