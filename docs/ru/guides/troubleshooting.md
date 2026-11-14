---
title: Диагностика проблем
description: Типичные сбои Vaulter и их устранение — нет драйвера прав в проде, панель AzGuard и morph-алиасы, область дедупликации, оговорки по рекурсивным CTE в MariaDB и порядок миграций.
outline: deep
---

# Диагностика проблем

Самые частые способы, которыми установка Vaulter спотыкается, почему это происходит и точное
решение. Каждый пункт ложится на реальный шов — см. [Архитектуру](/ru/concepts/architecture) для
модели и [Конфигурацию](/ru/guides/configuration) по каждому ключу.

[[toc]]

## Нет драйвера прав в проде {#no-permission-driver}

**Симптом.** Приложение нормально стартует в local/dev, но падает на первом же авторизованном
запросе в проде:

```
[Vaulter] The open null host-role driver [null] is forbidden in production.
Why: it grants every actor full access; running it in prod would expose every node.
Fix: set config(vaulter-drive.access.permission_driver) to a real driver (database|gate|spatie|azguard)
or bind your own \Vaulter\Drive\Contracts\Access\PermissionDriver.
```

**Почему.** ACL Drive нужна полоса `PermissionDriver`. Открытый драйвер `null` (каждый актор —
супер-админ) намеренно завязан на окружение: `PermissionDriverManager` бросает
`MissingPermissionDriverException` всякий раз, когда его резолвит **production**-приложение, так что
он не может втихую открыть прод-установку ([ADR-0007](/adr/0007-pluggable-authorization)).

**Решение.** Выберите реальный драйвер в `config/vaulter.php`:

<!-- snippet: config -->

