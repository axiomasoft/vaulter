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
| `committed` | Собранные байты прошли через `BlobStore.commit`; созданы `File` + `FileVersion` + узел, а `assembled_blob_id` установлен. **Терминальное.** |
| `aborted`   | Клиент (или `DELETE`) отказался от сессии; staged-temp + S3-multipart очищены. **Терминальное.** |
| `expired`   | Сборщик забрал протухшую сессию по истечении её TTL. **Терминальное.** |

**Открыты** (принимают байты, могут быть подметены) только `initiated` и `uploading`. Переходы
защищены `UploadStatus::canTransitionTo` — терминальное состояние никогда не открывается заново.

::: warning Feature-флаг
Группа резюмируемых маршрутов **и** схема `v_upload_sessions` / `v_upload_parts` закрыты
feature-флагом `uploads` (`config('vaulter.features.uploads')`, по умолчанию `true`). tus-эндпоинты
закрыты *дополнительно* через `config('vaulter.uploads.tus.enabled')` — при выключении отдают `403`.
:::

## Цели

Сессия может нацеливаться на одну из двух форм (это зеркалит `UploadFileData`):

- **Новый файл** → задайте `parent_id` (или опустите его для корня хранилища) и `name`; коммит
  создаёт узел `kind=file` в этой папке.
- **Новая версия** → задайте `node_id` (существующий узел `kind=file`); коммит увеличивает
  `version_no` и перенаправляет head.

Авторизация — по цели, проверяется через `AccessResolver` при каждом перемещении байтов/состояния
(и при создании, и на каждой последующей операции):

- **Новый файл** → `canCreateInWorkspace($actor, $storage)` **и** право (ability) `Ability::Create`
  на папке-назначении.
- **Новая версия** → право (ability) `Ability::Edit` на целевом узле.

Отказ — это `403` через `PermissionDeniedException`.

## Маршруты

Все маршруты монтируются под настроенным префиксом (по умолчанию `api/vaulter`) и требуют фичи
`uploads`. `{upload_id}` — UNIQUE-хэндл клиента (простой скаляр, **не** route-model-bound —
контроллер сам резолвит сессию и заново авторизует её цель).

| Метод    | Маршрут                                      | Контроллер / действие        | Назначение |
| -------- | -------------------------------------------- | ---------------------------- | ------- |
| `POST`   | `/storages/{storage}/resumable-uploads`      | `TusUploadController@create` | Открыть сессию (возвращает `Location` + `upload_id`). |
| `GET`    | `/resumable-uploads/{upload_id}`             | `TusUploadController@status` | Опросить статус сессии. |
| `HEAD`   | `/resumable-uploads/{upload_id}`             | `TusUploadController@head`   | Авторитетный `Upload-Offset` (точка возобновления). |
| `PATCH`  | `/resumable-uploads/{upload_id}`             | `TusUploadController@patch`  | Дописать чанк; по завершении собрать→закоммитить. |
| `DELETE` | `/resumable-uploads/{upload_id}`             | `TusUploadController@abort`  | Прервать (staged-temp + multipart освобождаются). |
| `POST`   | `/resumable-uploads/{upload_id}/parts`       | `S3MultipartController@parts`| Записать (подписать/проксировать) нумерованную часть. |
| `POST`   | `/resumable-uploads/{upload_id}/complete`    | `S3MultipartController@complete` | Complete-multipart → собрать → закоммитить. |

Прямой one-shot-путь — это эндпоинт загрузки Drive
`POST /storages/{storage}/uploads` (multipart-форма `file` + опциональные `parent_id` / `node_id`
/ `name`).

## Поток tus core

tus-обработчик реализует три операции CORE напрямую (без тяжёлого tus-фреймворка —
самодостаточно поверх Flysystem + таблицы сессий) и работает на **любом диске**.

1. **Create** — `POST /storages/{storage}/resumable-uploads`. Менеджер выдаёт сессию +
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
   достигает `total_size_bytes`, контроллер запускает `markAssembled` → `CommitUploadAction` и
   возвращает созданный узел (`201`).

::: code-group

```bash [curl]
# 1. Создать сессию (файл 1 ГиБ, в корень хранилища).
curl -i -X POST https://app.test/api/vaulter/storages/01J.../resumable-uploads \
  -H 'Authorization: Bearer <user-token>' \
  -H 'Content-Type: application/json' \
  -d '{"protocol":"tus","total_size_bytes":1073741824,"name":"video.mp4"}'
# → 201, Location: .../resumable-uploads/<upload_id>, Upload-Offset: 0

# 2. Спросить у сервера, с какого места возобновлять.
curl -I https://app.test/api/vaulter/resumable-uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' -H 'Authorization: Bearer <user-token>'
# → Upload-Offset: <server offset>

# 3. Отправить чанк по этому смещению.
curl -X PATCH https://app.test/api/vaulter/resumable-uploads/<upload_id> \
  -H 'Tus-Resumable: 1.0.0' \
  -H 'Content-Type: application/offset+octet-stream' \
  -H 'Upload-Offset: 0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @chunk-0.bin
# → 204 Upload-Offset: <new>  (ещё чанки)  |  201 + node JSON (завершено)
```

```vue [Vue]
<script setup lang="ts">
import { useUpload } from '@vaulter/vue'

const { uploadResumable, progress, sent, total, abort } = useUpload(storageId)

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

```bash
# Записать часть 1 (тело — байты части).
curl -X POST 'https://app.test/api/vaulter/resumable-uploads/<upload_id>/parts?part_no=1&byte_offset=0' \
  -H 'Authorization: Bearer <user-token>' \
  --data-binary @part-1.bin
