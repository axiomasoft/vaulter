# 10. DOCX и серверный обмен документами (vaulter-editor-pro)

[Паспорт `vaulter-editor-pro`](../repos/vaulter-editor-pro.md) · [План разработки](../dev-plans/vaulter-editor-pro.md) · [Спецификация](../spec/implementation/editor-pro.md) · [Схема БД](../spec/database/editor-pro.md) · [DDL обмена](../spec/database/sql/editor-pro-exchange.sql).

### 10.1 Корпус OOXML (D1–D4) и локальный экспорт DOCX

**После** — 1.0, 3.1.

1. В `vaulter-editor-pro/packages/docx` (`@vaulter/editor-pro-docx`) зафиксировать версии OSS XML/ZIP/OOXML библиотек и реализовать версионированный mapping `Tiptap JSON/review ↔ intermediate model ↔ OOXML` с машинно-читаемым отчётом потерь `{profile, source_marker, diagnostics}`.
2. Подготовить синтетический корпус D1 (body/table: widths, header row, 2×2 merge, bold/link), D2 (sections: default/first/even headers/footers, inheritance, page break), D3 (discussions: range replies/resolve, detached, legacy) и D4 (revisions: insert/delete/replace, group metadata, loss report для native formatting revision). Профили D3/D4 включаются после 8.1/8.2 соответственно.
3. В `packages/vue` (`@vaulter/editor-pro-vue`) добавить локальный экспорт подтверждённой доступной версии с выбором профиля и preview предупреждений без сетевых запросов (проверка через fetch trap).

- **10.1-A** Корпус D1–D2 (и D3–D4 при включённых профилях) проходит roundtrip `DOCX → model → DOCX → model` с совпадением заявленных свойств и читаемым отчётом потерь; неподдерживаемый обязательный элемент блокирует профиль.
- **10.1-B** Браузерный локальный экспорт работает полностью офлайн (fetch trap = 0 запросов) и даёт эквивалентный semantic report с Node.

### 10.2 Серверные задания обмена и атомарный импорт DOCX

**После** — 3.3, 10.1.

1. В `vaulter-editor-pro/packages/exchange` (`axiomasoft/vaulter-editor-pro-exchange`) реализовать миграцию `v_document_exchange_jobs` по [DDL](../spec/database/sql/editor-pro-exchange.sql), очередь с lease/fencing, удержание исходного Media blob и неизменяемый output artifact.
2. Реализовать маршруты `POST /documents/{id}/exchange-jobs`, `GET …/{job}`, `GET …/{job}/file` (с повторной проверкой прав View/export/history) и `POST …/{job}/apply` (атомарное применение через `AtomicDocumentMutation` в snapshot или `CommandJournal` в journal после 3.5 с отдельным `command_id` и контролем поколения).
3. Ограничить размер распаковки, число entries и отключить external relationships / XXE до разбора архива. В `packages/vue` добавить UI загрузки, статуса после reconnect, отчёта потерь и явного подтверждения apply.

- **10.2-A** Roundtrip import/export, kill worker после записи Media до ссылки (GC убирает orphan, повтор завершает один job), повтор apply и потеря ответа после owner commit возвращают прежнюю квитанцию без дублирования.
- **10.2-B** Устаревший preview после изменения головы даёт конфликт; битый архив или external rel отклоняются без изменения документа; отзыв прав перед скачиванием или apply закрывает доступ.
