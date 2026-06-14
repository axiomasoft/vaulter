import 'dotenv/config'
import { Server } from '@hocuspocus/server'

// DocVault collaboration server (skeleton).
//
// Flow (docvault-spec.md §10):
//   1. The frontend requests a JWT from the Laravel backend
//      (POST /api/docvault/documents/{id}/collab/token).
//   2. DocVaultEditor connects here over WebSocket with that token.
//   3. onAuthenticate validates the token against the Laravel webhook.
//   4. Yjs syncs changes; snapshots are persisted back to the backend on save.
//
// Auth/persistence are intentionally stubbed for the scaffold — see the TODOs.

const port = Number(process.env.DOCVAULT_COLLAB_PORT ?? 1234)

const server = new Server({
  port,

  async onAuthenticate({ token }) {
    // TODO (M3): verify `token` against the Laravel backend webhook using
    // DOCVAULT_COLLAB_APP_ID / DOCVAULT_COLLAB_SECRET, return { user }.
    if (!token) {
      throw new Error('Missing collaboration token')
    }
    return {}
  },

  async onStoreDocument() {
    // TODO (M3): POST the Yjs snapshot back to the Laravel backend so it lands
    // in dv_documents.content (and optionally dv_document_versions).
  },
})

server.listen().then(() => {
  // eslint-disable-next-line no-console
  console.log(`DocVault Hocuspocus server listening on ws://0.0.0.0:${port}`)
})
