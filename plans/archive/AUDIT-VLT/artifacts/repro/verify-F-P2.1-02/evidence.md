# Верификация F-P2.1-02 (роль: скептик, попытка опровергнуть)

Вердикт: **не опровергнута** (refuted=false). Находка подтверждена фактами кода.

## Что проверялось

Утверждение: `docs/reference/{facade,contracts}.md` документируют API
(`resolvePermissionsWith`, `permissionResolver()`, `driver()`,
`Contracts\Access\PermissionResolver`, `Contracts\Collaboration\CollaborationDriver`),
которого больше нет в коде — excise по ADR-0014.

## Команды и результат

```bash
# 1. Методы VaulterManager сегодня — resolvePermissionsWith/permissionResolver/driver ОТСУТСТВУЮТ
$ grep -n "function " packages/core/src/VaulterManager.php
    __construct / for / forStorage / driveFactory / panel / panels / registerPanel /
    registerDocumentType / documentTypes / registerHandler / handlers /
    registerExporter / exporter / exporterFormats
# -> resolvePermissionsWith, permissionResolver, driver() нет вообще

# 2. Контракты, которые facade.md/contracts.md выдают за реальные, физически удалены
$ find . -path ./vendor -prune -o -path "*/Contracts/Access/PermissionResolver.php" -print
$ find . -path ./vendor -prune -o -path "*/Contracts/Collaboration/CollaborationDriver.php" -print
    (пусто — файлов нет)
$ grep -rn "Contracts\\Collaboration\\CollaborationDriver\|Contracts\\Access\\PermissionResolver" packages/*/src/
    (пусто)

# 3. ADR-0014 не удалён (файл на месте, Status: accepted) — но именно ОН предписал excise
$ ls docs/adr/0014-legacy-docvault-strangler.md
    существует

# 4. Коммит, реально снёсший legacy DocVault (включая PermissionResolver/CollaborationDriver путь)
$ git log --oneline -- packages/core/src/VaulterManager.php
    33c7460 refactor: extract vaulter-documents ... (P2 #5) (D2)      2026-07-02
    336d327 refactor(core): kernel-hardening — DocDrive single-prefix rename
    6cc1d07 refactor(core)!: excise legacy DocVault, keep single v_nodes path (ADR-0014 step 2)
    404a6e1 refactor!: complete ADR-0014 — single v_nodes document path (steps 1–3) (#38)

# 5. docs/reference/facade.md не трогали с ре-платформинга (ДО excise-коммитов)
$ git log -1 --format="%ai %H %s" -- docs/reference/facade.md
    2026-06-30 80e3369 "Vaulter: re-platform DocVault into an embeddable virtual document storage (#20)"
$ git log -1 --format="%ai %H %s" -- packages/core/src/VaulterManager.php
    2026-07-02 33c7460 (после 404a6e1/6cc1d07 excise-коммитов)
```

## Вывод

- Находка не про "ADR-0014 удалён" (сам файл ADR цел, Status: accepted) — формулировка
  находки в этой части неточна/двусмысленна, но по сути верна: ADR-0014 **предписал**
  excise legacy-пути, и коммиты `404a6e1`/`6cc1d07` этот excise реально выполнили.
- `docs/reference/facade.md:36` утверждает "Every method below is a real method on
  `VaulterManager`" и в таблице (строки 109-126 по нумерации находки, фактически метод
  `resolvePermissionsWith`/`permissionResolver`/`driver` встречаются в таблице методов
  и в разделе "Authorization (legacy document path)") — но эти методы физически
  отсутствуют в `packages/core/src/VaulterManager.php` на HEAD.
- `docs/reference/contracts.md:17,25,38,285-295` документирует интерфейсы
  `Contracts\Access\PermissionResolver` и `Contracts\Collaboration\CollaborationDriver`
  как существующие ("Coexists with `NodeCollaborationDriver`") — оба файла удалены из
  дерева исходников, нет вхождений через `grep -rn` по всем пакетам.
- Никакого ADR/заметки в CHANGELOG, оправдывающих сохранение этих доков как
  "документации для legacy-адаптера/BC-пакета", не найдено — `packages/` не содержит
  ни одного legacy-пакета с этим API.
- Datestamp подтверждает механизм дефекта: `facade.md` в последний раз правили
  30.06 (до excise), `VaulterManager.php` — 02.07 (после excise, включая коммиты
  404a6e1/6cc1d07) — то есть доки просто не подтянули вслед за исходником.

Попытка опровергнуть находку (защита выше по стеку, недостижимый код, намеренное ADR-
решение сохранить документацию по историческим причинам, тестовый/стаб-код) —
не удалась. Основания для refuted=true отсутствуют.
