<!-- plan-projection
projection: decision-archive
projection_version: v1
source_scope: decisions/
through: 2026-10-05/D22
inputs_sha256: 3d9035fc56a9dcf650b053e5b099804d756f4f53525d019a876b23fba0806958
generated_by: task plan-views decisions
-->

# Solutions archive

> Do not manipulate by hand: this is a generated view.

| ID | Date | Status | Items | Solution |
|:--|:--|:--|:--|:--|
| D21 | 2026-10-05 | superseded | P2.2, P2.3 | Единица итогового независимого Grok review — весь исходно заказанный workset P2.2 + P2.3. Реализация исходного P2.3 включается в ещё открытый P2.2; все его исходные требования и исключения сохранены дословно в `artifacts/P2.2/combined-review-unit/P2.3-original.md`. Исходный P2.2 сохранён в `artifacts/P2.2/combined-review-unit/P2.2-original.md`. Оба результата, новые и удалённые файлы, необходимые нормы, зависимости и финальное validation передаются вместе в одну отдельную read-only сессию. История executor туда не передаётся. Selector остаётся `grok/grok-4.7/500k`, effort high. Владелец изменил организацию review, а не условия приёмки. Полный обязательный набор обоих исходных пунктов исполняется на стабильном объединённом кандидате; после GREEN обязательны freshness и closure checks. История P2.2, текущие attempt 5 / run `08750714b29a6d5a7792f308c7e281d3832ed1f76f0f1b29319a02f60f1c124b`, подлинный FINDINGS первого completed review и бюджет двух completed rounds сохраняются. Остановленная по уточнению владельца вторая transport attempt сохраняется как INCOMPLETE, не становится GREEN и не обнуляет бюджет. P2.3 после реального закрытия объединённого P2.2 — successor verification: проверяет выполненные исходные обязанности documents и подлинную поставку общего review. Отдельный reviewer request P2.3 не создаётся; это явная отмена per-item автоматизации исходного selector для этого successor, поскольку тот же весь продукт уже входит в общий reviewer unit. Его старт и закрытие выполняются существующими native admission, contract, recovery и terminal API, с исходным root invocation и фактической route evidence. Подмена item_id в review receipt или копирование чужого GREEN запрещены. |
