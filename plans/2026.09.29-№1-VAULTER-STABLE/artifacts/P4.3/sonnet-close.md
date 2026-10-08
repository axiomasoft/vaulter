# P4.3 — закрытие (Sonnet 5.5, medium)

Скопируй всё ниже черты в новую сессию Claude Code в корне `/home/vostrikov/projects/packages/vaulter`
на **Sonnet 5.5, effort medium**. Этот файл заменяет `sonnet-handoff.md` (там устаревшие шаги).

---

Ты закрываешь пункт **P4.3** плана `2026.09.29-№1-VAULTER-STABLE` (реестр типов документов). Код, тесты, доки и
решения готовы (Opus 5.5/high), часть проверок уже зелёная. Твоя работа: допрогнать оставшиеся проверки,
починить **мелочи**, выполнить негативные контроли, finish и commit. Ничего не перепроектируй.

`plan.py` = `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py`;
`PL=2026.09.29-№1-VAULTER-STABLE`; `A=plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.3`;
`M=plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4-midaudit`.
Актор для `plan.py log|finish|commit`: `--actor anthropic/claude-sonnet-5-5/medium`. `plan.py start` не запускай.
Без `git add -A`, `--no-verify`, amend. Push в конце — да (владелец хочет всё в `main`, без веток).

## 0. Прочитай (коротко)

- `$A/completion-notes.md` (раздел «Post-audit session» — что уже зелёное), `$A/negative-controls.md`
- `$M/check-plan.md` разделы C3, C4 (общий порядок аудита) — используй как справку по ожиданиям
- `plan.py show $PL D55` (принятые отклонения P4.3 — не «чини» их обратно) и `D56`
- `git status --short`

Уже выполнено и не повторяется на неизменных входах (D51): V82, G1 (goldens регенерированы, diff проверен),
C1 (core/drive/documents Arch), C2 drive, V2, V3. Полоса AzGuard **не входит** в P4.3: она стала пунктом P4.14 (D56).

## 1. Прогоны (полный вывод → `$M/receipts/<id>.txt`; в отчёт только числа)

Скрипт-обёртка (по желанию): `cwd`, команда, `git rev-parse --short HEAD`, exit — в начало/конец файла.

| id | cwd | команда |
|---|---|---|
| V1 | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Types tests/Document tests/Unit tests/Operations tests/Arch` |
| V1x | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Feature tests/Profiles tests/Providers tests/Configuration tests/Collaboration tests/Comment tests/Schema` |
| V2x | . | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Feature packages/core/tests/Unit packages/core/tests/Content packages/core/tests/ObservabilityDocuments packages/core/tests/E2E packages/core/tests/Providers packages/core/tests/Support` |
| C3-guard-drive | packages/drive | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_WRITE_GUARD=1 ../../scripts/php84 vendor/bin/pest --ci tests/PublicApi tests/Drive/DrivePolicyEnforcementTest.php tests/Drive/QuotaLedgerTest.php tests/Uploads/P3/ScanWorkRecoveryTest.php` |
| C3-guard-documents | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_WRITE_GUARD=1 ../../scripts/php84 vendor/bin/pest --ci tests/Operations tests/Document/DocumentCopyLifecycleTest.php tests/Types` |
| C3-guard-media | packages/media | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: VAULTER_TEST_WRITE_GUARD=1 ../../scripts/php84 vendor/bin/pest --ci tests/Cas/BlobGcBatchTest.php` |
| V80-core | . | `scripts/php84 vendor/bin/pint --test packages/core && scripts/php84 vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress` |
| V80-documents | packages/documents | `../../scripts/php84 vendor/bin/pint --test && ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |
| V80-drive | packages/drive | то же, что documents |
| V80-filament | packages/filament | то же, что documents |
| V81 | . | `python3 plans/2026.09.29-№1-VAULTER-STABLE/artifacts/coverage/opus-coverage.py check && python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py check $PL` |

Pint — только из cwd пакета (у каждого свой `pint.json`; корневой — для core). PG-полосы (`$M/check-plan.md` H1/H2)
в эту сессию **не входят** — они принадлежат барьерам P4.6/P4.7.

Как обращаться с падениями:
- **Мелочь — чини на месте**: импорты, точные типы PHPStan (array shapes, `list<class-string>`, union в
  `DocumentTypeRegistry`, `first([...])` подмножество колонок, PHPUnit `Assert` в `src/Testing`), форматирование,
  устаревшие ожидания тестов (старый `handler.id = 'vaulter/tiptap'`, `rich_text`, xlsx-mime у spreadsheet-узла,
  `format:`/`mimeType:` в `CreateDocumentInput`, ключи профиля `'markdown'` вместо `'vaulter/markdown'`),
  коллизии глобальных Pest-функций между файлами. Тест, которому нужен другой набор типов, подменяет реестр
  (`TypesTestCase::installTypes()` или `app()->instance(DocumentTypeRegistry::class, …)`), а не регистрирует в
  замороженный.
- Каждую правку: одна строка `plan.py log $PL P4.3 --type difficulty --summary "…" --actor …`; перезапусти только
  затронутую проверку.
- **СТОП и эскалация** (`--type blocker`, отчёт владельцу): утечка tenant/Download, потеря данных, иной порядок
  блокировок, неожиданная миграция или публичная сигнатура, необъяснённый diff golden, lossy save, ремонт guard
  выключением защиты.

## 2. Негативные контроли (часть V80)

Выполни NC1–NC5 из `$A/negative-controls.md` и A-NC1…A-NC4 из `$M/check-plan.md` (раздел C4): поломка → только
указанный тест → реальный RED → восстановить точные байты (`git diff -- <file>` как до контроля) → GREEN.
Syntax/setup error — не валидный RED: уточни контроль и сохрани оба вывода. Заполни таблицу в
`$A/negative-controls.md` и добавь таблицу A-NC в `$A/completion-notes.md`.

## 3. Finish, commit, push

Допиши результаты в `$A/completion-notes.md` (раздел «Check session results»: команды, exit, счётчики, правки). Затем:

```bash
plan.py finish $PL P4.3 --status done_with_deviations \
  --check V1=0 --check V2=0 --check V3=0 --check V80=0 --check V81=0 --check V82=0 \
  --deviation "D55: core DocumentContent parent until P4.4, create(?type) default_type, export via type, presentation names + kind presenter tag, vaulter/ first-party rule, degraded/newer schema read-only, size limit for all types, service-locator baseline moved, HTTP type field, files beyond task_files" \
  --notes "Document type registry (config/tag/provider, frozen, vendor/name, vaulter/ reserved), rich-text/markdown/spreadsheet, TipTapCodec lossless read, unknown-type fallback (V10), degraded read-only (P4-I16), documents presented by type (V34), default_type boot check (V82), generator + contract tests; AzGuard lane moved to P4.14 (D56)" \
  --actor anthropic/claude-sonnet-5-5/medium
plan.py commit $PL P4.3 --role executor --actor anthropic/claude-sonnet-5-5/medium --dry-run
```

В dry-run должны попасть **все** пути из `git status`; недостающие добавь через `--path` (ожидаемо: docs/**,
`docs/.vitepress/config.ts`, оба golden, тесты вне glob пункта, `$A/**`, `$M/receipts/**`, JSON плана с D55/D56/P4.14).
Затем та же команда без `--dry-run`, потом `git push origin main`. Рабочее дерево должно остаться чистым.

## Финальный отчёт

Проверки с числами; правки (по строке); NC/A-NC результаты; sha коммита; строка `plan.py next $PL`.
Риск пункта — публичный API и целостность данных: порекомендуй владельцу `task:review`, сам не запускай.
