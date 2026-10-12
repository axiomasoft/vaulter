# Бриф: независимое read-only ревью P4 (Grok 4.7)

Ты — независимый ревьюер репозитория `vaulter` (монорепо Laravel-пакетов; корень — текущая директория).
План: `plans/2026.09.29-№1-VAULTER-STABLE` (JSON — источник истины; `views/` генерируется, не читать).
Ты НИЧЕГО не правишь и не коммитишь: запись в репозиторий запрещена. Другой ревьюер работает параллельно и
независимо; ты его результатов не видишь. Фаза P4 не закрыта, P4.6 (`todo`) не исполнен — это ревью, не фазовый аудит.
Не используй сабагентов; один проход по всему диапазону. Начни со швов между items и осей 1–2.

Правила прогонов: разрешены только точечные проверки гипотез из cwd пакета:
`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <file> [--filter …]`,
phpstan пакета; для `packages/vue` — unit/typecheck скрипты; чтение/`git log|show|diff|blame`/grep без ограничений.
Запрещено: полные наборы, PostgreSQL/MySQL, e2e хаба, мутации, CI, composer, правки. Если находку можно доказать
только тяжёлой проверкой — `confidence: PLAUSIBLE` и точная команда для барьера.
`plan.py`: `python3 ~/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py` (`show <plan> P4.m`).

## Что проверяем (диапазон)

Сквозной mid-audit 2026-10-08 (`b68d2d63`, отчёт `artifacts/P4-midaudit/report.md`, документ
`knowledge/mid-audit-legacy-projections.json` и связанные) покрыл P4 до P4.2/P4.8–P4.13 и **явно не проверил
P4.3**. Всё после него ещё не видело сквозного взгляда:

```
git diff b68d2d63..HEAD -- . ':!plans'      # ~221 файл, +8.5k/−3.9k
```

| Item | Тема | Предыдущее ревью item (вход, не повторять закрытое) |
|---|---|---|
| P4.3 | Реестр типов документов, кодеки, безопасный fallback | Grok 4.7: `artifacts/P4.3/*`, коммит `b3108204` |
| P4.4 | Экспорт: реестр (тип, формат), выдача файла | нет |
| P4.5 | Коллаборация: серверная загрузка, поколение комнаты (epoch), storage fence, read-only; `hocuspocus-server`, `packages/vue` | Grok 4.7: `artifacts/P4.5/review-grok-4.7*`, коммит `3adfb9f4` |
| P4.7 | Валидаторы: writer-матрица sequence/state_version/epoch, V36, content hash | нет |
| P4.14 | Мост AzGuard v1 (`packages/azgard`) | Grok 4.7, 2 раунда: `artifacts/P4.14/review-verdict.md`, `re-review-*` |

Главная ценность — **швы между items** (P4.3↔P4.4↔P4.5↔P4.7↔P4.8), которые ревью отдельных items не видели,
и полнота закрытия того, что mid-audit передал этим items.

### Не повторять

- Находки mid-audit, переданные **P4.6** (MOVE: F05, F06, F09, F13, F15 и др. — см. `artifacts/P4-midaudit/report.md`
  и `phases/P4/P4.6.json`): P4.6 ещё не исполнен. Сообщай о них только если код после `b68d2d63` **ухудшил**
  ситуацию или owning-адрес неверен.
- Находки предыдущих ревью items, которые закрыты коммитами-исправлениями (см. таблицу), — только если
  исправление неполное или сломало соседнее.
- P4.14: если `artifacts/P4.14/re-review-verdict.md` ещё нет, ре-ревью может быть в работе — сверься с
  `log.jsonl` (`grep P4.14`). Не дублируй F1–F6, проверяй только швы моста с core (`AccessResolver`,
  `PermissionDriver`, профили) и доки.
- Чужие грязные пути (`git status`) не трогать и не оценивать как часть кандидата: `plans/…/views/**` и всё,
  чего нет в диапазоне.

## Нормы

1. Решения плана: `plans/…/decisions/D*.json` — для этих items в первую очередь D14, D15, D17, D23, D24, D28,
   D34–D37, D44, D51, D54, D56, D57 (+ записи отходов в `log.jsonl` по item). OPUS — `audits/2026-09-29-stable-audit/opus/`
   через `knowledge/artifacts-P4-design-P4.m-opus.json` (дословные единицы item).
2. Нормативный контракт фазы: `knowledge/P4-design.json`, инварианты `knowledge/P4-invariants.json`.
3. Проектные правила: `CLAUDE.md`, `.claude/rules/**` (публичный API/BC, провайдеры, миграции, тесты).
4. Состояние item: `python3 <plan.py> show 2026.09.29-№1-VAULTER-STABLE P4.m`, его `artifacts/P4.m/*`,
   события `log.jsonl`. Генерируемые views не читать.

`plan.py`: `python3 ~/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py` (или найди
`find ~/.claude/plugins -path '*task*/scripts/plan.py'`).

## Оси ревью (риск-ориентированно, adversarial для security/данных/конкурентности/публичного API)

1. **Авторизация и egress.** P4.4: выдача файла экспорта — Download vs View, профиль/тенант, имя/Content-Type/
   Content-Disposition, нет ли обхода OperationGuard. P4.5: аутентификация хаба (`authenticate/load/store`),
   подпись/replay-кэш, read-only, закрытие соединений по кодам, нельзя ли записать в чужой документ/старое
   поколение; egress хаба (C08). P4.14: мост не расширяет решения AzGuard.
2. **Целостность данных и конкурентность.** P4.5 storage fence и epoch: store старого поколения не мёрджится,
   409 не бросается (v4 повторяет), сервер не сидирует клиента; P4.7 writer-матрица: кто двигает
   `v_nodes.sequence`, кто — `state_version`/`collab_epoch`; гонки в транзакциях (порядок блокировок,
   повторные чтения после lock), content hash не зависит от порядка ключей на всех драйверах.
3. **Реестры и fallback.** P4.3 неизвестный тип/схема/кодек: безопасный fallback без потери данных, lossy/
   newer-schema защита (шов с P4.8 — F16 mid-audit, acceptance P4.7 V4); P4.4 реестр (тип, формат):
   неизвестный формат, конфликт регистраций, порядок провайдеров.
4. **Публичный API и BC.** Новые публичные классы/методы/события (подписи событий P4.7), `@internal` vs
   публичное, snapshot-DTO (D44), снапшоты `tests/Arch/__snapshots__`, UPGRADE/CHANGELOG соответствуют коду.
5. **Тесты доказывают заявленное.** Тесты, которые прошли бы с неправильной реализацией; отсутствие
   негативных контролей там, где они заявлены (`artifacts/P4.m/negative-controls*.md`); пропуски выдаются за GREEN.
6. **Документация.** en/ru зеркала согласованы с кодом (`docs/guide`, `docs/ru/guide`, `docs/reference`).


## Формат ответа

Последним сообщением верни ТОЛЬКО JSON (без markdown-обрамления):
{"route":{"model":"...","effort":"..."},
 "verdict":"GREEN|ATTENTION|RED",
 "findings":[{"id":"G1","severity":"Blocker|Major|Minor|Nit","confidence":"CONFIRMED|PLAUSIBLE",
   "file":"path","line":N,"owning_item":"P4.x","title":"...","failure_scenario":"конкретные входы/состояние -> неверный результат",
   "evidence":"цепочка file:line или результат прогона","is_p46_move_zone":false}],
 "residual_risks":["..."],"needs_barrier":["команда/сценарий"]}
Не включай находки без конкретного сценария отказа. Лучше 8 проверенных, чем 30 догадок.
