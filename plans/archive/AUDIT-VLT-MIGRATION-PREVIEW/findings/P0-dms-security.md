# P0 — RAG-выжимка: безопасность DMS (авторизация, ACL, share-links, IDOR)

**Дата верификации:** 2026-07-10 · **Автор:** issue-planner/opus (design-time RAG)
**Инструмент:** perplexity-web `search_advanced` (web)
**Запрос:** «DMS authorization security: ACL inheritance with deny-veto, hierarchical
permission resolution pitfalls, confused deputy via share links, IDOR on document ids,
signed URL expiry best practices 2025»

**Вердикт:** ✅ подтверждено внешними источниками — перечисленные ниже требования
считаются load-bearing и служат чеклистом фазы P1 (items P1.1–P1.3).

---

## S1. ACL-наследование с deny-вето

| # | Требование | Источник |
|:--|:--|:--|
| S1.1 | Порядок вычисления — от ближайшего скоупа наружу (документ → папка → библиотека → root); при равном приоритете deny побеждает allow | Alfresco ACL ordering & evaluation |
| S1.2 | Разрешение вычисляется **per-subject** (пользователь + группы + роли), а не глобально: deny для одного субъекта не должен затирать allow для другого | Alfresco |
| S1.3 | Составные права (WRITE = WriteContent + WriteProperties): при allow на составное и deny на под-право эффективное право = разность множеств | Alfresco |
| S1.4 | Должен быть «hard deny»-маркер, прерывающий обход вверх (у нас — `inherit_grants=false`) | Alfresco |
| S1.5 | Неявные права (owner, system-role) должны быть видимы в модели ACL; смешивание неявных прав с явными ACE без отражения в UI/резолвере — источник сюрпризов | Alfresco |
| S1.6 | Обязателен централизованный `can(user, action, node)` — единственная точка обхода дерева и свёртки ACE; проверки не размазаны по слоям | Alfresco / MS MDS |
| S1.7 | Обязателен инструмент «explain access» (какой ACE на каком узле дал allow/deny) — иначе отладка эффективных прав невозможна | Alfresco |
| S1.8 | Множественные пути к одному узлу (ярлыки, коллекции, теги, smart-folders) обходят ACL, если права проверяются «по пути в дереве», а не «по объекту» | MS Master Data Services |

## S2. Confused deputy через share-links

| # | Требование | Источник |
|:--|:--|:--|
| S2.1 | Токен ссылки обязан связывать: resource (node), issuer principal (user/tenant), allowed operations, expiry, nonce | AWS confused-deputy prevention |
| S2.2 | На потреблении ссылки проверять НЕ только подпись: (а) не истекла, (б) **issuer до сих пор имеет права** на ресурс, (в) audience/tenant совпадает | AWS |
| S2.3 | Backend не должен действовать сервисным принципалом, недоступным инициатору («share as X») | AWS |
| S2.4 | Идентификатор ресурса берётся ИЗ проверенного токена, а не из path/query запроса | OWASP |

## S3. IDOR на идентификаторах документов

| # | Требование | Источник |
|:--|:--|:--|
| S3.1 | Серверная объектная авторизация на КАЖДЫЙ запрос; неугадываемые id — только defense-in-depth, не замена авторизации | OWASP A01:2025 |
| S3.2 | Запрещены «голые» выборки по PK: запрос всегда несёт tenant/user-скоуп | OWASP |
| S3.3 | Последовательные числовые id не выставляются наружу (утечка счётчиков/порядка создания) | OWASP |

## S4. Подписанные URL и их срок жизни

| # | Требование | Источник |
|:--|:--|:--|
| S4.1 | TTL — минуты/часы, не дни: 15–60 мин для эфемерного шэра; 24–72 ч для email-шэра, с потолком на тенант | AWS presigned-url best practices |
| S4.2 | Один URL = один объект + одна операция (read ИЛИ write); URL не даёт list/search | AWS |
| S4.3 | Presigned URL **не одноразовый** по природе — одноразовость требует серверного состояния (счётчик/отметка использования) | AWS (явно) |
| S4.4 | Нужны два уровня отзыва: отзыв конкретного токена и отзыв ключа подписи (убивает пачку URL) | AWS / GCS |
| S4.5 | URL — bearer-токен: не класть в него чувствительные метаданные, не логировать | AWS |

---

## Источники

1. https://support.hyland.com/r/Alfresco/Alfresco-Content-Services/23.4/Alfresco-Content-Services/Administer/Manage-Security/Authorization/Access-Control-Lists/ACL-ordering-and-evaluation
2. https://support.hyland.com/r/Alfresco/Alfresco-Content-Services/23.3/Alfresco-Content-Services/Administer/Manage-Security/Authorization/Access-Control-Lists/An-ACL-example
3. https://learn.microsoft.com/sl-si/previous-versions/sql/sql-server-2008-r2/ee633732(v=sql.105)
4. https://docs.aws.amazon.com/dms/latest/userguide/cross-service-confused-deputy-prevention.html
5. https://docs.aws.amazon.com/prescriptive-guidance/latest/presigned-url-best-practices/faq.html
6. https://owasp.org/www-community/attacks/insecure_direct_object_reference
7. https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-presigned-url.html
