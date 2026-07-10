# Verify F-P4.2-01 — скептик-раунд

**Вердикт: находка НЕ опровергнута (refuted=false).** Severity Major остаётся.

## Что проверялось

Достижимость: есть ли реальный путь эксплуатации от недоверенного (обычного,
low-privilege) актора до неограниченного обхода предков в
`AccessResolver::ancestorChain()` (`packages/core/src/Access/AccessResolver.php:356-375`,
вызов `$node->ancestors()->get()` без `withMaxDepth()` на `:361`).

## Попытка динамического repro

`/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/P4.2/NPlusOneReproTest.php --group=p4.2`
падает на `SQLSTATE[HY000]: table "v_storages" already exists` — тот же сбой
воспроизведён на СОВЕРШЕННО НЕСВЯЗАННОМ репро `P1.1/AccessResolverAuditTest.php`
(тоже упал с идентичной ошибкой миграции). Это подтверждает, что сбой —
преюдициальная поломка тестового окружения (RefreshDatabase/миграции), а не
специфика этой находки; она уже была задокументирована автором находки
(`findings/P4.2-nplus1-recursion-pagination.md:48`, «окружение сломано»).
Вердикт вынесен по статическому чтению кода, как и допускает норма D-repro
(`⚠ partial` при неподнятом Testbench).

## Статическая проверка — искал причины закрытия пути

1. **Нет override `ancestors()` в модели.** `packages/core/src/Models/Drive/Node.php`
   использует `HasRecursiveRelationships` без переопределения `ancestors()` —
   ни глобального scope, ни встроенного лимита глубины.
2. **Нет потолка глубины на ЗАПИСИ.** Grep по `depth|maxDepth|MAX_DEPTH` в
   `packages/*/src` и `packages/*/config` не находит НИ ОДНОЙ проверки глубины
   на пути создания/перемещения узла (`CreateFolderAction::execute()`,
   `packages/drive/src/Actions/CreateFolderAction.php`, не валидирует глубину
   родителя вообще). Значит дерево не бывает ограничено «само по себе» —
   гипотеза «withMaxDepth не нужен, т.к. дерево физически не может быть
   глубоким» опровергнута в обратную сторону: дерево МОЖЕТ быть сколь угодно
   глубоким.
3. **Путь создания достижим обычным актором.** `NodeController::guardCreate()`
   (`packages/drive/src/Http/Controllers/NodeController.php:407-417`) требует
   только `canCreateInWorkspace` + `Ability::Create` на `$destination` — это
   штатная низкопривилегированная операция (создание вложенной папки в своём
   же workspace), НЕ админ-функция и не за фиче-флагом.
4. **Самоусиление.** `guardCreate()` сам вызывает
   `AccessResolver::can(..., Ability::Create, $destination)`, то есть КАЖДЫЙ
   вызов создания папки уже гоняет `ancestorChain()` на глубину текущей
   цепочки. Создание N вложенных папок подряд — это уже O(N) вызовов с
   суммарной стоимостью обхода O(N²), без единого привилегированного действия.
5. **Нет фиче-флага/gate, закрывающего именно эту операцию** — `guardCreate`
   не содержит доп. проверок помимо ability-гейта, который есть у любого
   актора с правом Editor/Creator в своём workspace.
6. **ADR-0005 подтверждает норму, а не отменяет её.** `docs/adr/0005-unified-node-tree.md`
   (Decision, п.2) требует `withMaxDepth()` симметрично для `descendants()` И
   `ancestors()`. Код выполняет это только для `descendants()`-стороны
   (`NodeReadRepository::descendants()/subtree()/descendantIdsOf()`, все три —
   `withMaxDepth(DEFAULT_MAX_DEPTH=64)`), но не для `ancestors()`-стороны
   (`AccessResolver::ancestorChain()` и `NodeReadRepository::ancestors()/breadcrumb()`).
   Это классифицируется как расхождение кода с ADR, а не «ADR устарел».

## Вывод

Ни защиты выше по стеку (middleware/policy/FormRequest), ни фиче-флага, ни
физического ограничения глубины дерева на записи не найдено. Путь
эксплуатации: обычный актор с правом Create в своём workspace создаёт длинную
цепочку вложенных папок (каждый шаг сам стоит O(текущая глубина) из-за
самоусиления через `guardCreate`), после чего каждая последующая
авторизационная проверка (`can()`/`abilitiesFor()`) на узлах этой цепочки
гоняет неограниченный recursive CTE до корня storage. Находка подтверждена;
severity Major и привязка к ADR-0005 п.2 корректны без изменений.
