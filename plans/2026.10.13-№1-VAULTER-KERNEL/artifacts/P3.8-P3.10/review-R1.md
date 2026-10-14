# Review R1 — P3.8 (нейтральный OperationContext и DriveContext)

Ревьюер: R1 (облегчённое ревью). Фактическая модель: Sonnet 5.5 (claude-sonnet-5-5). Режим: read-only.

## Вердикт: APPROVE_WITH_NITS

Находок уровня BLOCKER/HIGH/MEDIUM нет. Одна LOW-находка (покрытие тестами) и три вопроса владельцу/исполнителю.

## Находки

### F1 (LOW) — нет теста на сообщения валидации, которые правило P3.8 требует сохранить
- Место: `packages/core/tests/Operations/OperationContextTest.php` (покрыты idempotency 1–255, causation, scope/tenant, привязка scope). Теста на correlation id (пустой, больше 191 байта, управляющие символы) и на «persisted drive» у `DriveContext` нет.
- Сценарий: правка regex или текста в `OperationContext::assertTraceId` (`packages/core/src/Operations/OperationContext.php:106`) или в `DriveContext::persistedKey` (`packages/core/src/Operations/DriveContext.php:78`) не упадёт ни в одном тесте. Единственная проверка текста — `drive/tests/Drive/StorageManagerTest.php:85` («persisted drive»), и она идёт через `Vaulter::drive()`. Текст correlation-сообщения тестом не закреплён нигде.
- Нарушенное правило: P3.8 rules[1] («та же валидация … с теми же сообщениями»). Сам код сообщения и условия посимвольно совпадает с `HEAD`, так что регресса сейчас нет — это только пробел в защите.

## Вопросы (без доказуемого сценария отказа)

- Q1. Порядок проверок при двух нарушениях. В `HEAD` конструктор сначала проверял correlation, потом persisted drive. Теперь `DriveContext::for()` вычисляет `persistedKey` (аргумент `scope`) раньше, чем `OperationContext` проверит correlation. Если приходят и неперсистентный drive, и плохой correlation id, исключение теперь про drive, раньше было про correlation. Тип исключения тот же (`InvalidArgumentException`), меняется только текст. Правило «никаких изменений порядка проверок» относится к авторизации и SQL, поэтому считаю это допустимым, но решение за исполнителем.
- Q2. `new DriveContext($operation, $drive)` проверяет scope, но не проверяет `$operation->tenant` относительно `$drive->tenant_id`. Через фабрики `for()`/`system()` расхождения не будет. Прямой конструктор публичный (snapshot `public-api.txt`), поэтому вызывающий может собрать контекст с чужим tenant. Сегодня tenant в операциях ядра не используется, а в P3.9 идёт только в telemetry. Если tenant станет участвовать в идемпотентности или claim (P6), стоит добавить инвариант или сделать конструктор непубличным.
- Q3. `TenantRef::id()` бросает исключение при id длиннее 191 байта. `DriveContext::for()` теперь вызывает `TenantRef::of($drive->tenant_id)`, то есть там, где раньше исключения не было. Сценарий: тенант-ключ хоста длиннее 191 байта. Для длинных строковых ключей такой тенант не мог работать и раньше, потому что у колонки есть лимит, но проверьте, что `tenant_id` в схеме не длиннее 191.

## Что проверено и чем

Вопросы из раздела R1 промпта:
- Telemetry без PII и без ключа: `OperationContext::telemetry()` (строки 85–101) отдаёт correlation_id, scope.kind, tenant.kind и actor.kind (`system`/`user`), а также causation_id, если он задан. Ни значения idempotency key, ни tenant id, ни user id нет. Тест `OperationContextTest.php:59` проверяет это на значениях `tenant-secret`/`secret-key`.
- Та же валидация и сообщения: сравнил с `git show HEAD:…/OperationContext.php`. Условие и текст correlation (`≤191`, без управляющих символов, непустой) и текст persisted drive совпадают дословно. Условие `getRawOriginal` и `exists` перенесено в `DriveContext::persistedKey` без изменений.
- `driveId()` переживает правку модели: `DriveContext` сохраняет `$driveKey` из `getRawOriginal` в конструкторе, как раньше. Scope тоже строится из `persistedKey`, а не из текущего атрибута.
- Конструирование контекста с иной семантикой: `grep` по `new OperationContext`, `OperationContext::for/system`, `new DriveContext`, `DriveContext::for/system` в `packages/*`. Вне тестов `OperationContext` строится только в `DriveContext::for` и `HttpOperationContextFactory::operation`. Все остальные места — `DriveContext::for/system` с теми же аргументами (actor, correlationId, reason), что были у старого `OperationContext`. Контекстов с произвольным scope или tenant вне `operation()` нет.
- Allowlist: stale-записи i1 (три записи `OperationContext` → `Drive`) и i2 (те же плюс `needs-explicit-require` documents → `OperationContext`) удалены обоснованно. `OperationContext` больше не ссылается на `Drive`, а documents теперь импортирует `DriveContext`. Добавлены правила target-map: `DriveContext` → owner drive, surface Api, `ScopeRef`/`TenantRef` → ядро. `scripts/php84 php scripts/inventory/inventory.php --check` — зелёный (scan-i3, scan-i4 и target-map ok).
- Снимки `public-api.txt`: у core убрана старая поверхность `OperationContext` (конструктор с `Drive`, `driveId`), добавлены `OperationContext`/`DriveContext`/`ScopeRef`/`TenantRef`. У drive и documents изменены только type-hint `OperationContext` → `DriveContext`. В diff снимков также видны поля P3.9/P3.10 (`OperationRunner $runner`), это вне моего среза.
- Запущено: `scripts/t sqlite -p core packages/core/tests/Operations/OperationContextTest.php` — 11 passed, 41 assertions.
- Не запускалось (выходит за облегчённое ревью): Pint/PHPStan (по сообщению исполнителя, зелёные), полные наборы.

Ограничение: механическую замену во всех 106 изменённых src-файлах просмотрел выборочно (места конструирования контекста и снимки), а не построчно.
