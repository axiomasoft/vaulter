# P0 — RAG-выжимка: hardening загрузок (tus 1.0.0 + S3 multipart + CAS)

**Дата верификации:** 2026-07-10 · **Автор:** issue-planner/opus (design-time RAG)
**Инструменты:** perplexity-web `search_advanced` (web) · context7 `/websites/tus_io`
**Запросы:** «tus/S3 multipart upload security checklist 2025 (offset race, checksum trust,
path traversal in Upload-Metadata, orphaned parts, SSRF, unbounded chunk size)»;
context7 — «tus 1.0.0 core protocol requirements».

**Вердикт:** ✅ подтверждено. Требования U1–U6 — чеклист items P1.4 и P1.5.

---

## U1. Нормативные требования протокола tus 1.0.0 (context7, tus.io/protocols/resumable-upload)

| # | Норма | Статус проверки в vaulter |
|:--|:--|:--|
| U1.1 | Заголовок `Tus-Resumable` — **обязателен** на всех запросах и ответах | проверить в P1.4 |
| U1.2 | `PATCH` требует `Content-Type: application/offset+octet-stream`; иначе **415** | проверить в P1.4 |
| U1.3 | `Upload-Offset` в запросе обязан **строго совпадать** с текущим смещением ресурса; несовпадение → **409 Conflict** | проверить в P1.4 |
| U1.4 | Успешный `PATCH` → **204 No Content** + актуальный `Upload-Offset` | проверить в P1.4 |
| U1.5 | `OPTIONS` анонсирует `Tus-Version`, `Tus-Extension`, `Tus-Max-Size` | проверить в P1.4 |
| U1.6 | Расширение checksum: `Upload-Checksum: <alg> <base64>`; несовпадение → **460 Checksum Mismatch**, неподдерживаемый алгоритм → **400** | проверить в P1.4 |
| U1.7 | Расширение termination: `DELETE` → **204** | проверить в P1.4 |

## U2. Offset как серверное состояние (гонки)

- U2.1 `Upload-Offset` клиента — это **заявление**, а не истина; сверять с персистентным `received_bytes`.
- U2.2 Строка сессии читается под **row-lock** (`SELECT … FOR UPDATE`) или оптимистической версией перед read-modify-write.
- U2.3 `offset < stored` — принимать как идемпотентный повтор ТОЛЬКО если диапазон байт совпадает с уже записанным; иначе **409** + указание пересинхронизироваться через `HEAD`.
- U2.4 `offset > stored` — всегда **409**; «прыжок вперёд» к клиентскому смещению запрещён.
- U2.5 `received_bytes` **никогда не убывает** и увеличивается только после durable-подтверждения бэкенда хранения.

## U3. Лимиты (DoS / integer-баги)

- U3.1 `Upload-Length`: отвергать отрицательные, нулевые и переполняющие значения; потолок per-tenant/per-user, продублированный в схеме БД (BIGINT + верхняя граница). Известны CVE на знаковых переполнениях в tus-хендлерах.
- U3.2 `Upload-Defer-Length`: финализация обязана пройти те же границы.
- U3.3 Потолок `Content-Length` на один `PATCH` (максимальный чанк) + суммарный потолок на upload-id.
- U3.4 Rate-limit (per-IP/user/tenant) + кап на число одновременных сессий; «chunk budget» — лимит числа PATCH на сессию (защита от амплификации мелкими чанками).

## U4. Upload-Metadata: traversal и инъекции

- U4.1 Storage-key генерирует **сервер** (uuid/hash); `filename` из метаданных используется только как метка в БД/UI и **никогда** не участвует в построении пути.
- U4.2 Отвергать `../`, url-encoded traversal, абсолютные пути в любых метаданных, влияющих на ключ.
- U4.3 Whitelist ключей метаданных; неизвестные ключи — отказ.
- U4.4 Лимиты длины каждого поля (filename ≤ 255 байт, mime ≤ 128, checksum ≤ 128).

## U5. Целостность и жизненный цикл

- U5.1 Клиентский checksum — **ожидаемое значение**, не доказательство. Сервер пересчитывает хеш по сохранённым байтам и сверяет; расхождение → отказ + удаление объекта.
- U5.2 Запрещено принимать решения доступа/пропуска сканирования на основе клиентского хеша.
- U5.3 Сессия несёт `status` + `expires_at`; sweeper переводит простаивающие в expired, удаляет частичные данные из хранилища.
- U5.4 Явный переход в complete (`received_bytes == total_size`) до любого downstream-использования.
- U5.5 Content-Type/magic-bytes валидируются **на собранном объекте**, не на чанке; антивирус — до публикации.

## U6. S3 multipart и presigned URL

- U6.1 Presign делает только бэкенд, фиксируя bucket+key+method; клиент не задаёт bucket/key. Никогда не пере-подписывать пользовательский URL (иначе backend = SSRF-прокси).
- U6.2 Права presign: только `s3:PutObject` (+ `AbortMultipartUpload`/`CompleteMultipartUpload`), ограниченные бакетом и key-префиксом.
- U6.3 TTL presign — 5–15 мин для мелких файлов, чуть больше для многогигабайтных; часы/дни запрещены.
- U6.4 Серверный токен (строка БД) связывает user, tenant, object key, multipart upload id; одноразовость валидируется приложением (part_number помечается использованным).
- U6.5 Границы part-size (5 MB–100 MB, кроме последнего); учёт `upload_id, part_number, size, etag` на сервере.
- U6.6 Осиротевшие multipart: S3 lifecycle policy + собственная сверка/abort по TTL.
- U6.7 Complete переводит сессию в complete только когда обе стороны согласны по total bytes и целостности.

## U7. Наблюдаемость

- U7.1 Логировать создание сессии, изменения offset, abort, complete (IP, tenant, user, key).
- U7.2 Метрики: число resume, средний чанк, доля отказов на тенант, отношение abort/complete.

---

## Источники

1. https://tus.io/protocols/resumable-upload.html (через context7 `/websites/tus_io`)
2. https://tus.io/blog/2015/02/03/protocol-v100-prerelease
3. https://aws-news.com/article/2025-05-14-securing-amazon-s3-presigned-urls-for-serverless-applications
4. https://d1.awsstatic.com/onedam/marketing-channels/website/aws/en_US/events/approved/reinforce-2025/reinforce/2024/slides/IAM321_Amazon-S3-presigned-URL-security.pdf
5. https://www.techinterview.org/post/3233471387/lld-resumable-upload/
