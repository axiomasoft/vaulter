I’ll verify only the listing-scope correction against the round-1 leak: authoritative drives must stay narrowed, and open drives must stay open.## Verdict: GREEN

The round-1 leak is closed. `grantFilteringApplies()` stays true when any registered profile still has grant narrowing (`NodeReadRepository.php:492-503`). `candidateNodeIds()` then unions two sets (`NodeReadRepository.php:525-579`):

- Grant anchors and their descendants stay limited to drives whose selected driver is grant-authoritative and whose grants feature is on.
- Node ids from only the other drives — a non-authoritative driver, or grants disabled — are added beside that set.

A sibling `gate` or `grants(false)` profile no longer makes the predicate false. `PanelAccessScope` therefore keeps a real id list (`PanelAccessScope.php:43-47`), and `DriveResource`, `NodeGrantResource`, `NodeShareResource`, and `ActivityResource` keep their `whereIn` predicates. An authoritative drive with no grant for the actor stays out of that list, so its drive row, grant, share, and activity rows stay out of those queries.

A pure open installation still returns false from `grantFilteringApplies()`, so `PanelAccessScope` returns null and `constrainToAccessible()` leaves the caller query unchanged (`NodeReadRepository.php:421-422`). The open drive in a mixed installation is still included through `openIds`, including when the authoritative side has anchors and the union is taken. Exact node checks are unchanged.

`validation.json#correction` records exit 0 for the 35-test core listing slice, the 6-test Filament listing file, core and Filament PHPStan, and Pint on the two changed files. This review did not re-run them.
