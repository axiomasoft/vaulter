### Hub on REST v1 (PLAN2.P6.5)

- Changed: the hocuspocus hub calls `{base}/collaboration/hub/{authenticate,load,store}` (was `{base}/collab/*`).
  `VAULTER_API_URL` (or `VAULTER_LARAVEL_WEBHOOK_URL`) is the versioned base and must end with `/v1`; a base
  without it fails the start. Derived from `VAULTER_BACKEND_URL` + `VAULTER_API_PREFIX` it gains `/v1` itself.
  A 409 `collaboration_epoch_mismatch` still closes the room with 4409 and is never retried.

