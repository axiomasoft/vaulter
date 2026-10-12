---
title: Загрузки
description: Резюмируемые загрузки — ядро tus.io на любом диске, S3-подобный multipart на объектных хранилищах и прямой one-shot-путь; все они сходятся к одному и тому же CAS-коммиту без гонок.
outline: deep
---

# Загрузки

Большие файлы загружаются **резюмируемо**: долгая загрузка переживёт разрыв соединения,
перезагрузку страницы и даже подхват другим клиентом ровно с того места, где остановился
предыдущий. Vaulter поставляется с двумя резюмируемыми транспортами плюс прямым one-shot-путём,
и каждый из них сводит собранные байты к **одному и тому же `BlobStore.commit` без гонок** — так
что дедупликация, конечный автомат состояний блоба без гонок и квота применяются одинаково, а
`sha256` **пересчитывается на сервере** из собранных байтов (клиентский `sha256` / S3 ETag
**никогда** не становится контент-адресом).

::: info Инвариант H.3
Сервер никогда не доверяет присланному клиентом хэшу. Объявленный `expected_sha256` — это
*подсказка* для проверки целостности, а не контент-адрес: `BlobStore.commit` заново хэширует
собранный staged-файл в момент коммита.
:::

## Квота и ретеншн ревизий

Квота **логическая** (PLAN2.D48): `used_bytes` — сумма `size_bytes` всех сохранённых ревизий всех
файлов диска, включая корзину, без скидки на сдедуплицированные байты: две одинаковые загрузки
занимают двойной размер. Новая ревизия получает номер `revision_count + 1` из заблокированного
файла и добавляет свой полный размер, потому что прежняя голова остаётся сохранённой ревизией.
Восстановление ревизии копирует её вперёд как новую и снова добавляет размер; копия файла
добавляет размер копируемой головы. Удаление ревизии, прунинг по политике ретеншна и очистка
файла освобождают байты. Диск, чьё потребление оказалось выше квоты (квоту снизили, пересчёт при
обновлении), продолжает читаться, удалять и очищаться; отклоняется только рост.

Каждая ревизия хранится, пока её не удалят, не вычистят прунингом или не очистят файл. Что
удаляет прунинг, решает `RevisionRetentionPolicy` профиля диска:

<!-- snippet: host -->

```php
use Vaulter\Profiles\DrivePolicyBuilder;
use Vaulter\Profiles\RevisionRetentionPolicy;

$profile->drivePolicy(fn (DrivePolicyBuilder $policy) => $policy->revisionRetention(new RevisionRetentionPolicy(
    maxNoncurrentRevisions: 100, // сколько новейших нетекущих ревизий хранить на файл; null = без лимита по числу
    maxNoncurrentAgeDays: null,  // удалять нетекущие ревизии, замещённые давнее этого; null = без лимита по возрасту
    minNoncurrentAgeDays: 7,     // никогда не удалять ревизию, замещённую позже этого срока
)));
// Короткая форма: не больше 10 ревизий на файл, включая голову.
$profile->revisionLimit(10);
```

Порядок защиты: голова не удаляется никогда, затем закреплённые ревизии, затем замещённые менее
`minNoncurrentAgeDays` назад; из остальных уходит всё сверх новейших `maxNoncurrentRevisions`
незакреплённых нетекущих ревизий и всё старше `maxNoncurrentAgeDays`. Закреплённые ревизии не
входят в лимит. Возраст считается от `superseded_at` — момента, когда ревизия перестала быть
головой.

Прунинг запускается как `php artisan vaulter:revisions:prune [--drive=ID ...] [--dry-run]`
(системный актор `revision-retention`, файлы пачками по 500) или как очередная задача
`Vaulter\Drive\Jobs\PruneFileRevisionsJob` для одного диска. Планируйте ежедневно, как
`vaulter:trash:purge`:

<!-- snippet: host -->

```php
Schedule::command('vaulter:revisions:prune')->daily();
```

Отдельными ревизиями управляет файловый API:

<!-- snippet: host -->

