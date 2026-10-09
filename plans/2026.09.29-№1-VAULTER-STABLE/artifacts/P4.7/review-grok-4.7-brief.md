# Ревью PLAN2.P4.7 — бриф для независимого read-only ревьюера (Grok 4.7)

Ты — независимый ревьюер. Репозиторий: `/home/vostrikov/projects/packages/vaulter` (монорепо Laravel-пакетов Vaulter).
Пиши только отчёт: файлы репозитория не меняй (запись запрещена ядром). Ответ — на русском.

## Что ревьюим

Рабочее дерево поверх `HEAD` (изменения item ещё не закоммичены): `git status --short`, `git diff HEAD`,
плюс неотслеживаемые файлы (`git status --short | grep '^??'`; игнорируй `artifacts/P4.5/review-grok-4.7.*` — чужое).
Item P4.7 «Валидаторы: writer-матрица sequence/state_version/epoch и V36».

Нормы (читать первыми):
- `plans/2026.09.29-№1-VAULTER-STABLE/phases/P4/P4.7.json` — scope, rules, validation;
- `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P4-design.json` раздел Validators; `knowledge/P4-invariants.json`;
- `plans/2026.09.29-№1-VAULTER-STABLE/decisions/D35.json`, `D51.json`;
- `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.7/` (`check-profile.json`, `writer-map.md`, `negative-controls.md`, `barrier-receipts.json`);
- журнал отклонений: `plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` (строки с `"item": "P4.7"`).

## Существенные изменения и швы

1. События `DocumentCreated`, `DocumentContentUpdated`, `DocumentRevisionCreated` получили `int $stateVersion, int $epoch`
   (значения из результата writer / заблокированной строки, не из чекпоинта-головы); пять эмиттеров в `packages/documents/src/Actions/**`.
2. Исполняемая writer-матрица `packages/documents/tests/Document/DocumentValidatorMatrixTest.php` (дельты sequence/state_version/epoch
   по всем writers drive+documents) и сценарий F16 `tests/Document/DocumentSchemaWritableTest.php`.
3. PG ForkRace: `packages/documents/tests/Concurrency/{DocumentStateRaceTest,NodeSequenceDocumentIndependenceTest,DocumentRenameStaleTest,DocumentSchemaWritableRaceTest,RaceFixtures}.php`.
4. Удалены `Legacy*Sequence*` тесты (таблица соответствия — `artifacts/P4.7/writer-map.md`), `tests/Pest.php` упрощён.
5. **Owning-коррекция из барьера V90 (источник P4.2):** `packages/documents/src/Content/ContentHasher.php` теперь сортирует ключи
   объектов перед хэшированием (на PostgreSQL jsonb возвращает ключи в своём порядке — хэш копии отличался от хэша источника).
   Проверь: не ломает ли это dedup/immutability ревизий, `skipsIdenticalWrite`, сравнение с уже сохранёнными хэшами, размер (`size`).
6. Тестовые правки вне Scope (см. журнал): `packages/drive/tests/Concurrency/NodeSequenceBoundaryTest.php` (устаревшие после P4.13 утверждения),
   `packages/documents/tests/Document/CollaborationSessionTest.php` (сигнатура драйвера после P4.5), импорт в core-тесте.
7. Документация: `docs/reference/concurrency.md` (+ru), events, documents guide, UPGRADE, CHANGELOG; golden `tests/Arch/__snapshots__/public-api.txt`.

## На что смотреть

Корректность прежде стиля: верна ли матрица (в т.ч. утверждения `docs/reference/concurrency.md` против реального кода writers),
слабые/всегда-зелёные места тестов (особенно ForkRace: гарантируют ли они, что гонка вообще происходит и что отказ — именно из-за
валидатора), не осталось ли путей, где `sequence` узла двигается записью содержимого или наоборот, F16 (проверка writeability на
заблокированной строке до overwrite — все writers), корректность значений в событиях при `changed=false`/повторе,
публичный API/BC, соответствие scope и exclude item (нет HTTP ETag, нет EventRecorder, нет новой колонки sequence).

## Как проверять

Разрешены чтение, grep, git и короткие точечные прогоны. Не запускай полные сьюты, PostgreSQL и mutation-инструменты.
- PHP: `cd packages/documents && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <файл> [--filter <имя>]`

Для каждой находки дай воспроизведение: падающий тест/команду с выводом, либо точную цепочку вызовов с файлами и строками.
Находка без воспроизведения помечается как «plausible».

## Формат ответа

```
# P4.7 — внешнее ревью Grok 4.7 (read-only)

## Находки
| # | Severity (critical/high/medium/low) | Файл:строка | Суть | Сценарий отказа | Воспроизведение | Рекомендация |

## Что проверено и чисто
(кратко по швам 1–7)

## Вердикт
approve | request changes — одной фразой почему.
```

Если находок нет — так и напиши, с перечнем проверенного.