# → 201 { "part_no": 1, "size": ..., "etag": "\"...\"" }

# Complete → сшить → собрать → закоммитить → вернуть созданный узел.
curl -X POST https://app.test/api/vaulter/resumable-uploads/<upload_id>/complete \
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

`CommitUploadAction` — это **единственное место**, где staged-байты пересекают границу Drive, и
делает он это тем же путём без гонок, что и one-shot-загрузка:

```
staged temp → UploadFileData → UploadFileAction → BlobStore.commit
```

Всё это работает в одной внешней транзакции, так что сессия никогда не остаётся закоммиченной
наполовину:

1. staged-temp считывается обратно как поток (он живёт на диске сессии под префиксом `tmp/`,
   ключом которого выступает `upload_id`, так что многогигабайтная загрузка переживает несколько
   запросов).
2. `UploadFileAction` кладёт его локально, **пересчитывает `sha256`** и вызывает
   `BlobStore.commit` — дедупликация, конечный автомат без гонок, учёт ссылок и квота применяются.
   Два одинаковых файла коммитятся в **один и тот же блоб** (контент-адресуемая дедупликация);
   `assembled_blob_id` сессии устанавливается в тот блоб, в который сдедупились байты — читается из
   строки закоммиченной версии, а не из клиентского заявления.
3. При успехе сессия помечается `Committed`, а staged-temp **выбрасывается** (очистка по принципу
   commit-or-abort) — делается это *вне* транзакции, чтобы сбой удаления с диска никогда не
   откатывал уже закоммиченный файл.

Преждевременный `complete()` не может перепрыгнуть вперёд: `markAssembled` — no-op, пока
обработчик не отрапортует о приёме каждого байта.

## Прерывание и подметание

Освобождают сессию два пути — оба отпускают состояние на стороне протокола (S3
`AbortMultipartUpload` / объекты по частям) и выбрасывают staged-temp:

- **Abort** (`DELETE /resumable-uploads/{upload_id}`) → терминальное состояние `Aborted` (клиент
  отменил). Идемпотентно — терминальная сессия возвращается нетронутой.
- **Sweep** (`php artisan vaulter:sweep-uploads`) → терминальное состояние `Expired`. Команда
  сканирует **открытые** сессии (`initiated` | `uploading`), у которых истёк `expires_at`, и
  освобождает каждую. Можно ставить в расписание.

```bash
# Забрать протухшие сессии по истечении TTL (staged-temp + S3-multipart очищаются).
php artisan vaulter:sweep-uploads
```

```php
// Поставить в расписание (например, app/Console/Kernel.php или routes/console.php):
Schedule::command('vaulter:sweep-uploads')->hourly();
```

::: tip В паре с lifecycle-правилом S3
Сборщик прерывает те незавершённые multipart-загрузки, о которых **знает** (свои строки
`v_upload_parts`). Поставьте его в пару с lifecycle-правилом бакета S3
`AbortIncompleteMultipartUpload` как страховкой для любого осиротевшего multipart, который таблица
не увидела — lifecycle-правило *никогда* не является механизмом удаления блобов (сам CAS-бакет
остаётся без версионирования, H.3).
:::

## Конфигурация

```php
// config/vaulter.php
'uploads' => [
    // The hard ceiling on a session's declared total size (0 = ∞). Rejected at
    // initiate — an oversize upload never opens a session. env: VAULTER_UPLOAD_MAX_BYTES
    'max_bytes' => env('VAULTER_UPLOAD_MAX_BYTES', 5_368_709_120), // 5 GiB

    // The advertised default chunk size (a hint; the server still accepts any
    // PATCH/part size up to the remaining bytes). env: VAULTER_UPLOAD_CHUNK_SIZE
    'chunk_size' => env('VAULTER_UPLOAD_CHUNK_SIZE', 8_388_608), // 8 MiB

    // A session not committed within this window is swept (status → expired).
    // env: VAULTER_UPLOAD_SESSION_TTL_HOURS
    'session_ttl_hours' => env('VAULTER_UPLOAD_SESSION_TTL_HOURS', 24),

    // Gate the tus resumable endpoints (independent of the 'uploads' schema feature flag).
    // env: VAULTER_UPLOAD_TUS_ENABLED
    'tus' => [
        'enabled' => env('VAULTER_UPLOAD_TUS_ENABLED', true),
    ],
],
```

Потолок `max_bytes` проверяется **дважды**: сразу на `initiate` (объявленный total сверх потолка
никогда не открывает сессию, так что не кладутся байты, которые пришлось бы отвергать) и снова
по ходу потока, пока растёт tus-смещение. Сменяемые модели привязываются через
`config('vaulter.models.upload_session')` и `config('vaulter.models.upload_part')`.

У one-shot бинарной загрузки Drive (`POST /storages/{storage}/uploads`) — **отдельный** блок
политики, `config('vaulter.drive')`: `max_upload_kb` (потолок размера на файл, проверяется
`UploadFileRequest`) и `allowed_mime` (опциональный allowlist mime; пустой = разрешено всё).
Блок `uploads` выше управляет только *резюмируемыми* сессиями.

## Смотрите также

- [Блобы](/ru/guide/storage-and-blobs) — контент-адресуемое хранилище, в которое коммитит каждая загрузка.
- [Файлы и папки](/ru/guide/drive) — дерево узлов, в которое попадает закоммиченный файл.
- [Публичные ссылки](/ru/guide/sharing) — опубликовать закоммиченный файл через bearer-ссылку.
- [Свой драйвер хранилища](/ru/guide/recipes) — подменить диск / объектное хранилище за загрузками.
- [Frontend-composable-функции](/ru/guide/vue) — `useUpload` (direct + resumable).
