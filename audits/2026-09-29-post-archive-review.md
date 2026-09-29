# Vaulter: результат проверки после архивации — 2026-09-29

Проверка завершена на `b9f0f0d68cf11f7dfb4f9318deb8ffd27eeb36da`.
На входе (`1132d8e50e06b58f8aed1107a184e9a88c25a5df`) и после двух
локальных коммитов рабочее дерево было чистым. Архивация
`49ae82644a6309ff9fdb4322eb479bb4148a135c` сохранена. Это отчёт о выполненной
работе, без активного плана, очереди задач и разрешения на публикацию.

Все 42 изменённых после архивации файла уже были закоммичены до начала проверки.
Автор трёх коммитов по метаданным Git — Dmitry Vostrikov; эти метаданные
не позволяют установить, кто непосредственно набирал изменения. Коммиты не
переписывались. Полный список файлов, SHA, дат и авторства сохранён в
[results.json](2026-09-29-post-archive-review/results.json).

| Коммит | Происхождение и результат |
|---|---|
| `2aa2eda88db634dbf82f9b958e14986e927a2a97` | Существовавший срез: 22 файла load, перенос ссылок на архив и чтение исторических путей evidence. Сохранён. |
| `6b76bb991efff3ad525bf802e77155486a50274c` | Существовавший срез: 6 файлов AzGuard, `dev-main`, расширенные контракты, тесты и публичный checkout в CI. Сохранён. |
| `1132d8e50e06b58f8aed1107a184e9a88c25a5df` | Существовавший срез: 14 файлов release, root npm lockfile, metadata, документация и ignore-правила. Только проверен. |
| `9bc347bceb90fc4fea8ed589562e6d10ee4de10a` | Добавлены три теста чтения архивных raw/report, сохранения исходных ссылок и байтов, приоритета существующего пути и отказа при отсутствии evidence. |
| `b9f0f0d68cf11f7dfb4f9318deb8ffd27eeb36da` | README AzGuard приведён к фактическим `dev-main`, публичному URL и отдельной Laravel 12/13 CI-матрице. |

Два новых коммита затронули только `load/gates/test_p8_trace_targets.py` и
`packages/azgard/README.md`. Деревья `plans/` и `.github/` совпадают по Git tree SHA
с состоянием на входе; вне `plans/archive/` остался только `NEXT-COMMANDS.md`.
Изменения настроек и навыков в соседнем `../azguard` сохранены без вмешательства.