```php
$drive->files()->setRevisionRetention($file, $revision, keepForever: true); // Edit: закрепить / снять
$drive->files()->deleteRevision($file, $revision);                          // Manage: удалить сразу
```

`deleteRevision` отказывает для головы (409 `cannot_delete_head_revision`) и закреплённых ревизий
(409 `revision_pinned`); сначала снимите закрепление. Удаление и прунинг берут одни и те же
блокировки (диск → узел файла → файл → ревизия → квота → CAS), поэтому ревизия, закреплённая или
ставшая неудаляемой после выбора прунингом, пропускается. Номера ревизий не переиспользуются.

## Транспорты

`UploadProtocol` (`Vaulter\Enums\Uploads\UploadProtocol`) выбирает транспорт для каждой сессии:

| Протокол      | Значение        | Работает на | Как |
| ------------- | --------------- | -------- | --- |
| **tus**       | `tus`           | **любом** Flysystem-диске | tus.io CORE — Creation + `HEAD`(`Upload-Offset`) + `PATCH`(`application/offset+octet-stream`). Каждый чанк дописывается в staged-temp; `received_bytes` — авторитетное смещение. |
| **S3 multipart** | `s3-multipart` | S3-подобных дисках | Нумерованные части записываются в `v_upload_parts` (повторная загрузка части заменяет её), сшиваются на complete. |
| **Direct**    | `direct`        | любом | Одноразовый нерезюмируемый `POST` — быстрый путь для мелких файлов. |

По умолчанию — **tus** (резюмируемый путь, не зависящий от диска). Транспорт выбирается в момент
создания сессии; `UploadSessionManager` резолвит подходящий обработчик (`TusUploadHandler` /
`S3MultipartHandler`) из контейнера, так что хост может подменить любую из реализаций.

## Жизненный цикл сессии

Резюмируемая загрузка — это полноценная строка (`UploadSession`, `v_upload_sessions`).
`UploadStatus` управляет конечным автоматом:

```
Initiated → Uploading → Assembled → Committed
     └──────────┴──────────┘
            ↓ (abort / sweep)
       Aborted / Expired
```

| Состояние   | Смысл |
| ----------- | ------- |
| `initiated` | Строка существует; байты ещё не приняты (`received_bytes = 0`). |
| `uploading` | Прилетел хотя бы один tus-`PATCH` / S3-часть; `received_bytes` — авторитетное серверное смещение. |
| `assembled` | Все байты собраны на staged-temp; готовы к коммиту. |
| `committed` | Собранные байты прошли через `BlobStore.commit`; созданы `File` + `FileRevision` + узел, а `assembled_blob_id` установлен. **Терминальное.** |
| `aborted`   | Клиент (или `DELETE`) отказался от сессии; staged-temp + S3-multipart очищены. **Терминальное.** |
| `expired`   | Сборщик забрал протухшую сессию по истечении её TTL. **Терминальное.** |

**Открыты** (принимают байты, могут быть подметены) только `initiated` и `uploading`. Переходы
защищены `UploadStatus::canTransitionTo` — терминальное состояние никогда не открывается заново.

::: info Резюмируемые загрузки
Маршруты включаются через `vaulter-drive.uploads.resumable.enabled`
(по умолчанию `true`). Таблицы загрузок существуют независимо от флага.
:::

## Цели

Сессия может нацеливаться на одну из двух форм (это зеркалит `IngestFileInput`):

- **Новый файл** → задайте `parent_id` (или опустите его для корня хранилища) и `name`; коммит
  создаёт узел `kind=file` в этой папке.
- **Новая версия** → задайте `node_id` (существующий узел `kind=file`); коммит увеличивает
  `version_no` и перенаправляет head.

Авторизация — по цели, проверяется через `AccessResolver` при каждом перемещении байтов/состояния
(и при создании, и на каждой последующей операции):

- **Новый файл** → `canCreateIn($actor, $scope)` **и** право (ability) `Ability::Create`
  на папке-назначении.
- **Новая версия** → право (ability) `Ability::Edit` на целевом узле.

Отказ — это `403` через `PermissionDeniedException`.

## Маршруты

