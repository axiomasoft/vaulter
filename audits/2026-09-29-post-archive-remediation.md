# Vaulter: исправления после post-archive review — 2026-09-29

Исходное рабочее дерево было чистым, HEAD —
`24a0c52` (полный SHA и метаданные — в
[provenance.json](2026-09-29-post-archive-remediation/evidence/provenance.json)).
[Исходный аудит](2026-09-29-post-archive-review.md) проверен по текущему коду и
сохранённым evidence; его текст и все 19 файлов каталога с evidence и results.json сохранены
побайтно. Активные планы не создавались и не восстанавливались.

Пересекающиеся изменения существовали до запуска:

| Срез | Происхождение |
|---|---|
| `1132d8e` | Required checks verifier и release-конфигурация; root npm lockfile. |
| `72f4dcb` | Минимум Hocuspocus Node 22 и удаление Node 20 из frontend CI. |
| `45cd838` | Добавление provider в Hocuspocus manifest без соответствующей standalone lock-записи. |
| `6b76bb9`, `b9f0f0d` | Текущие AzGuard manifest/CI и README. |

Коммиты сохранены, история не переписывалась. Авторство этих коммитов по Git —
Dmitry Vostrikov; оно не устанавливает непосредственного исполнителя правок.
Происхождение игнорируемого локального `packages/azgard/composer.lock` по Git
установить нельзя. Его исходный SHA-256:
`867d2c81b91e118a11b90d7794ab7ead5f4242e7634756acf018274c072157d8`.

| Замечание и актуальность | Исправление / исследование | Фактическая проверка и ограничения |
|---|---|---|
| **1. Required checks:** gate по-прежнему требовал Hocuspocus Node 20, отсутствующий в текущей CI-матрице. | Удалено только это требование. Оставшиеся восемь имён соответствуют jobs в code-style, static-analysis, tests и frontend workflows. Commit `a3f7ca7`. | [Baseline](2026-09-29-post-archive-remediation/evidence/release-baseline.txt) воспроизвёл отказ; [после правки](2026-09-29-post-archive-remediation/evidence/release-gate.txt) четыре теста проходят, включая 11 mock-сценариев. Реальные CI checks/API не проверялись. |
| **2. Verifier permissions:** `checks: read` отсутствовал при чтении `check-runs`. | Только job `verify-release-sha` получает `contents: read` для compare и `checks: read` для check-runs. Связь `split.needs` сохранена. Commit `a3f7ca7`. | Статически проверены эффективные permissions job и зависимость split от verifier. Это декларация прав, а не доказательство работоспособности реального GitHub token; 403 не воспроизводился. |
| **3. Standalone Hocuspocus lock:** manifest содержит provider, standalone lock его не содержал. | Добавлены provider 4.3.0 и его `@lifeomic/attempt` 3.1.0 из существующего root lockfile. npm offline нормализовал lock, включая peer flags ioredis/yjs. Версии всех прежних пакетов сохранены; root lockfile и manifest не менялись. Commit `d739304`. | Новая изолированная [offline установка](2026-09-29-post-archive-remediation/evidence/hocuspocus-offline-ci.txt): 30 пакетов, exit 0. Отдельная [production установка](2026-09-29-post-archive-remediation/evidence/hocuspocus-production-ci.txt): 29 пакетов, exit 0; [импорты](2026-09-29-post-archive-remediation/evidence/hocuspocus-production-import.txt) server/provider проходят. Docker build и Node 22 не запускались. |
| **4. Игнорируемый AzGuard lock:** актуальный исходный файл остаётся устаревшим. | Manifest, lock и path packages скопированы в отдельную временную структуру с теми же относительными путями. `update --lock --no-install` offline исправил копию: изменились только content-hash и stability flags двух dev-main зависимостей; массивы packages/packages-dev идентичны. Сохранён [diff копии](2026-09-29-post-archive-remediation/evidence/azgard-isolated-lock.diff). Чужой оригинал не перезаписан. | [Validate копии](2026-09-29-post-archive-remediation/evidence/azgard-lock-validation.txt): exit 0. [Новая offline установка](2026-09-29-post-archive-remediation/evidence/azgard-offline-install.txt): **exit 100**, отсутствуют cached archives, включая PHPStan/Pint/Testbench. [Validate оригинала](2026-09-29-post-archive-remediation/evidence/azgard-original-lock.txt): **exit 2**. Замечание об оригинальном lock остаётся; воспроизводимость новой Composer-установки не подтверждена. |

