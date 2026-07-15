# HANDOFF — 2026-07-15 — after P1.1

**Next:** P2 — скелет (16 полей не заполнены), разворачивается `/task:plan-design VLT-REL P2`, НЕ
`plan-exec` (упал бы на предусловии). P2 = заморозка публичной API-поверхности (снапшот
`root/contracts/public-api-surface.md`), гейт P3–P5. §3 Routing P2.* = opus/high manual (триггер §10
«public contract/SemVer», effort high+ MANDATORY).

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — §3 Routing P2.* = заморозка публичного контракта, effort high+ MANDATORY |
| Context | NEW SESSION — шаг-не-item (`plan-design` читает план с диска заново) |
| Суть | Развернуть скелет P2 в исполнимые items: снапшот публичной поверхности 6 composer-пакетов (граница из P1.1 §2) + arch-test/snapshot-гейт на `.claude/rules/public-api.md`, заморозка ДО тега |

```
/task:plan-design VLT-REL P2
```

**Done:** P1 закрыта (1/1 🟢). P1.1 → `plans/VLT-REL/root/cut-line.md` (item-коммит `9369755`):
граница minimal-тега 0.1.0 — 6 composer IN (`axioma-studio/vaulter-{core,drive,documents,media,
filament,azgard}`) + `@vaulter/vue` OUT (D3, отдельный npm-трек); публичная поверхность composer
определена совместимо с `.claude/rules/public-api.md` (граница, не поимённый список — энумерация = P2);
L-01…L-08 + VLT-LOAD P3–P7 + VLT-MUT drive отложены «в оптимизацию» (не блокер тега); версия 0.1.0
(D2) + bump-rule при major=0 (PATCH=hot-path без смены сигнатур, MINOR=аддитив/`MetricsRecorder`,
MAJOR не используется до 1.0); `CHANGELOG [Unreleased]` → тело `[0.1.0]` в P5. Расхождений с
`public-api.md` не обнаружено → эскалация §10 не потребовалась.

**Remaining:** P2 (design+exec: заморозка поверхности, гейт P3–P5) → {P3 (условия внедрения), P4
(upgrade-путь)} → P5 (тег + публикация). P2–P5 всё ещё скелеты.

**Sources of truth:** `plan.md` (Meta/Routing §3/D-лог D1/D2/D3/Contracts/Обсуждение) · `phases/P1.md`
(P1.1 закрыт, Completion Notes) · `root/cut-line.md` (граница релиза — SSOT набора пакетов и
bump-rule) · `phases/P2.md`…`P5.md` (блупринты) · `plans/VLT-LOAD/plan.md` §7 (L-01…L-08, D9, D11) ·
`plans/VLT-LOAD/slo.md` §5 · `.claude/rules/public-api.md` (гейт P2) · `CHANGELOG.md [Unreleased]`.

**Open risks:**
- L-02…L-06 остаются гипотезами до подтверждения VLT-LOAD (P3/P5/P6) — cut-line откладывает их как «не
  проверено под нагрузкой», не гарантия.
- VLT-MUT `vaulter-drive` < Score 95 (D11) — dev-срез фикса L-01 не открыть, пока VLT-MUT не закроет;
  тег P5 это не блокирует (условие внедрения из P3), но P4-upgrade-путь на этом факте стоит.
- Нарратив `CHANGELOG [Unreleased]` перечисляет 3 composer, факт — 6; выравнивание перечня на 6 —
  задача P5 (переименование `[Unreleased]` → `[0.1.0]`), зафиксировано в `cut-line.md` §5.
- ACTIVE.md несёт multi-plan формат (VLT-MUT + VLT-REL) — отклонение от строгого 4-строчного канона
  SSOT; существующая практика проекта, `plan-lint` может дать WARNING (см. Lint-счёт ниже).
- P2 уточняет поимённую поверхность и проверяет «фактическое vs `public-api.md`» — расхождение там =
  эскалация «public contract/SemVer», не тихий фикс (вход P2, `cut-line.md` §2).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: полное закрытие VLT-LOAD (P3–P7) сознательно НЕ блокирует minimal-тег (D1); TS/API-заморозка
  `@vaulter/vue` вне scope тега (D3).
- open_questions: —