Все маршруты монтируются под `/{routes.prefix}/v1` (по умолчанию `api/vaulter/v1`),
называются `vaulter.v1.uploads.*`, ограничиваются `throttle:vaulter-uploads` и регистрируются
всегда: при `vaulter-drive.uploads.resumable.enabled = false` операции отклоняют каждое
действие, кроме опроса статуса, с `403 profile_restriction`. `{upload}` — `upload_id` сессии:
простой id, который контроллер находит, а операции загрузки заново авторизуют на каждом
действии. Полные формы запросов и ответов —
[справочник REST](/ru/reference/rest-api#резюмируемые-загрузки).

| Метод    | Маршрут                            | Имя                     | Назначение |
| -------- | ---------------------------------- | ----------------------- | ------- |
| `POST`   | `/drives/{drive}/uploads`          | `uploads.store`         | Открыть сессию (`protocol`: `tus` или `s3-multipart`); tus отвечает `Location` + `upload_id`. |
| `GET`    | `/uploads/{upload}`                | `uploads.show`          | Опросить статус сессии. |
| `HEAD`   | `/uploads/{upload}`                | `uploads.tus.head`      | Авторитетный `Upload-Offset` (точка возобновления). |
| `PATCH`  | `/uploads/{upload}`                | `uploads.tus.patch`     | Дописать чанк; последний коммитит. |
| `DELETE` | `/uploads/{upload}`                | `uploads.tus.destroy`   | Прервать (staged-temp + multipart освобождаются). |
| `OPTIONS`| `/uploads/{upload}`                | `uploads.tus.options`   | Обнаружение tus. |
| `POST`   | `/uploads/{upload}/parts`          | `uploads.parts.store`   | Сохранить нумерованную часть. |
| `POST`   | `/uploads/{upload}/complete`       | `uploads.complete`      | Complete-multipart → собрать → закоммитить. |

One-shot-путь — `POST /drives/{drive}/files` (multipart-форма `file` + опциональные
`parent_id` / `name`); новая ревизия существующего файла одним запросом —
`POST /files/{node}/revisions` с `If-Match`.

::: warning Новой ревизии нужен тег файла
Сессия с `node_id` (новая ревизия существующего файла) создаётся только с `If-Match`, несущим
текущий `ETag` этого файла, — `428` без него, `412` при устаревшем, — чтобы клиент не начал
слать байты в файл, изменившийся у него за спиной. Тег проверяется при создании сессии;
коммит в конце его повторно не сравнивает (сохранение ожидания в сессии запланировано после
1.0).
:::

## Поток tus core

tus-обработчик реализует три операции CORE напрямую (без тяжёлого tus-фреймворка —
самодостаточно поверх Flysystem + таблицы сессий) и работает на **любом диске**.

1. **Create** — `POST /drives/{drive}/uploads`. Менеджер выдаёт сессию +
   `upload_id` (tus-`Location`) и штампует TTL. Метод `initialise()` обработчика — no-op
   (staged-temp создаётся лениво при первом `PATCH`). Ответ `201` с
   `Tus-Resumable`, `Location`, `Upload-Offset: 0`.
2. **HEAD** — `head()` возвращает **авторитетный серверный `received_bytes`** как
   `Upload-Offset`, сверенный с реальной длиной staged-temp (упавший `PATCH` никогда не
   отрапортует смещение дальше байтов, которые действительно легли). Возобновляющийся клиент
   читает **это**, а не свою догадку.
3. **PATCH** — `patch()` дописывает чанк `application/offset+octet-stream` по объявленному
   `Upload-Offset`. Смещение **обязано** совпасть с текущим смещением сервера (иначе tus-`409 Conflict`
   — где лягут байты, решает сервер, а не клиент). Когда `received_bytes`
   достигает `total_size_bytes`, контроллер запускает `markAssembled` → `CompleteUploadAction` и
   возвращает созданный узел (`201`).

::: code-group

<!-- snippet: shell -->

```bash [curl]
# 1. Создать сессию (файл 1 ГиБ, в корень хранилища).
curl -i -X POST https://app.test/api/vaulter/v1/drives/01J.../uploads \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{"protocol":"tus","total_size_bytes":1073741824,"name":"video.mp4"}'
# → 201, Location: .../v1/uploads/<upload_id>, Upload-Offset: 0

# 2. Спросить у сервера, с какого места возобновлять.
curl -I https://app.test/api/vaulter/v1/uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' -H 'Authorization: Bearer <user-token>'
# → Upload-Offset: <server offset>

# 3. Отправить чанк по этому смещению.
curl -X PATCH https://app.test/api/vaulter/v1/uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' \
  -H 'Content-Type: application/offset+octet-stream' \
  -H 'Upload-Offset: 0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @chunk-0.bin
# → 204 Upload-Offset: <new>  (ещё чанки)  |  201 + node JSON (завершено)
```

<!-- snippet: host -->

```vue [Vue]
<script setup lang="ts">
import { useUpload } from '@vaulter/vue'

const { uploadResumable, progress, sent, total, abort } = useUpload(driveId)

async function onPick(file: File) {
  // Ведёт tus-сессию чанк за чанком; прогресс реактивен.
  const node = await uploadResumable(file, {
    parentId,                       // или опустить для корня хранилища
    onProgress: (s, t) => console.log(`${s}/${t}`),
  })
  console.log('committed node', node.id)
}
</script>

<template>
  <progress :value="progress" max="1" />
  <span>{{ sent }} / {{ total }}</span>
  <button @click="abort">Cancel</button>
</template>
```

:::

::: tip Метаданные tus
Настоящие tus-клиенты могут прислать заголовок `Upload-Metadata` (список пар
`key base64(value)` через запятую). Контроллер декодирует его и накладывает поверх `metadata`
из JSON-тела, так что поддержаны и реальные tus-клиенты, и JSON-API.
:::

## Поток S3-multipart

Когда диск сессии — объектное хранилище, байты загружаются **нумерованными частями**.
Обработчик записывает каждую часть (`part_no` / `byte_offset` / `size` / `etag`) в `v_upload_parts` —
повторная загрузка части **заменяет** её строку (семантика S3, закодированная в
составном первичном ключе `(session, part_no)`) — и на `complete` сшивает части (по возрастанию
`part_no`) в собранный staged-temp.

<!-- snippet: shell -->

```bash
# Записать часть 1 (тело — байты части).
curl -X POST 'https://app.test/api/vaulter/v1/uploads/<upload_id>/parts?part_no=1&byte_offset=0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @part-1.bin
# → 201 { "part_no": 1, "size": ..., "etag": "\"...\"" }

# Complete → сшить → собрать → закоммитить → вернуть созданный узел.
curl -X POST https://app.test/api/vaulter/v1/uploads/<upload_id>/complete \
  -H 'Authorization: Bearer <user-token>'
```

::: info ETag никогда не адрес
Сохраняемый нами по каждой части `etag` — это лишь хэндл объектного хранилища (для отладки /
реального вызова complete-multipart), он **никогда** не становится контент-адресом. На `complete`
именно **сшитые байты**, а не multipart-ETag, `BlobStore.commit` заново хэширует (H.3). Обработчик
по умолчанию проксирует части через `StorageDriver`, так что один и тот же поток работает и против
поддельного/локального диска в тестах, и против реального S3-диска в продакшене; хост, желающий
настоящие presigned-PUT-части, подменяет обработчик — контракт record/complete при этом не меняется.
:::

## Сборка → CAS-коммит и дедупликация

`CompleteUploadAction` — это **единственное место**, где staged-байты пересекают границу Drive, и
делает он это тем же путём без гонок, что и one-shot-загрузка:

```
staged temp → IngestFileInput → IngestFileAction → BlobStore.commit
```

Всё это работает в одной внешней транзакции, так что сессия никогда не остаётся закоммиченной
наполовину:

1. staged-temp считывается обратно как поток (он живёт на диске сессии под префиксом `tmp/`,
   ключом которого выступает `upload_id`, так что многогигабайтная загрузка переживает несколько
   запросов).
2. `IngestFileAction` кладёт его локально, **пересчитывает `sha256`** и вызывает
   `BlobStore.commit` — дедупликация, конечный автомат без гонок, учёт ссылок и квота применяются.
   Два одинаковых файла коммитятся в **один и тот же блоб** (контент-адресуемая дедупликация);
   `assembled_blob_id` сессии устанавливается в тот блоб, в который сдедупились байты — читается из
   строки закоммиченной версии, а не из клиентского заявления.
3. При успехе сессия помечается `Committed`, а staged-temp **выбрасывается** (очистка по принципу
   commit-or-abort) — делается это *вне* транзакции, чтобы сбой удаления с диска никогда не
   откатывал уже закоммиченный файл.

### Длительность транзакции `complete()`

One-shot `files()->upload()` читает и хэширует байты *до* открытия транзакции. Резюмируемый
`complete()` так не может: он держит блокировку строки сессии всю внешнюю транзакцию, потому что
именно она не даёт sweeper'у и `abort()` выбросить staged-байты посреди чтения. Поэтому
единственный проход по этим байтам (чтение, `sha256`, инспекция) выполняется внутри транзакции
без активных запросов — соединение находится в состоянии `idle in transaction`.

Замер на PostgreSQL 16 с локальным staging-диском: файл 100 МиБ держит транзакцию открытой
около 1,1–1,2 с, из них около 0,8 с занимает хэширование. Время растёт линейно с размером (около
12 с на ГиБ, то есть лимит по умолчанию 5 ГиБ — около минуты) и со скоростью staging-хранилища.

Что учесть при эксплуатации:

- Держите `idle_in_transaction_session_timeout` (и idle-лимит прокси) выше
  `max_file_size ÷ скорость staging`; более короткий таймаут откатит завершение (сессия остаётся
  assembled, байты целы), но каждая повторная попытка упадёт так же.
- Каждый одновременный `complete()` занимает одно соединение с БД на это время: рассчитывайте
  пул на ожидаемое число параллельных крупных завершений или снижайте `max_file_size`.
- Блокировка сессии намеренная: два завершения одной сессии коммитятся один раз, а sweep и abort
  уступают идущему завершению. Чтобы сократить транзакцию, эту блокировку нужно заменить
  сохраняемым claim на сессии — это меняет контракт lease и ведётся отдельной поправкой.

Преждевременный `complete()` не может перепрыгнуть вперёд: `markAssembled` — no-op, пока
обработчик не отрапортует о приёме каждого байта.

## Прерывание и подметание

Освобождают сессию два пути — оба отпускают состояние на стороне протокола (S3
`AbortMultipartUpload` / объекты по частям) и выбрасывают staged-temp:

- **Abort** (`DELETE /v1/uploads/{upload}`) → терминальное состояние `Aborted` (клиент
  отменил). Идемпотентно — терминальная сессия возвращается нетронутой.
- **Sweep** (`php artisan vaulter:uploads:sweep`) → терминальное состояние `Expired`. Команда
  сканирует **открытые** сессии (`initiated` | `uploading`), у которых истёк `expires_at`, и
  освобождает каждую. Можно ставить в расписание.

<!-- snippet: shell -->

```bash
# Забрать протухшие сессии по истечении TTL (staged-temp + S3-multipart очищаются).
php artisan vaulter:uploads:sweep
```

<!-- snippet: host -->

```php
// Поставить в расписание (например, app/Console/Kernel.php или routes/console.php):
Schedule::command('vaulter:uploads:sweep')->hourly();
```

::: tip В паре с lifecycle-правилом S3
Сборщик прерывает те незавершённые multipart-загрузки, о которых **знает** (свои строки
`v_upload_parts`). Поставьте его в пару с lifecycle-правилом бакета S3
`AbortIncompleteMultipartUpload` как страховкой для любого осиротевшего multipart, который таблица
не увидела — lifecycle-правило *никогда* не является механизмом удаления блобов (сам CAS-бакет
остаётся без версионирования, H.3).
:::

## Конфигурация

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'uploads' => [
    'max_file_size' => 5_368_709_120, // bytes; 0 = unlimited
    'allowed_mime_types' => [],
    'resumable' => [
        'enabled' => true,
        'chunk_size' => 8_388_608,
        'max_chunk_size' => 67_108_864,
        'session_ttl_hours' => 24,
        'max_active_sessions_per_actor' => 20,
        'metadata' => [
            'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
            'max_value_length' => 512,
        ],
    ],
],
'trash' => ['retention_days' => 30],
'models' => [
    'upload_session' => \Vaulter\Drive\Models\Uploads\UploadSession::class,
    'upload_part' => \Vaulter\Drive\Models\Uploads\UploadPart::class,
],
```

`max_file_size` — общий лимит в байтах для обычных и резюмируемых загрузок.
Модели задаются в `vaulter-drive.models.*`; резюмируемые маршруты управляются
`vaulter-drive.uploads.resumable.enabled`.

## Тесты вашего расширения

Набор для тестов поставляется внутри пакетов (`Vaulter\Testing`, `Vaulter\Drive\Testing`);
production-код его не импортирует.

**Данные — через Operations.** `Drive::factory()->for($owner)`, `Node::factory()->file('bytes')->in($drive)`
и `FileRevision::factory()->for($file)->bytes('v2')` создают данные теми же Actions, что и запрос,
от имени system-актора, поэтому квота, sequence, блобы и scan-работа остаются согласованными.
Тенант выбирается через `forTenant()`. `InteractsWithVaulter` добавляет `vaulterFakeStorage()`,
`vaulterActingAs($user)`, `assertVaulterEventRecorded()` / `assertVaulterEventNotRecorded()` и
проверки видимости.

**Сканер содержимого.** `ContentScannerContractTests::assertContentScannerContract($scanner, $samples)`
принимает байты, на которых ваш сканер выдаёт каждый исход (`clean` обязателен; `infected`,
`unsupported`, `too_large`, `password_protected`, `access_denied` и `timeout` — по желанию), и
проверяет, что каждый превращается в evidence так, как его пишет жизненный цикл: вердикт либо сбой
с этой причиной, повторяемый только для таймаута. Также проверяется, что нечитаемые байты никогда не
объявляются чистыми.

**Жизненный цикл сканирования на вашей конфигурации.** `ScanLifecycleContractTests` работает через
Operations драйва от вашего актора, команду `vaulter:scan:reconcile` и скриптованный
`FakeContentScanner`:

<!-- snippet: host -->

```php
use Vaulter\Drive\Testing\Contracts\ScanLifecycleContractTests;

it('keeps the scan lifecycle', function (): void {
    (new ScanLifecycleContractTests)->assertScanLifecycleContract(
        static function (): void {
            config()->set('vaulter.scan.mode', 'all_content');
            config()->set('vaulter.scan.scanner_key', 'acme');
            config()->set('vaulter.scan.scanner_version', '1');
            config()->set('queue.default', 'redis');
        },
        $user,
    );
});
```

Набор доказывает: scan-работа создаётся в области драйва и тенанта ревизии, а evidence не
переиспользуется между областями; новое содержимое недоступно до clean, а legacy-содержимое блокируется
только при `all_content`; потерянная публикация и просроченная аренда восстанавливаются командой, а
устаревшая попытка не может завершить работу; отключение webhooks не выключает сканирование. Хосту нужны
асинхронное подключение очереди и актор, который может создавать и читать в драйве.

**Файловые обработчики.** `FileHandlerContractTests::assertFileHandlerContract($handler)` проверяет ключ,
заявленные типы и что `capabilities()` зависит только от mime-типа и расширения, по которым реестр
кеширует результат.

## Смотрите также

- [Блобы](/ru/concepts/storage-and-blobs) — контент-адресуемое хранилище, в которое коммитит каждая загрузка.
- [Файлы и папки](/ru/concepts/nodes-and-revisions) — дерево узлов, в которое попадает закоммиченный файл.
- [Публичные ссылки](/ru/guides/sharing) — опубликовать закоммиченный файл через bearer-ссылку.
- [Свой драйвер хранилища](/ru/guides/recipes) — подменить диск / объектное хранилище за загрузками.
- [Frontend-composable-функции](/ru/guides/vue) — `useUpload` (direct + resumable).
