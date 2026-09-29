# 09-packages-release

Дата: 2026-09-29. Ответ Perplexity; поисковые утверждения не равны проверке первоисточника. Проверенные выводы и ограничения см. в основных документах папки.

## Запрос

Подготовь source-based практику качества Laravel PHP+Vue монорепозитория: архитектурные границы без чрезмерного package splitting, Eloquent extension API vs DTO design, typed config/aliases and config cache, Testbench package consumer install, Composer path repo masking dependencies, Roave BackwardCompatibilityCheck limits, Deptrac rules, changesets monorepo releases, pnpm frozen lockfile, GitHub artifact attestations/provenance. Нужны 8–10 первичных ссылок на official docs/repositories и конкретные проверки release artifact; distinguish package name vendor vs repository owner; optional packages installed independently; migrations upgrade from previous artifact; PHP+Laravel+Node matrix derived from declared constraints not imaginary current latest; no arbitrary 1.0 checklist expansion. Обычный текст и списки, без таблиц, русский, 900 слов.

## Ответ

Ниже — проверяемый минимум для монорепозитория, который выпускает Laravel/PHP-пакеты и Vue/TypeScript-пакеты. Цель — доказать, что опубликованный артефакт работает у потребителя и что границы модулей сохраняются, а не расширять произвольный «чек-лист для версии 1.0».

## Архитектурные границы и API

- Делите код по устойчивым бизнес-границам и разным причинам изменения, а не по каждому namespace или классу. Не превращайте один продукт в множество Composer/npm-пакетов без необходимости независимой установки, версионирования, владения или публикации.
- Зафиксируйте небольшое число правил зависимостей: например, Domain не зависит от Infrastructure; пакет billing не импортирует внутренности school; публичный API отделён от Internal. Проверяйте правила статически Deptrac, а не только ревью. Начните с запрета запрещённых направлений; новые слои вводите лишь при реальной границе.
- Для интеграции с Eloquent используйте штатные точки расширения, когда пакет действительно расширяет модельную семантику: касты, события, scopes, отношения, custom collections и регистрацию через service provider. Не оборачивайте каждую модель в DTO «на всякий случай». DTO оправданы на границах приложения — например, HTTP/API, очереди или внешние системы — где требуется явная форма данных и независимость от состояния Eloquent. Laravel документирует, в частности, custom collections через CollectedBy или newCollection.
- Публичный контракт должен быть явным: типизированные параметры и возвращаемые значения, документированные настройки, стабильные имена конфиг-ключей и aliases только там, где они нужны потребителю. Читайте env() в config-файлах, а в коде обращайтесь к config(): после config:cache Laravel не загружает .env для запросов и Artisan-команд. Добавьте CI-проверку, которая собирает config cache и запускает smoke-тест с кэшированной конфигурацией.

## Тестирование как у потребителя

- Unit/feature-тесты пакета на Testbench проверяют интеграцию с Laravel без полного продукта; Laravel прямо рекомендует его для тестирования пакета в окружении, похожем на установленное приложение.
- Добавьте отдельную проверку consumer install: соберите дистрибутив, установите его в минимальное временное Laravel-приложение из локального Composer-репозитория/архива, разрешите зависимости так, как это сделает пользователь, загрузите provider и выполните тест миграции/публичного API. Тесты из рабочего дерева не заменяют эту проверку.
- Проверьте optional packages отдельно: установка базового пакета без необязательных интеграций должна проходить; затем — отдельные сценарии с каждой поддерживаемой optional dependency. В composer.json не помещайте optional dependency в require, если базовый пакет может работать без неё; проверяйте ветвь поведения при её отсутствии. Аналогично для JS: тестируйте базовый пакет отдельно от optional peer dependencies.
- Для миграций проверяйте upgrade именно с предыдущего опубликованного артефакта: развернуть его на тестовой БД, создать репрезентативные данные, установить новый артефакт, запустить migrate, проверить схему и сохранность/преобразование данных. Проверка migrate:fresh доказывает только создание пустой базы, а не обновление существующей.

## Зависимости и матрицы

- Composer name — идентификатор пакета вида vendor/package, заданный в composer.json; это не обязано совпадать с GitHub owner или именем репозитория. В документации и release metadata указывайте отдельно composer package name и repository URL.
- Path repository удобен для разработки монорепозитория, но в Composer 2 репозитории по умолчанию canonical: пакет, найденный раньше в path repo, может замаскировать одноимённую версию из нижестоящего репозитория. Поэтому CI должен иметь два режима: локальная разработка через path repo и consumer install без него, с опубликованным архивом/VCS-тегом. Если намеренно разрешаете поиск ниже, настраивайте canonical осознанно, а не лечите случайное разрешение версий.
- Матрицу PHP/Laravel/Node выводите из require, require-dev, peerDependencies, engines, lockfiles и реально поддерживаемых диапазонов. Проверяйте минимальные заявленные сочетания и необходимые крайние версии; не подставляйте «latest» или версии, которых пакет не обещает поддерживать. Укажите точные версии инструментов CI, включая Composer/pnpm, чтобы результат был воспроизводим.
- В CI запускайте pnpm install --frozen-lockfile; он должен завершаться ошибкой, если manifest и lockfile расходятся. Зафиксируйте pnpm через packageManager/версию setup и тестируйте сборку и тесты на Node-матрице, полученной из engines и поддерживаемой политики проекта.

## Совместимость и релизы

- Roave BackwardCompatibilityCheck — полезный сигнал для сравнения PHP API двух ревизий библиотеки, но не доказательство полной совместимости: он не проверяет поведение, SQL-эффекты, конфигурационные значения, frontend API или все интеграции Laravel. Сверяйте его с заявленной политикой BC и consumer/upgrade-тестами; исключения API должны быть явными и минимальными.
- Для набора независимо выпускаемых пакетов Changesets подходит как механизм декларации затронутых пакетов, semver bump и changelog. В monorepo добавляйте changeset только для изменений, меняющих опубликованное поведение/API; внутренний рефакторинг без изменения артефакта не обязан порождать релиз.
- Для release artifact проверяйте конкретно: состав файлов (composer archive/pnpm pack --dry-run), отсутствие тестовых секретов и dev-only файлов, наличие provider/config/migrations, соответствие lockfile и манифестов, consumer install из полученного архива, тест обновления с предыдущего артефакта и опубликованные версии/теги. Не ограничивайтесь зелёным CI на исходниках.
- Для GitHub Actions можно прикреплять provenance attestations к артефактам. Проверяйте, что attestation относится к ожидаемому workflow, репозиторию, commit и именно опубликованному digest; provenance связывает артефакт с происхождением сборки, но само по себе не доказывает отсутствие вредоносного кода.

Sources:
1. [Deptrac: проект и документация](https://github.com/deptrac/deptrac)
2. [Eloquent Collections](https://laravel.com/framework/docs/12.x/eloquent-collections)
3. [Laravel Configuration](https://laravel.com/framework/docs/configuration)
4. [Laravel Package Development](https://laravel.com/framework/docs/packages)
5. [Testbench](https://github.com/orchestral/testbench)
6. [Laravel Migrations](https://laravel.com/framework/docs/migrations)
7. [Composer schema](https://github.com/composer/composer/blob/master/res/composer-schema.json)
8. [Composer repositories](https://getcomposer.org/doc/05-repositories.md)
9. [Repository priorities](https://getcomposer.org/doc/articles/repository-priorities.md)
10. [pnpm install](https://pnpm.io/cli/install)