При исходной offline попытке npm со старым standalone lock получил
[ENOTCACHED для provider metadata](2026-09-29-post-archive-remediation/evidence/hocuspocus-lock-baseline.txt).
Это не прямое воспроизведение online ошибки `npm ci` из-за рассогласования:
отсутствие записи установлено сравнением файлов. После переноса записей из root
lock [npm install --package-lock-only --offline](2026-09-29-post-archive-remediation/evidence/hocuspocus-lock-refresh.txt)
прошёл, затем были выполнены настоящие изолированные установки из cache.

В AzGuard применялся `COMPOSER_DISABLE_NETWORK=1`, plugins и scripts отключены.
[Обновление копии](2026-09-29-post-archive-remediation/evidence/azgard-lock-refresh.txt)
прошло с предупреждением: metadata взяты из локального cache и могут быть устаревшими.
Логи неуспешной установки содержат URL отменённых downloader-запросов; сеть была
отключена. Обращений к npm/Packagist/GHCR не выполнялось. Локальный HEAD соседнего
AzGuard — `253cbf4cce0226545cd96ca8c6bcbefe329b1403`; в этом запуске с GitHub HEAD
он не сверялся. Соседний репозиторий не изменялся.

Дополнительные package checks выполнены на PHP 8.4.1 и Node 24.12.0:

| Проверка | Результат |
|---|---|
| AzGuard, текущий installed vendor | [Pest](2026-09-29-post-archive-remediation/evidence/azgard-tests.txt): 21 passed, 53 assertions; [Pint](2026-09-29-post-archive-remediation/evidence/azgard-pint.txt) и [PHPStan](2026-09-29-post-archive-remediation/evidence/azgard-phpstan.txt): exit 0. |
| Hocuspocus, изолированная standalone установка | [53 теста](2026-09-29-post-archive-remediation/evidence/hocuspocus-tests.txt) и [syntax](2026-09-29-post-archive-remediation/evidence/hocuspocus-syntax.txt): exit 0. `Max payload size exceeded` — ожидаемый результат успешного теста oversized frame. |
| Vue, текущие installed dependencies | [112 тестов](2026-09-29-post-archive-remediation/evidence/vue-tests.txt), [typecheck](2026-09-29-post-archive-remediation/evidence/vue-typecheck.txt), [build package-local Vite 6.4.3](2026-09-29-post-archive-remediation/evidence/vue-build-vite6.txt): exit 0. Сборка и declarations находятся во временном каталоге. |
| Статика release и lockfiles | [Пять YAML, два bash-фрагмента, manifest/lock consistency и сохранность исходных evidence/Composer lock](2026-09-29-post-archive-remediation/evidence/static-checks.txt): exit 0. Actionlint отсутствует; YAML и `bash -n` не заменяют его. |

Перед Pest прочитаны TestCase, RealAzgardTestCase, Pest bootstrap и phpunit.xml:
переназначений DB в этих TestCase нет. Запуск явно задавал `APP_ENV=testing`, SQLite
`:memory:`, cache/session/mail `array`, queue `sync`; `DB_URL` и `DATABASE_URL`
удалены из окружения команды, snapshot update отключён. Базы dev/prod не использовались.
Успех этих тестов относится к текущему installed vendor, а не к неуспешной новой установке.

Воспроизводимый release regression runner:

```text
PYTHONDONTWRITEBYTECODE=1 python3 scripts/test-release-gate.py
```

Runner читает фактические YAML matrices, подменяет `gh` локальным executable и
выполняет verifier через `bash -e -o pipefail`. Успех actual-matrix/identical даёт
exit 0; off-main/diverged/missing/pending/failure/cancelled/skipped/API-error/
compare-error дают exit 1. Stub возвращает уже обработанные значения после `--jq`:
контракт JSON GitHub, jq-фильтры и авторизацию этот тест не проверяет.

Точные команды, exit codes, время выполнения и SHA-256 evidence сохранены в
[results.json](2026-09-29-post-archive-remediation/results.json). Логи сохранены
полностью с удалением ANSI-кодов, trailing whitespace и конечных пустых строк.
Временные пути в логах фиксируют место запусков и не нужны для чтения результатов.

Не выполнялись push, публикация, split repositories, PR, CI dispatch, запросы токенов,
live/load campaigns или mutation. Не проверялись Laravel 12, Node 20/22, свежая
установка Vue, Docker build и реальные release actions. Новая Composer-установка
не прошла offline. Release readiness и готовность публикации **не подтверждены**.
