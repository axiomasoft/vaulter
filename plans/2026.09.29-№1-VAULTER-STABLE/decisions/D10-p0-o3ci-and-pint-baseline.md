---
id: D10
date: 2026-10-02
status: accepted
item: P0
items: [P0]
supersedes: []
superseded_by: null
---
# D10 — P0: разрешение O-3-CI и исправление root Pint (B-03) до кандидата (accepted)

**Actor:** repository owner (ответ на вопросы handoff после P0.4); фиксирует claude-opus-5-5/medium.
**Evidence:** ответы владельца 2026-10-02: O-3-CI — «Да, push + dispatch»; root `pint --test` (B-03) — «Исправить сейчас»;
`findings/baseline.md` B-03; `artifacts/P0.4/phase-local/pint-after-fix.log`.

## Solution

1. **O-3-CI разрешён** (D07 п.3): push итоговой ветки кандидата P0 и запуск `tests.yml` через `workflow_dispatch` на
   этом ref. PR и merge по-прежнему не разрешены — только после GREEN фазового аудита, отдельным запросом владельца.
2. **B-03 исправляется в P0, а не в P8.1.** Состав B-03: 30 файлов `plans/archive/**` и 5 файлов `load/`.
   - `plans/` исключается из root Pint (`pint.json` `"exclude": ["plans"]`): архивы — замороженные repro/evidence,
     их байты — часть закрытых доказательств; переформатирование меняло бы историю.
   - 5 файлов `load/` форматируются Pint. Добавленный `declare(strict_types=1)` в `load/app/P5PatchWindow.php`
     потребовал явного `(string)` для `getenv()`/заголовка (иначе `false` → TypeError в `preg_match`).
   - Проверка: root `pint --test` exit 0; `php -l` 5 файлов OK; `load/gates` unittest P5/P7
     (fault_barriers, chaos, product_ops, evidence, p5_cases, p5_concurrency) OK.
3. Phase-level Validation P0 по Pint теперь выполняется буквально (exit 0); B-03 у P8.1 снимается.

## Why

Фаза P0 закрывается только после hosted-evidence O-3-CI (D07 п.3), а phase-level Validation требует root
`pint --test` exit 0. Владелец выбрал исправить B-03 сейчас, чтобы кандидат O-3-CI уже содержал зелёный стиль и
хвост не переходил в P8.1. Архивы исключены, а не переформатированы, чтобы не менять байты закрытых доказательств.
