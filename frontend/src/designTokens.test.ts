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
      '--color-state-warning',
      '--color-state-warning-soft',
      '--color-state-warning-strong',
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

  it('uses one colored, interactive size for every addition action', () => {
    expect(appStyles).toMatch(
      /\.add-button\s*\{[^}]*background:\s*var\(--color-accent\);[^}]*font-family:\s*var\(--font-body\);[^}]*font-size:\s*0\.78rem;[^}]*height:\s*36px;[^}]*width:\s*132px;/s,
    )
    expect(appStyles).toMatch(
      /\.add-button-icon\s*\{[^}]*stroke:\s*currentcolor;[^}]*width:\s*15px;/s,
    )
    expect(appStyles).toMatch(
      /\.add-button:hover:not\(:disabled\)\s*\{[^}]*background:\s*var\(--color-accent-hover\);[^}]*transform:\s*translateY\(-1px\);/s,
    )
  })

  it('keeps localized action labels on one line with wider compact controls', () => {
    expect(appStyles).toMatch(
      /\.primary-button\s*\{[^}]*font-size:\s*0\.84rem;[^}]*white-space:\s*nowrap;/s,
    )
    expect(appStyles).toMatch(
      /\.compact-button\s*\{[^}]*min-width:\s*92px;/s,
    )
  })

  it('draws site-styled checkboxes, radios, and warning unknown states', () => {
    expect(appStyles).toMatch(
      /\.choice-control > span::before\s*\{[^}]*border:\s*1px solid var\(--color-border\);[^}]*height:\s*18px;/s,
    )
    expect(appStyles).toMatch(
      /\.choice-control > input\[type="checkbox"\]:checked \+ span::before\s*\{[^}]*background:\s*var\(--color-accent\);/s,
    )
    expect(appStyles).toMatch(
      /\.choice-control > input\[type="radio"\]:checked \+ span::before\s*\{[^}]*box-shadow:\s*inset 0 0 0 4px var\(--color-surface-elevated\);/s,
    )
    expect(appStyles).toMatch(
      /\.unknown-toggle-button\[aria-pressed="true"\]\s*\{[^}]*background:\s*var\(--color-state-warning-soft\);[^}]*border-color:\s*var\(--color-state-warning\);/s,
    )
  })

  it('keeps every active date field on one row', () => {
    expect(appStyles).toMatch(
      /\.date-input-row\s*\{[^}]*display:\s*flex;[^}]*min-width:\s*0;/s,
    )
    expect(appStyles).toMatch(
      /\.date-controls\s*\{[^}]*display:\s*flex;[^}]*flex:\s*1 1 auto;/s,
    )
    expect(appStyles).toMatch(
      /\.approximate-toggle\s*\{[^}]*justify-self:\s*start;/s,
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
