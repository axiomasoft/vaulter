---
title: Диагностика проблем
description: Типичные сбои Vaulter и их устранение — нет драйвера прав в проде, morph-алиасы Azgard, область дедупликации, оговорки по рекурсивным CTE в MariaDB и порядок миграций.
outline: deep
---

# Диагностика проблем

Самые частые способы, которыми установка Vaulter спотыкается, почему это происходит и точное
решение. Каждый пункт ложится на реальный шов — см. [Архитектуру](/ru/guide/architecture) для
модели и [Конфигурацию](/ru/guide/configuration) по каждому ключу.

[[toc]]

## Нет драйвера прав в проде {#no-permission-driver}

**Симптом.** Приложение нормально стартует в local/dev, но падает на первом же авторизованном
запросе в проде:

```
[Vaulter] The open null host-role driver [null] is forbidden in production.
Why: it grants every actor full access; running it in prod would expose every node.
Fix: set config(vaulter.drivers.permission) to a real driver (database|gate|spatie|azgard)
or bind your own \Vaulter\Contracts\Access\HostRoleDriver.
```

**Почему.** ACL Drive нужна полоса `HostRoleDriver`. Открытый драйвер `null` (каждый актор —
супер-админ) намеренно завязан на окружение: `HostRoleDriverManager` бросает
`MissingHostRoleDriverException` всякий раз, когда его резолвит **production**-приложение, так что
он не может втихую открыть прод-установку ([ADR-0007](/adr/0007-pluggable-authorization)).

**Решение.** Выберите реальный драйвер в `config/vaulter.php`:

```php
// config/vaulter.php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],
```

```dotenv
# .env (production)
VAULTER_PERMISSION_DRIVER=database   # or gate | spatie | azgard
```

- **`database`** — автономный дефолт. Авторизует чисто из строк `v_node_grants`, без зависимости
  от хоста. Берите его, если нужны только гранты на узлы.
- **`gate` / `spatie` / `azgard`** — мост к вашей существующей системе ролей; заполните
  соответствующий блок конфига `gate.*` / `spatie.*` / `azgard.*`.
