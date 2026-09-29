# Карта первоисточников и переносимых практик

Проверено 2026-09-29. Это содержательная выжимка для работы без повторного web search. **Наблюдение** относится к указанному источнику; **применение** — мой вывод для Vaulter. Чтение документации не означает запуск чужого проекта, benchmark или доказанную совместимость с нашими зависимостями.

Для S01–S03 прочитан source и зафиксирован commit; [машинная запись](research/verified-repository-revisions.json) содержит SHA-256 файлов. Для остальных карточек прочитан соответствующий официальный документ/README/спецификация; где публичная страница не загружалась, указан исходный Markdown проекта. `main`, `dev`, `stable` и `current` — изменяемые ссылки, версии для реализации предстоит брать из поддерживаемых manifests.

## Расширения, Laravel и права

### S01 — Nextcloud: разные оси метаданных

[Репозиторий и FileInfo на commit](https://github.com/nextcloud/server/blob/882be16fb5d3a8c544315ca18992f1f3372ee536/lib/public/Files/FileInfo.php).

Наблюдение: `TYPE_FILE/TYPE_FOLDER`, `getType`, `getMimetype`, `getPermissions` — отдельные части публичного интерфейса (строки 25, 112, 160, 168). Применение: structural kind, byte format и access decision не надо сводить к одному enum. Ограничение: это file-server API; готовой модели редактируемого документа или schema migration он не даёт. Проверка: новый MIME handler не требует нового `NodeKind` и не выдаёт права сам по себе.

### S02 — Spatie Media Library: policy в программной операции

[FileAdder на commit](https://github.com/spatie/laravel-medialibrary/blob/3bbac92f7c6ad4a162307c59378d5125a5ca8868/src/MediaCollections/FileAdder.php).

Наблюдение: проверка `max_file_size` в путях добавления; `processMediaItem` вызывает `guardAgainstDisallowedFileAdditions`, который учитывает коллекцию и `acceptsMimeTypes` (309, 375, 595, 670). Применение: проверять policy в общей операции, доступной PHP-клиенту. Ограничение: API также принимает metadata; не считать любой переданный размер доверенным и не приравнивать MIME validation к AV. Проверка: одинаково запрещённые bytes отклоняются HTTP и facade.

### S03 — Laravel Scout: небольшой registry адаптеров

[EngineManager на commit](https://github.com/laravel/scout/blob/deb229f068a5eb350a906ea25bda9fa2811ba1a9/src/EngineManager.php).

Наблюдение: специализированный manager наследует Laravel `Manager`, создаёт конкретные engines. Применение: registry может быть компактным и container-native; ему не требуется владеть persisted document identity. Ограничение: этот паттерн не определяет collision/freeze/schema-upgrade policy за Vaulter. Проверка: известная регистрация разрешается из чистого provider boot и после config cache; неизвестный ключ даёт предметную ошибку.

### S04 — Laravel: authorization и lifecycle контекста

[Authorization](https://laravel.com/framework/docs/12.x/authorization), [scoped bindings](https://laravel.com/framework/docs/12.x/container#binding-scoped-singletons).

Наблюдение: Gates/Policies проверяют действие над ресурсом; `forUser` позволяет явно выбрать actor. Scoped bindings имеют request/job lifecycle, включая сброс между циклами долгоживущего worker. Применение: единый application entrypoint с явным actor/context. Ограничение: scoped сам не разрешает tenant и не защищает уже полученную модель; singleton, удерживающий scoped instance, всё ещё опасен. Проверка: tenant A, затем B в одном worker, включая исключение в A.

### S05 — Spatie Multitenancy: очередь переносит tenant

[Официальный исходник документации](https://raw.githubusercontent.com/spatie/laravel-multitenancy/main/docs/basic-usage/making-queues-tenant-aware.md).

Наблюдение: tenant фиксируется при dispatch и восстанавливается при исполнении; отсутствующий tenant-aware job tenant вызывает ошибку. Документ отдельно описывает восстановление окружающего контекста после успеха/ошибки. Применение: jobs не должны зависеть от случайного HTTP state. Ограничение: перенос tenant не проверяет ACL пользователя и не заменяет явный режим глобального GC. Проверка: tenant удалён до исполнения; job не продолжает работу в landlord/предыдущем tenant.

## Upload, CAS и конкурентность

### S06 — tusd: транспорт и прикладная готовность различаются

[tusd hooks](https://tus.github.io/tusd/advanced-topics/hooks/), [репозиторий tusd](https://github.com/tus/tusd).

Наблюдение: `pre-create` блокирует создание; `post-finish` — неблокирующая обработка после получения данных/ответа. Порядок разных hooks не является общим гарантированным порядком. Применение: transport completion не означает разрешённый download; finalize/inspection управляются приложением. Ограничение: нельзя считать hook устойчивой transactional queue; retry зависит от способа доставки и настроек. Проверка: duplicate/out-of-order completion не создаёт вторую версию.

### S07 — OWASP: содержимое upload и outbound requests

[File Upload](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html), [SSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html).

Наблюдение: нельзя доверять client Content-Type; нужны ограниченные типы/размеры по назначению, безопасное размещение и несколько уровней проверки. SSRF рассматривается на application и network уровнях. Применение: единая policy staged bytes, контролируемый egress webhook. Ограничение: это рекомендации по угрозам, не требование сделать все scanners обязательными зависимостями библиотеки. Проверка: spoofed MIME, архив с большим распакованным объёмом, redirect/retry к запрещённому адресу; запрет чтения при обязательном незавершённом scan.

### S08 — Flysystem: потоковый I/O

[Filesystem API](https://flysystem.thephpleague.com/docs/usage/filesystem-api/), [league/flysystem](https://github.com/thephpleague/flysystem).

Наблюдение: `readStream/writeStream` работают с ресурсами, ошибки выражены исключениями операций. Применение: upload не обязан целиком превращаться в PHP string. Ограничение: filesystem abstraction не обеспечивает атомарность SQL+S3 и не решает ownership произвольного входного stream. Проверка: non-seekable источник, ошибка посреди чтения, ограниченная память и cleanup staging.

### S09 — PostgreSQL: lock охраняет конкретный invariant

[Explicit locking](https://www.postgresql.org/docs/current/explicit-locking.html).

Наблюдение: row locks сериализуют конкурирующие mutations выбранных строк; advisory locks требуют согласованного использования приложением. Возможны deadlocks и rollback. Применение: защищать quota/storage row и version head, с единым порядком блокировок и определённым retry. Ограничение: уникальный индекс предотвращает duplicate rows, но сам не превращает два `max+1` в две успешные операции. Проверка: две независимые DB connections с синхронизированным precheck, разные hashes, свободно 10 и два upload по 8.

### S10 — restic: удаление ссылок отдельно от очистки bytes

[Forget/prune](https://restic.readthedocs.io/en/stable/060_forget.html), [restic repository](https://github.com/restic/restic).

Наблюдение: удаление snapshot и освобождение данных разделены; prune имеет собственные эксплуатационные параметры и dry-run. Применение: retained versions, liveness и физическое освобождение не равны одной операции удаления узла. Ограничение: backup repository имеет иную модель writers; детали locking нельзя переносить без собственного протокола Vaulter. Проверка: удаление одной ссылки на общий blob сохраняет bytes для остальных.

### S11 — CNCF Distribution: важный контрпример online GC

[Garbage collection](https://distribution.github.io/distribution/about/garbage-collection/).

Наблюдение: mark-and-sweep, `--dry-run`; документация требует stopped/read-only registry при GC из-за гонок с upload. Применение: ссылки/объекты и диагностика кандидатов — полезные идеи. Ограничение: алгоритм нельзя объявить безопасным online GC для Vaulter. Проверка: новый reference во время sweep должен защищать объект; grace period без writer coordination недостаточен.

### S12 — S3: conditional publish и незавершённые uploads

[Conditional writes](https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes.html), [abort incomplete multipart lifecycle](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpu-abort-incomplete-mpu-lifecycle-config.html).

Наблюдение: условная запись позволяет избежать безусловного overwrite; lifecycle удаляет незавершённые multipart parts. Применение: идемпотентное продвижение immutable bytes и ограничение staging расходов. Ограничение: это не транзакция с PostgreSQL; lifecycle не знает активные business leases приложения. Проверка: потерянный ответ после PUT, повторный complete и конфликт с cleanup не теряют достижимые bytes.

## События и совместные документы

### S13 — Debezium: event identity отличается от aggregate key

[Outbox Event Router](https://debezium.io/documentation/reference/stable/transformations/outbox-event-router.html).

Наблюдение: outbox содержит уникальный event ID, aggregate ID/type и payload; event ID пригоден для дедупликации, aggregate key — для маршрутизации/порядка. Применение: заменить дедупликацию по итоговому имени/состоянию на идентичность возникновения. Ограничение: Vaulter может сохранить SQL relay, без CDC/Kafka. Проверка: три перехода `A→B→A→B` порождают три occurrence IDs.

### S14 — CloudEvents: идентичность события

[Спецификация v1.0.2](https://github.com/cloudevents/spec/blob/v1.0.2/cloudevents/spec.md).

Наблюдение: идентичность определяется `source + id`; повтор того же события может сохранять идентификатор. Применение: сделать явными occurrence ID, source и тип, не обязательно принимая полный envelope. Ограничение: стандарт формата не обеспечивает delivery, транзакцию или command deduplication. Проверка: повтор доставки не создаёт эффект повторно, новая mutation того же node не исчезает.

### S15 — Stripe: retries и порядок как контракт потребителя

[Webhook delivery](https://docs.stripe.com/webhooks), [idempotent requests](https://docs.stripe.com/api/idempotent_requests).

Наблюдение: webhooks могут повторяться и приходить вне порядка; API idempotency связывает ключ с результатом/параметрами запроса. Применение: отдельные event ID и request key, документированная политика повторов. Ограничение: сроки хранения ключей и кеширование ошибок Stripe не обязательны для Vaulter. Проверка: сбой после HTTP delivery до ack приводит к тому же event ID; повтор команды с другим payload даёт конфликт.

### S16 — Yjs: binary updates имеют собственную семантику

[Document updates](https://docs.yjs.dev/api/document-updates), [yjs/yjs](https://github.com/yjs/yjs).

Наблюдение: updates коммутативны, ассоциативны и идемпотентны; есть state vectors и merge updates. Применение: повтор бинарного update не равен повторному созданию документа из JSON. Ограничение: эти свойства не проверяют ACL, schema compatibility и бизнес-порядок revisions. Проверка: repeated/reordered updates сходятся, а forbidden writer не проходит server boundary.

### S17 — Hocuspocus: persistence и write permissions

[Persistence](https://tiptap.dev/docs/hocuspocus/guides/persistence), [authentication](https://tiptap.dev/docs/hocuspocus/guides/authentication).

Наблюдение: persist binary `Uint8Array`; восстановление Y.Doc из JSON может дублировать содержимое при merge. Auth hook может отказать и установить read-only connection. Применение: binary state как основа collaborative документа, JSON как отдельная проекция; серверный read-only. Ограничение: мгновенный revoke действующего соединения и restore fencing — отдельная ответственность Vaulter. Проверка: save/reconnect без дублей, downgrade активного пользователя, старый socket после restore.

### S18 — Tiptap: схема старого клиента способна удалить новые узлы

[Schema compatibility в collaborative editing](https://tiptap.dev/docs/hocuspocus/guides/collaborative-editing), [schema/content checking](https://tiptap.dev/docs/editor/core-concepts/schema).

Наблюдение: строгая схема не знает новые node types; документация описывает потерю новых узлов при совместной работе старого клиента и варианты контроля schema version. Применение: compatibility handshake и безопасный отказ записи; отдельный `schema_version`. Ограничение: universal migration framework библиотека не предоставляет. Проверка: новый node сохраняется при подключении старого клиента, а не удаляется его нормализацией.

## REST и клиентская совместимость

### S19 — RFC 9110: условное изменение ресурса

[If-Match, §13.1.1](https://www.rfc-editor.org/rfc/rfc9110.html#section-13.1.1).

Наблюдение: If-Match использует strong comparison, проверяется до изменения; false condition обычно даёт 412. Применение: token версии для rename/move/обычного save. Ограничение: валидатор нужно проверять атомарно с mutation; одного заголовка недостаточно, и он не заменяет Yjs. Проверка: второе изменение с устаревшей версией не перезаписывает первое.

### S20 — RFC 9457: machine-readable errors

[Problem Details](https://www.rfc-editor.org/rfc/rfc9457.html).

Наблюдение: стандартный problem envelope поддерживает дополнительные поля. Применение: стабильный machine code и request correlation; human message может локализоваться. Ограничение: переход с текущего envelope требует совместимости клиента; не раскрывать tenant/path/secrets в details. Проверка: неизвестный code даёт безопасный UI fallback, а знакомый — предметное действие.

### S21 — OpenAPI, openapi-typescript, oasdiff

[OpenAPI 3.1.1](https://spec.openapis.org/oas/v3.1.1.html), [openapi-typescript](https://openapi-ts.dev/introduction), [oasdiff](https://github.com/oasdiff/oasdiff), [Laravel cursor pagination](https://laravel.com/framework/docs/12.x/pagination#cursor-pagination).

Наблюдение: схема описывает HTTP; types generator и schema diff решают разные задачи. Cursor pagination требует подходящего уникального ordering, имеет ограничения для NULL. Применение: contract fixture проверяет фактический JSON, генерация типов проверяется diff, сортировка имеет tie-breaker. Ограничение: TS types не runtime validation, cursor не snapshot при изменении sort values. Проверка: старый SDK, unknown document type, равные sort values, denied candidates и concurrent move.

### S22 — Paperless-ngx: DMS API и восстановление

[API source](https://raw.githubusercontent.com/paperless-ngx/paperless-ngx/dev/docs/api.md), [administration source](https://raw.githubusercontent.com/paperless-ngx/paperless-ngx/dev/docs/administration.md).

Наблюдение: API version выбирается через Accept header; administration описывает exporter/importer и integrity/sanity checks. Применение: версия URL не единственный рабочий выбор, восстановление документов — часть эксплуатации. Ограничение: ветка `dev` не равна installed release; OCR pipeline Paperless не нужен автоматически Vaulter. Проверка: чистый restore возвращает bytes, metadata и доступ; старый контракт API живёт в объявленном окне. **Поправка к Perplexity:** в прочитанном `dev` default API уже не `v1`; число из поискового ответа не использовать.

## Устройство репозитория и выпуск

### S23 — Composer: path repositories и отдельный consumer

[Repositories / path](https://getcomposer.org/doc/05-repositories.md#path).

Наблюдение: path packages могут symlink/mirror локальные директории; repositories управляются root project. Применение: smoke install собранного пакета в чистый host, где нет sibling source/autoload. Ограничение: обычный workspace test не доказывает правильность опубликованного manifest. Проверка: optional packages отдельно и совместно; declared dependencies, `self.version` и split tags разрешаются из артефактов.

### S24 — Testbench: host framework для package tests

[Introduction](https://packages.tools/testbench), [orchestral/testbench](https://github.com/orchestral/testbench).

Наблюдение: пакет тестируется в Laravel test application. Применение: исполняемые integration recipes для core/drive/documents, provider discovery, config cache и migrations. Ограничение: Testbench внутри monorepo может иметь дополнительные dev dependencies и fixtures, которых нет у потребителя. Поэтому consumer archive installation — отдельная проверка.

### S25 — Deptrac: выбранные границы можно проверять

[Концепции](https://github.com/deptrac/deptrac/blob/4.x/docs/concepts.md).

Наблюдение: layers, collectors и ruleset описывают разрешённые зависимости. Применение: закрепить текущие package owners и запрет production imports из testing namespace. Ограничение: инструмент не выбирает правильную архитектуру и не видит все runtime container зависимости. Проверка: добавленная запрещённая зависимость действительно ломает проверку; нет чрезмерно широких exclusions.

### S26 — Roave BC Check: PHP поверхность между ревизиями

[Roave/BackwardCompatibilityCheck](https://github.com/Roave/BackwardCompatibilityCheck).

Наблюдение: сравнение API по git revisions/autoload; нужны доступные tags. Применение: pin baseline поддерживаемого релиза для каждого публикуемого пакета. Ограничение: не проверяет смысл quota, SQL migration, config keys или REST behavior; package-specific tags могут потребовать явного baseline. Проверка: удаление метода обнаруживается, а поведенческие изменения закрыты отдельными consumer tests.

### S27 — Changesets, pnpm и provenance

[Changesets](https://github.com/changesets/changesets), [pnpm install](https://pnpm.io/cli/install), [GitHub artifact attestations](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations).

Наблюдение: Changesets связывает package changes с версиями/changelog; frozen lockfile останавливает несовпадающий install; attestation связывает artifact с build provenance. Применение: согласованные PHP/JS release artifacts и проверяемый digest. Ограничение: Changesets не управляет Composer split release автоматически, provenance не доказывает отсутствие ошибок. Проверка: установка именно собранного архива, ожидаемый commit/digest и поддерживаемая matrix из manifests.

## Эксплуатация, UX и точность ACL-выборок

### S28 — Prometheus: метрики с ограниченной кардинальностью

[Instrumentation practices](https://prometheus.io/docs/practices/instrumentation/).

Наблюдение: комбинации labels создают отдельные series; динамические user IDs и подобные значения резко увеличивают cardinality. Применение: outcome/protocol/backend как ограниченные labels, отдельные защищённые logs для operation IDs. Ограничение: метрики не заменяют integrity checks и restore drill. Проверка: нагрузочный набор с тысячами nodes не создаёт series на каждый node/tenant/token.

### S29 — WAI-ARIA: файловое дерево с клавиатуры

[APG Tree View](https://www.w3.org/WAI/ARIA/apg/patterns/treeview/).

Наблюдение: определены keyboard navigation, expanded/selected states, различие selection и focus. Применение: Vue/Filament tree должен иметь предсказуемые действия и фокус после mutations. Ограничение: ARIA attributes без корректного поведения недостаточны; если UI фактически table/list, tree pattern не нужно навязывать. Проверка: открыть ветку, выбрать файл, переименовать/удалить, сохранить понятный focus без мыши.

### S30 — OpenFGA: проверка объекта и выборка разрешённых объектов

[ListObjects](https://openfga.dev/docs/getting-started/perform-list-objects), [Search With Permissions](https://openfga.dev/docs/interacting/search-with-permissions).

Наблюдение: авторизованное перечисление выделено в отдельную операцию, а не выводится из одного успешного Check. Официальный guide сравнивает search-then-check, локальный индекс с финальной проверкой и список разрешённых IDs перед search; выбор зависит от доли доступных объектов и размера множества. Применение: для Vaulter list/search/count semantics должны согласовываться с точным AccessResolver, включая deny и revoke. Ограничение: отдельный authorization service не предлагается; pagination/лимиты выбранной версии внешней системы не переносим автоматически. Проверка: запрещённый child отсутствует в metadata listing, а разрешённый после длинной серии denied candidates остаётся достижим по continuation.

## Что не является проверенным основанием

Остальные ссылки в `research/` — дополнительная поисковая база, не все независимо прочитаны. Deep Research потерял часть содержимого при выгрузке; общие слова о «25 источниках» не подтверждают их наличие. В материалах нет измеренных сравнений производительности, утверждений о текущих production installations или рекомендации копировать чужой код целиком. Выбирается переносимый механизм, а совместимость версий и условия использования кода проверяются при реальном заимствовании.
