---
title: Модель угроз
---

# Модель угроз

## Активы

Хранимое содержимое (файлы, документы, ревизии), решения о доступе вокруг него, изоляция тенантов
и целостность потока событий, который публикуют вебхуки.

## Границы доверия

| Граница | Доверие | Основной контроль |
| --- | --- | --- |
| HTTP API v1 | вызывающий не доверенный до аутентификации | auth middleware хоста; каждая операция авторизуется по узлу ([Модель доступа](/ru/concepts/access-model)) |
| Протокольный адаптер | транспорт доверенный, его вызывающие — нет | проверка principal, привязки и поколения, общая policy на каждой записи, закрытый отказ при неопределённости |
| Egress вебхуков | URL цели выбирает злоумышленник | guard исходящих URL: разрешённые схемы, заблокированные диапазоны IP, `allowed_ip_ranges`, лимит размера ответа |
| Загружаемые байты | содержимое не доверенное | политика размера/MIME, инспекторы, устойчивое обязательство сканирования |
| Direct URL скачивания | URL — предъявительский ключ | короткий TTL; `strict` как альтернатива ([Лимиты direct URL](/ru/guides/operations/direct-url-limits)) |
| Граница тенанта | тенанты не должны видеть друг друга | dedup и ключи в области тенанта ([Мультитенантность](/ru/concepts/tenancy)) |

## Угрозы и контроль

- **Несанкционированное чтение или запись** — каждый handle действует от имени actor; решает драйвер прав, grant может поднять доступ, отсутствие решения запрещает.
- **Межтенантное раскрытие через dedup** — одинаковые байты не разделяются между тенантами (область по умолчанию `tenant`; [ADR 0012](/adr/0012-dedup-scope)).
- **SSRF через вебхуки** — guard отклоняет неразрешённые схемы и внутренние диапазоны IP в момент доставки. Диапазоны по умолчанию также покрывают IPv6 со встроенным IPv4 (IPv4-compatible, NAT64, 6to4, Teredo), TEST-NET и benchmark; production-инвариант проверяет их бинарным покрытием CIDR (более широкий свой диапазон допустим, некорректный — нарушение). Доставка не использует `HTTP(S)_PROXY`/`ALL_PROXY` (любой регистр), не следует редиректам, заново закрепляет DNS на каждой попытке, не декодирует `Content-Encoding` и прерывает тело ответа, превысившее `max_response_bytes` во время чтения, даже при отсутствующем или ложном `Content-Length`. Явный `allowed_ip_ranges` остаётся переопределением хоста. Unit-фикстуры доказывают только wiring; реальное поведение libc/DNS/cURL/TLS — в квалификации владельца.
- **Вредоносный файл** — загрузки удерживаются до вердикта сканера, если сканирование обязательно; карантин не снимается массово.
- **Потерянное или поддельное событие** — события пишутся в транзакции изменения; доставки подписаны и несут стабильный `webhook-id`.
- **Утёкший URL скачивания** — ограничен только TTL; см. runbook.

## Контроли загрузок → тесты

Статус: **fast** — sqlite-тест пакета; **server/stand** — нужны реальные гонки хранилища/БД (runbook владельца); **pending** — тест не сопоставлен. Встроенный multipart (`S3MultipartHandler`) проксирует части через сервер, это **не** нативный AWS presigned multipart: векторы native upload-id, composite checksum и direct-part — **N/A**. Assembled ≠ clean: закоммиченная ссылка не разрешает чтение байтов в карантине.

