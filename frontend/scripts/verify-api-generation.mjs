import { createHash } from 'node:crypto'
import { readdir, readFile } from 'node:fs/promises'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawn } from 'node:child_process'

const generatedDirectory = fileURLToPath(new URL('../src/api/generated/', import.meta.url))

async function snapshotDirectory(directory, relativePath = '') {
  const entries = await readdir(directory, { withFileTypes: true })
  const snapshot = []

  for (const entry of entries.sort((left, right) => left.name.localeCompare(right.name))) {
    const entryRelativePath = `${relativePath}${entry.name}`
    const entryPath = join(directory, entry.name)

    if (entry.isDirectory()) {
      snapshot.push(...await snapshotDirectory(entryPath, `${entryRelativePath}/`))
      continue
    }

    if (entry.isFile()) {
      const content = await readFile(entryPath)
      snapshot.push(`${entryRelativePath}:${createHash('sha256').update(content).digest('hex')}`)
    }
  }

  return snapshot
}

function runApiGeneration() {
  const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm'

  return new Promise((resolve, reject) => {
    const child = spawn(npmCommand, ['run', 'api:generate'], { stdio: 'inherit' })

    child.once('error', reject)
    child.once('exit', (code) => resolve(code ?? 1))
  })
}

const before = await snapshotDirectory(generatedDirectory)
const exitCode = await runApiGeneration()

if (exitCode !== 0) {
  process.exit(exitCode)
}

const after = await snapshotDirectory(generatedDirectory)

if (before.join('\n') !== after.join('\n')) {
  console.error('Generated API files are stale. Commit the changes in src/api/generated.')
  process.exit(1)
}
