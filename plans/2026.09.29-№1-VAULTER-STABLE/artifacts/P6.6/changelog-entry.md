### Filament through the drive handle (PLAN2.P6.6)

- Changed: the node and drive resources read and write through `Vaulter::drive($drive)->actingAs(PanelActor::current())`
  and its snapshot DTOs (grant, share and activity resources already use the core operations with the panel actor). `NodeResource` is a custom-records table (`Table::records()`) fed by the handle's listings;
  row actions are shown from the handle's abilities and executed by the operations, which re-authorize. A move needs
  Move on the node and Create on the destination; the destination list offers only folders the admin may create into.
- Removed: `Vaulter\Filament\Support\PanelAccessScope` (no Eloquent access scope remains in the panel), the
  grant-candidate query behind the node and drive tables, and the `ListNodes` page's own exact-visibility pass.
- Changed: a drive is listed when the admin can view its root node; a grant on a nested folder only does not make
  the drive appear in the panel (it still works through the API). The node table shows at most 2000 nodes.
- Added: `FilamentBoundaryArchTest` — the `Vaulter\Filament` namespace uses no Actions, Repositories or `AccessResolver`.
