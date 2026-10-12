---
title: Profiles
---

# Profiles

A profile names the policy of a drive: disk, owners, quota, upload rules, permission driver and
features. Scaffold a provider:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:profile project_drive --host="App\Models\Project"
```

A provider is a class that extends `ProfileProvider`; because it is a class and not a closure it
survives `config:cache`.

<!-- snippet: signature Vaulter\Profiles\ProfileProvider -->

```php
abstract class ProfileProvider
{
    abstract public function profile(Profile $profile): Profile;

    public function resolve(): Profile;
}
```

Register the provider in `vaulter.profiles.providers`. A profile needs no migration: it is a
configuration envelope. See [Drives and profiles](/concepts/drives-and-profiles).
