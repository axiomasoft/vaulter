---
title: Glossary
---

# Glossary

| Term | Meaning |
| --- | --- |
| **Drive** | The file space of one owner (any Eloquent model using `HasDrives`). Opened with `Vaulter::drive($owner)`. |
| **Profile** | A named policy of a drive: disk, quota, upload rules, permission driver, enabled features. See [Drives and profiles](/concepts/drives-and-profiles). |
| **Node** | A folder, file or document in a drive's tree. |
| **Revision** | One immutable version of a file's or document's content. |
| **Blob** | Content-addressed bytes in `vaulter-media`, shared according to the dedup scope. |
| **Dedup scope** | Where identical bytes may share one blob: `tenant`, `drive` or `off`. |
| **Tenant** | An isolation boundary of a multi-tenant host. See [Tenancy](/concepts/tenancy). |
| **Actor** | The user a drive handle acts for (`actingAs`). |
| **Grant** | A per-node permission given to a subject. |
| **Ability** | A computed capability (`view`, `comment`, `edit`, `manage`, `delete`) shipped to the client. |
| **Permission driver** | The component that decides access: `database`, `gate`, `spatie`, `null` or your own. |
| **Outbox** | Event rows written in the same transaction as the change; the source of webhook deliveries. |
| **Delivery** | One attempt series of sending one event to one webhook endpoint. |
| **Scan obligation** | The durable duty to obtain a malware-scan verdict for a file revision. |
| **Inspector** | An `UploadInspector` that checks an upload before it is accepted. |
| **Hub** | The optional Node service (`@vaulter/collaboration-hub`, Hocuspocus + Yjs) that serves real-time collaboration through the `vaulter-collaboration` module. |
| **Receipt** | The backend's committed acknowledgement of one collaboration update; only a receipt makes an edit *saved*. |
| **Checkpoint** | The merged Yjs state and JSON projection the hub publishes from the update log; the log up to it is then deleted. |
| **Pending marker** | `(generation, through_seq)` on a document whose saved collaboration edits are not in a checkpoint yet; the content is not served or replaced meanwhile (`document_state_pending`). |
