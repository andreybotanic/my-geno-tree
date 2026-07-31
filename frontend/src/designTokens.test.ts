/// <reference types="node" />

import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const appStyles = readFileSync(resolve('src/App.css'), 'utf8')
const tokens = readFileSync(resolve('src/index.css'), 'utf8')
describe('semantic design contracts', () => {
  it('defines reusable palette tokens for a future theme', () => {
    for (const token of [
      '--color-background',
      '--color-surface',
      '--color-text',
      '--color-border',
      '--color-placeholder',
      '--color-graph-line',
      '--color-shadow',
      '--color-backdrop',
      '--shadow-dialog',
    ]) {
      expect(tokens).toContain(`${token}:`)
      expect(appStyles).toContain(`var(${token})`)
    }

    expect(appStyles).not.toMatch(/#[\da-f]{3,8}\b|(?:rgb|hsl)a?\(/i)
  })

  it('keeps the authenticated avatar inside the fixed-height header', () => {
    expect(appStyles).toMatch(
      /\.app-header\s*\{[^}]*height:\s*50px;/s,
    )
    expect(appStyles).toMatch(
      /\.avatar-button\s*\{[^}]*height:\s*34px;[^}]*width:\s*34px;/s,
    )
  })
})
