# Quick Start

## 1. Register a permission resolver

Vaulter delegates every authorization check to your application. Implement
`Vaulter\Contracts\PermissionResolver` and register it:

```php
// app/Providers/AppServiceProvider.php
use Vaulter\Facades\Vaulter;
use App\Vaulter\PermissionResolver;

public function boot(): void
{
    Vaulter::resolvePermissionsWith(new PermissionResolver());
}
```

## 2. Add the components to a page

```js
// resources/js/app.js
import { VaulterPlugin } from '@vaulter/vue'

app.use(VaulterPlugin, { baseUrl: '/api/vaulter' })
```

```vue
<VaulterFileManager
  workspace-type="App\Models\Project"
  :workspace-id="project.id"
  @open-document="openEditor"
/>
```

## 3. Edit a document

```vue
<VaulterEditor :document-id="documentId" :collaborative="true" />
```

That's the intended end-state. See [Configuration](/guide/configuration) for the available
options and [Collaboration](/guide/collaboration) for the real-time setup.
