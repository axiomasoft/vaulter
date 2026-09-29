# 09 — Целостность данных и безопасность

Документ задаёт **алгоритмы**, которые исполнители реализуют без самостоятельных решений.
Решения: [D07](02-decisions.md#d07)–[D13](02-decisions.md#d13), [D18](02-decisions.md#d18)–[D25](02-decisions.md#d25).

## 1. `OperationGuard`

```php
namespace Vaulter\Access;

final readonly class OperationGuard
{
    /** @throws NodeNotFoundException, PermissionDeniedException, MissingActorException */
    public function authorize(OperationContext $context, Ability $ability, Node $node): void;

    /** Создание нового узла в папке $parent. */
    public function authorizeCreate(OperationContext $context, Node $parent): void;

    /**
     * Пакетная видимость (D12).
     * @param iterable<Node> $nodes
     * @return list<Node> только видимые, в исходном порядке
     */
    public function visible(OperationContext $context, iterable $nodes): array;

    public function abilities(OperationContext $context, Node $node): NodeAbilities;
}
```

Алгоритм `authorize`:

1. `node.drive_id !== context.drive.id` → `NodeNotFoundException`.
2. Tenancy включена: `context.drive.tenant_id !== TenantResolver::current()` (строгое сравнение строк) → `NodeNotFoundException`.
3. `context.actor.isSystem()` → разрешить (записать `actor_type = vaulter:system` уже в событии).
4. `GrantSet $g = AccessResolver::effectiveGrants(actor, node)` (драйвер из профиля drive).
5. `!$g->allows(Ability::View)` → `NodeNotFoundException` (скрытие, D09).
6. `!$g->allows($ability)` → `PermissionDeniedException`.
7. Профиль запрещает действие (например, share links выключены) → `ProfileRestrictionException`.

`authorizeCreate`: шаги 1–3, затем `AccessResolver::canCreateIn(actor, drive)` и `Ability::Create` на `$parent`.

Правило для Operations: сначала **резолв** узла в пределах drive (`NodeLocator::find($context, $key)` — одна
выборка, `WHERE drive_id = ?`), затем `authorize`, затем Action. Для записи Action перечитывает узел под
блокировкой и повторно проверяет инварианты (права повторно не проверяются внутри транзакции — окно между
проверкой и блокировкой принимается и документируется).

## 2. Пакетная оценка доступа (`AccessResolver::evaluateMany`)

```php
/**
 * @param iterable<Node> $nodes
 * @return array<string, GrantSet> ключ — (string) node id
 */
public function evaluateMany(Authorizable $actor, iterable $nodes): array;
```

1. `override()` драйвера — один раз на (actor, drive). Allow/Deny → одинаковый результат для всех.
2. `subjectsFor(actor)` — один раз.
3. Сгруппировать узлы по `parent_id`. Для каждого уникального родителя вычислить **наследуемую** маску
   для его детей (allow/deny, level): только `subtree`-grants самого родителя и его предков с учётом
   `inherit_grants`-обрывов; `item`-grants родителя детям не передаются. Одна рекурсивная CTE на всех
   родителей страницы (`WHERE id IN (...)`), результат кэшируется в объекте запроса. Правила свёртки
   (ближайший deny, приоритеты) — ровно те же, что в текущем `resolveAcl`; алгоритм выносится в общий
   `AclFolder`, которым пользуются и `effectiveGrants()`, и `evaluateMany()`.
4. Одна выборка grants по `node_id IN (<ids страницы>) AND (subject_type, subject_id) IN (<subjects>)`, не истёкших.
5. Для каждого узла: если `inherit_grants = false` — наследуемая маска `P = (0, 0, null)` (как в текущем
   `resolveAcl`: обход останавливается на узле), иначе `P` — маска его родителя; поверх — собственные
   grants узла `O` (и `item`, и `subtree` применяются к самому узлу). Свёртка эквивалентна текущему
   правилу «deny пробивается только строго более близким allow»:

   ```text
   P.allow  = объединение allow по цепочке родителя (только subtree, с обрывами)
   P.denied = deny цепочки родителя, уже пробитые более близкими allow внутри цепочки
   allowed  = O.allow | P.allow
   denied   = O.deny  | (P.denied & ~O.allow)      # allow узла пробивает deny предков, но не свой deny
   level    = max(O.level, P.level)
   ``` Затем роль-лейн драйвера (`resolve()` по субъектам в `AccessScope` drive) —
   одинаков для всех узлов drive, кроме `nodeOwner`, поэтому кэшируется по `owned_by`.
6. Результат совпадает с `effectiveGrants()` для каждого узла — это контрактный тест (property-based:
   случайные деревья, grants, deny, inherit cut-off).

Сложность: O(1) SQL-запросов на страницу независимо от `per_page` (≤ 3 запроса).

## 3. Листинг с точной видимостью

```text
cursor = decode(options.cursor)              # (sort key tuple, id) последнего ПРОСМОТРЕННОГО
visible = []; scanned = 0; last = cursor
while count(visible) < perPage and scanned < scanBudget:
    batch = SQL(children of folder, after last, order by sort+id, limit perPage*2)
    if batch empty: last = null; break
    visible += guard.visible(context, batch)[: perPage - count(visible)]
    last = key(последний элемент batch, который был ПРОСМОТРЕН до заполнения страницы)
    scanned += count(просмотренных)
return NodePage(visible, nextCursor = last === null ? null : encode(last), scanned)
```

Курсор подписывается HMAC (`app.key`) и включает drive id + сортировку, чтобы не принимать курсор другого
запроса. `total` не выдаётся. Поиск: то же, но кандидаты — `lower(name) LIKE %term%` по drive (экранирование как сейчас).

## 4. Инварианты дерева в Actions (D13)

| Action | Проверки внутри транзакции |
|---|---|
| `CreateFolderAction`, `CreateDocumentAction`, `IngestFileAction` (новый файл) | родитель: `kind=folder`, `trashed_at IS NULL`, тот же drive; имя валидно; конфликт → `OnNameConflict` |
| `RenameNodeAction` | не корень; имя валидно; конфликт с соседями |
| `MoveNodeAction` | `lockTree`; не корень; назначение — активная папка того же drive; назначение не узел и не потомок (CTE внутри блокировки); конфликт имени |
| `CopyNodeAction` | назначение — активная папка того же drive; копия поддерева без узлов в корзине; байты через lifecycle; квота одним условным UPDATE на сумму |
| `TrashNodeAction` | не корень; не уже в корзине; поддерево помечается одним UPDATE по CTE (как сейчас) |
| `RestoreNodeAction` | `lockTree`; исходный родитель активен → туда, иначе `RestoreOptions::destination` или корень; конфликт имени → `Rename` по умолчанию |
| `PurgeNodeAction` | `lockTree`; узел в корзине; для каждого узла поддерева — `NodeContentLifecycle::purge()`; освобождение квоты одним UPDATE |

Нормализация имени (`Vaulter\Support\NodeName::normalize(string): string`): trim пробелов по краям; NFC
(`ext-intl`); запрет `/`, `\`, NUL и C0/C1-управляющих; запрет `.` и `..`; длина ≤ `names.max_length` байт.

## 5. Загрузка: единый ingest (D18)

```text
UploadSource ──stage──► StagedUpload(tmp path, size, sha256)          # потоково, лимит max_file_size во время чтения
             ──facts──► UploadFacts(name, ext, size, sha256, detectedMime(finfo), declaredMime)
             ──policy─► UploadPolicy(profile ∧ global): size, allowed MIME (по detected), blocked ext
             ──inspect► UploadInspector[priority DESC]  (reject → UploadRejectedException{reason})
             ──tx────►  VaulterDatabase::transaction:
                           lock агрегата (для новой ревизии) → квота (условный UPDATE)
                           BlobStore::commit(staged, BlobScope(drive), disk)   # promote вне SQL-гарантий, см. §7
                           rows: node/file/revision/edge, sequence++, head
                           EventRecorder::record(FileUploaded | FileRevisionAdded)
             ──after──► scan job (если scan_status = pending)
StagedUpload удаляется в finally; ошибка до commit не оставляет строк.
```

Правила MIME:

- `detectedMime` — `finfo` по первым 8 КиБ + уточнение по расширению только для «общих» типов
  (`application/octet-stream`, `text/plain`, `application/zip` → docx/xlsx/…).
- Несовпадение семейства (`image/*` заявлено, `application/x-dosexec` найдено) при `reject_mime_mismatch` → отказ `mime_mismatch`.
- В `v_nodes.mime_type` и `v_file_revisions.mime_type` пишется `detectedMime`; `declared_mime_type` — заявленный.
- Inline-отдача по-прежнему только из `downloads.inline_mime_types` по **detected** MIME; `nosniff` и
  `Content-Disposition: attachment` для остальных (как сейчас).

Resumable (D19): `InitiateUploadAction` проверяет политику по заявленным размеру/имени (ранний отказ), а
`CompleteUploadAction` прогоняет полный ingest по фактическим байтам. `complete` повторно на завершённой
сессии возвращает сохранённый результат (`upload_sessions.result_node_id`, `result_revision_id`).

## 6. Карантин

| `scan_status` | download / preview / export / share / thumbnail | Кто ставит |
|---|---|---|
| `not_required` | разрешено | ingest, если scan не требуется |
| `pending` | запрещено (`content_unavailable`, 423) при `scan.required` | ingest |
| `clean` | разрешено | scan job |
| `infected` | запрещено всегда; узел остаётся видимым с флагом | scan job; событие `file.quarantined` (T2) |
| `failed` | запрещено при `scan.required`; повтор job с backoff | scan job (таймаут = failed) |

Метаданные (имя, размер) остаются видимыми по правилам ACL. Если голова не `clean`, предыдущая чистая ревизия
не подставляется автоматически (явное поведение).

## 7. CAS-сага и сборка мусора

Порядок commit (без изменений по сути, фиксируется в документации): stage → sha256 → advisory lock
`(scope_key, sha256)` → найти blob (resurrect, если tombstoned) или promote объекта (`ifNoneMatch`) и вставить
строку → edge → SQL commit. Откат SQL после promote оставляет объект-сироту; `vaulter:blobs:reconcile`
удаляет сирот старше `orphan_grace_hours`, перепроверяя отсутствие строки под той же блокировкой. GC:
mark/sweep как сейчас, но все блокировки и транзакции — через `VaulterDatabase` (N01).

Дополнительно:

- ключ объекта строится из `BlobScope::key()`; reconciler распознаёт старые форматы (`s\d+`, `t\d+`, `snull`,
  `tnull`) и новые (`g`, `t-{urlsafe}`, `d-{urlsafe}`), включая UUID с дефисами;
- удаление drive (D27) не удаляет байты напрямую: оно удаляет рёбра, дальше работает GC.

## 8. Tenancy (D10)

| Точка | Поведение strict-режима |
|---|---|
| boot | `tenancy.enabled` без resolver → `TenancyMisconfiguredException` |
| `DriveProvisioner` | `tenant_id = resolver.current()`; null → исключение; при `ResolvesOwnerTenant` — `tenantOf(owner)` должен совпасть, иначе `NodeNotFoundException` |
| `Vaulter::drive(Drive $drive)` | тенант drive ≠ текущий → `NodeNotFoundException` (drive невидим) |
| `OperationGuard` | шаг 2 §1 |
| HTTP резолв | `HttpResourceResolver` фильтрует `tenant_id` при выборке drive/node/revision/upload |
| Jobs | payload содержит `tenant_id`; middleware job `RestoreTenantContext` сверяет; нет тенанта → fail без ретрая |
| CAS | `BlobScope::tenant(drive.tenant_id)` при `dedup.scope = tenant` |
| Обслуживание | GC/reconcile/repair/purge-trash — `Vaulter::withoutTenancy()` явно |

## 9. Шаринг (проверки к тестам)

Сохраняются текущие механизмы (hash токена, TTL, rate limit, timing-safe сравнение, пароль, require_login).
Обязательные тесты к 1.0: доступ к поддереву после move узла из-под share (не виден), к узлам в корзине (не
видны), к файлам с `scan_status ≠ clean` (запрещено), гонка revoke/download (после commit revoke — 404),
отсутствие сырого токена в логах/событиях/исключениях, ключ rate limit только по IP сервера
(`$request->ip()` с доверенными прокси хоста). `level=commenter` у share-ссылки запрещён (D11).

## 10. Коллаборация (D24)

- Токен (JWT, HS256, `hub.jwt_secret`): `sub` = actor, `node`, `drive`, `tenant`, `epoch`, `schema_version`,
  `mode` = `rw|ro`, `exp` ≤ `token_ttl`.
- `collab/authenticate`: повторная проверка прав на момент подключения; ответ `mode`.
- `collab/store`: HMAC-подпись с временной меткой (`webhook_secret`) + JWT комнаты; `epoch` должен совпасть с
  `v_documents.collab_epoch`, иначе 409 `collaboration_epoch_mismatch`; хаб при 409 выгружает документ и
  разрывает соединения комнаты.
- Replay-кэш подписей — в `Cache` store приложения (общий для инстансов; doctor предупреждает при `array`/`file`
  драйвере кэша в production).
- Хаб (`hocuspocus-server`): read-only соединения игнорируют входящие updates (серверная проверка), room name
  включает drive и node id.
- Production-проверки doctor: `wss://` у `hub.url`, оба секрета заданы и различны, `token_ttl ≤ 3600`.

## 11. Webhooks

Текущая защита (DNS pinning, запрет redirect, блок-листы, лимит ответа, подпись) сохраняется. Добавить к 1.0:

- заголовок `X-Vaulter-Event-Id` (occurrence id) и `X-Vaulter-Timestamp` в подпись (уже есть timestamp);
- повторная DNS/IP-проверка **на каждой** попытке (если сейчас кэшируется — проверить) и тесты: IPv4-mapped IPv6,
  десятичная/восьмеричная запись IP, CNAME-цепочка на приватный адрес, DNS rebinding между попытками;
- документация egress-прокси как рекомендации эксплуатации.

## 12. Секреты и цепочка поставок (CI)

Gitleaks (или TruffleHog) на PR; `composer audit` и `npm audit --omit=dev` в release-гейте; Dependabot/Renovate
для Composer, npm, GitHub Actions и Docker; SBOM CycloneDX для hub-образа; сканирование образа (Trivy).
