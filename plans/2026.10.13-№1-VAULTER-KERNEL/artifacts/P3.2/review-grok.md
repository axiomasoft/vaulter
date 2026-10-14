# Review P3.2 (f5476019) — R1 «Ядро модулей»

Ревьюер: read-only subagent (модель Grok 4.7 в этой среде недоступна; ревью выполнено нативным ревьюером сессии). Тесты не запускались.
Отчёт записан интегратором по ответу ревьюера (у read-only роли нет Write).

Вердикт: approve, без CRITICAL/HIGH. Проверено без замечаний: lifecycle booting/boot; InvalidModuleGraph (artisan жив, только modules/doctor,
503 по HTTP); route cache (маршруты не грузятся из кэша, fingerprint-default снимается forgetParameter, устаревший кэш ловит doctor);
publish-теги `vaulter-config`/`vaulter-migrations`; отсутствие I/O при boot.

## F1 — MED — кэш маршрутов, собранный при невалидном графе, навсегда отвечает 503 (исправлено)
- packages/core/src/VaulterServiceProvider.php (registerInvalidGraphRoute), Http/Controllers/ModuleGraphInvalidController.php.
- route:cache при невалидном графе кэширует catch-all; после исправления графа кэш подменяет все маршруты, каждый запрос под префиксом
  отвечает 503 с пустыми reasons до route:clear.
- Правка: контроллер при валидном ядре отвечает 404 (doctor routes_missing объясняет причину). Тест: InvalidModuleGraphHttpTest.

## F2 — MED — пустой `vaulter.routes.prefix` превращает catch-all в 503 на весь хост (исправлено)
- `Route::any('/{path?}')->where('.*')` при невалидном графе регистрируется до маршрутов хоста.
- Правка: при пустом префиксе catch-all не регистрируется. Тест: InvalidModuleGraphHttpTest.

## F3 — LOW — EnsureModuleActive не в $middlewarePriority: auth/throttle идут раньше (исправлено)
- Неавторизованный запрос к маршруту неактивного модуля получал 401/429 вместо 404 (и вместо 503 при невалидном графе).
- Правка: HttpRegistrar добавляет EnsureModuleActive в приоритет сразу после correlation/version/cache-policy. Тест: ActivationTest.

## F4 — LOW — конфиг vaulter мёрджится в booting, а не в register() (не исправлено; документация)
- Провайдеры, читающие `config('vaulter.*')` в своём register(), больше не видят дефолтов пакета. Часть ADR-0027 (граф компилируется
  после register всех провайдеров); требуется строка в UPGRADE/docs — рекомендация владельцу.
