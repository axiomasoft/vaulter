---
title: Drive Profiles
description: Named drive policies over one shared Vaulter schema.
outline: deep
---

# Drive Profiles

A `Vaulter\Profiles\Profile` names a drive policy for a host entity. Profiles share
one schema. Register providers with `vaulter.profiles.providers`; the default provider
is `vaulter.profiles.default`. The persisted discriminator is `profile_key`.
`Vaulter::for($owner, 'project')` opens the drive for that owner and profile.

## Configure a provider

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

Register the FQCN in `config/vaulter.php`:

```php
'profiles' => ['providers' => [App\Vaulter\Profiles\ProjectProfile::class]],
```

Profiles are hydrated during application boot. The default profile is registered
first; duplicate profile IDs fail boot validation. Unknown IDs throw
`Vaulter\Exceptions\Profiles\UnknownProfileException`.

## Registry

```php
$profile = Vaulter::profiles()->get('project');
Vaulter::profiles()->registerProvider(new ProjectProfile);
Vaulter::profiles()->register((new Profile)->id('reports'));
$profiles = Vaulter::profiles()->all();
```

The registry exposes `register`, `registerProvider`, `fromConfig`, `get`, `has`, `all`,
and `default`. `Vaulter::panel()/panels()/registerPanel()` have been replaced by this registry.

## Profile builder

| Method | Meaning |
|:--|:--|
| `id(string)` / `getId()` | Named profile ID |
| `host(string)` / `hostModel()` | Host model FQCN |
| `grants()` / `withoutGrants()` / `hasGrants()` | Grants policy |
| `storageType(string)` / `storageTypeKey()` | Current storage policy preset |
| `features(array)` / `feature(string, bool)` / `featureEnabled(string)` | Profile feature settings |

Own-tables settings, per-profile ID strategies, model maps, pipelines and resource hooks
are removed. Profiles do not define separate table names or generate migrations.
`booted()` previously ran after provider registration and is now removed; move any
host initialization to your Laravel service provider. Runtime enforcement of all
profile policies is delivered separately; declaring a feature here does not promise
that every existing operation already enforces it.

## Scaffold a provider

```bash
php artisan vaulter:make:profile project --host="App\Models\Project" --no-grants
```

This writes `app/Vaulter/Profiles/ProjectProfile.php` without modifying config or migrations.
Use `--force` to overwrite an existing provider. Own-tables `--prefix/--id` options and
`vaulter:panel:migrations` are removed. See UPGRADE.md for the complete breaking API map.
