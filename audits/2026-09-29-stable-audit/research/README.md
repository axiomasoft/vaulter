# Исследовательский архив Perplexity

Дата: 2026-09-29. Выполнено **13 запросов: 12 обычных Search и 1 Deep Research**. После широкого обзора сделаны целевые запросы по всем основным направлениям исходного аудита и два дополнительных — по ACL listing и безопасному ремонту scopes.

Это первичная поисковая подложка. Авторитетные выводы первого прохода — в [проверке кода](../01-evidence.md), [позиции](../02-design-position.md) и [карте прочитанных первоисточников](../03-source-map.md). Сохранённые ответы не следует принимать как автоматически верные факты о Vaulter или текущих версиях сторонних проектов.

| Материал | Для какого решения полезен |
|---|---|
| [00 — первичная разведка](00-initial-discovery.md) | Nextcloud, Spatie, tusd, scoped context |
| [01 — Deep Research](01-landscape-deep.md) | Общая карта альтернатив и контраргументы чрезмерному переписыванию |
| [02 — extensions](02-extensions.md) | Types, MIME, schema, registry lifecycle, editor/export |
| [03 — authorization/tenancy](03-auth-tenancy.md) | Trust boundaries HTTP/PHP/jobs, контекст worker и maintenance |
| [04 — uploads/security](04-uploads-security.md) | Staging, MIME, реальные bytes, quarantine, SSRF |
| [05 — CAS/concurrency](05-cas-concurrency.md) | IDs, dedup domain, quota, revisions, GC, restore |
| [06 — outbox](06-outbox.md) | Atomicity, occurrence IDs, retry и ordering |
| [07 — collaboration](07-collaboration.md) | Yjs binary, schema mismatch, restore и revocation |
| [08 — API/SDK](08-api-sdk.md) | OpenAPI, ETag, errors, cursor, idempotency, старый клиент |
| [09 — packages/release](09-packages-release.md) | Eloquent/DTO, Deptrac, Testbench, clean install, release artifacts |
| [10 — operations/product](10-operations-product.md) | Restore drills, bounded metrics, staging pressure, accessibility |
| [11 — ACL listing](11-acl-listing.md) | Exact SQL, materialization, bounded scan и metadata visibility |
| [12 — migrations/DX](12-migrations-dx.md) | Repair старых scope keys, config evolution, named-argument BC |
| [Зафиксированные OSS revisions](verified-repository-revisions.json) | Commit и SHA-256 прочитанных FileAdder, FileInfo, EngineManager |

## Поправки редактора: читать до использования сырых ответов

1. **Deep Research выгрузился неполностью.** В `01` есть пустые тематические разделы и заявление о 25 ссылках при фактически меньшем списке. Это ограничение полученного ответа. Темы повторно исследованы отдельными запросами; полнота не выводится из собственной самооценки Perplexity.
2. **Paperless API default.** `08` утверждает default v1. Прочитанный официальный `dev/docs/api.md` указывает другое значение (10 на момент чтения). Для нашей рекомендации важен version header и policy, не номер. Dev-документация также не доказывает default любого deployed release.
3. **Число событий.** В `06` сказано, что `A→B→A→B` даёт четыре события. Из исходного A это **три перехода/rename events**. В основных документах это исправлено.
4. **Post-filter pagination.** В `11` слишком широко утверждается, что продвижение cursor на 20 проверенных кандидатов само по себе пропускает allowed records. Если все 20 проверены и разрешённые возвращены, само продвижение корректно. Проблемы — недозаполненная страница, ошибочное определение конца выдачи, раскрытие superset totals и неучтённые continuation/scan limits. Bounded scanning — возможное решение, не обязательное требование всех API.
5. **tusd retries.** Формулировку из `04` «hooks обычно не повторяются» нельзя использовать как универсальный контракт. Официальная hook-документация имеет настройки retries для отдельных transport implementations. Даже retries не превращают hook в атомарную запись outbox вместе с нашей mutation.
6. **CLI-примеры не исполнялись.** В частности, `pnpm pack --dry-run` из `09` не проверен и не принят как готовая команда. Для будущего CI подобрать реально поддерживаемые команды закреплённой версии инструмента.
7. **GC grace period не является доказательством безопасности.** Даже задержанный writer может пережить рассчитанный интервал. Нужны согласованные locks/leases, референсы и повторная liveness-проверка либо контролируемый maintenance window.
8. **Предложения не становятся release policy.** Обязательный AV, tenant framework, отдельный auth server, отказ от Eloquent, всеобщий schema migration framework, OCR и список operational gates из сырых ответов не утверждены. Основные документы удерживают решения в пределах продукта.

Несколько страниц Spatie/Paperless не открылись через web-reader. Нужные факты сверены по официальным raw Markdown/source; ошибки получения страницы не выдаются за проверку. Прочие дополнительные ссылки в ответах сохранены для навигации, но не все прошли отдельное открытие.
