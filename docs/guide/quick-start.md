# Quick Start

## 1. Register a permission resolver

DocVault delegates every authorization check to your application. Implement
`DocVault\Contracts\PermissionResolver` and register it:

```php
// app/Providers/AppServiceProvider.php
use DocVault\Facades\DocVault;
use App\DocVault\PermissionResolver;

public function boot(): void
{
    DocVault::resolvePermissionsWith(new PermissionResolver());
}
```

## 2. Add the components to a page

```js
// resources/js/app.js
import { DocVaultPlugin } from '@docvault/vue'

app.use(DocVaultPlugin, { baseUrl: '/api/docvault' })
```

```vue
<DocVaultFileManager
  workspace-type="App\Models\Project"
  :workspace-id="project.id"
  @open-document="openEditor"
/>
```

## 3. Edit a document

```vue
<DocVaultEditor :document-id="documentId" :collaborative="true" />
```

That's the intended end-state. See [Configuration](/guide/configuration) for the available
options and [Collaboration](/guide/collaboration) for the real-time setup.
