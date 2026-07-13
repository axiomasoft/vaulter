# HANDOFF — 2026-07-13 — after P2.3

**Next:**

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | low — вердикт «канон ок / no-op» по уже готовому списку папок |
| Context | continue (/clear) — ручной item |
| Суть | P2.4: Access/Uploads-сверка — `documents/src/Access/HostUserResolver` (свернуть в слой ЛИБО подтвердить домен-сервисом) + core `Access/`/`Webhooks/` сверка с ADR-0017 (вероятно no-op) — вердикт по каждой папке в Completion Notes |

```
/task:plan-exec VLT-CANON P2.4
```

**Done:** P2.3 🟢 Done. Три catch-all папки разобраны по роли (git mv, 9 файлов + 48
файлов-потребителей): `documents/src/Documents/Handlers/{TipTapHandler,UniverHandler}` →
`Engines/Handlers/*`; `Documents/NodeExportService` → `Export/NodeExportService` (присоединился
к `HtmlExporter`/`MarkdownExporter`); `Documents/CollaborationSessionService` →
`Collaboration/CollaborationSessionService` (присоединился к `NodeCollaborationDriverManager`);
`Support/{TipTapRenderer,Concerns/HasTiptapExtensions}` → `Support/TipTap/*`
(`DocumentContentHasher` остался движок-нейтральным в `Support/`); `drive/src/Uploads/
{MagicByteSniffer,StagedUpload,UploadModels}` → `Support/Uploads/*` (`UploadSessionManager`/
`S3MultipartHandler`/`TusUploadHandler` остались в `Uploads/` — оркестраторы). Тавтология
`Documents/Documents` устранена. Найдена и починена ловушка same-namespace `use`
(`StagedUpload`/`CollaborationSessionService` — переехавшие классы, на которые ссылались
классы из старого/нового же namespace без явного импорта). Гейты (pint/phpstan/pest ×3
пакета/type-cov 100%) зелёные; `vaulter-api-surface` → PASS clean rename-only diff (BC MAJOR,
задокументирован); `vaulter-test-verifier` → все три сьюта зелёные. Decision по ролям
`NodeExportService`/`CollaborationSessionService` (Export/Collaboration вместо буквальных
Support/Actions из текста item'а) — трактовка «по роли», не расхождение по существу, см.
Known Deviations. `vaulter-reviewer` не вызывал — Routing P2.2–P2.4 его не требует. Status
Board P2 = 3/4. Commits: `427b9ce`.

**Remaining:** P2.4 (Access/Uploads-сверка) → P3 (docs/ROADMAP/arch-тесты/supersede VLT-HSEAM).
Открытых owner-развилок больше нет: D7/D8/D9/D10/D11 — все закрыты.

**Sources of truth:** `plans/VLT-CANON/plan.md` (primary; §6 P2.3 Completion Notes, §4 Status
Board P2=3/4) · `docs/adr/0017-package-structure-canon.md` (нормативный канон структуры) ·
`findings/structure-canon-inventory.md` §2.3/§2.4 (P2.3/P2.4 источник разбора) ·
`UPGRADE.md`/`CHANGELOG.md` `[Unreleased]` · наследие: `plans/VLT-HSEAM/root/adr-file-handler-seam.md`.

**Open risks:**
1. **Required status checks на `main` не переименованы** (MED, наследуется из P2.1, **вне
   дерева репо**): job id/display-name azgard-лейна сменились; чинит owner в настройках
   репозитория, не коммитом.
2. **`docs/plan/*` держат старые FQCN в настоящем времени** (LOW, наследуется из P2.1,
   расширено P2.3): `00-master-plan.md:54,539`, `p2-5-option-b-topology.md` (permissions-azgard);
   `p2-5-namespace-strategy-drive-docdrive.md`, `p2-5-option-b-topology.md` (`Documents\Documents`,
   `Drive\Uploads\StagedUpload`) — исторический срез, кандидат в P3.1.
3. **`format` — смешанный value-space, backfill не шипается** (наследуется из P0.4, MED).
   Назначенного item'а нет.
4. **Gate-scope gap CI** (наследуется из P0.3/P0.4, Major): XSS-барьер в `packages/documents`
   не несёт enforcing-порогов mutation/coverage. Нужен отдельный CI/gate-item — ни в одном
   P2.x/P3.x его нет.
5. `docs/guide/document-handlers.md` несёт стухшие FQCN в примерах конфига (P0.1/P0.4).
   Кандидат в P3.1.
6. **Pre-existing dead link `./activity`** в `guide/search-trash.md`/ru-зеркале ломает
   `docs:build` независимо от VLT-CANON. Кандидат в отдельный docs-fix item (или P3.1).
7. `plan-lint.py plans/VLT-CANON` — красный (не регресс): линтер требует `phases/P0..P3.md` +
   `## 6. Update Log`, план держит фазы инлайном в `## 6. Phases (детализация)` (Update Log —
   `## 9`); `plans/VLT-CANON/phases/` пуст с создания плана. Структуру плана правит
   plan-design, не exec.
8. **Реестр `registerEngineComponent` не реактивен** (наследуется из P1.2, 2 LOW, не
   блокирующие): регистрация ПОСЛЕ монтирования узла не подхватывается уже смонтированным
   `VaulterDocumentNode`. Задокументировано в `document-handlers.md` (en+ru).
9. **`packages/azgard/README.md` расходится с `azgard.yml`** (пред-существующее, не регресс
   P2.1): README говорит, что `tests/RealAzgard/` не гоняется в standalone-матрице, а workflow
   гоняет `pest --ci` без фильтра — т.е. гоняет.
10. Ветка `remediation/audit-vlt` несёт незакоммиченный дифф вне scope плана (`.claude/**`
    агент-пак, `.swissknife.json`, `plans/VLT-CANON/{findings,workflows}/`, `plans/VLT-HSEAM/`,
    `pnpm-lock.yaml`) — не мой, не трогал.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: required checks в branch protection (Open risk 1, owner). `docs/plan/*` стухшие
  имена (2). `format` value-space (3). CI gate-scope gap (4). `document-handlers.md` FQCN (5).
  Dead link `search-trash.md` (6). README-vs-CI расхождение azgard (9).
- open_questions: —
