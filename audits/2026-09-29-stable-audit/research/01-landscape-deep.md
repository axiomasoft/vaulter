# Широкий обзор: Perplexity Deep Research

Дата: 2026-09-29. Поисковый синтез, не независимая проверка кода Vaulter. Статус проверки первоисточников указан в `../03-source-map.md`.

## Запрос

Prepare a primary-source research dossier for an independent audit of Vaulter, a PHP/Laravel monorepo embedded document/file platform with core/drive/media/documents/Vue/Filament packages. Existing concerns: document-type registry versus MIME handlers; authorization only at HTTP not public PHP facade; tenant context delegated to custom Eloquent models; CAS scope IDs integer-only despite ULID/UUID support; quota/version concurrency; after-commit events with outbox inserted after commit; REST compatibility and schema evolution; public API over-abstraction; reproducible package releases. Research diverse actual implementations: Nextcloud, ownCloud OCIS, Spatie laravel-medialibrary, Flysystem, tusd/Uppy, Paperless-ngx, Tiptap/Yjs/Hocuspocus, Laravel/Spatie multitenancy or stancl tenancy, Doctrine/deptrac/Roave BC checks, Laravel package-tools/Testbench. Select useful projects rather than covering every name. For each major direction give concrete source/doc URLs, observed implementation pattern, transfer to Vaulter, limits/tradeoffs, and an acceptance-test idea. Prefer official repos/docs and specifications. Separate source evidence from your recommendations. Include counterexamples to 'must remove Eloquent', 'must rename every model', 'must split many packages', 'must force Sanctum', and 'after-commit guarantees durable webhook delivery'. Discuss document schema versioning/collaboration updates, storage scope+physical disk, GC grace periods, outbox event identity, and consumer compatibility. Do not invent current versions or benchmark claims. Aim a substantive dossier with 20-30 primary links, no implementation schedule. Output in Russian.

## Ответ

Краткий вывод. Сопоставление с первичными источниками не подтверждает необходимость радикально переписывать Vaulter: ни отказ от Eloquent, ни принудительная смена всех имён моделей, ни дробление на множество Composer-пакетов, ни обязательный Sanctum не следуют из рассмотренных реализаций. Сильнее обоснованы проверяемые инварианты: единый путь авторизации для HTTP и PHP API, явный tenant/storage scope, транзакционно корректные квоты и события, версионирование схемы документов, а также проверяемый release pipeline.

## Объект и рамки

В репозитории на проверенном commit 67fe5244206297eea9d1c921820e2883874d7179 найдены пакеты core, drive, media, documents, vue, filament и azgard, а также корневые tests, e2e, hocuspocus-server, testbench.yaml, vaulter-spec.md и .changeset. Это монорепозиторий с PHP- и JS-частями; выводы ниже относятся к этому снимку, а не к будущему состоянию ветки. Vaulter: дерево репозитория на зафиксированном commit
Граница исследования: рассматриваются опубликованные контракты и исходный код проектов как примеры возможных решений, а не как доказательство, что соответствующую реализацию нужно копировать целиком. Где ниже написано «наблюдение» — это описанный первичным источником контракт или паттерн; «рекомендация» и тест — вывод для Vaulter, а не утверждение о текущем поведении его кода.

## Реестр документов и файловые операции


## Доступ, tenants, CAS и хранение


## Схемы документов и совместная работа


## Публичный API, события и эволюция REST


## События и надёжность доставки


## Пять тезисов, которые не следуют из примеров

