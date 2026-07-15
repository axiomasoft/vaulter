# VLT-REL — AMEND: как легально изменить замороженную публичную поверхность после тега `0.1.0`

> Дизайн-артефакт фазы P2 (item **P2.2**). SSOT замороженной поверхности —
> `public-api-surface.md`; enforcement — per-package `tests/Arch/PublicApiSnapshotTest.php`
> (core/drive/documents/azgard) + empty-surface гвард (media/filament). Этот документ —
> **процедура**, на которую указывает diff-сообщение упавшего snapshot-теста. Инвариант
> `cut-line.md` §4: **breaking на публичной поверхности не проходит тихим коммитом** — он
> либо ловится этим гейтом, либо оформляется новым `D#` + синхронным апдейтом `plan.md`
> `## 7. Contracts`.

> ⚠ **SUPERSEDED (номер базы) → `plan.md` §5 D12 (2026-07-15).** Замороженная поверхность выходит в
> релизе **`v0.2.0`** (не `0.1.0` — заголовок и MINOR-пример «`0.2.0`» ниже писались от базы `0.1.0`,
> сдвинуть на `0.2.0`: `MetricsRecorder`-аддитив → `0.3.0`, PATCH → `0.2.z`). Процедуры/классы бампа
> НЕ меняются; авторитетный номер — D12.

## Что морозит гейт

Рефлексией (D5): раздел 1 SSOT (интерфейсы `**/src/Contracts/**` — полная сигнатура: имя
параметра + тип вкл. union/intersection/nullable, by-ref, variadic, default; return-тип) +
раздел 2 (фасад `Vaulter\Facades\Vaulter` по `@method`-докблоку). Config-ключи, публикуемые
ресурсы, биндинги (разделы 3–5) держатся доком + `.claude/rules/public-api.md` + §2
D#-дисциплиной — **не** рефлексией.

Канонизация детерминирована: контракты сортируются по FQCN, методы — по имени, члены
union/intersection — сортируются. Безобидный реордер билд НЕ красит; реальный дифф сигнатуры —
красит.

## Классификация изменения (по `cut-line.md` §4, пока `major=0`)

| Изменение поверхности | Класс бампа | Схема |
|:--|:--|:--|
| **Аддитивное**: новый `Contracts/`-интерфейс, новый метод в контракте, новый `@method` фасада, новый host-facing config-ключ | **MINOR** | `0.y.0` |
| **Breaking**: удаление/переименование метода или контракта, сужение типа параметра, ужесточение return | **MINOR + пометка `BREAKING`** (при `0.x`; при `1.x` — MAJOR) | `0.y.0` + `BREAKING` в CHANGELOG + upgrade-заметка |
| Внутренняя реализация за неизменной сигнатурой (напр. hot-path фикс L-01) | **PATCH** — поверхность НЕ меняется, гейт НЕ трогается | `0.1.z` |

Пример аддитива: `MetricsRecorder`-контракт наблюдаемости из **VLT-LOAD P8** (D31,
`plans/VLT-LOAD/phases/P8.md`) — добавится ПОСЛЕ снапшота как **MINOR** (`0.2.0`).

## Процедура аддитивного изменения (MINOR)

1. **Внеси изменение** в код контракта/фасада пакета (dev-срез вне этого плана, §2).
2. **Обнови SSOT-док** `public-api-surface.md` — энумерируй новую поверхность ключ-в-ключ
   (тот же детерминированный порядок).
3. **Регенерируй golden** затронутого пакета — ТОЛЬКО через env-гейт:
   ```bash
   # core — из КОРНЯ репо (core тестируется корневым pest, не `cd packages/core`):
   UPDATE_SNAPSHOT=1 vendor/bin/pest --filter=PublicApiSnapshot
   # drive/documents/azgard — из каталога пакета:
   cd packages/<pkg> && UPDATE_SNAPSHOT=1 vendor/bin/pest --filter=PublicApiSnapshot
   ```
   Регенерация golden **вне этой процедуры** (без нового `D#`) = `[POSSIBLE-DEFECT]`
   (`.claude/rules/tests.md`): за обходкой почти всегда реальный breaking. `UPDATE_SNAPSHOT`
   НИКОГДА не выставляется ни одним CI-workflow (гейт `.github/workflows/*` не несёт этой env).
4. **Заведи новый `D#`** в `plan.md` `## 5. Decision Log` (что добавлено, класс бампа, почему
   аддитивно).
5. **Синхронно обнови** `plan.md` `## 7. Contracts` (строка `Exported` — Версия + Уведомлены;
   при потреблении чужого frozen-контракта — строка `Consumed`).
6. **CHANGELOG** — запись класса **MINOR** (`cut-line.md` §4 / P4). Для кросс-репо потребителей
   (напр. `MetricsRecorder`) — запись в `open-questions.md` донора + строка Open risks в его
   `handoff.md` (кросс-репо push — задача шины mAInd, не plan-протокола).
7. **Прогони гейт** — тест должен снова быть зелёным на новом golden.

## Процедура breaking-изменения

Breaking (удаление/переименование/сужение) при `0.x` формально допустим semver в minor, но
**избегается**. Реальный, неизбежный break — это **SemVer-решение владельца**, НЕ автоматическая
регенерация golden:

1. **СТОП** — не регенерируй golden молча. Эскалация владельцу «public contract/SemVer»
   (`plan.md` §2, plan-protocol §10).
2. Владелец/`plan-design` принимает новый `D#`, санкционирующий break.
3. Далее — шаги 2–7 аддитивной процедуры, НО CHANGELOG несёт явную пометку **`BREAKING`** +
   upgrade-заметку (миграционный путь для ранних потребителей тега).

## Куда смотреть

- SSOT поверхности: `plans/VLT-REL/root/contracts/public-api-surface.md`
- Bump-rule: `plans/VLT-REL/root/cut-line.md` §4
- Правило границы публичного: `.claude/rules/public-api.md`
- Тесты гейта: `packages/{core,drive,documents,azgard}/tests/Arch/PublicApiSnapshotTest.php`;
  golden — `packages/<pkg>/tests/Arch/__snapshots__/public-api.txt`; empty-surface гвард —
  `packages/{media,filament}/tests/Arch/ArchTest.php`.
