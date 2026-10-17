---
title: Профили
---

# Профили

Профиль задаёт политику диска: хранилище, владельцев, квоту, правила загрузки, драйвер прав и
функции. Создайте провайдер:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:profile project_drive --host="App\Models\Project"
```

Провайдер — класс, наследующий `ProfileProvider`; поскольку это класс, а не замыкание, он переживает
`config:cache`.

<!-- snippet: signature Vaulter\Drive\Profiles\ProfileProvider -->

```php
abstract class ProfileProvider
{
    abstract public function profile(Profile $profile): Profile;

    public function resolve(): Profile;
}
```

Зарегистрируйте провайдер в `vaulter-drive.profiles.providers`. Профилю не нужна миграция: это конверт
конфигурации. См. [Диски и профили](/ru/concepts/drives-and-profiles).
