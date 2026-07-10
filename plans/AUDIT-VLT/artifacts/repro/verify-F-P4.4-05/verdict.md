# Верификация F-P4.4-05 (скептик-раунд)

**Метод:** находка не про PHP-путь выполнения — `vendor/bin/pest` тут не применим
(нет продакшн-кода на PHP, который можно было бы гонять). Верификация — чтением
`packages/vue/package.json:58-68`, `packages/vue/vite.config.ts`, приложенного
репро-лога `artifacts/repro/P4.4/npm-audit-vue.txt` и CI-воркфлоу `frontend.yml`.

## Проверенные факты

1. `packages/vue/package.json:58-68` — `happy-dom` действительно в `devDependencies`
   (не в `dependencies`/`peerDependencies`).
2. `packages/vue/vite.config.ts:33` — `test: { environment: 'happy-dom' }`: happy-dom
   реально используется как рантайм vitest-тестов, не мёртвая зависимость.
3. `packages/vue/package.json:29-31` — `"files": ["dist"]`: опубликованный npm-пакет
   `@vaulter/vue` содержит только `dist/`; `devDependencies` потребителям пакета не
   ставятся (`npm install <pkg>` без `--include=dev` их не тянет) — подтверждает
   собственную оговорку находки «не поверхность продакшн-рантайма».
4. `artifacts/repro/P4.4/npm-audit-vue.txt` — реальный вывод `npm audit`, дословно
   совпадает с цитатой в находке: `happy-dom <=20.8.8`, critical, GHSA-37j7-fg3j-429f,
   `6 vulnerabilities (3 moderate, 1 high, 2 critical)`.
5. `.github/workflows/frontend.yml` — CI гоняет `npm install` + `npm run test` на
   каждый push/PR (matrix Node 20/22); нет `npm audit`-гейта, нет `overrides`/
   `resolutions`, фиксирующих happy-dom на патченную версию — ничто не блокирует и не
   митигирует уязвимую цепочку.
6. `packages/vue/tests/**` — обычные unit/component-тесты Vue-компонентов пакета,
   не потребляют внешний/недоверенный HTML — прямой путь эксплуатации VM Context
   Escape («вредоносный тест-фикстур или supply-chain-инъекция») требует уже
   привилегированной позиции (пишущий доступ к репо / скомпрометированная
   транзитивная зависимость), а не недоверенного внешнего актора продакшн-приложения.

## Вывод

Опровергнуть находку не удалось: факты (наличие критических advisories в
`happy-dom`, использование happy-dom как реального test-environment, отсутствие
audit-гейта в CI) подтверждаются чтением кода 1:1 с текстом находки. Норма `P4.4 C8`
— механическая («advisory high+ = находка»), не завязана на reachability от
недоверенного продакшн-актора, поэтому линза «achievability» тут не отменяет
находку как таковую — она лишь проверяет, не завышена ли severity.

Находка сама уже корректно скопирована по severity: текст явно признаёт «не
поверхность продакшн-рантайма» и держит Major (не Blocker/Critical), что
соответствует тому, что реальный вектор — CI/dev-машина участников репозитория,
а не конечный пользователь Vaulter. Дальнейшего занижения не требуется — Major
уместен для critical RCE-advisory в стеке, который реально исполняется в CI на
каждый push/PR без audit-гейта.

**Вердикт: находка НЕ опровергнута. Severity — keep (Major).**
