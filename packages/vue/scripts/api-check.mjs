// Regenerates the API types into a temp file and fails when src/api/schema.d.ts differs
// (or is not tracked by git): the committed types must always match openapi/vaulter-v1.yaml.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const committed = 'src/api/schema.d.ts'
const dir = mkdtempSync(join(tmpdir(), 'vaulter-api-'))
const generated = join(dir, 'schema.d.ts')

function fail(message) {
  console.error(message)
  process.exitCode = 1
}

try {
  execFileSync('npx', ['openapi-typescript', '../../openapi/vaulter-v1.yaml', '-o', generated], { stdio: 'inherit' })

  if (readFileSync(generated, 'utf8') !== readFileSync(committed, 'utf8')) {
    fail(`${committed} is stale: run \`npm run api:generate\` and commit the result.`)
  } else {
    try {
      execFileSync('git', ['ls-files', '--error-unmatch', committed], { stdio: 'ignore' })
    } catch {
      fail(`${committed} is not tracked by git: add it to the commit.`)
    }
  }
} catch {
  fail('openapi-typescript failed to generate the types.')
} finally {
  rmSync(dir, { recursive: true, force: true })
}