Во время исходной проверки публичный GitHub HEAD AzGuard был получен без токена:
[`253cbf4cce0226545cd96ca8c6bcbefe329b1403`](https://github.com/axiomasoft/azguard-private/commit/253cbf4cce0226545cd96ca8c6bcbefe329b1403).
Локальный исходник, подключённый через Composer path repositories, совпал с ним.
Это наблюдение на дату проверки, а не обещание неизменного HEAD.

Проверки выполнялись на установленных зависимостях: PHP 8.4.1, Laravel 13.33.0,
Testbench 11.1.0, Node 24.12.0. PHP-тестам явно заданы `APP_ENV=testing`, SQLite
`:memory:`, cache/session `array`, queue `sync`; `DB_URL` и `DATABASE_URL` удалены
из окружения запуска, обновление snapshot отключено.

| Проверка | Фактический результат и evidence |
|---|---|
| AzGuard Pest | 21 passed, 53 assertions; [лог](2026-09-29-post-archive-review/evidence/azgard-tests.txt). |
| AzGuard Pint / PHPStan | Exit 0; [Pint](2026-09-29-post-archive-review/evidence/azgard-pint.txt), [PHPStan](2026-09-29-post-archive-review/evidence/azgard-phpstan.txt). |
| Composer manifest | `validate --no-check-lock --no-check-publish`: exit 0 **с предупреждением** об устаревшем lockfile; [лог](2026-09-29-post-archive-review/evidence/azgard-manifest.txt). |
| Composer с проверкой lockfile | `validate --no-check-publish`: **exit 2**, lockfile устарел; повторно подтверждено при сохранении отчёта; [лог](2026-09-29-post-archive-review/evidence/azgard-full-validation.txt). |
| Load offline | 57 исходных тестов; затем 15 в изменённом модуле, включая 12 повторных и 3 новых. Итого 60 различных тестов, не 72; [исходный прогон](2026-09-29-post-archive-review/evidence/load-offline.txt), [архивные ссылки](2026-09-29-post-archive-review/evidence/load-archive-regression.txt). |
| Vue | 112 passed; [лог](2026-09-29-post-archive-review/evidence/vue-tests.txt). Typecheck и сборка прошли. |
| Vue: уточнение toolchain | Исходная команда использовала root Vite 5.4.21; [старый лог](2026-09-29-post-archive-review/evidence/vue-build.txt). При сохранении отчёта повторены typecheck и сборка package-local Vite 6.4.3, соответствующим `^6.0.0`: exit 0; [новый лог](2026-09-29-post-archive-review/evidence/vue-build-vite6.txt). |
| Hocuspocus | 53 passed, JS syntax прошёл; [лог](2026-09-29-post-archive-review/evidence/hocuspocus-tests.txt). Сообщение `Max payload size exceeded` ожидаемо в успешном тесте отказа на oversized frame. |
| Workflow syntax | Разобрана структура 16 YAML; `bash -n` для 35 встроенных shell-фрагментов пяти затронутых workflow. Python AST и shell syntax изменённых load-файлов прошли. Это не полноценная проверка Actionlint. |
| Root npm lockfile | Version/dependencies/devDependencies/peerDependencies/engines согласованы с тремя workspace manifests. Проверка свежей установки не выполнялась. |
| Release gate | 7 локальных сценариев с подменённым `gh`: success → exit 0; off-main, missing, pending, failure, API-error, actual-matrix → exit 1. Последний воспроизводит отказ на Node 20; [лог](2026-09-29-post-archive-review/evidence/release-actual-matrix.txt). |
| Git | `git diff --check` прошёл; разрешённый набор файлов и неизменность архивов проверены. |

Команды package checks приведены для точности записи; зависимости при проверке
не устанавливались и не обновлялись:

```text
cwd packages/azgard:
  env -u DB_URL -u DATABASE_URL APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync UPDATE_SNAPSHOT=0 php vendor/bin/pest --ci --compact
  php vendor/bin/pint --test
  php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
  COMPOSER_DISABLE_NETWORK=1 composer --no-interaction --no-plugins --no-scripts validate --no-check-publish
  COMPOSER_DISABLE_NETWORK=1 composer --no-interaction --no-plugins --no-scripts validate --no-check-lock --no-check-publish
cwd load/gates:
  PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -q test_p3_explain test_p4_memory test_p7_publication test_p8_trace_targets test_r04_capture test_r05_capture
  PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -q test_p8_trace_targets
cwd packages/vue:
  node ../../node_modules/vitest/vitest.mjs run
  node ../../node_modules/vue-tsc/bin/vue-tsc.js --noEmit
  node node_modules/vite/bin/vite.js build --outDir <temporary-directory>/vue-vite6-dist
cwd hocuspocus-server:
  node --test --test-reporter=spec
```

Release readiness не подтверждена. Сохранены следующие результаты статической проверки:

1. В [monorepo-split.yml](../.github/workflows/monorepo-split.yml) запрашивается
   `@vaulter/hocuspocus-server (Node 20)`, а [frontend.yml](../.github/workflows/frontend.yml)
   содержит для Hocuspocus только Node 22. Даже успешная текущая матрица не удовлетворяет gate.
2. Тот же verifier объявляет только `contents: read`, без `checks: read`, хотя читает
   `check-runs`. Несогласованность permissions установлена статически; реальный GitHub 403
   не воспроизводился, и mock не проверяет авторизацию.
3. Отдельный [Hocuspocus lockfile](../hocuspocus-server/package-lock.json) не содержит
   `@hocuspocus/provider`, обязательный в [manifest](../hocuspocus-server/package.json).
   Зависимость добавлена ранее в `45cd838`; несогласованность существовала до этой проверки.
   Root lockfile её не исправляет для Docker build с контекстом `hocuspocus-server/`.
4. Локальный `packages/azgard/composer.lock` игнорируется `.gitignore` и устарел.
   Он не перезаписывался. Успех тестов на текущих установленных зависимостях не доказывает
   воспроизводимость новой установки.

Не запускались Laravel 12 / Node 20 / Node 22 CI-матрицы, Actionlint, новая установка
зависимостей, Docker build или реальные release actions. Проверки YAML и mock-сценарии
не проверяют контракт удалённых actions, обработку реальных ответов API, права токенов
или доступность registries. Сборка Vite 6 уточняет предыдущий результат, но не снимает
остальные ограничения воспроизводимости.

Публикаций, push, создания split repositories, CI dispatch, обращений к npm/Packagist/GHCR
и запросов токенов не было. Live/load campaigns и mutation не запускались. Load-тесты
использовали фикстуры, подменённые команды и временные каталоги; Hocuspocus — локальные
тестовые соединения. Release/live/mutation prerequisites не объявлены выполненными.

По просьбе владельца независимую сверку выполнил **GPT-6 Sol**: Git-происхождение,
сохранённые логи, четыре ограничения и локальный Composer validate. Sol работал read-only,
без сети и повторного package test suite. Он подтвердил 42 уникальных файла и границы
двух новых коммитов; отдельно уточнил, что mock не доказывает реальный отказ API по правам.

Evidence перенесены в репозиторий из временной папки. Из логов удалены ANSI-коды
цвета, пробелы в конце строк и пустые строки в конце файла; непустые файлы заканчиваются
одним переводом строки. SHA-256 исходных и сохранённых байтов перечислены в `results.json`. Пути `/tmp/`
в логах описывают прежнее место сборки и не нужны для чтения отчёта. Непосредственные
логи есть для package checks и mock-сценариев; сводка одноразовых статических проверок
зафиксирована по результатам команд сессии, без выдуманных raw-логов.
