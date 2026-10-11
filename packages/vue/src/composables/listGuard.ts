/**
 * Ordering of async list results against newer local state. A list response is a snapshot of
 * the moment the server answered; anything the user did locally in between (an add, a rename,
 * a newer refresh) is newer than it, so the snapshot must not overwrite it.
 *
 *  - `begin()` starts a (re)load; only the newest load may apply its page.
 *  - `invalidate()` is called by a local mutation: loads started before it are history.
 *  - `beginMore()` starts a "next page" request: single-flight, and dropped when a reload or a
 *    mutation happened while it was in flight.
 */
export interface ListGuard {
  begin: () => number
  /** True when no (re)load is left in flight — the moment `loading` may go false. */
  end: () => boolean
  isCurrent: (token: number) => boolean
  invalidate: () => void
  beginMore: () => number | null
  endMore: () => void
}

export function createListGuard(): ListGuard {
  let seq = 0
  let inflight = 0
  let moreInFlight = false

  return {
    begin() {
      inflight += 1

      return ++seq
    },
    end() {
      inflight -= 1

      return inflight === 0
    },
    isCurrent: (token) => token === seq,
    invalidate() {
      seq += 1
    },
    beginMore() {
      if (moreInFlight) {
        return null
      }
      moreInFlight = true

      return seq
    },
    endMore() {
      moreInFlight = false
    },
  }
}
