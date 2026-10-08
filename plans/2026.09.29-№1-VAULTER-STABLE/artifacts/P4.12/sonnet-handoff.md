# P4.12 — handoff to the barrier session (Sonnet)

Prompt (paste into a fresh Claude Code session on Sonnet in /home/vostrikov/projects/packages/vaulter):

---

Ты завершаешь пункт плана `2026.09.29-№1-VAULTER-STABLE` **P4.12** (ревизии файлов: ретеншн, удаление/закрепление; квота по всем ревизиям; дедуп без global). Код написан Opus в предыдущей сессии и лежит **незакоммиченным** в рабочем дереве (item уже `in_progress`, `start` не вызывай). Твоя задача: дождаться длинных прогонов, добить мелочи, перевести RU-зеркала доков, закрыть пункт и закоммитить. Архитектуру не переделывай.

`plan.py` = `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py`, план = `2026.09.29-№1-VAULTER-STABLE`.

1. Прочитай: `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.12/{check-profile.json,completion-notes.md,negative-controls.md}`, затем `plan.py show 2026.09.29-№1-VAULTER-STABLE P4.12` (поле `validation`: V1–V4, V80, V81, V90). Сгенерированные Markdown-views не читай и не правь. `git status` — все изменения относятся к P4.12 (список в конце).
2. Перед PG/MySQL: скиллы `laravel-testing:test-isolation-guard` и `devops:db-test-preflight`, затем `scripts/db-preflight.sh pgsql` и `scripts/db-preflight.sh mysql`. Только `vaulter_test`.
3. Прогоны — каждую команду ровно как в `validation` (cmd/cwd/env), вывод в `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.12/receipts/<id>.txt` (через `> file 2>&1`, в контекст — только итог). Длинные запускай в фоне и жди уведомления, без поллинга:
   - V1 (drive SQLite: tests/Drive tests/Uploads tests/PublicApi), V2 (media SQLite: tests).
   - V3 (drive PG: RevisionPruneRaceTest + RevisionNumberRaceTest) — нужен receipt: executed ≥ 1, skipped = 0, имена тестов.
   - V4 (корень PG: packages/core/tests/Upgrade) — там новый `QuotaAllRevisionsUpgradeTest` и цепочка с миграцией `2026_10_07_000007_quota_all_revisions` (SchemaParityTest, Canon03UpgradeTest).
   - V90 барьер: P4.10 V3 (`packages/core/tests/Sharing/ShareSecurityBoundaryTest.php packages/core/tests/Upgrade` на PG) и MySQL `NameKeyUniquenessTest.php CanonicalSchemaTest.php` — точные команды в V90 procedure.
   - Дополнительно затронутые H (impact, не acceptance; тот же PG env, cwd packages/drive): `tests/Concurrency/NodeSequenceBoundaryTest.php tests/Concurrency/LockOrderRaceTest.php tests/Concurrency/QuotaRaceTest.php tests/Concurrency/CompleteUploadRaceTest.php`; cwd packages/media: `tests/Cas/RepairBlobScopesCommandTest.php` (на SQLite скипается — новый кейс про `global` проверяется только на PG).
   - V80 и V81 уже зелёные у Opus (completion-notes.md); повторить только если правил PHP-код: pint --test и phpstan затронутого пакета по V80.
   Сводку всех запусков запиши в `artifacts/P4.12/barrier-receipts.json` (как `artifacts/P4.11/receipts.json`: id, cwd, cmd, exit, executed/passed/skipped, имена файлов, время).
4. Если что-то красное:
   - тест ожидает старую семантику (квота = только головы / дельта new−old, `global` дедуп, `dedupScope()` строкой) → обнови ожидание под D48/D49 с коротким комментарием `(PLAN2.D48)`/`(PLAN2.D49)`;
   - дефект продукта, очевидный и локальный → одна минимальная owning-коррекция, затем перезапуск только упавших/затронутых; `plan.py log … --type deviation` с указанием item-источника;
   - нужно менять дизайн/контракт (сигнатуры, формулу квоты, порядок блокировок) или дефект в закрытом item → **стоп**: `plan.py log … --type blocker`, `finish` не делай, опиши в отчёте.
   - падение/skip ≠ успех; skipped > 0 в V3/V4/V90 = не pass.
5. RU-зеркала (правило item): перенеси EN-изменения из `git diff docs/guide docs/reference` в `docs/ru/guide/{uploads,storage-and-blobs,configuration,errors}.md` и `docs/ru/reference/commands.md` — тот же смысл, стиль существующего RU-текста, код-блоки без изменений.
6. Закрытие:
   ```
   plan.py finish 2026.09.29-№1-VAULTER-STABLE P4.12 --status done \
     --check V1=0 --check V2=0 --check V3=0 --check V4=0 --check V80=0 --check V81=0 --check V90=0 \
     --notes "<кратко: что сделано (см. artifacts/P4.12/completion-notes.md), P4-I18/I19 self-check, receipts artifacts/P4.12/barrier-receipts.json, негативные контроли NC1–NC7>"
   ```
   (`done_with_deviations` + `--deviation "…"`, если были коррекции/отклонения сверх уже залогированных Opus; `--actor` — твой фактический model/effort.)
7. Коммит: `plan.py commit 2026.09.29-№1-VAULTER-STABLE P4.12 --role executor`. На `OUTSIDE_SCOPE` добавь `--path` для каждого пути P4.12 из списка ниже (и для receipts/RU-доков); чужие пути не трогай. Никаких `git add -A`, `--no-verify`, amend, push.
8. Отчёт: sha коммита, итоги V1–V4/V90 (числа executed/skipped), правки, и строка `plan.py next 2026.09.29-№1-VAULTER-STABLE`.

Пути P4.12 вне буквальных task_files (для `--path`): `packages/core/src/Exceptions/Drive/CannotDeleteHeadRevisionException.php`, `packages/core/src/Exceptions/Drive/RevisionPinnedException.php`, `packages/core/src/Models/Drive/FileRevision.php`, `packages/core/src/Profiles/Profile.php`, `packages/drive/src/Actions/Files/DeleteFileRevisionAction.php`, `packages/drive/src/Actions/Files/SetFileRevisionRetentionAction.php`, `packages/drive/src/Events/Files/FileRevisionDeleted.php`, `packages/drive/src/Providers/HttpRegistrar.php`, `packages/drive/src/Console/RescanFileRevisionsCommand.php`, `packages/drive/src/Files/FileRevisionRetention.php` (удалён), `packages/media/src/Configuration/DedupScope.php`, все изменённые `packages/{core,drive,media}/tests/**`, `docs/guide/errors.md`, `docs/reference/commands.md`, `docs/ru/**` (после шага 5), `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.12/**`.

---
