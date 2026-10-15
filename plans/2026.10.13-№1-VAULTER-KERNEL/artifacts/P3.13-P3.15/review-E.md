**Вердикт: approve.** По `6c3977a4` поведение D38 держится. Блокирующих дефектов нет. Одна низкая находка — дыра в канале reason компилятора, на 32 штатных типа не влияет.

Проверено по диффу, без прогонов: 32 значения и ключи `data` те же (`webhook` → `external` один к одному, в том числе `upload.*` = false и `document.updated` = true). `EventCatalog::get`/`all`/`owner` — только активные, `resolve()` — ещё и выключенный установленный модуль. Дубль значения по всем установленным модулям даёт reason `event type "…" is declared by both`. `SinkResult::Skipped` не решает replay, первый `Stored`/`Replayed` решает, дальнейший не-`Stored` — `LogicException` «stored only partially». Слоты `(priority, id)`: `drive.activity`, затем `drive.changes`; outbox добавляется последним и читает `journal_seq`. Флаги `features.activity` / `features.changes` только внутри вкладов. `EventsReference`: колонка Module, 32 строки, `documents` затем `drive`. I1: четыре строки `EventRecorder` → `ActivityRecorder`/`ChangeJournal` сняты, новых нет. I2: старые цели `EventType` заменены на `EventCatalog` / `EventTypeContract` / `DocumentEventType` с теми же category и count. I4: один проб `enum_exists($class)`. Ledger: 32 `redefined`, decision `D38`, id событий те же.

## Находки

**low** — `packages/core/src/Modules/ModuleCompiler.php:564`

Вход: установленный модуль объявляет `enum Bad: int implements EventTypeContract` с case `= 1` и `->events(Bad::class)`. `enum_exists` и `is_subclass_of` проходят, `$case->value` — int. `preg_match` на PHP 8 даёт `TypeError`, его никто не ловит. `compileOffered()` не возвращает `InvalidModuleGraph`, booting-callback падает, диагностика `vaulter:modules` / doctor не поднимается. Строковый дубль при этом по-прежнему становится reason.

Репро (не запускать): фиктивный модуль с таким enum в `offered()` и `vaulter.modules.<id>=true`. Ожидание по контракту компилятора — reason в запечатанном невалидном графе, как у malformed string.

Строковые значения 32 событий этим путём не проходят.