| Вектор | Инвариант | Тест (`file::test`) | Статус |
| --- | --- | --- | --- |
| Byte limit (chunk) | chunk over `max_chunk_bytes` rejected before staging | `drive/tests/Uploads/R07StagingSnifferContractsTest.php::rejects a stat-able chunk one byte over max_chunk_bytes before staging` | fast |
| Byte limit (total) | PATCH cannot grow past `max_bytes` | `drive/tests/Uploads/R07StagingSnifferContractsTest.php::rejects a PATCH that would grow past max_bytes after a partial upload` | fast |
| Declared size mismatch | parts sum < total size → no blob | `drive/tests/Uploads/UploadIntegrityTest.php::S3 complete при сумме частей меньше total_size_bytes → отказ, блоб не создан, F-P1.4-03` | fast |
| MIME / signature spoof | concrete declared MIME contradicting magic bytes rejected | `drive/tests/Uploads/UploadIntegrityTest.php::commit отклоняет объект с mime image/png и magic-bytes ZIP, F-P1.4-11`; `drive/tests/Uploads/R07StagingSnifferContractsTest.php::MagicByteSniffer rejects PNG declared when the head is a ZIP archive` | fast |
| Repeated part / retransmit | offsets must match; idempotent retransmit only at server offset | `drive/tests/Uploads/R07StagingSnifferContractsTest.php::rangeMatches requires staged length to cover offset plus chunk length exactly`; `…::accepts an idempotent retransmit when the upload is already complete at the server offset` | fast |
| Staged truncation / terminal resurrection | session lock; missing staged object refuses, terminal session never revives | `drive/tests/Uploads/UploadSessionCriticalSectionTest.php::append() при offset > 0 и пропавшем staged-объекте ОТКАЗЫВАЕТ, а не усекает файл, R-03`; `…::коммит реклеймленной сессии отбивается, узел не создаётся, статус не воскресает, R-11`; `…::putPart() перечитывает статус под локом: часть терминальной сессии не пишется, RV-02` | fast (races: server) |
| Double commit | replay returns same revision, no re-ingest | `drive/tests/Uploads/P3/CompleteUploadIdempotencyTest.php::SEC-R19-replay replays persisted exact revision after a newer head and without staging`; `…::SEC-CLEANUP-input-fingerprint rejects changed completion intent and corrupt authoritative pointers without ingest` | fast |
| Metadata rollback | staged bytes survive outer rollback for retry | `drive/tests/Uploads/P3/CompleteUploadIdempotencyTest.php::SEC-R13 keeps protocol bytes until outer commit and retains them after outer rollback`; `media/tests/Cas/BlobCommitAtomicityTest.php::commitStaged keeps the temp file reusable across an outer SQL rollback` | fast |
| Read before verdict (assembled ≠ clean) | pending/infected head never opens bytes | `drive/tests/Uploads/P3/ContentDeliveryBoundaryTest.php::SEC-R17 checks pending head, explicit older clean, metadata and sticky infection before opening bytes` | fast |
| Scanner verdict error / legacy bypass | required failure never grants bytes; legacy `not_required` cannot authorize | `drive/tests/Uploads/P3/QuarantineBoundaryTest.php::SEC-R07 required structured failure never grants PHP or share bytes`; `…::SEC-R01 legacy not_required and bare clean cannot authorize required public bytes` | fast |
| Strict / direct revoke | strict refuses bearer URLs; direct URL bounded by TTL | `drive/tests/Uploads/P3/ContentDeliveryBoundaryTest.php::SEC-R16 strict refuses bearer URLs before touching a signer`; `…::SEC-R16 explicit direct delivery binds exact immutable bytes and TTL with bounded revoke` | fast |
| Cross-tenant dedup | same bytes in two tenants → two blobs | `media/tests/Cas/BlobDedupScopeBehaviourTest.php::tenant: одинаковые байты в разных тенантах → ДВА блоба, два объекта` | fast |
| Orphan staged object | orphan detected, owned object kept | `media/tests/Cas/BlobOrphanSweepTest.php::обнаруживает настоящий orphan и пропускает закоммиченный объект (report-only)` | fast |
| Checksum mismatch (client SHA-256) | сервер пересчитывает хеш; ложный client hash / ETag отклоняется | `drive/tests/Uploads/TusFlowTest.php::лживый client sha256 отклоняется до записи версии`; `drive/tests/Uploads/S3MultipartTest.php::лживый ETag не используется как адрес — сервер пересчитывает sha256` | fast |
| Filename `../` / control / RTL | имя — метаданные: ключ хранения — content-addressed ключ CAS (непрозрачный, не выводится из имени); запрос resumable отклоняет разделители пути; disposition скачивания очищается от CR/LF, управляющих символов и разделителей; отдельной политики отказа control/RTL нет (только метаданные) | `drive/tests/Feature/R07RequestRuleContractsTest.php::R07Request surfaces the resumable name separator custom message`; `drive/tests/Http/ContentResponderTest.php::strips CR/LF, control characters and path separators from the disposition` | fast |
| Finite completion deadline | один монотонный бюджет `uploads.resumable.complete_timeout_seconds` (1..300, по умолчанию 60) создаётся в `UploadOperations::complete` до `withSession(attempts: 3)` и общий для повторов; проверяется до staged-чтения, между чанками по 64 KiB и перед receipt; отказ ничего не публикует, staged-байты Assembled сохраняются | `drive/tests/Uploads/P3/CompletionDeadlineTest.php::D19-config types the completion budget as 1..300 seconds with a 60 second default`; `::D19-slow-stream refuses a staged stream that outlives the budget between chunks without publishing`; `::D19-retry shares one monotonic budget across attempts instead of resetting it`; `::D19-request starts the budget once before the session critical section of UploadOperations::complete`; `::D19-interrupted rejects a staged stream that stops making progress` | fast (fake clock) |
| Блокирующий staged storage I/O | сокетный staged-поток получает `stream_set_timeout` ≤ остатка бюджета перед каждым чтением; локальные файлы — локальный I/O; любой другой поток (user-space wrapper, например удалённый SDK) отклоняется как unsupported capability | `drive/tests/Uploads/P3/CompletionDeadlineTest.php::D19-transport bounds a socket stream with the remaining budget and reads it`; `::D19-transport refuses a stream without an enforceable timeout as an unsupported capability` | fast; реальная задержка хранилища → server/stand |
| Completion storage calls | перед сборкой S3-прокси / проверкой полноты в `UploadOperations::complete` и перед staged-открытием, CAS prepare и receipt в `CompleteUploadAction` диск сессии (он же диск CAS) проходит preflight: квалифицируется только локальный адаптер Flysystem; удалённый адаптер (в Flysystem нет таймаута на вызов) отклоняется как unsupported capability до любого вызова хранилища | `drive/tests/Uploads/P3/CompletionDeadlineTest.php::D19-preflight refuses an unboundable remote disk before any staged open, CAS write or assemble` | fast (строгий spy-адаптер); завершение на удалённом диске не поддерживается до появления ограниченного драйвера |
| Native AWS multipart | — | N/A (прокси-обработчик) | N/A |

## Вне области

Аутентификация хоста, транспортная безопасность приложения-хоста, безопасность настроенного диска
хранилища и сканера.
