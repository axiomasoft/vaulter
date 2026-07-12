# P0 — Нормы ремедиации W0 (RAG-выжимка)

**Дата верификации:** 2026-07-10 · **Кто:** issue-planner/opus · **Инструменты:** perplexity-web ×3, context7 `/websites/tus_io`

Датированный снимок внешних фактов, на которых стоят ТЗ items P0.1–P0.3. Нормативен и неизменен
в ходе исполнения VLT-REMED (аналог D16 плана AUDIT-VLT): свежий факт, противоречащий выжимке,
не правит её, а эскалируется (`rule conflict`).

---

## N1 — Серверная санитизация HTML (вход P0.1, F-P1.11-01/02/04)

**Вердикт:** `symfony/html-sanitizer` — рекомендуемый дефолт 2026 для PHP/Laravel; `ezyang/htmlpurifier`
стабилен, но legacy и требует аддонов под HTML5/WYSIWYG.

Факты:
- `UrlAttributeSanitizer` прогоняет ВСЕ URL-атрибуты (`href`, `src`, `action`, `formaction`, `poster`,
  `cite`, `ping`, `background`) через `UrlSanitizer::sanitize()`: allowlist схем/хостов, **отклонение
  `javascript:` by design** — эту схему нельзя разрешить через `allowLinkSchemes()`.
- Дефолтный безопасный конфиг: `allowSafeElements()` + `allowLinkSchemes(['https','http','mailto'])`
  + `allowMediaSchemes(['https','http'])`. Схемы вне списка (в т.ч. `data:`) — вырезаются.
- `allowRelativeLinks()` нужен явно: по умолчанию относительные ссылки удаляются.
- **Оговорка (важно для Code Guidance):** advisory 2026 — известны обходы allowlist'а схем/хостов
  из-за расхождения WHATWG- и RFC-3986-парсинга URL (обратные слэши, BiDi-управляющие символы).
  Следствие: санитайзер — НЕ единственный барьер. Нужен собственный строгий allowlist схемы `href`
  на входе (store-time валидация TipTap-JSON), а санитайзер — второй эшелон на выходе (render/export).

Источники: <https://symfony.com/packages/HTML%20Sanitizer> · <https://github.com/symfony/html-sanitizer> ·
<https://advisories.gitlab.com/pkg/composer/symfony/html-sanitizer/> ·
<https://github.com/tonysm/rich-text-laravel/blob/main/workbench/app/Html/Sanitizer.php>

---

## N2 — Защита исходящих webhook от SSRF (вход P0.2, F-P1.7-01)

**Вердикт:** валидации URL-строки при регистрации endpoint'а НЕДОСТАТОЧНО. Обязательна связка из пяти
контролей, из них три — на пути доставки, а не регистрации.

| # | Контроль | Где | Почему |
|:--|:--|:--|:--|
| 1 | Allowlist схем `http`/`https` | регистрация + доставка | Отсекает `file:`/`gopher:`/`ftp:` |
| 2 | Резолв A + AAAA, отказ при попадании в приватный/зарезервированный диапазон | доставка | `127.0.0.0/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` (metadata!), `100.64/10`, `::1`, `fc00::/7`, `fe80::/10`, IPv4-mapped `::ffff:127.0.0.1` |
| 3 | **DNS-пиннинг** резолвнутого IP (`CURLOPT_RESOLVE` либо IP-в-URL + заголовок `Host`) | доставка | Закрывает DNS-rebinding TOCTOU: «проверили → DNS перекинулся → подключились» |
| 4 | Редиректы выключены (`allow_redirects => false`) ЛИБО перевалидация каждого хопа + лимит глубины | доставка | Публичный хост 302-ит на `169.254.169.254` |
| 5 | `CURLOPT_PROTOCOLS` / `CURLOPT_REDIR_PROTOCOLS` = HTTP\|HTTPS; низкие таймауты; лимит размера тела ответа | доставка | Protocol smuggling + DoS-ответом (закрывает и F-P1.7-06) |

