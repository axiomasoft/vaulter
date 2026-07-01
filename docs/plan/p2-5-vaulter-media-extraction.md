# P2 #5 — Извлечение `vaulter-media` (Option A) — durable plan

> **СТАТУС: ВЫПОЛНЕНО (3 среза, оба гейта зелёные, НЕ закоммичено).**
> core: pint ✓ · phpstan max ✓ · pest **528** ✓ · type-cov **100%** ✓.
> media: pint ✓ · phpstan max ✓ · pest **67 passed / 4 skipped** (PG-only, исполнятся в media-pgsql CI) ✓ · type-cov **100%** ✓.
> Сохранение: 67 замыканий (63 passed + 4 PG-skip) ушли из core (было 591+4skip) и пришли в media — ноль потерь.
> Цикл разорван (0 ссылок core/src → Vaulter\Media). PG-линию локально не гонял (нет своего *_test PG),
> исполняется в CI (media.yml postgres-джоб = копия проверенного core test-pgsql). Line-coverage `--min=80`
> локально не мерил (нет pcov) — CAS-путь по-прежнему сквозняком покрыт retained Upload/Download/Copy-тестами,
> движки ушли из core/src целиком (вне знаменателя). Pre-existing (не моё): packages/core/composer.lock
> рассинхрон (spatie не в локе) — core тестируется на root, свой лок вестигиальный.


> Источник правды по этому шагу. Ветка: `docs/adr-0014-node-path-guides` (продолжаем на ней —
> tree чистый, без push/PR без явной просьбы). Профиль: `/task:dev` (Opus/high планирование).
> Решения-основания: **D2/D3** (Vaulter Media = standalone CAS-слой), **D15** (один репо, path-repos +
> subtree-split), **D18** (dedup-scope). Master-plan §P2 #5.

## Выбранная архитектура — **Option A + «оставить ключи конфига»**

Подтверждено пользователем. Из трёх вариантов (A engine-only / B полный домен с циклом / C физперенос
без ренейма) выбран **A** — единственный, кто не нарушает инварианты (ноль изменений поведения, зелёный
core-гейт, dedup-scope цел, PG-тесты живы) и **не создаёт циклической composer-зависимости**.

**Ключевой факт-обоснование:** CAS — не лист. `BlobStore`/`BlobGarbageCollector`/`BlobReconciler` и
интерфейс `Contracts\Cas\BlobStore` ссылаются на **Drive**-модель `FileVersion` +
`FileVersionBlobStoreRepository` (остаются в core) → **media обязан require core**. А core hard-ссылается
на CAS-реализации (`Support/Config` + `config/vaulter.php` дефолты + провайдер-биндинги) → если это
оставить, **core тоже require media → цикл**. A разрывает цикл: дефолты реализаций переезжают в
провайдер media (`mergeConfigFrom`), core перестаёт ссылаться на media-классы в compile-time.

### Что ПЕРЕЕЗЖАЕТ в `packages/media/` (namespace `Vaulter\Media\`)
Только **конкретный движок CAS** + команды:
- `Cas/Sha256ContentStore` · `Cas/FlysystemStorageDriver` · `Cas/BlobStore` · `Cas/BlobGarbageCollector`
  · `Cas/BlobReconciler` · `Cas/StagedTempObject` → `Vaulter\Media\Cas\*`
- `Console/GcBlobsCommand` · `Console/ReconcileBlobsCommand` → `Vaulter\Media\Console\*`
- CAS-тесты `tests/Cas/*` + `tests/Fixtures/Cas/*` + `CasTestHelpers` → `packages/media/tests/*`
  (namespace `Vaulter\Media\Tests\*`), включая PG-линию (`DB_CONNECTION=pgsql`).
- Дефолты swappable-классов `vaulter.cas.{content_store,storage_driver,blob_store}` → провайдер media.

### Что ОСТАЁТСЯ в core (шов + схема + домен)
- Контракты `Contracts\Cas\*` (все 7) — **шов под phpstan**; их зависит core (Actions/Controllers).
- **`Cas/BlobLock`** — низкоуровневый DB advisory-lock примитив; его импортирует core-резидент
  `Repositories\Cas\BlobStoreRepository` → если увезти в media, вернётся core→media цикл. Остаётся
  `Vaulter\Cas\BlobLock` (единственный класс в namespace `Vaulter\Cas` у core).
- `Models\Cas\Blob` · `Enums\Cas\BlobStatus` · `Repositories\Cas\BlobStoreRepository`.
- **Схема `v_blobs`** — `PanelTablesBuilder::defineBlobs` + dedup-uniq по `dedup_scope`. Физический вынос
  схемы/миграции честно **ОТЛОЖЕН** (см. ниже): пока `FileVersion`↔`Blob` FK — cross-package, дробить
  единую 6-табличную миграцию = поведенческое изменение (порядок/эмиссия FK), нарушает инвариант.
