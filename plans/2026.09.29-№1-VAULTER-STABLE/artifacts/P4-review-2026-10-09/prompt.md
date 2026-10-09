# Промпт: двойное ревью P4 после mid-audit (Sonnet + Grok), read-only

Ты — ведущий ревьюер репозитория `vaulter` (монорепо Laravel-пакетов; корень — текущая директория
`/home/vostrikov/projects/packages/vaulter`). План: `plans/2026.09.29-№1-VAULTER-STABLE` (JSON — источник
истины; Markdown в `views/` генерируется и не правится). Ты работаешь в новой сессии Claude Code на **Sonnet**
и параллельно запускаешь второго, независимого ревьюера — **Grok 4.7** — через read-only мост. Итог — один
сведённый вердикт с подтверждёнными находками. **Ты ничего не исправляешь**: исправления — работа `plan-run`
владельца отдельной сессией.

## Основание (владелец, дословно, 2026-10-09)

> давай ревью sonnet + grok в новой сессии, подготовь промт

Контекст: перед этим исполнитель предложил порядок «ре-ревью P4.14 → P4.6 (фазовый барьер) → фазовый аудит P4».
Владелец выбрал ревью сейчас, до барьера. Значит: это **ревью, а не фазовый аудит D03** — фаза P4 не закрыта,
P4.6 (`todo`) ещё не исполнен, тяжёлые проверки (PG/MySQL/гонки/e2e, полные наборы) не прогнаны и прогонять их
ты не должен.

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

## Двойное ревью: порядок работы

1. **Старт.** Зафиксируй свой наблюдаемый маршрут (модель и effort из настроек/окружения сессии — не выдумывай).
   Создай `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4-review-2026-10-09/` (этот промт уже там).
2. **Запусти Grok первым, в фоне** (он работает ~15–30 мин):
   - Собери бриф Grok `grok-brief.md` из этого промта: диапазон, «не повторять», нормы, оси 1–6, правила прогонов
     (ниже) и формат ответа JSON. Бриф самодостаточный — Grok не видит эту сессию.
   - argv только через мост, не руками:
     ```bash
     export SKM_ORCH_ROOT=/home/vostrikov/projects/packages/swissknifeman/packages/orch
     python3 <plan.py> bridge orch launch reviewer grok --model grok-4.7 --effort high --cwd "$PWD" \
       > plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4-review-2026-10-09/grok-launch.json
     ```
   - Запуск: argv из `grok-launch.json`, бриф на stdin, поток — в `grok-stream.jsonl` той же папки
     (фоновой задачей; таймаут 3600 с). Мост запускает Grok read-only: запись в репозиторий запрещена ядром.
   - Маршрут Grok доказывай по его сессии, не по запуску: `~/.grok/sessions/<encoded cwd>/<session_id>/`
     (`chat_history.jsonl` → `model_id`, `reasoning_effort`; `signals.json` → `contextWindowTokens` ≥ 500000).
     Несовпадение — route mismatch, не подмена.
3. **Твой собственный проход (Sonnet)** — независимо, не подглядывая в поток Grok до конца своего прохода.
   Один проход по всему диапазону, без сабагентов. Начни с швов и осей 1–2.
4. **Сведение.** После завершения Grok извлеки его итог (последняя строка потока, `type: result`) в
   `grok-result.txt`. Для каждой находки обоих ревьюеров:
   - подтверди сам: прогоном, который проверяет гипотезу (см. ниже), или точной цепочкой `file:line`;
   - вердикт: `CONFIRMED` / `PLAUSIBLE` / `REJECTED` (с причиной);
   - дедуплицируй, укажи источник (`sonnet`, `grok`, `both`) и **owning item** (P4.3/4.4/4.5/4.7/4.14, или
     P4.6, если это его MOVE-зона, или «новый item»).
5. **Отчёт и запись в план** (только API, без правки состояния items):
   - `report.md` в папке ревью: вердикт **GREEN / ATTENTION / RED**, таблица находок (severity
     `Blocker|Major|Minor|Nit`, confidence, `file:line`, сценарий отказа, основание, источник, owning item,
     вердикт подтверждения), остаточные риски, что требует барьера P4.6.
   - Knowledge-документ: `python3 <plan.py> add-document 2026.09.29-№1-VAULTER-STABLE --file <input.json>` с
     `id: review-p4-post-midaudit-2026-10-09`, `applies_to: ["P4.3","P4.4","P4.5","P4.7","P4.14"]`, секции:
     verdict, findings, recommendations, routes (маршруты обоих ревьюеров с доказательствами).
   - `python3 <plan.py> log 2026.09.29-№1-VAULTER-STABLE P4 --type note --summary "review sonnet+grok <verdict>: @plan/knowledge/review-p4-post-midaudit-2026-10-09.json" --actor <твой маршрут>`
     (если `P4` как ref не принимается — на P4.6 как ближайший открытый item, и напиши это в отчёте).
   - Не коммить, не `reopen`, не `grant`: решение о коррекциях принимает владелец.

## Прогоны (бюджет)

Разрешено **только для проверки конкретной гипотезы находки**, точечно, из cwd пакета:
`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <file> [--filter …]`,
`../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress` пакета,
для `packages/vue` — его unit/typecheck скрипты; чтение/`git log|show|diff|blame`/grep без ограничений.
Временные тесты для подтверждения — только во временной папке вне `src/` и удалить после прогона (используй
`python3 -c "import shutil; shutil.rmtree(...)"`, `rm` может блокироваться хуком), упомянуть в отчёте.
Запрещено: полные наборы пакетов, PostgreSQL/MySQL, e2e процессов хаба, мутационное тестирование, CI/workflow
dispatch, composer, правки продукта и плана (кроме add-document/log), `git add/commit/push`.
Если находку можно доказать только тяжёлой проверкой — пометь `PLAUSIBLE` и укажи точную команду/сценарий для
барьера P4.6.

## Готово, когда

- `report.md`, `grok-brief.md`, `grok-launch.json`, `grok-stream.jsonl`, `grok-result.txt` лежат в
  `artifacts/P4-review-2026-10-09/`;
- knowledge-документ добавлен и note залогирован;
- финальный ответ владельцу: вердикт, число подтверждённых находок по severity и owning items, маршруты обоих
  ревьюеров (доказанные), что требует барьера P4.6, и строка `python3 <plan.py> next 2026.09.29-№1-VAULTER-STABLE`.
