import { spawn } from 'node:child_process'
import process from 'node:process'

const children = [
  spawn(process.execPath, ['server.mjs'], { stdio: 'inherit' }),
  spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5193', '--strictPort'], { stdio: 'inherit' }),
]
let stopping = false
function stop(code = 0) {
  if (stopping) return
  stopping = true
  for (const child of children) child.kill('SIGTERM')
  process.exitCode = code
}
for (const child of children) child.on('exit', code => stop(code ?? 1))
process.on('SIGINT', () => stop())
process.on('SIGTERM', () => stop())
