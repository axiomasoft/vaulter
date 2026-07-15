# HANDOFF — 2026-07-15 — after P2.1

**Next:** ЗАПУСК ВРУЧНУЮ: opus/high — P2.2 (per-package snapshot-regression гейт + amend-процедура).
§3 Routing P2.* = opus/high manual (триггер §10 «public contract/SemVer», effort high+ MANDATORY);
exec под моделью сессии, НЕ pin. P2.2 морозит рефлексией то, что P2.1 энумерировал в SSOT
(`root/contracts/public-api-surface.md`). Инвокация — `/task:plan-run VLT-REL P2.2` (launch-block ниже).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — §3 Routing P2.* = заморозка публичного контракта, effort high+ MANDATORY |
| Context | continue (/clear) — ручной item (plan-run читает план с диска заново) |
| Суть | P2.2: per-package Pest snapshot-regression гейт (core/drive/documents/azgard) рефлексирует сигнатуры `Contracts\**` + фасад из SSOT P2.1 → закоммиченный golden на пакет; RED-on-drift + env-gated `UPDATE_SNAPSHOT` (НЕ в CI); media/filament empty-surface гвард; amend-процедура (MINOR-путь `MetricsRecorder` D31). Red-before ОБЯЗАТЕЛЕН (инъекция метода роняет тест). |

```
/task:plan-run VLT-REL P2.2
```

**Done:** P1 закрыта (1/1 🟢). **P2.1 закрыт 🟢** (item-коммит `79d10fa`): `root/contracts/public-api-surface.md`
— SSOT публичной поверхности 0.1.0: **26** интерфейсов-контрактов (core 21 / drive 1 / documents 3 /
azgard 1; media/filament 0) с полной сигнатурой методов (раздел 1), фасад `Vaulter\Facades\Vaulter`
(accessor `'vaulter'` → `VaulterManager`, 12 `@method`, раздел 2), host-facing config-ключи 6 файлов
ключ-в-ключ (раздел 3), публикуемые ресурсы — 3 config standalone + 19 миграций core + translations
(раздел 4), биндинги контрактов по 6 провайдерам (раздел 5). **Drift-check vs `.claude/rules/public-api.md`
= `clean`** (раздел 6) — эскалация НЕ потребовалась, P2.2 разблокирован. Порядок дока детерминированный
(пакет→namespace→интерфейс→методы) — machine-generatable golden для P2.2.

**Remaining:** P2.2 (гейт) → фаза P2 закрыта (2/2) → {P3 условия внедрения, P4 upgrade-путь параллельно}
→ P5 тег+публикация. P3–P5 всё ещё скелеты.

**Sources of truth:** `plan.md` (Meta/Routing §3/D-лог D1–D6/§7 Contracts/Status Board P2 1/2) ·
`phases/P2.md` (P2.1 закрыт 🟢, P2.2 готов к exec) · `root/contracts/public-api-surface.md`
(**SSOT публичной поверхности — источник golden P2.2**) · `root/cut-line.md` (§2 граница, §4 bump-rule) ·
`.claude/rules/public-api.md` (правило гейта) · `.claude/rules/tests.md` (`composer test`, стиль) ·
`packages/core/tests/Arch/ArchTest.php` (arch-идиома, стиль-референс P2.2) ·
`plans/VLT-LOAD/phases/P8.md` (D31 `MetricsRecorder` — MINOR после тега, amend-путь).

**Open risks:**
- P2.2 морозит грубо → breaking проскочит (D5 требует ПОЛНОЙ сигнатуры: union/nullable/default/variadic)
  ИЛИ девелоперы привыкнут к `UPDATE_SNAPSHOT` без D# (заморозка-театр) — mitigation: env-gated escape
  hatch, НЕ в CI; регенерация вне amend = `[POSSIBLE-DEFECT]`; red-before обязателен.
- Фасад `Vaulter` над `'vaulter'`→`VaulterManager` (Manager-паттерн, не прямой интерфейс) — P2.2 морозит
  его `@method`-поверхность через резолвящийся менеджер, не через контракт напрямую (см. §2 SSOT-дока).
- `AzgardBackedUser` — маркер `extends HasPermissions, HasScopedRoles` из azguard-пакета: golden
  фиксирует НАСЛЕДУЕМУЮ поверхность (рефлексия резолвит AzGuard-методы) — не пустую строку.
- monorepo-снапшот отклонён (D6, autoload-gap filament/azgard) → per-package тесты в `tests/Arch/`
  каждого пакета, гоняются существующими per-package CI-job'ами.
- `MetricsRecorder` (VLT-LOAD P8, D31) появится ПОСЛЕ снапшота — amend-процедура P2.2 обязана прописать
  MINOR-путь (регенерация golden + D# + `plan.md` §7 Contracts + CHANGELOG MINOR).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: config-ключи/публикуемые ресурсы/биндинги морозятся доком+review-правилом, НЕ автотестом
  (D5); `@vaulter/vue` TS-поверхность вне composer-заморозки (D3).
- open_questions: —
