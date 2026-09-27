# P8.4–P8.7 lifecycle reconciliation — 2026-09-20

## Evidence check

| Item | Historical terminal row | Write-site evidence | Spec-digest evidence | Reconciled status / owner |
|:--|:--|:--|:--|:--|
| P8.4 | journal line 30, `closed-green` | absent | absent | In progress; P8.4 reruns declared validation after D41 audit |
| P8.5 | line 31, `closed-green` | prose token present | absent | In progress; P8.5 reruns validation and emits digest-bound producer evidence |
| P8.6 | line 33, `closed-green` | prose token present | absent | In progress; P8.6 reruns validation and emits digest-bound producer evidence |
| P8.7 | line 34, `closed-deviations` | prose token present | absent; live evidence explicitly deferred | In progress; P8.7 owns one operator live-gate continuation |

Current spec SHA-256 values at reconciliation are P8.4
`e9639b3a91f47e2a57d30638975928eedee107bf7b3532157bd7958fc54991ba`, P8.5
`a8af9d29eafb37b353bea557956731b9c956db734abd8bbd4067431ed8f40f8b`, P8.6
`a8dd52f4b93287c7cd7d4b10737d737cecf08b6758c0bdac55259a6094298d1b`, and P8.7
`4e92a45cb7b63ac550628278974f75afb4754637e9f9f0eee9199f9e2f76ed6e`. None appears in the historical
terminal rows; prose claims therefore cannot bind those rows to the current specs.

## Repair

Append-only journal lines 47–50 open attempt 2 under D41. Generated item states are rebuilt from
those events and remain In progress. No terminal result or receipt is fabricated. P5/P6 consume P8.5
only after its new GREEN state; P3–P7 consume P8.7 only after its live finding and GREEN state.
