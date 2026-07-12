# Скептик-верификация F-P2.2-02

**Вердикт: находка НЕ опровергнута (CONFIRMED).**

## Что проверено

1. Прочитан `packages/core/src/VaulterServiceProvider.php:34-54` целиком (не только
   :39-40): `discoversMigrations()->runsMigrations()` включены БЕЗ условия, `runsMigrations()`
   остаётся активным всегда — провайдер не даёт хосту способа выключить вендорный
   автозапуск после публикации (нет флага/конфига, который бы это делал).
2. Прочитан источник `vendor/spatie/laravel-package-tools/src/Concerns/PackageServiceProvider/ProcessMigrations.php`:
   `discoverPackageMigrations()` при `runsMigrations=true` делает `loadMigrationsFrom($filePath)`
   БЕЗУСЛОВНО на каждый boot (:73-75), одновременно регистрируя publish-цель с НОВЫМ
   именем через `generateMigrationName()` (текущий `Carbon::now()`, если в
   `database/migrations` хоста ещё нет файла с тем же basename-без-timestamp — :79-103).
   Т.е. вендорный автозапуск не отключается публикацией — это подтверждает механизм,
   заявленный в находке, чтением исходника библиотеки, а не домыслом.
3. Обнаружено на диске (до моего прогона, оставлено более ранними item'ами
   P2.2/P2.5 этого же аудита): `vendor/orchestra/testbench-core/laravel/database/migrations/`
   содержит реальные ОПУБЛИКОВАННЫЕ копии миграций пакета под НОВЫМИ timestamp-именами
   (`2026_07_10_103033_create_v_storages_table.php` и ещё 17). Это — живое,
   непреднамеренное воспроизведение ИМЕННО того сценария, который описывает
   F-P2.2-02 (публикация после/наряду с автозапуском).
4. Написан и прогнан repro (`DuplicateRunBase.php` + `DuplicateRunTest.php`, этот же
   каталог) — свежий Testbench-хост со ВСЕМИ 4 провайдерами (core+media+drive+documents),
   ЧИСТАЯ `:memory:` SQLite, `php artisan migrate --force`.

## Результат прогона

```
/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/verify-F-P2.2-02/DuplicateRunTest.php
```

Тест УПАЛ (что и является доказательством) на:

```
SQLSTATE[HY000]: General error: 1 table "v_storages" already exists
(Connection: testing, Database: :memory:, SQL: create table "v_storages" (...))
```

— ровно предсказанный находкой отказ: Migrator увидел ОБА пути (вендорный, из
`runsMigrations()`, basename `2026_06_13_000007_create_v_storages_table`, и
опубликованную копию из `database/migrations`, basename
`2026_07_10_103033_create_v_storages_table` — РАЗНЫЕ имена → оба «pending» на
чистой БД), выполнил один `CREATE TABLE v_storages`, затем второй — и словил
"table already exists".

## Почему это НЕ опровергает находку и не смягчает severity

- Механизм подтверждён ТРОЙНО: (а) чтением исходника `PackageServiceProvider`
  провайдера пакета, (б) чтением исходника spatie/laravel-package-tools,
  (в) живым фактическим прогоном `migrate`, упавшим ИМЕННО с предсказанной ошибкой.
- ADR по миграционной стратегии не найден (`docs/adr/*.md` — ни один файл не
  обсуждает это конкретное решение); значит намеренного, задокументированного
  компромисса нет — это не «сознательный trade-off», а пробел в документации,
  как и утверждает находка.
- `docs/guide/installation.md:75-79` объявляет канонический режим (auto-run без
  публикации), но НЕ предупреждает, что после `vendor:publish --tag=vaulter-migrations`
  вендорный автозапуск нужно считать несовместимым с повторным `migrate` —
  ровно тот пробел, который находка называет «undocumented consequence».
- Единственная зацепка для смягчения — PT2.2 (`findings/P2-package-tools.md:32`)
  дословно требует лишь «каноничность режима объявлена в доках», а не разбора
  последствий смены режима; т.е. буквальная норма PT2.2 формально соблюдена.
  Но сам текст находки это учитывает («канон назван, последствие — нет») и не
  заявляет, что документация отсутствует целиком — оценка Major/high остаётся
  адекватной цене дефекта (боевой `SQLSTATE... already exists` у хоста).

## Артефакты

- `DuplicateRunBase.php`, `DuplicateRunTest.php` — repro (этот каталог).
- Полный вывод прогона — см. команду выше, JSON `error_details[0].message`
  содержит точный SQL и текст ошибки СУБД.

## Независимый повторный заход (второй скептик-раунд)

Возражение, которое стоило проверить: `DuplicateRunTest.php` полагается на leftover-файлы
`vendor/orchestra/testbench-core/laravel/database/migrations/*.php`, оставленные БОЛЕЕ
РАННИМИ item'ами аудита (P2.2/P2.5) — можно было заподозрить, что это артефакт
загрязнения тестового окружения, а не воспроизводимый сценарий реального хоста.

Написан ВТОРОЙ, полностью самодостаточный repro (`CleanSequenceBase.php` +
`CleanSequenceTest.php`, этот же каталог): `setUp()` явно удаляет ВСЕ файлы из
`database/migrations()` ДО каждого теста, затем воспроизводит ровно
документированную (`docs/guide/installation.md:75-80`) последовательность хоста:

1. Чистый `migrate --force` (вендорный автозапуск создаёт схему) — ✅ exit 0.
2. `vendor:publish --tag=vaulter-migrations --force` (хост публикует ради правки схемы,
   как явно рекомендует документация) — ✅ exit 0, файлы физически появляются в
   `database/migrations` под НОВЫМИ timestamp-именами.
3. Повторный `migrate --force` — падает: `SQLSTATE[HY000]: General error: 1 table
   "v_storages" already exists`.

Прогон:
```
/usr/bin/php8.4 vendor/bin/pest plans/AUDIT-VLT/artifacts/repro/verify-F-P2.2-02/CleanSequenceTest.php
```
Результат: 2/3 теста прошли (шаги 1 и 2), шаг 3 упал ИМЕННО с предсказанной ошибкой —
без единого leftover-файла на входе. Это устраняет возражение «test pollution» и
подтверждает механизм НЕЗАВИСИМО от состояния, оставленного другими item'ами.

**Итоговый вердикт (повторная скептик-проверка): находка F-P2.2-02 НЕ ОПРОВЕРГНУТА.**
Severity Major / confidence high — адекватны; не понижать и не повышать.
