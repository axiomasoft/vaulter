// The load-stand's own auth seam (LoadActingAs.php): the acting user
// is whatever `X-Load-Actor` carries, no session/CSRF/login.
export function actorHeaders(actorId) {
  return { 'X-Load-Actor': String(actorId) };
}
