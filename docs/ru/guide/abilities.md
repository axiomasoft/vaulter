# Права (abilities) — интеграция с фронтендом

Vaulter вычисляет права на документ на стороне сервера и отдаёт их фронтенду, поэтому
ваши Vue-компоненты отрисовывают только те действия, которые пользователю доступны. Никаких
лишних обращений к API, никакого дублирования логики прав в JavaScript.

## В ответах API

`DocumentResource` встраивает объект `abilities`, вычисленный вашим `PermissionResolver`:

```json
{
  "data": {
    "id": 1,
    "title": "Q3 Proposal",
    "abilities": { "view": true, "comment": true, "edit": true, "manage": false, "delete": false }
  }
}
```

Vue-редактор читает `document.abilities.edit`, чтобы решить, монтироваться ли в режиме только для чтения.

## Свой DTO прав

Отнаследуйтесь от `Vaulter\Abilities\AbilitiesDto` и сопоставьте ключи прав с действиями резолвера:

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

Прокидывайте права для документов текущей страницы как Inertia-пропсы и биндите их прямо во Vue —
булевы значения уже вычислены на стороне сервера.
