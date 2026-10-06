---
title: Профили Drive
description: Именованные политики Drive поверх общей схемы Vaulter.
outline: deep
---

# Профили Drive

`Vaulter\Profiles\Profile` задаёт именованную политику Drive для сущности хоста.
Все профили используют общую схему. Провайдеры задаются в `vaulter.profiles.providers`, профиль по умолчанию — в
`vaulter.profiles.default`. Сохранённый дискриминатор — `profile_key`.
`Vaulter::for($owner, 'project')` открывает Drive владельца в указанном профиле.

## Провайдер профиля

```php
namespace App\Vaulter\Profiles;

use App\Models\Project;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

final class ProjectProfile extends ProfileProvider
{
    public function profile(Profile $profile): Profile
    {
        return $profile->id('project')->host(Project::class)->storageType('team')
            ->withoutGrants()->feature('share_links', true);
    }
}
```

Зарегистрируйте FQCN в `config/vaulter.php`:

```php
'profiles' => ['providers' => [App\Vaulter\Profiles\ProjectProfile::class]],
```

Профили разрешаются при загрузке приложения. Профиль по умолчанию регистрируется
первым; дублирующиеся ID профилей отвергаются при boot. Неизвестный ID вызывает
`Vaulter\Exceptions\Profiles\UnknownProfileException`.

## Реестр

```php
$profile = Vaulter::profiles()->get('project');
Vaulter::profiles()->registerProvider(new ProjectProfile);
Vaulter::profiles()->register((new Profile)->id('reports'));
$profiles = Vaulter::profiles()->all();
```

Доступны `register`, `registerProvider`, `fromConfig`, `get`, `has`, `all`, `default`.
Вызовы `Vaulter::panel()/panels()/registerPanel()` заменены доступом к реестру.

## Настройки профиля

| Метод | Назначение |
|:--|:--|
| `id(string)` / `getId()` | Именованный ID профиля |
| `host(string)` / `hostModel()` | FQCN модели хоста |
| `grants()` / `withoutGrants()` / `hasGrants()` | Политика грантов |
| `storageType(string)` / `storageTypeKey()` | Текущий пресет политики хранилища |
| `features(array)` / `feature(string, bool)` / `featureEnabled(string)` | Флаги функций профиля |

Собственные таблицы, стратегии ID на уровне профиля, карты моделей, pipelines и
хуки ресурсов удалены. Профиль больше не задаёт имена таблиц и не генерирует миграции.
Ранее `booted()` вызывался после регистрации провайдера; теперь он удалён.
Инициализацию хоста перенесите в Laravel service provider. Полное применение
политик профиля в операциях будет реализовано отдельно: объявление флага само
по себе пока не гарантирует его проверку каждой операцией.

## Создание провайдера

```bash
php artisan vaulter:make:profile project --host="App\Models\Project" --no-grants
```

Команда создаёт `app/Vaulter/Profiles/ProjectProfile.php`. Конфиг и миграции она не
меняет. Для перезаписи существующего провайдера используйте `--force`.
Опции `--prefix/--id` и команда `vaulter:panel:migrations` удалены.
Полная карта изменений API приведена в UPGRADE.md.
