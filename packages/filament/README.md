# axiomasoft/vaulter-filament

Filament 5 administration for Vaulter drives, nodes, grants, share links and activity.
PHP 8.4+ and Laravel 13 are required. This is the only Vaulter package that depends on Filament.

```sh
composer require axiomasoft/vaulter-filament
```

Register `Vaulter\Filament\VaulterPlugin::make()` in your Filament panel's `plugins()` list.
The plugin implements Packman's `FilamentPanelPlugin`; resources use its shared authorization
hooks. Resource access requires an authenticated authorizable actor. Hosts can define Laravel
Gate abilities such as `vaulter.drive.view_any` to restrict entry. Drive handles separately
filter every listed snapshot and authorize each mutation against the acting user.

Configuration lives in `vaulter-filament.php`: `navigation_group` and the `resources` map
(`drive`, `node`, `grant`, `share`, `activity`). `FilamentOptions::fromArray()` produces an
immutable snapshot and rejects unknown keys. The package module can be disabled through
`vaulter.modules.filament`; its runtime bindings and hooks then remain inactive.

See [the Filament guide](../../docs/guides/filament.md) and
[the migration decision](../../docs/adr/0039-native-packman-extensions.md).
