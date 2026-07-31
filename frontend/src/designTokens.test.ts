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
      /\.profile-trigger-avatar\s*\{[^}]*height:\s*34px;[^}]*width:\s*34px;/s,
    )
  })

  it('uses sans-serif typography throughout the application', () => {
    expect(tokens).toContain('--font-heading: var(--font-body);')
    expect(tokens).not.toMatch(/\b(?:Georgia|Times New Roman|ui-serif)\b/)
  })

  it('keeps the editor panel on the left and death controls on their own row', () => {
    expect(appStyles).toMatch(
      /\.editor-workspace-panel-open\s*\{[^}]*grid-template-columns:\s*390px minmax\(0, 1fr\);/s,
    )
    expect(appStyles).toMatch(
      /\.person-details-panel\s*\{[^}]*border-right:\s*1px solid var\(--color-border\);/s,
    )
    expect(appStyles).toMatch(
      /\.death-date-row\s*\{[^}]*grid-column:\s*1 \/ -1;/s,
    )
  })

  it('centers timeline dots on their vertical line', () => {
    expect(appStyles).toMatch(
      /\.event-timeline li::before\s*\{[^}]*left:\s*-1px;[^}]*transform:\s*translateX\(-50%\);/s,
    )
  })

  it('uses a shared inline SVG for addition actions', () => {
    expect(appStyles).toMatch(
      /\.add-button\s*\{[^}]*display:\s*inline-flex;[^}]*gap:\s*7px;/s,
    )
    expect(appStyles).toMatch(
      /\.add-button-icon\s*\{[^}]*stroke:\s*currentcolor;[^}]*width:\s*15px;/s,
    )
  })

  it('keeps localized action labels on one line with wider compact controls', () => {
    expect(appStyles).toMatch(
      /\.primary-button\s*\{[^}]*font-size:\s*0\.84rem;[^}]*white-space:\s*nowrap;/s,
    )
    expect(appStyles).toMatch(
      /\.compact-button\s*\{[^}]*min-width:\s*92px;/s,
    )
    expect(appStyles).toMatch(
      /\.event-heading \.compact-button,[^{]*\{[^}]*font-size:\s*0\.78rem;[^}]*min-width:\s*132px;/s,
    )
  })

  it('uses a larger portrait card without doubled section dividers', () => {
    expect(appStyles).toMatch(
      /\.person-card\s*\{[^}]*grid-template-columns:\s*96px minmax\(0, 1fr\);[^}]*height:\s*160px;[^}]*width:\s*260px;/s,
    )
    expect(appStyles).toMatch(
      /\.person-photo-placeholder\s*\{[^}]*height:\s*128px;[^}]*width:\s*96px;/s,
    )
    expect(appStyles).toMatch(
      /\.person-details-section > \.person-detail-row:last-child,[^{]*\{[^}]*border-bottom:\s*0;/s,
    )
  })
})
