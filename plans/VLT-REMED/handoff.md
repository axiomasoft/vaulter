# HANDOFF — 2026-07-10 — after P0.1

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Закрыть Blocker F-P1.7-01 (SSRF на пути доставки webhook) + 4 сопутствующие находки (шифрование signing_secret, окно потери события в outbox, двойная доставка, лимит тела ответа) |

```
/task:plan-exec VLT-REMED P0.2
```

**Done:** P0.1 (B1 — stored XSS в TipTap) закрыт 🟢. Двухэшелонная защита (D4): store-time closed
allow-list узлов/марок/атрибутов + схем `href` в `TipTapContent` (новый `LinkSchemeValidator`,
BiDi/control-char-aware нормализация) — fail-closed, не санитайзер; render-time второй эшелон —
`symfony/html-sanitizer` инжектится в `TipTapRenderer` конструктором из `VaulterDocumentsServiceProvider`
(защищает и контент, сохранённый до валидатора); markdown-путь экранирует текст и повторно проверяет
схему ссылки. `ensureWithin(Config::maxDocumentSizeKb())` теперь вызывается в трёх Action'ах (guard
`instanceof TipTapContent`, т.к. `DocumentContent` — handler-agnostic интерфейс, opaque-контент
спредшитов лимит не несёт — баг словил `packages/documents` own `composer analyse`, не root). Пиннинг
`@tiptap/extension-link ^2.10.4` в `packages/vue/package.json` (резолвится `2.27.2`, npm workspaces, не
pnpm). Закрыты F-P1.11-01 (Blocker), F-P1.11-02, F-P1.11-04, F-P1.11-05. Коммит `86a60a6` на ветке
`remediation/audit-vlt`. Детали — `plans/VLT-REMED/phases/P0.md` P0.1 Completion Notes.

**Remaining:** P0.2 (B3 — SSRF/webhook), P0.3 (B5 — resumable-uploads/CAS race) → фаза P0 терминальна →
развернуть и исполнить P1 (4), P2 (3), P3 (6), P4 (10), P5 (3). Развёртывание каждой фазы —
`/task:plan-design VLT-REMED Pn` перед первым её item'ом (P1–P5 пока скелеты).

**Sources of truth:** `plans/VLT-REMED/plan.md` · `plans/VLT-REMED/phases/P0.md` ·
`plans/VLT-REMED/findings/P0-remediation-norms.md` · `plans/AUDIT-VLT/findings/REGISTER.md` (SSOT
103 находок, append-only, VLT-REMED в него не пишет) · `plans/AUDIT-VLT/findings/P5.2-remediation-backlog.md`
(партиция на 24 батча и sequencing волн).

**Open risks:**
- Два Blocker'а ещё открыты: F-P1.4-01 (гонка offset → порча файла, P0.3), F-P1.7-01 (SSRF webhook,
  P0.2). До закрытия всей фазы P0 релиз пакета невозможен (D7 плана AUDIT-VLT).
- «Зелёное, но не починенное»: барьер, поставленный на путь наблюдения, а не доставки (pre-mortem
  R2/R3/R4) — для P0.2 это резолв DNS ТОЛЬКО при регистрации без пиннинга на доставке. Митигация —
  D7 (тест красный до фикса) + для P0.3 PG-прогон конкурентных сценариев (D12).
- Мажор `@tiptap` 2.x → 3.x (F-P4.5-01, P4.1) — ломающий фронтенд-апгрейд, при разрастании требует
  собственного плана.
- Массовый namespace-refactor (P4.10) может молча сломать хост FlexCRM: morph-алиасы, FQCN в
  опубликованном конфиге. Митигация — arch-гейты P4.1 до переноса + `UPGRADING.md`.
- Root `composer lint:check` красный из-за пред-существующего форматирования в
  `plans/AUDIT-VLT/artifacts/repro/**` (чужой read-only план, вне scope VLT-REMED) — известно с P0.1,
  не блокирует closure (только новый/изменённый код этого плана обязан быть pint-чистым).

**Workarounds/Deferred/Open questions:**
- workarounds: `packages/documents` own `composer analyse`/`composer test:types` запускались отдельно
  от root-скриптов — root `phpstan.neon`/`phpunit.xml` покрывают только `packages/core`; для
  documents-пакета нужен `cd packages/documents && composer analyse|test:types` (сам root-гейт D12
  остаётся зелёным, но не покрывает документный пакет — если следующий item трогает documents/drive/
  media/filament, гони и их собственный `composer analyse`/`test:types`, не только root).
- deferred: `docs/`-миграция и архивация AUDIT-VLT — отдельный запуск `/task:plan-close archive AUDIT-VLT`;
  формальное закрытие AUDIT-VLT в `🟠 Done with deviations` (диспозиция Blocker'ов теперь есть — D1).
- open_questions: Q3 (`open-questions.md`) — нужна ли ротация уже выданных `signing_secret` после
  перевода колонки в шифрованный вид; решение владельца, P0.2 не блокирует (Pending Work P0.2, если
  колонка будет менять тип). Q1/Q2 — `Resolved → D1/D10`.
