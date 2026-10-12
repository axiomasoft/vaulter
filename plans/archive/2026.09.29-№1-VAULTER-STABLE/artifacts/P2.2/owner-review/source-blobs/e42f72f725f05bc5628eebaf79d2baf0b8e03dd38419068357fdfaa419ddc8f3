---
id: D21
date: 2026-10-05
status: superseded
item: P2.2
items: [P2.2, P2.3]
supersedes: []
superseded_by: D22
---
# D21 — одно итоговое review всей работы P2.2 + P2.3

**Actor:** владелец репозитория, текущая Codex-сессия.
**Evidence:** прямое уточнение владельца в текущем root:
> а почему нельзя было сделать все в одно ревью?????????? так в инструкциях написано
> что ревью должен быть по всей работе, а не по кускам

SHA-256 двух дословных сообщений, соединённых LF: `87e85459bae03f9fdf56651f632ec7623706a6761a474405460c6202f375b94c`.

## Solution

Единица итогового независимого Grok review — весь исходно заказанный workset
P2.2 + P2.3. Реализация исходного P2.3 включается в ещё открытый P2.2; все его
исходные требования и исключения сохранены дословно в
`artifacts/P2.2/combined-review-unit/P2.3-original.md`. Исходный P2.2 сохранён
в `artifacts/P2.2/combined-review-unit/P2.2-original.md`. Оба результата,
новые и удалённые файлы, необходимые нормы, зависимости и финальное validation
передаются вместе в одну отдельную read-only сессию. История executor туда не
передаётся. Selector остаётся `grok/grok-4.7/500k`, effort high.

Владелец изменил организацию review, а не условия приёмки. Полный обязательный
набор обоих исходных пунктов исполняется на стабильном объединённом кандидате;
после GREEN обязательны freshness и closure checks. История P2.2, текущие
attempt 5 / run `08750714b29a6d5a7792f308c7e281d3832ed1f76f0f1b29319a02f60f1c124b`,
подлинный FINDINGS первого completed review и бюджет двух completed rounds
сохраняются. Остановленная по уточнению владельца вторая transport attempt
сохраняется как INCOMPLETE, не становится GREEN и не обнуляет бюджет.

P2.3 после реального закрытия объединённого P2.2 — successor verification:
проверяет выполненные исходные обязанности documents и подлинную поставку
общего review. Отдельный reviewer request P2.3 не создаётся; это явная отмена
per-item автоматизации исходного selector для этого successor, поскольку тот
же весь продукт уже входит в общий reviewer unit. Его старт и закрытие
выполняются существующими native admission, contract, recovery и terminal API,
с исходным root invocation и фактической route evidence. Подмена item_id в
review receipt или копирование чужого GREEN запрещены.

## Why

Уточнение владельца требует review всей заказанной работы. Coalescence сохраняет
последовательную native зависимость P2.2 → P2.3 и все требования продукта,
при этом не требует второго частичного review или изменения Task runtime.
Будущие пункты, схемы/колонки P4.1, Types/Content/export и push/publication
не включаются.

## Executable acceptance mapping

The exact original P2.2 and P2.3 acceptance literals remain mandatory in their
immutable `combined-review-unit/P2.2-original.md` and `P2.3-original.md` archives.
Executable carriers change transport, preserving every obligation:

- `code-guidance`: both original Code Guidance gates, actual reflection and E10 zero-hit.
- `packages-pg`: actual four-package Pint/PHPStan/Pest, completed generation, exact isolated P1 PostgreSQL matrices with zero failed/skipped, unchanged candidate and runtime.
- `drive-arch`: original drive Arch/Providers gate and its actual counts.
- `documents-arch`: original documents Arch/Providers gate and its actual counts.
- `combined-review`: authentic independent entire-workset P2.2 Grok GREEN for current bytes.
- `producer-delivery`: committed native P2.2 close and its authentic entire-workset GREEN, with current product equality; no P2.3 review relabel or request.
- `freshness`: current P2.3 native item/bundle/decisions/status checks and selected lint before close; native terminal reconciliation and terminal delivery remain mandatory after close.

The behavioral DAG and its stable evidence precede the direct native review
packet. The packet binds those receipts/logs and acceptance code/config. After
actual GREEN, lightweight executable qualification runs through the current
contract, followed by native closure. Qualification output is outside the reviewed
inventory and does not rewrite behavioral or review evidence.

The active Vue type comment referencing the removed document resource is updated mechanically to `DocumentRevisionResource`; TypeScript types and behavior remain unchanged. This satisfies original P2.3 Code Guidance over package source callers.
