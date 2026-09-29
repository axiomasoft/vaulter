# P0 — RAG-выжимка: best practices встраиваемых Laravel-пакетов

**Дата верификации:** 2026-07-10 · **Автор:** issue-planner/opus (design-time RAG)
**Инструмент:** perplexity-web `search_advanced` (web)
**Запрос:** «Embeddable Laravel package best practices 2025: swappable Eloquent models,
configurable table prefixes and migration publishing, minimal public API surface, SemVer
for facades/contracts, avoiding host-app coupling, morph map aliases, config-first design»

**Вердикт:** ✅ подтверждено. Требования E1–E7 — чеклист фаз P2 и P3.

---

## E1. Swappable-модели

- E1.1 Контракты в `Contracts/` описывают доменное поведение, не персистентность; сервисы зависят от контрактов.
- E1.2 Дефолтные Eloquent-реализации в `Models/`, привязка через `config('pkg.models.*')` в провайдере.
- E1.3 **Запрет статических Eloquent-вызовов внутри сервисов пакета** (`User::query()`); только инъекция контракта/репозитория.
- E1.4 Публичные методы возвращают контракты/DTO, а не конкретные Eloquent-модели — если пакет не объявлен «Eloquent-first» явно.

## E2. Имена таблиц, префиксы, соединения

- E2.1 Config-first: `table_prefix` + карта `tables.*`; имя выводится централизованной функцией/резолвером.
- E2.2 Миграции и модели берут имя из резолвера, не хардкодят.
- E2.3 Соединение параметризовано (`Schema::connection(config('pkg.connection'))`) — иначе мультитенант/несколько БД ломаются.
- E2.4 Публикация: `vendor:publish --tag=pkg-config` и (опционально) `--tag=pkg-migrations`; в доках явно сказано, какой режим канонический и как апгрейдить.

## E3. Минимальная публичная поверхность

- E3.1 Публичны только: тонкие фасады, контракты, DTO/VO, события, ключи конфига. Всё прочее (сервисы, репозитории, хелперы) — внутреннее.
- E3.2 Фасад тонкий, над manager-классом; «кухонная раковина» в фасаде делает эволюцию без breaking невозможной.
- E3.3 Никакого глобального состояния помимо фасадов; всё через контейнер.

## E4. SemVer для фасадов и контрактов

- E4.1 Новый метод с разумным дефолтом → minor.
- E4.2 Изменение сигнатуры или семантики → major.
- E4.3 Фасад следует тем же правилам, что и контракт: молча менять возвращаемый тип/поведение нельзя.
- E4.4 `@deprecated` + работоспособность до следующего major; changelog + migration guide.

## E5. Отсутствие связности с хост-приложением

- E5.1 Никаких ссылок на `App\Models\*`; конкретный класс приходит из config-биндинга.
- E5.2 Интеграция с воркфлоу хоста — через **события** пакета, а не вызовы произвольных сервисов хоста.
- E5.3 Не резолвить из контейнера ключи, которыми пакет не владеет; вместо этого объявить контракт и потребовать биндинг от хоста.
- E5.4 Параметризованы: имена таблиц, соединение, очереди, префиксы маршрутов и middleware, cache-ключи, фиче-флаги.

## E6. Morph map

- E6.1 `Relation::morphMap` с **пространственно-именованными алиасами** (`pkg_user`), значения берутся из `config('pkg.models.*')`.
- E6.2 В полиморфных колонках **никогда** не хранить FQCN — смена реализации/неймспейса ломает данные.
- E6.3 Зарезервированные алиасы задокументированы, чтобы хост случайно их не переиспользовал.

## E7. Config-first

- E7.1 Разумные дефолты: `composer require` обязан работать **без публикации** конфига; публикация нужна для кастомизации, не для базовой работы.
- E7.2 Чувствительное/средозависимое — через `env()` **только внутри config-файла**.
- E7.3 На boot из config читаются: биндинги контрактов, morph map, префиксы/middleware маршрутов.

---

## Проекция на vaulter (для P2/P3)

Существующие ключи `packages/core/config/vaulter.php` (по грепу на 2026-07-10):
`models`, `table_names`, `id_type`, `ids`, `panels`, `default_panel`, `migrations`,
`features`, `handlers`, `tenant`, `table_prefix`, `drivers`, `permission_drivers`,
`gate`, `spatie`, `azgard`, `storage_adapter`, `dedup`, `gc`, `storage`,
`share_token_generator`, `share`, `trash`, `uploads`, `webhooks`, `route_prefix`,
`middleware`, `max_versions`, `versions`, `enabled_document_types`,
`max_document_size_kb`, `drive`, `exporters`, `collaboration`.

Проверить в P2: наличие `connection` (E2.3 — в списке НЕТ), полноту morph-map (E6),
возвращаемые типы фасада `Vaulter\Facades\Vaulter` (E3.2/E1.4),
`env()` вне config (E7.2), работоспособность без публикации (E7.1).

---

## Источники

1. https://www.slideshare.net/slideshow/laravel-package-development-best-practices-updated-for-2025-pdf/273122108
2. https://medium.com/laravel-news/separation-of-concerns-with-laravel-s-eloquent-part-1-an-introduction-c9a6dc6b4a65
3. https://dev.to/westtan/laravel-best-practices-tips-and-tricks-for-2025-5542
4. https://laravel.com/docs/eloquent (morph map, `Relation::enforceMorphMap`)
