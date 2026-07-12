# verify-F-P2.3-03 — скептик-ревью находки «morph map не зарегистрирован»

## Находка
F-P2.3-03 (Major, high): «Morph map не зарегистрирован — 5 активно заполняемых
полиморфных колонок хранят сырой FQCN». Локация: PanelTablesBuilder.php:143
(`owner_type`), :381 (`subject_type`), :495 (`author_type`), :714 (`actor_type`),
:721 (`target_type`).

## Что проверено

1. `grep -rn "morphMap" packages/*/src` — подтверждено: НИ ОДИН production
   ServiceProvider не вызывает `Relation::morphMap()`. Единственное упоминание —
   в тесте `packages/documents/tests/Comment/NodeCommentApiTest.php`.

2. Все 5 колонок — это морфы Vaulter → ПРОИЗВОЛЬНАЯ модель ХОСТА:
   - `owner_type` (Storage::owner()) — «project, team, user, …» (докблок
     Storage.php:18-19, ADR-0003 «Polymorphic workspace»).
   - `subject_type` (NodeGrant) — «user|role|team», управляется подключаемым
     `HostRoleDriver::subjectsFor()` — Vaulter физически не знает конкретные
     классы (контракт `Contracts/Access/HostRoleDriver.php`).
   - `author_type` (NodeComment) — хостовый пользователь.
   - `actor_type` (Activity) — «acting host user».
   - `target_type` (Activity) — докблок PanelTablesBuilder.php:719-720: «may be
     any model, swappable».

   Ни одна из этих моделей не перечислена в `config('vaulter.models.*')` —
   там только СОБСТВЕННЫЕ сущности пакета (storage, node, file, …). Пакет
   структурно не может заранее знать/алиасить чужие модели (User и т.п.),
   которые предоставляет хост.

3. Write-путь везде использует `$model->getMorphClass()`, НЕ `::class`:
   - `packages/drive/src/StorageManager.php:78` — `'owner_type' =>
     $owner->getMorphClass()`.
   - `packages/core/src/Activity/ActivityRecorder.php:167,194,201` —
     `targetType: $target?->getMorphClass()`, `$user->getMorphClass()`.
   - `packages/documents/src/Models/NodeComment.php` + docblok
     PanelTablesBuilder.php:492-493 — то же для `author_type`.

   `getMorphClass()` — это ИМЕННО API, который «слушается» морф-карты хоста:
   если хост вызвал `Relation::morphMap()`/`enforceMorphMap()`, в колонку
   уйдёт алиас; если нет — Laravel вернёт FQCN (штатное, а не пакетное,
   поведение ORM).

4. `Rules/Access/RegisteredSubjectType.php` — валидатор на write-пути
   (`StoreNodeGrantRequest.php:30`) реализует ТОЧНО ту же двухрежимную
   семантику, что и ядро Laravel (`Relation::requiresMorphMap()`):
   алиас — всегда ок; сырой FQCN — ok ТОЛЬКО когда хост НЕ включил
   `enforceMorphMap()`. Комментарий в файле явно называет это «Laravel's own
   morph fallback» — осознанное, а не случайное поведение.

5. **Исполняемое доказательство** — уже существующий (не написанный для этого
   опровержения) тест:
   `packages/documents/tests/Comment/NodeCommentApiTest.php:53-70`
   `it('creates a comment with the REGISTERED morph alias, not a raw FQCN')`
   регистрирует `Relation::morphMap(['user' => TestUser::class])` и проверяет,
   что `author_type === 'user'` (алиас), а не FQCN. Прогнан:

   ```
   cd packages/documents && /usr/bin/php8.4 vendor/bin/pest tests/Comment/NodeCommentApiTest.php
   ```

   Результат: **8 passed (30 assertions)**, включая именно этот кейс — PASS.
   Это прямое опровержение тезиса «колонки ВСЕГДА хранят сырой FQCN»: когда
   хост регистрирует морф-карту (обычная хостовая практика Laravel,
   не специфика Vaulter), Vaulter корректно пишет алиас.

## Вывод

Находка технически права в узком месте («grep на `morphMap` в
production-коде пакета — пусто»), но НЕВЕРНО интерпретирует норму E6.1/E6.2:
пример нормы («pkg_user», значения из `config('pkg.models.*')`) относится к
СОБСТВЕННЫМ сущностям пакета, которые пакет может перечислить и заалиасить.
Пять помеченных колонок — это морфы к ПРОИЗВОЛЬНЫМ моделям ХОСТА (User/Role/
Team/любая target-модель), которые Vaulter структурно не знает и не может
анонсировать за хоста — это явно задокументированное архитектурное решение
(ADR-0003 «Polymorphic workspace», докблоки в 5 местах, отдельный класс
`RegisteredSubjectType`) и стандартная Laravel-семантика «без enforced
morph-map — FQCN», которую пакет корректно НАСЛЕДУЕТ через `getMorphClass()`,
а не изобретает сам.

Реальный (гораздо более узкий) gap — не в PanelTablesBuilder, а в
DX/документации: README/install-гайд мог бы явно рекомендовать хосту
зарегистрировать `Relation::enforceMorphMap()` для user/role/team в своём
`AppServiceProvider`. Это Minor/DX-находка, не Major-уязвимость схемы.

**Вердикт: находка опровергнута (refuted).** Severity, если оставлять
производную находку про DX-рекомендацию — Minor, не Major.
