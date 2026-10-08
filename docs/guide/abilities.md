# Abilities (frontend integration)

Vaulter computes per-document permissions server-side (from the actor's [roles](./authorization#abilities-and-roles); `view` and `download` are separate abilities) and ships them to the front-end, so
your Vue components render only the actions the user may perform. No extra API round-trips,
no permission logic duplicated in JavaScript.

## In API responses

`DocumentResource` embeds an `abilities` object computed from your `PermissionResolver`:

```json
{
  "data": {
    "id": 1,
    "title": "Q3 Proposal",
    "abilities": { "view": true, "download": true, "comment": true, "edit": true, "manage": false, "delete": false }
  }
}
```

The Vue editor reads `document.abilities.edit` to decide whether to mount in read-only mode.

## Building your own ability DTO

Extend `Vaulter\Abilities\AbilitiesDto` and map ability keys to resolver actions:

```php
use Vaulter\Abilities\AbilitiesDto;
use Vaulter\VaulterManager;
use Vaulter\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ExportAbilities extends AbilitiesDto
{
    public function __construct(public bool $export) {}

    public static function for(?Authenticatable $user, Document $document, VaulterManager $manager): self
    {
        $flags = self::resolveFlags($user, $document, $manager, ['export' => 'manage']);

        return new self(export: $flags['export']);
    }

    public function toArray(): array
    {
        return ['export' => $this->export];
    }
}
```

## Inertia

Share abilities for the current page's documents as Inertia props and bind directly in Vue —
the booleans are already resolved server-side.