- `Casts\BinaryString` · `Support\Config` (cas/dedup-акцессоры — правим только 3 fallback'а).
- **ACL целиком** — `AccessResolver`/`GrantAuthoritativeDriver`. В CAS кода ACL нет (только 2 docblock
  `{@see}` в Listable-контрактах — перепишем в прозу). Разрешает D3-развилку: media ACL-агностичен.

### Конфиг (развилка 2): **оставить ключи, дефолты у media**
`config('vaulter.cas.*')`/`config('vaulter.dedup.*')` — путь для хоста НЕ меняется. media-провайдер
`mergeConfigFrom` отдаёт дефолты 3 swappable-классов в `vaulter.cas`. Из core-конфига эти 3 ключа
**удаляются** (иначе `array_merge` core-значение победит и дефолт media потеряется). `dedup.*` целиком
остаётся в core (нужен схеме `defineBlobs` независимо от media).

### Направление зависимостей (цикла НЕТ)
- `media` → **requires** `axioma-studio/vaulter-core: self.version` (path-repo `../core`, symlink).
- `core` → **require-dev** `vaulter-media` (для своих Upload/Download/Copy-тестов, которым нужен
  bound `BlobStore`) + **suggest** (functional-optional: CAS-фичи Drive требуют media). Runtime-цикла нет.
- В core-тестах `VaulterMediaServiceProvider` регистрируется в `TestCase::getPackageProviders()`.
- Хост: ставит оба пакета, Laravel auto-discovers оба провайдера.

## Отложенное (явно, не молча)
- **Вынос схемы `v_blobs`/`defineBlobs` в media-owned миграцию** — вместе с извлечением Drive (тогда
  `FileVersion`↔`Blob` перестаёт быть cross-package и дробление миграции безопасно). Сейчас media владеет
  CAS-движком + конфигом + командами; схема/модель/контракты — шов в core. Это дух D3 «тонкий шов,
  схлопывается обратно».
- drive/docdrive-извлечение, medialibrary-bridge, owner-шаги (split-репо/Packagist/секреты) — out of scope.

## Срезы (вертикальные, риск = blast-radius)

- **Slice 1 (LOW) — scaffold.** `packages/media/{composer.json, phpstan.neon (max), pint.json, phpunit.xml}`,
  `VaulterMediaServiceProvider` (пока без CAS-биндингов), `config/` defaults-файл (пустой merge-таргет),
  `tests/{TestCase, Pest.php, Arch/ArchTest}`. Root: path-repo `packages/*` уже покрывает media; добавить
  root require `vaulter-media @dev`. Core: require-dev + провайдер в `TestCase`. Гейт: media ставится, его
  trivial-тест зелёный; core-гейт без изменений (провайдер media — no-op).
- **Slice 2 (HIGH) — move engine + rebind seam.** Физперенос 6 impls + `StagedTempObject` + 2 команд в
  `Vaulter\Media\*`. media-провайдер: биндит `Contracts\Cas\{StorageDriver,ContentStore,BlobStore}` +
  `hasCommand(Gc/Reconcile)` + `mergeConfigFrom` дефолтов. core: провайдер снимает 3 CAS-singleton'а + 2
  `hasCommand` + 2 `use`; `config/vaulter.php` убирает 3 класс-ключа + 3 `use`; `Support/Config` убирает 3
  `use` + меняет 3 fallback'а (читает config, guard-throw при отсутствии); `ListableContentStore/
  ListableStorageDriver` — снять `use Vaulter\Cas\*` из docblock. Тесты Cas + Fixtures/Cas + CasTestHelpers →
  media (namespace `Vaulter\Media\Tests\*`, `use Vaulter\Cas\*`→`Vaulter\Media\Cas\*`). Root `tests/Pest.php`
  снять `$core.'/Cas'`. media TestCase: sqlite/pgsql lane (реплика core), `loadMigrationsFrom` core-migrations
  через vendor-symlink (паттерн filament), ids=bigint, permission=null. Гейт: **оба** (core: pint+phpstan
  max+pest+type100%; media: pint+phpstan max+pest+type100%), PG CAS-линия исполняется.
- **Slice 3 (MED) — CI.** `.github/workflows/media.yml` (паттерн `filament.yml` + `test-pgsql`-джоб из
  `tests.yml`); `monorepo-split.yml` matrix `+media`. Валидация структуры + локальный прогон.

## Инварианты (проверяются на каждом срезе)
1. core-гейт зелёный: `pint --test` · `phpstan analyse` (max) · `pest` · type-cov 100%.
   (Кол-во core-тестов уменьшится на число CAS-тестов — они переезжают; сумма сохраняется.)
2. media-гейт зелёный: pint · phpstan **max** · pest · type-cov 100%; PG CAS-линия исполняется.
3. Ноль изменений поведения — механическое извлечение. dedup-scope конфиг цел.
4. Контракты `ContentStore/BlobStore/StorageDriver` = шов, phpstan-clean с обеих сторон.
5. Никакой циклической зависимости runtime (core не require media).
6. Breaking (пре-релиз 0.1.0, major-семантика) — отметить в CHANGELOG/changeset при релизе.

## Дисциплина
namespace-refactor-safety (перенос = новое место с новым namespace → обновить ссылки → жёсткий rename;
здесь ссылок на impls почти нет — только config/Config/provider/tests, что и есть «обновить ссылки»).
Прогон реального гейта как verifier после каждого среза. На HIGH — adversarial self-review.