```php
// config/vaulter.php
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

```dotenv
# .env (production)
VAULTER_PERMISSION_DRIVER=database   # or gate | spatie | azguard
```

- **`database`** — автономный дефолт. Авторизует чисто из строк `v_node_grants`, без зависимости
  от хоста. Берите его, если нужны только гранты на узлы.
- **`gate` / `spatie` / `azguard`** — мост к вашей существующей системе ролей; заполните
  соответствующий блок конфига `vaulter-drive.access.gate.*` / `vaulter-drive.access.spatie.*` / `vaulter-azguard.*`.
- **Свой** драйвер — см. [рецепт](/ru/guides/recipes#permission-driver).

::: warning Кэш конфига прячет env, который вы забыли
Если вы выполнили `php artisan config:cache` до того, как задали `VAULTER_PERMISSION_DRIVER`,
в кэшированном конфиге всё ещё старое значение. Перезапустите `php artisan config:cache`
(или `config:clear`) после изменения `.env`.
:::

## Панель AzGuard или morph-алиасы не согласованы {#azguard-morphs}

**Симптом.** При `access.permission_driver = 'azguard'` проверки отказывают пользователям, которые
явно держат разрешение, либо AzGuard бросает `InvalidAssignmentScopeException`,
`PanelNotResolvedException` или `PrefixConflictException`, либо всплывает ошибка вида
`Class "AzGuard\…" not found`.

**Почему.** Драйвер и шов `Vaulter\AzGuard\Contracts\AzGuardGuard` находятся в `vaulter-azguard`,
а не в core. Обе проверки шва — решения AzGuard v1, поэтому панель AzGuard хоста должна знать
пользователя, workspace и разрешения, о которых спрашивает Vaulter.

**Решение.**

1. Установите мост; он требует AzGuard v1 (`axiomasoft/azguard`, закреплён на коммите), а его
   provider сам регистрирует драйвер `azguard` и реальный адаптер:

   ```bash
   composer require axioma-studio/vaulter-azguard
   ```

   Модель пользователя `implements Vaulter\AzGuard\Contracts\AzGuardBackedUser` и использует
   `AzGuard\Concerns\HasAzGuard`. Настройки моста — `vaulter-azguard.*`.

2. Зарегистрируйте **morph-алиасы** для пользователя и каждой модели workspace (владельца диска)
   (`Relation::enforceMorphMap([...])`). Идентичности AzGuard — алиасы вида
   `[a-z0-9][a-z0-9_.-]*`, а не имена классов: владелец диска с типом-именем класса вызывает
   `InvalidAssignmentScopeException`. Vaulter прокидывает строку `type` workspace **нетронутой** —
   он никогда не резолвит алиас сам.

3. Объявите каждый алиас workspace **типом assignment scope** панели
   (`PanelBuilder::scopes(AssignmentScopePolicy::inherit(...))`). Корректный алиас, не объявленный
   панелью, получает отказ. При `isolated(...)` грант на весь тенант — включая роль super-admin —
   внутри workspace не действует; выдавайте грант внутри workspace.

4. Проверьте выбор панели: карта `vaulter-azguard.permissions` по умолчанию называет локальные
   ключи (`documents.view`, …), поэтому префикс ресурсов панели не должен быть `documents`
   (AzGuard не загрузит такую панель — `PrefixConflictException`; задайте другой
   `resourcePrefix()`). Если модель пользователя входит в несколько панелей, задайте
   `VAULTER_AZGUARD_PANEL`.

::: tip Шов — это два решения AzGuard
`hasScopedPermission($perm, $entity)` — это `hasPermission($perm, on: $entity)`, а
`hasPermissionIn($type, $id, $perm)` — `hasPermission($perm, on: AssignmentScopeRef::of($type, $id))`.
Обхода для super-admin нет: super-admin AzGuard получает роль owner через эти решения.
Расхождение почти всегда в объявлении панели или morph-алиасе, а не в адаптере.
:::

## Область дедупликации: межтенантные утечки или «неправильная» дедупликация {#dedup-scope}

**Симптом A — утечка факта существования.** В мультитенантном приложении загрузка байтов, которые
уже существуют у другого тенанта, наблюдаемо дешевле (нет новой физической записи) — побочный канал
типа confused deputy.

**Симптом B — байты не дедуплицируются там, где вы ждали**, либо GC в одной области удаляет объект
другой области.

**Почему.** `config('vaulter-media.dedup.scope')` — это граница, в пределах которой уникален
контент-адрес `sha256`, и она задаёт **и** уникальный индекс дедупликации, **и** квалифицированный
областью ключ хранения `<scope>/ab/cd/<sha256>` ([ADR-0012](/adr/0012-dedup-scope)):

| Область | Уникальна в пределах | Когда использовать |
|---|---|---|
| `tenant` (по умолчанию) | `(tenant_id, sha256)` | Мультитенантность — нет межтенантной утечки существования. |
| `storage` | `(storage_id, sha256)` | Каждое хранилище — свой островок дедупликации. |
| `global` | `sha256` по всей установке | Только single-tenant или полностью доверенные тенанты. |

**Решение.**

<!-- recipe: packages/media/tests/Recipes/MediaRecipeTest.php#media-dedup-scope -->

```php
// config/vaulter-media.php
'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'),
],
```

- Держите `tenant` (или `storage`) для любого развёртывания, где тенанты друг другу не доверяют.
- Выбирайте `global` только осознанно — для single-tenant-установки или тенантов, доверяющих друг
  другу.

::: danger Не меняйте область дедупликации на заполненной установке
Область запечена в физический ключ хранения каждого существующего блоба. Переключение позже
переименовывает пространство имён для новых ключей, но оставляет старые объекты под старым
префиксом — планируйте миграцию, не переключайте флаг на месте.
:::

## MariaDB: оговорки по спуску через рекурсивные CTE {#mariadb-cte}

**Симптом.** Листинг/перемещение дерева работает на PostgreSQL, но запрос потомков фатален или
дико медленный на MySQL/MariaDB; либо арх-тест падает с
*«non-test src never uses whereHas/withCount on descendants (H.4)»*.

**Почему.** Дерево узлов — это список смежности; спуск (`descendants()` / `ancestors()`) использует
рекурсивные CTE `staudenmeir/laravel-adjacency-list`, ограниченные по `storage_id`
([ADR-0005](/adr/0005-unified-node-tree)). Рекурсивная **агрегация по потомкам** —
`whereHas('descendants')` или `withCount('descendants')` — генерирует коррелированные рекурсивные
подзапросы, фатальные на MariaDB и патологичные в остальных случаях, поэтому они **запрещены на
путях запросов** и это проверяется арх-тестом:

<!-- snippet: host -->

```php
// tests/Arch/ArchTest.php
test('non-test src never uses whereHas/withCount on descendants (H.4)', ...);
// matches /(whereHas|withCount)\(\s*['"]descendants['"]/ and fails the build.
```

**Решение.**

- Никогда не пишите `whereHas('descendants')` / `withCount('descendants')` в доменном коде. Работу
  с поддеревом делайте **обходом** ограниченной цепочки (обход предков в `AccessResolver` —
  образец) либо явной рекурсивной связью, а не агрегатом.
- Уникальность соседей опирается на частичный уникальный индекс по `(parent_id, lower(name))` на
  Postgres и **вычисляемый хранимый столбец** на MySQL — оба идут в миграциях; руками их добавлять
  не нужно.
- Для деревьев в миллионы узлов или горячего резолва ACL read-модель на `ltree`/closure-table —
  это опциональный драйвер, добавляемый только когда этого требует профилирование, — но никогда не
  дефолт.

::: tip Для больших деревьев предпочитайте Postgres
Спуск через рекурсивный CTE дешевле всего и наиболее предсказуем на PostgreSQL. MariaDB работает
для поставляемых путей, но если вы планируете очень глубокие/широкие деревья, Postgres — более
гладкая дорога.
:::

## Порядок миграций и проблемы с префиксом {#migrations}

**Симптом.** `php artisan migrate` падает на внешнем ключе, ссылающемся на `v_nodes` /
`v_drives` / `v_blobs`, либо таблицы приземляются без префикса `v_`, либо таблицы второго пакета
сталкиваются с таблицами Vaulter.

**Почему и решение.**

- **Порядок.** Миграции Vaulter названы `0001_01_01_00NNNN_create_v_<table>_table` в одной общей
  последовательности (core `0001…`, media `0101`, drive `0201…`, documents `0301…`, webhooks `0401…`), чтобы
  родительские таблицы (`v_drives`, `v_nodes`, `v_blobs`) создавались раньше таблиц, которые на
  них ссылаются. Каждый пакет запускает свои миграции из `vendor/`; они не публикуются.

  ```bash
  php artisan vaulter:install        # publishes config, offers to run the migrations
  # or, manually:
  php artisan vendor:publish --tag=vaulter-config
  php artisan migrate
  ```

- **Префикс.** Каждая таблица Vaulter читает `config('vaulter.database.table_prefix')` (по умолчанию `v_`).
  Миграции и модели читают его обе, так что меняйте его в **одном** месте. Если вы
  переключите префикс **после** миграции, модели будут искать таблицы `<newprefix>…`, которых не
  существует — задайте префикс **до** первой миграции или переименуйте таблицы под него.

  ```php
  // config/vaulter.php
  'database' => ['table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_')],
  ```

- **Профили.** Все профили используют общую схему; генерация собственных таблиц удалена.

## Broadcast / совместное редактирование не подключается {#collaboration}

Эксплуатация коммерческого дополнения описана в его приватном руководстве.

## Как читать отчёт doctor {#doctor}

**Симптом.** После деплоя или установки нужен ответ «да/нет» по настройке, либо что-то работает
локально и не работает в production.

**Почему и решение.** Запустите `php artisan vaulter:doctor --production` (с `--json` для CI). У каждой
находки есть `code` и, если проблему устраняет команда, подсказка:

- Ошибки `core.routes.*` / `core.access.*` означают, что API открыт или нет настоящего драйвера прав;
  см. [Нет драйвера прав в проде](#no-permission-driver).
- Ошибки `core.schema.tables`: выполните `php artisan migrate`; `core.schema.key_types` значит, что
  `ids.strategy` или `ids.host_keys` отличается от значения, с которым создавалась схема, — верните
  прежнее значение, колонки руками не правьте.
- `core.outbox.leases`, `core.outbox.lag` и `drive.scan.obligations` указывают на не запущенный воркер или
  планировщик: запустите воркер очереди и `schedule:run`, затем `vaulter:relay` или
  `vaulter:scan:reconcile --all-tenants --dry-run`.
- `drive.scan.policy` с предупреждением о переходе значит, что конфиг называет политику сканирования,
  которую drive не активировал; сначала `vaulter:scan:rescan --activate-policy --drive=<id> --dry-run`.
- `media.disk.probe` пропускается, пока не передан `--probe-storage` (или `--production`); он пишет и
  удаляет один небольшой объект на диск.
- `doctor.check_failed` значит, что проверка не смогла выполниться; повторите с `-v`, чтобы увидеть
  сообщение исключения.

Doctor — read-only, сам он ничего не чинит. См. [`vaulter:doctor`](/ru/reference/commands#vaulter-doctor).

## Несовпадение диска блоба / пропавшие байты {#blob-disk}

**Симптом.** Узел существует, но скачивание отдаёт 404 на байтах, либо GC удаляет объекты, на
которые всё ещё есть ссылки.

**Почему и решение.**

- **Резолв диска.** Байты блоба лежат на диске `disk` хранилища, если оно его закрепляет, иначе на
  `config('vaulter-media.disk')` (`VAULTER_DISK`). Если вы поменяете `VAULTER_DISK` после
  загрузки, старые блобы всё ещё указывают на старый диск — не уводите диск из-под существующих
  блобов.
- **Никогда не версионируйте CAS-бакет.** На S3 CAS-бакет должен быть **без версионирования**.
  S3 lifecycle/versioning как механизм удаления будет воевать с двухфазным GC Vaulter.
  Единственное lifecycle-правило, которое стоит добавить, — `AbortIncompleteMultipartUpload`
  ([ADR-0006](/adr/0006-content-addressed-blobs-gc)).
- **Слишком короткий grace у GC.** Если кажется, что GC удаляет живые байты, поднимите
  `config('vaulter-media.gc.grace_hours')` до значения ≥ вашей худшей задержки коммита multipart, чтобы
  загрузка в полёте успела воскресить блоб с меткой tombstone. См.
  [рецепт GC блобов](/ru/guides/recipes#blob-gc).
- **Дрейф.** Если `ref_count` выглядит неправильным — это кэш; запустите
  `php artisan vaulter:blobs:reconcile`, чтобы перестроить его из пинов в `v_blob_references` (источника истины).

## Ошибки валидации панелей при загрузке {#panel-validation}

**Симптом.** Приложение падает при загрузке с `InvalidProfileException` или `UnknownProfileException`.

**Почему и решение.** Проверьте регистрацию запрошенного профиля:

- **`UnknownProfileException::forName`** — `Vaulter::drive($entity, profile: 'x')` (или маршрут) назвал
  панель, которая не зарегистрирована. Зарегистрируйте FQCN её провайдера в
  `config('vaulter-drive.profiles.providers')` или используйте панель `default`.

## Пустое «Last updated» в документации {#last-updated}

**Симптом.** Собирая эту документацию сами, вы видите пустой таймстамп *Last updated* на странице.

**Почему и решение.** VitePress читает `lastUpdated` из истории git. Поверхностный (shallow)
checkout в CI не имеет истории, поэтому таймстамп пустой. Делайте checkout с полной историей:

```yaml
# .github/workflows/docs.yml
- uses: actions/checkout@v4
  with:
    fetch-depth: 0   # else lastUpdated is empty
```

## См. также

- [Архитектура](/ru/concepts/architecture) — слои и контракты за этими сбоями.
- [Рецепты](/ru/guides/recipes) — рабочие конфигурации.
- [Справочник ошибок](/ru/guides/errors) — каждое исключение Vaulter, с якорем и HTTP-статусом.
