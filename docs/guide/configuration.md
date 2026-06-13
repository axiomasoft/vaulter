# Configuration

Publish the config file with `php artisan vendor:publish --tag=docvault-config`. It lands at
`config/docvault.php`:

| Key | Default | Description |
|---|---|---|
| `permission_resolver` | `null` | FQCN of your `PermissionResolver`, or register it at runtime. |
| `storage_adapter` | `DefaultStorageAdapter::class` | Pluggable storage for binary attachments. |
| `route_prefix` | `api/docvault` | Prefix for all API routes. |
| `middleware` | `['api']` | Middleware applied to the API routes. |
| `max_versions` | `50` | Max stored versions per document (`0` = unlimited). |
| `document_types` | `['richtext', 'table', 'markdown', 'template']` | Enabled document types. |
| `collaboration.enabled` | `true` | Toggle real-time collaboration. |
| `collaboration.server_url` | `ws://localhost:1234` | Hocuspocus WebSocket URL. |
| `max_document_size_kb` | `5120` | Per-document size limit. |

## Environment variables

```env
DOCVAULT_COLLAB_URL=ws://your-hocuspocus-server:1234
DOCVAULT_COLLAB_APP_ID=your-app-id
DOCVAULT_COLLAB_SECRET=your-secret-key
```