- **Свой** драйвер — см. [рецепт](/ru/guide/recipes#permission-driver).

::: warning Кэш конфига прячет env, который вы забыли
Если вы выполнили `php artisan config:cache` до того, как задали `VAULTER_PERMISSION_DRIVER`,
в кэшированном конфиге всё ещё старое значение. Перезапустите `php artisan config:cache`
(или `config:clear`) после изменения `.env`.
:::

## morph-алиасы Azgard / Corex не согласованы {#azgard-morphs}

**Симптом.** При `drivers.permission = 'azgard'` проверки прав возвращают `false` для пользователей,
которые явно держат роль, либо всплывает ошибка вида `Class "AzGuard\…" not found`, либо гранты,
адресованные workspace, никогда не совпадают.

**Почему.** Полоса `azgard` общается только со швом `Contracts\Access\AzgardGuard`, принадлежащим
Vaulter, поэтому ядро **не** несёт зависимости от `azguard`/`corex`. Прод-хост обязан
(1) **привязать** реальный адаптер к этому шву и (2) **согласовать morph-типы**, чтобы
идентификаторы workspace/владельца, которые прокидывает Vaulter, совпадали с алиасами,
зарегистрированными AzGuard/Corex. Реальный адаптер по умолчанию не поставляется — тесты привязывают
`Vaulter\Testing\FakeAzgardGuard`.

**Решение.**

1. Привяжите реальный адаптер `AzgardGuard`, который делегирует в
   `hasScopedPermission` / `hasPermissionIn` AzGuard / wildcard SuperAdmin:

   ```php
   // A service provider's register()
   $this->app->bind(
       \Vaulter\Contracts\Access\AzgardGuard::class,
       \App\Vaulter\AzGuardAdapter::class,
   );
   ```

2. Согласуйте ULID morph-стратегию во всех трёх системах, чтобы morph-типы совпали:

   ```php
   // config/vaulter.php
   'ids' => ['default' => 'ulid'],
   ```

   ```dotenv
   # .env
   VAULTER_ID_STRATEGY=ulid
   AZ_GUARD_MORPH_TYPE=ulid
   # corex.ids.strategy=Ulid in the Corex config
   ```

3. Зарегистрируйте типы моделей workspace/владельца как **morph-алиасы Corex** (EntityRegistry).
   Vaulter прокидывает строку `type` для workspace **нетронутой** — он никогда не резолвит алиас
   сам, поэтому незарегистрированный алиас втихую никогда не совпадёт.

::: tip Шов зеркалит AzGuard один-в-один
`hasScopedPermission($perm, $entity)`, `hasPermissionIn($type, $id, $perm)` и `isSuperAdmin()`
точно зеркалят методы AzGuard, так что адаптер — это тонкий проброс. Расхождение почти всегда в
morph-типе, а не в адаптере.
:::

## Область дедупликации: межтенантные утечки или «неправильная» дедупликация {#dedup-scope}

**Симптом A — утечка факта существования.** В мультитенантном приложении загрузка байтов, которые
уже существуют у другого тенанта, наблюдаемо дешевле (нет новой физической записи) — побочный канал
типа confused deputy.

**Симптом B — байты не дедуплицируются там, где вы ждали**, либо GC в одной области удаляет объект
другой области.

**Почему.** `config('vaulter.dedup.scope')` — это граница, в пределах которой уникален
контент-адрес `sha256`, и она задаёт **и** уникальный индекс дедупликации, **и** квалифицированный
областью ключ хранения `<scope>/ab/cd/<sha256>` ([ADR-0012](/adr/0012-dedup-scope)):

| Область | Уникальна в пределах | Когда использовать |
|---|---|---|
| `tenant` (по умолчанию) | `(tenant_id, sha256)` | Мультитенантность — нет межтенантной утечки существования. |
| `storage` | `(storage_id, sha256)` | Каждое хранилище — свой островок дедупликации. |
| `global` | `sha256` по всей установке | Только single-tenant или полностью доверенные тенанты. |

**Решение.**

```php
// config/vaulter.php
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
`v_storages` / `v_blobs`, либо таблицы приземляются без префикса `v_`, либо таблицы второго пакета
сталкиваются с таблицами Vaulter.

**Почему и решение.**

- **Порядок.** Миграции Vaulter упорядочены по таймстампу (`..._000001_…` → `..._000021_…`), чтобы
  родительские таблицы (`v_storages`, `v_nodes`, `v_blobs`) создавались раньше таблиц, которые на
  них ссылаются. Пакет сам обнаруживает и запускает свои миграции; если вы **публикуете** их через
  `vendor:publish --tag=vaulter-migrations`, сохраните порядок опубликованных имён файлов —
  не перенумеровывайте их.

  ```bash
  php artisan vaulter:install        # publishes config + migrations, offers to run them
  # or, manually:
  php artisan vendor:publish --tag=vaulter-config
  php artisan vendor:publish --tag=vaulter-migrations
  php artisan migrate
  ```

- **Префикс.** Каждая таблица Vaulter читает `config('vaulter.table_prefix')` (по умолчанию `v_`).
  Опубликованные миграции и модели читают его обе, так что меняйте его в **одном** месте. Если вы
  переключите префикс **после** миграции, модели будут искать таблицы `<newprefix>…`, которых не
  существует — задайте префикс **до** первой миграции или переименуйте таблицы под него.

  ```php
  // config/vaulter.php
  'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
  ```

- **Переименование по сущности.** Чтобы переименовать одну таблицу без изменения глобального
  префикса, используйте `config('vaulter.table_names')` — `PanelTablesBuilder` и модели резолвят
  через него.

- **Панели со своими таблицами.** Панель получает собственные таблицы только в продвинутом режиме
  own-tables (`->tablePrefix()` / `->tables()`); тогда вы генерируете их через
  `php artisan vaulter:panel:migrations <panel>` ([ADR-0009](/adr/0009-storage-panels)). Дефолтная
  панель с нулём миграций **не** добавляет ни одной миграции.

## Broadcast / совместное редактирование не подключается {#collaboration}

**Симптом.** Редактор загружается, но синхронизация в реальном времени так и не подключается, либо
авторизация приватного канала отдаёт 403.

**Почему и решение.**

- **Драйвер Reverb (по умолчанию).** Vue-клиент присоединяется к приватному broadcast-каналу
  `vaulter.documents.{nodeId}`; колбэк авторизации канала гейтит его через `AccessResolver`
  (актору нужно как минимум `View` на узле). 403 здесь означает, что у актора действительно нет
  `View` — проверяйте гранты, а не сокет. Убедитесь, что Reverb запущен и ваше соединение
  `config('broadcasting')` совпадает с
  `config('vaulter.collaboration.drivers.reverb.connection')`.
- **Фича `document_versions`.** Авторизация комнаты узла и маршруты DocDrive монтируются только
  когда включена фича `document_versions`. Если редактор так и не появляется, подтвердите:

  ```php
  // config/vaulter.php
  'features' => [
      'document_versions' => true, // gates the editor schema, routes, and node-room auth
  ],
  ```

- **Драйвер Hocuspocus.** Node-хаб делает колбэки в `collab/authenticate` + `collab/store`. Эти
  эндпоинты принимают **только** валидный общий секрет или подписанный JWT комнаты — открытыми они
  не бывают. 401 от хаба обычно означает, что `VAULTER_COLLAB_WEBHOOK_SECRET` (или `secret`
  драйвера) различается между Laravel и хабом.

## Несовпадение диска блоба / пропавшие байты {#blob-disk}

**Симптом.** Узел существует, но скачивание отдаёт 404 на байтах, либо GC удаляет объекты, на
которые всё ещё есть ссылки.

**Почему и решение.**

- **Резолв диска.** Байты блоба лежат на диске `disk` хранилища, если оно его закрепляет, иначе на
  `config('vaulter.storage.default_disk')` (`VAULTER_DISK`). Если вы поменяете `VAULTER_DISK` после
  загрузки, старые блобы всё ещё указывают на старый диск — не уводите диск из-под существующих
  блобов.
- **Никогда не версионируйте CAS-бакет.** На S3 CAS-бакет должен быть **без версионирования**.
  S3 lifecycle/versioning как механизм удаления будет воевать с двухфазным GC Vaulter.
  Единственное lifecycle-правило, которое стоит добавить, — `AbortIncompleteMultipartUpload`
  ([ADR-0006](/adr/0006-content-addressed-blobs-gc)).
- **Слишком короткий grace у GC.** Если кажется, что GC удаляет живые байты, поднимите
  `config('vaulter.gc.grace_hours')` до значения ≥ вашей худшей задержки коммита multipart, чтобы
  загрузка в полёте успела воскресить блоб с меткой tombstone. См.
  [рецепт GC блобов](/ru/guide/recipes#blob-gc).
- **Дрейф.** Если `ref_count` выглядит неправильным — это кэш; запустите
  `php artisan vaulter:reconcile-blobs`, чтобы перестроить его из edge-таблицы (источника истины).

## Ошибки валидации панелей при загрузке {#panel-validation}

**Симптом.** Приложение падает при загрузке с `InvalidPanelException` или `UnknownPanelException`.

**Почему и решение.** `PanelRegistry` валидирует каждую панель при загрузке (fail-loud, H.1):

- **`InvalidPanelException::missingClass` / `notInstanceOf`** — панель ссылается на FQCN модели /
  пайпа / ресурса, который не существует или не удовлетворяет своему контракту. Поправьте FQCN
  (модель панели должна наследовать `Illuminate\Database\Eloquent\Model`).
- **`InvalidPanelException::idStrategyWithoutOwnTables`** — панель задала `->idStrategy()`, оставаясь
  на общей схеме. У одной общей `v_nodes` ровно один тип PK, так что стратегия id на панель законна
  только в режиме own-tables. Либо уберите `->idStrategy()`, либо добавьте `->tablePrefix()`, чтобы
  включить собственные таблицы ([ADR-0011](/adr/0011-id-strategy)).
- **`UnknownPanelException::forName`** — `Vaulter::for($entity, panel: 'x')` (или маршрут) назвал
  панель, которая не зарегистрирована. Зарегистрируйте FQCN её провайдера в
  `config('vaulter.panels')` или используйте панель `default`.

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

- [Архитектура](/ru/guide/architecture) — слои и контракты за этими сбоями.
- [Рецепты](/ru/guide/recipes) — рабочие конфигурации.
- [Справочник ошибок](/ru/guide/errors) — каждое исключение Vaulter, с якорем и HTTP-статусом.
