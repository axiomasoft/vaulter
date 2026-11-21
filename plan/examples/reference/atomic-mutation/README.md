# Образец: AtomicDocumentMutation, повтор после потери ответа

Исполняемый PHP 8.4 + PostgreSQL 18 образец участка 3.3/4.2. Он показывает путь от авторизованного `nodeId` до внутреннего `document_id`, порядок блокировок, сравнение режима, поколения и позиции, запись payload, квитанции и outbox. Вторая часть — восстановление маршрута ядром на отдельном соединении CORE.

```bash
bash plan/checks/atomic-mutation/run.sh          # из корня; временный кластер PG18, две БД: owner_db и core_db
REF_VERBOSE=1 bash plan/checks/atomic-mutation/run.sh   # то же, плюс stderr воркеров (ожидаемая injected failure в C)
```

Ожидаемо: код возврата 0, 30 строк `OK` и последняя строка `OK atomic-mutation: all scenarios and mutants distinguished`. Зависимости: PHP с `pdo_pgsql`, `posix`, `sodium`; `pg_config` указывает на PostgreSQL 18. Composer не нужен.

## Что переносится и куда

| Файл образца | Целевой файл и символ | Как переносить |
| --- | --- | --- |
| `src/Documents/Contracts/AtomicDocumentMutation.php` | `packages/documents/src/Contracts/AtomicDocumentMutation.php` | Без изменений, кроме namespace `Vaulter\Documents\…`. Важны docblock: повтор callback, запрет внешних эффектов |
| `src/Documents/Contracts/LockedDocumentMutation.php` | `packages/documents/src/Contracts/LockedDocumentMutation.php` | `replace(DocumentContent …)` вместо массива; `connection()` возвращает `Illuminate\Database\Connection` из `VaulterDatabase::connection()` |
| `src/Documents/Data/DocumentMutationState.php` | `packages/documents/src/Data/Documents/DocumentMutationState.php` | Поля `documentId` и `pending` обязательны. Граница 2^53−1 проверяется в конструкторе |
| `src/Documents/Actions/RunAtomicDocumentMutation.php` | `packages/documents/src/Actions/Documents/RunAtomicDocumentMutation.php` | **BOUNDARY:** SQL метода `lock()` заменяется на `DocumentWriteLock::acquire()`, а затем на `OperationGuard::authorize(…, Ability::Edit, $lockedNode, Mutation)`. Порядок T → N → Edit → D и проверка после блокировки обязательны |
| `src/Documents/Actions/LockedMutation.php` | `packages/documents/src/Actions/Documents/LockedMutation.php` | **BOUNDARY:** тело `replace()` заменяется вызовом общего `WriteLockedDocumentContent` (`DocumentStateWriter::write(Update)` + `DocumentContentUpdated`). Проверки перед записью переносятся как есть |
| `src/Documents/Actions/ChangeDocumentWriteMode.php` | `packages/documents/src/Actions/Documents/ChangeDocumentWriteModeAction.php` | Минимальная версия DB0 для сценария; полная описана в vaulter.md DB0 |
| `src/Ai/Documents/Writing/SingleTextOwner.php` | vaulter-ai `packages/documents/src/Writing/SingleTextOwner.php` | Порядок в `decideLocked()` переносится как есть. SQL — через Query Builder на `connection()` |
| `src/Ai/Documents/Writing/{ReplaceExactPlan,TextReplacement}.php` | vaulter-ai `packages/documents/src/{Writing/TextPreconditions,Commands/ReplaceExact}.php` | Применение на стороне владельца. Правила UTF-16/ProseMirror — в образце `text-replace` (участок 4) |
| `src/Ai/Core/Persistence/{OwnerRoutes,Proposals}.php` | vaulter-ai `packages/core/src/Persistence/` | Переносятся как есть, на соединении CORE |
| `src/Ai/Core/Operations/{CommitCommand,StatusCommand}.php` | vaulter-ai `packages/core/src/Operations/{Commit,Status}.php` | Переносятся как есть |
| `src/Ai/Core/{Contracts,Data,Protocol}/…` | vaulter-ai `packages/core/src/{Contracts,Data,Protocol}/` | Порты `CommandOwner`, `ResourceDirectory`, DTO, `RequestFingerprint`, `Wire` |
| `src/Database/Pg.php`, `src/Drive/{DriveContext,SqlAccessGate}.php`, `src/Ai/Core/Support/SecretBox.php` | — | **BOUNDARY**, не переносить: заменители `VaulterDatabase`, `DriveContext`, `AccessResolver` и codec хоста |

## Границы фиксации

```mermaid
sequenceDiagram
    participant W as Worker / HTTP
    participant C as CORE db
    participant O as OWNER db (Documents)
    W->>C: #1 BEGIN; INSERT v_ai_owner_routes (key, proposal, fingerprint, owner_key из плана); COMMIT
    W->>O: #2 BEGIN; T → N → Edit → D; исход по ключу?
    alt исход уже есть
        O-->>W: тот же receipt/marker (режим не проверяется)
    else исхода нет
        O->>O: режим/поколение/схема/цель → replace() → v_ai_text_commands + evidence + v_outbox; COMMIT
    end
    W->>C: #3 UPDATE route SET receipt_id; COMMIT
    W-->>W: ответ клиенту только после #3
```

Работа ставится в очередь в транзакции CORE: решение P, route и dispatch фиксируются вместе (`ResolveProposal`). Задание несёт только ID. При автоматическом согласовании шаг #1 и есть регистрация намерения.

## Что доказывают сценарии

| Сценарий | Наблюдаемый исход | Мутант и его неверный исход |
| --- | --- | --- |
| A | Kill после #2 → смена режима → повтор: тот же receipt, маркер 3/29, одна строка команды, одно событие команды; чужой fingerprint → conflict; после отзыва → `resource_unavailable` без receipt в выводе | `mode_first`: `generation_changed`, квитанция потеряна |
| B | Отзыв держит N; писатель ждёт (`pg_blocking_pids`) и после COMMIT отзыва получает отказ | `authorize_before_lock`, порядок действующего `writeContent`: запись после отзыва |
| C | Две параллельные попытки, один ключ: одна квитанция; при откате первой попытки эффект создаёт вторая | — |
| D | Серверная ошибка 40001 внутри callback: callback повторяется целиком, одна строка, ответ равен сохранённой квитанции | — |
| E, F | Изменённая цель → durable `rejected/precondition_failed`; смена режима до commit → `generation_changed` без одиночной замены | — |

## Что остаётся проверить после переноса

Автономный образец доказывает порядок и SQL-инварианты. Он не принимает Laravel-интеграцию. После переноса нужно проверить:

1. `packages/documents/tests/Concurrency/AtomicDocumentMutationRaceTest.php` — два соединения Laravel к выделенной PostgreSQL, сценарии B и C на `DocumentOperations`/`RunAtomicDocumentMutation`; барьер — `pg_blocking_pids`.
2. Тот же тест подтверждает, что `OperationGuard::authorize` под блокировкой не использует мемоизированное решение предварительной проверки (драйвер прав, кэш ролей).
3. vaulter-ai `packages/documents/tests/OwnerTransactionTest.php` и `packages/core/tests/RecoveryTest.php`: сценарий A через настоящий `Office::handle` и HTTP `POST` с потерей ответа (kill воркера Laravel queue после #2).
4. Повтор `VaulterDatabase::transaction` при 40001/40P01 вместе с `EventRecorder::record`: в отменённой попытке after-commit callbacks не выполняются (это уже делает `VaulterDatabase`), а событие записывается один раз.
