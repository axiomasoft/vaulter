# Filament admin

`axioma-studio/docvault-filament` is an optional Filament 5 plugin that adds DocVault management to
your existing Filament panel — list, search, edit, and trash/restore/force-delete documents.

```bash
composer require axioma-studio/docvault-filament
```

Register the plugin in your panel provider:

```php
use DocVault\Filament\DocVaultPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        DocVaultPlugin::make(),
    ]);
}
```

That adds a **Documents** resource (under the `DocVault` navigation group) with:

- a searchable, sortable table (title, type, workspace, created date) with a trashed filter;
- an edit form for the document's metadata (title, type, workspace);
- delete / restore / force-delete row and header actions.

The resource resolves the configured model (`config('docvault.models.document')`), so it works with
swapped/ULID/tenant-aware models too.

## Why a separate package & CI

Filament is a heavy, optional dependency. Keeping the admin in its own package means apps that don't
use Filament never pull it in, and its CI runs in isolation against a Filament-compatible Laravel —
so it never constrains the core package's Laravel 12 / 13 test matrix.

::: tip Customising
Need folders, share-links or comment moderation in the panel too? Extend the plugin's `register()`
in a subclass and add your own Filament resources for `DocVault\Models\DocumentFolder`,
`DocumentShare`, etc. — they follow the same conventions.
:::
