# Abilities (frontend integration)

Vaulter computes per-node permissions server-side (from the actor's [roles and grants](/concepts/access-model#abilities-and-roles)) and ships them to the front-end, so
your Vue components render only the actions the user may perform. No extra API round-trips,
no permission logic duplicated in JavaScript.

## In API responses

Every node resource embeds an `abilities` object — one boolean per ability, resolved for the
authenticated actor:

```json
{
  "data": {
    "id": "01J...",
    "name": "Q3 Proposal",
    "abilities": {
      "view": true, "download": true, "comment": true, "edit": true, "create": true, "move": true,
      "copy": true, "trash": true, "restore": false, "share": true, "manage": false, "delete": false
    }
  }
}
```

`GET /nodes/{node}/abilities` returns the same object on its own. The Vue editor reads
`abilities.edit` to decide whether to mount in read-only mode.

## In PHP

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#abilities-read -->

```php
$abilities = $drive->nodes()->abilities($node);   // NodeAbilities
$canEdit = $abilities->flags['edit'];             // ability name => allowed

// One resolver pass for a whole listing instead of one call per row.
$byNode = $drive->nodes()->abilitiesMany([$node, $other]);   // node id => NodeAbilities
```

Without an acting user every flag is `false`: the client gets a read-only shell rather than
actions it may not run.

## Inertia

Share the abilities of the current page's nodes as Inertia props and bind directly in Vue —
the booleans are already resolved server-side.