- «Нужно удалить Eloquent». Не следует: Spatie Media Library намеренно использует Eloquent-ассоциации; Laravel предлагает Gates/Policies вокруг моделей; tenancy-пакет конфигурирует tenant-модель. Практический критерий — не отсутствие ORM, а отсутствие неявных security/tenant зависимостей в публичной операции.
- «Нужно переименовать каждую модель». Не следует: расширяемость через базовую модель, контракт или настройку — реальный паттерн. Переименование надо обосновывать конфликтом или публичной неоднозначностью и сопровождать upgrade path.
- «Нужно разбить монорепозиторий на множество пакетов». Не следует: Composer поддерживает path repositories для монорепозиториев, а Laravel предлагает workbench для разработки пакета; границы стоит определять по независимой публикации и зависимостям, а не по максимальному числу директорий.
- «Нужно принудительно использовать Sanctum». В рассмотренных источниках нет основания навязывать конкретный механизм аутентификации. Важно отделить идентификацию actor-а от authorization policy и не допустить, чтобы только HTTP middleware выполнял существенное решение о доступе.
- «After-commit гарантирует доставку webhook». Нет: отложить dispatch до commit — не то же самое, что записать durable event в транзакции и надёжно доставить его. При сбое после commit, но до публикации, простое after-commit событие может потеряться.

## Приоритетные критерии при независимом аудите

Это не план работ, а перечень проверяемых критериев для оценки реализации:
- Authorization одинаково действует через HTTP, PHP facade, queue и CLI; системный обход — явный и ограниченный.
- Tenant и storage scope обязательны в каждой операции, где отсутствие контекста может расширить выборку или доступ.
- Числовые допущения о ключах проверяются сквозным тестом с ULID и UUID, включая CAS, события, сериализацию и физическое хранилище.
- Проверка квоты и фиксация/резервирование байтов устойчивы к конкуренции и ошибке после загрузки, но до записи метаданных.
- Документный тип, MIME и parser/handler зарегистрированы раздельно; неизвестные типы и форматы имеют явное поведение.
- Версии файла, schema version редактора и Yjs state/snapshot — различные понятия с тестируемыми migration и recovery правилами.
- Удаление физического объекта отложено и повторно проверяет ссылки, версии и scope; задержка между DB и object storage не приводит к потере достижимых данных.
- Событие, требующее гарантированной доставки, создаётся внутри той же транзакции, что и бизнес-изменение; доставка имеет стабильный ID и идемпотентный consumer.
- REST/OpenAPI и PHP facade проверяются как публичные контракты; внутренние имена моделей не считаются API без явного объявления.
- Каждый публикуемый пакет проходит тест в чистом host-приложении; release artifact проверяется отдельно от состояния рабочего монорепозитория.
Использованные первичные источники: 25 ссылок выше, включая официальные документы Laravel, Composer, Flysystem, tus, Uppy, Nextcloud, ownCloud, Tiptap/Hocuspocus, Paperless-ngx, Spatie и репозитории Deptrac/Roave. Версии проектов намеренно не фиксировались: исследование использует URL документации и репозиториев как свидетельства паттернов, не делая заявлений о текущих релизах или сравнительных benchmark-ах.

Sources:
1. [Vaulter: дерево репозитория на зафиксированном commit](https://github.com/axiomasoft/vaulter/tree/67fe5244206297eea9d1c921820e2883874d7179/packages)
2. [Paperless-ngx: REST API](https://github.com/paperless-ngx/paperless-ngx/blob/dev/docs/api.md)
3. [Spatie Laravel Media Library](https://github.com/spatie/laravel-medialibrary)
4. [Flysystem: адаптеры](https://flysystem.thephpleague.com/docs/advanced/creating-an-adapter/)
5. [visibility](https://flysystem.thephpleague.com/docs/visibility/)
6. [Nextcloud: WebDAV операции](https://docs.nextcloud.com/server/stable/developer_manual/client_apis/WebDAV/basic.html)
7. [tus resumable upload protocol](https://tus.io/protocols/resumable-upload)
8. [Uppy Tus plugin](https://uppy.io/docs/tus/)
9. [Laravel Authorization](https://laravel.com/framework/docs/authorization)
10. [Tenancy for Laravel: введение](https://tenancyforlaravel.com/docs/)
