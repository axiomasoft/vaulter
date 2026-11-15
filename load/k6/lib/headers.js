// Case-insensitive response header lookup (k6 preserves the server's
// original header casing in `res.headers`, and the tus/S3 endpoints' casing is not a
// contract worth relying on byte-for-byte).
export function header(res, name) {
  const wanted = name.toLowerCase();

  for (const key of Object.keys(res.headers || {})) {
    if (key.toLowerCase() === wanted) {
      return res.headers[key];
    }
  }

  return null;
}
