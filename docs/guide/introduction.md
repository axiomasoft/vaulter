# What is DocVault?

**DocVault** is an open-source Laravel package for embedding a complete document-management
system into an existing application. It grew out of the collaborative-editing module in
FlexCRM, where a TipTap editor syncs through a server hub with JSON version storage in the
database. DocVault lifts that infrastructure into a reusable, framework-agnostic-as-possible
component you can drop into any Laravel project with its own role system.

## Key concepts

| Concept | Description |
|---|---|
| **Document** | A unit of content (text, table, presentation…) stored as TipTap JSON. |
| **Version** | A snapshot taken on save; the chain of versions forms the history. |
| **Folder** | A hierarchical folder tree (Google Drive / Yandex Disk style). |
| **Workspace** | An isolated document space bound to a host entity (organization, project, user). |
| **Permission Context** | The adapter through which DocVault talks to your role system. |

## What DocVault does not do

- It does **not** implement its own authentication — it integrates through an adapter.
- It does **not** store binary files itself — only JSON content; binary attachments go
  through a `StorageAdapter`.

## Requirements

- PHP `^8.4`, Laravel `^12` or `^13`
- Vue `3` for the frontend components
- Node `>=20` for the collaboration server / asset build