Проверка при регистрации — «ранний отказ» и UX, а не барьер: DNS между регистрацией и доставкой меняется.

Пакет-кандидат `jeffersongoncalves/laravel-ssrf-guard` (Packagist, поддерживается 2026) реализует
1–4 включая per-hop-перевалидацию. Отвергнут как рантайм-зависимость библиотеки — см. `plan.md` D5.

Источники: <https://www.guard-api.com/guides/how-to-fix-ssrf-in-laravel/> ·
<https://packagist.org/packages/jeffersongoncalves/laravel-ssrf-guard> ·
<https://blog.nette.org/en/nette-http-ssrf-protection> · <https://www.sourcery.ai/vulnerabilities/php-lang-security-php-ssrf>

---

## N3 — tus: конкурентный PATCH и коды ответа (вход P0.3, F-P1.4-01/02)

**Вердикт спеки** (tus 1.0.0 CORE, `https://tus.io/protocols/resumable-upload.html`):
- PATCH обязан нести `Content-Type: application/offset+octet-stream` и `Upload-Offset`.
- `Upload-Offset` не совпал с текущим серверным → **`409 Conflict`** (не 302/422).
- Успех → **`204 No Content`** + новый `Upload-Offset`.
- Несуществующий ресурс → `404 Not Found`.

**Вердикт по гонке:** спека возлагает предотвращение конкурентных PATCH на СЕРВЕР. Официальная позиция
проекта (blog «tus + S3 backend»): *«Placing the responsibility of preventing concurrent uploads on the
client side … does not guarantee corruption-free uploads. Clients might still accidentally send multiple
requests simultaneously, and the server, without its own locking mechanism, cannot prevent this.»*
Следствие: проверка `if ($offset !== $serverOffset)` без блокировки — **не** защита от гонки: два PATCH
читают одинаковый `$serverOffset` и оба проходят проверку (ровно F-P1.4-01). Нужен серверный лок на
сессию, удерживаемый на всё окно «прочитать offset → дописать байты → продвинуть received_bytes».

Источник: context7 `/websites/tus_io` (2026-07-10) — разделы «Requests > PATCH», «Resuming an Upload»,
«S3's eventual consistency model».

---

## N4 — TipTap: линия версий и CVE-2025-14284 (вход P0.1 и P4.1)

- **CVE-2025-14284** — XSS через `javascript:`-URL в `@tiptap/extension-link` (`setLink`/`toggleLink` без
  валидации протокола). Уязвимы **все версии < 2.10.4**. Первая исправленная — **2.10.4** (добавлен
  хелпер `isAllowedUri` с allowlist'ом `http`/`https`/`mailto`).
- **Tiptap 3.0 — текущий стабильный мажор в 2026.** Миграция 2.x → 3.x объявлена «ре-архитектурой»:
  TypeScript-first ядро, реорганизация пакетов, ломающие изменения в `@tiptap/core`, `@tiptap/vue-*`,
  `@tiptap/extensions`, изменённые сигнатуры команд и опций расширений.
- Текущее состояние репо: `packages/vue/package.json` → `@tiptap/starter-kit`, `@tiptap/vue-3`,
  `@tiptap/pm` на `^2.10`; `@tiptap/extension-link` — транзитивная зависимость starter-kit.

**Следствие для плана:** пиннинг `@tiptap/extension-link ≥ 2.10.4` в пределах `^2.10` — не ломающая,
дешёвая мера, входит в P0.1 (клиентский эшелон защиты). Мажор 2.x → 3.x — самостоятельный ломающий
апгрейд, риск которого не связан с Blocker'ом; вынесен в P4.1 (см. `plan.md` D6).

Источники: <https://tiptap.dev/blog/release-notes> · <https://tiptap.dev/docs/resources/whats-new> ·
база уязвимостей CVE-2025-14284 (first patched version 2.10.4).
