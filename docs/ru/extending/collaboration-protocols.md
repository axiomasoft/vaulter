# Протоколы коллаборации

Публичный пакет documents обслуживает `POST documents/{node}/collaboration/session` и
`Documents::in($drive)->collaborationSession($node)`. Клиент получает нейтральную сессию;
транспорт протокола реализует коннектор приложения. Yjs предоставляет коммерческое дополнение
`axiomasoft/vaulter-collaboration`.

## Регистрация адаптера

Реализуйте `Vaulter\Documents\Contracts\CollaborationProtocolDriver`:
`protocol(): string`, `version(): string`,
`supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool`
и `issueSession(CollaborationGrant $grant): CollaborationSession`.
Предикат совместимости чистый: без I/O, конвертации и авторизации.
Регистрация драйвера: `ModuleBuilder::slot(CollaborationProtocolDriver::class, AcmeDriver::class, 'acme.protocol', key: 'acme-ot')`.
Зарегистрируйте `PendingStateDrainer` с тем же ключом протокола, тип документа — с ключом типа.
Используйте запечатанный граф модулей без второго реестра. `SupportsCollaboration` типа
возвращает протокол и формат сохранённого состояния независимо друг от друга.

## Grant и проверка

Ниже форма read grant из фикстуры. В production grants выдаёт `CollaborationAccessPolicy`,
а не вызывающий код. `DocumentsOptions::collaborationSessionTtl()` ограничивает TTL диапазоном 1–300 секунд.

<!-- snippet: host -->

```php
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\ValueObjects\Access\ActorRef;

$read = new CollaborationGrant(
    nodeId: '42', driveId: '7', tenant: null,
    principal: new ActorRef('staff', '42'), mode: CollaborationMode::Read,
    generation: 3, schemaVersion: 1, documentType: 'acme/ot',
    protocol: 'acme-ot', stateFormat: 'acme-ot-v1', notAfter: time() + 300,
);
```

`CollaborationSession` содержит protocol, version, nullable endpoint, token, expiresAt, mode,
nodeId, generation, schemaVersion и несекретные скалярные options. `assertGrant($grant, $version)`
проверяет, что сессия не расширяет grant. Режим read не может стать write.
Фикстура Acme выдаёт случайные opaque credentials, сохраняет grant и проверяет его через
`AcmeOtTokenVerifier::verify(string $token): array`; неверные и истёкшие токены отклоняются.
Нормализованный результат содержит типизированный principal, tenant, node/drive, protocol,
state_format, generation, schema_version, mode и expires_at. Не восстанавливайте их из непроверенного ввода.
Production backend также повторно вызывает `CollaborationAccessPolicy::evaluateReference()`
для пользовательских обращений с проверенными actor/reference и сверяет полный binding под блокировкой.
Локальный журнал фикстуры показывает сохранение и fencing, но не полный сетевой security deployment.
Отзыв действует при следующей live-проверке policy; срок токена ограничивает detached credentials.
Внешняя доставка disconnect/control — best effort. Сервис может завершить checkpoint уже ACKed
записей после отзыва доступа пользователя; это не разрешение новых append.

## Совместимость и лимиты

| Адаптер | Wire | Формат хранения | Схема/checkpoint |
|---|---|---|---|
| Фикстура Acme (`acme/ot`) | `acme-ot/1` | `acme-ot-v1` | 1; JSON авторитетен, checkpoint может оставаться null |
| Yjs rich text/markdown | `yjs/2` | `yjs-update-v1` | схема типа; null только до seed, затем checkpoint обязателен |

Фикстура Acme использует `OpaqueJsonCodec(maxSizeBytes: 4096)`. Общий размер документа задаёт
`maxSizeBytes()` кодека или `DocumentsOptions::maxContentSizeKb()`. Транспортные лимиты принадлежат
адаптеру: конфигурация Yjs предоставляет `updateBytes()`, `requestBytes()`, `maxStateBytes()` и
`checkpointRequestBytes()`; это не универсальные лимиты кадров Acme. Сетевой адаптер проверяет
собственные лимиты кадров/updates до буферизации и лимиты проекции до публикации checkpoint.
Одна wire version не доказывает совместимость формата состояния.

## Сохранение, drain и restore

Исполняемые примеры: `packages/documents/tests/Collaboration/AcmeOtProtocolContractTest.php`
и `SecondProtocolTest.php`, реализации — в `tests/Fixtures/Collaboration` этого пакета.
Используйте `CollaborationProtocolContractTests::assertCollaborationProtocolContract($driver, $read, $write, $verifier)`
с настоящим backend verifier, а не специально придуманным для теста декодером токена.

Lifecycle создаёт документ Acme и сессию, вызывает `AcmeFixtureLog::append($token, $content)`,
выполняет flush/fsync записи и `markPending($locked, $binding, $sequence)` до ACK.
В `VaulterDatabase::transaction()` получите `$locked = $state->lock($nodeId)` и публикуйте через
`new CheckpointPublication($marker->binding, $locked->stateVersion, $marker->throughSeq, null, $content)`.
Проверяйте `CheckpointOutcome::published`: чужой протокол или устаревший CAS не очищает marker.
`AcmePendingStateDrainer::drain($marker)` готовит проекцию вне транзакции, затем блокирует состояние
и публикует её. Чтение pending-состояния выполняет drain перед возвратом авторитетной проекции.

Обычная замена JSON блокирует старую generation. `restore($node, $revisionId)` восстанавливает
содержимое/binding ревизии в новой epoch; затем запросите вторую сессию. Старый токен не может
выполнить append даже при равном содержимом. Для подготовки transition нужен конкретный конвертер;
универсального реестра конвертации нет. Pending queries фильтруются по сохранённому протоколу;
отсутствующий drainer блокирует purge. Порядок блокировок: дерево → узел → документ, затем строки
транспорта. Не выполняйте merge и не ждите удалённый backend, удерживая блокировки.

При reconnect получите новую авторизованную сессию и возобновляйте работу только внутри её generation.
ACK означает durable-границу журнала адаптера, а не доставку WebSocket; checkpoint может отставать
от ACK. Не воспроизводите старую generation после restore. Несовместимость чтения делает комнату
недоступной; несовместимость записи может дать read-only. JSON остаётся доступным по обычным
правилам чтения. REST-ошибки перечислены в [каталоге проблем](/reference/errors);
совместимость wire/state для конкретного релиза принадлежит матрице совместимости адаптера.

## Выбор клиента

<!-- snippet: host -->

```ts
// `session` is the neutral REST response; this host selects its Acme connector.
if (session.protocol !== 'acme-ot' || session.version !== 'acme-ot/1'
    || session.endpoint !== null || session.options.document !== session.node_id
    || session.expires_at <= Math.floor(Date.now() / 1000)) {
  throw new Error('Unsupported collaboration session')
}
// Pass session.token and session.mode to the host connector; never log the token.
```

Другому адаптеру может требоваться endpoint. До подключения проверьте его по разрешённым origins
приложения, версии протокола и схеме options. При ошибке проверки не переключайтесь на другой
протокол. См. [Типы документов](/ru/extending/document-types) и
[Вьюеры](/ru/extending/viewers) о границах типа и интерфейса.

См. [справочник совместимости](/reference/compatibility).
