// The claims of a room JWT as the hub reads them: WITHOUT verifying the signature — the hub never
// holds the signing secret, and the backend verifies the token on every call. Local bookkeeping
// only (when to stop accepting messages, when to renew, which drive and tenant a connection belongs
// to once the backend accepted the token); a claim grants nothing on its own.

/** The claims of a JWT, unverified; malformed → null. */
export function decodeTokenClaims(token) {
  if (typeof token !== 'string') {
    return null
  }

  const segments = token.split('.')

  if (segments.length < 2) {
    return null
  }

  try {
    const payload = JSON.parse(Buffer.from(segments[1], 'base64url').toString('utf8'))

    return payload !== null && typeof payload === 'object' ? payload : null
  } catch {
    return null
  }
}

/** The `exp` (unix seconds) claim of a JWT, unverified (see decodeTokenClaims); missing → null. */
export function decodeTokenExpiry(token) {
  const exp = decodeTokenClaims(token)?.exp

  return typeof exp === 'number' ? exp : null
}
