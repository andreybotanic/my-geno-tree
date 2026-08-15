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
      '--color-person-female-surface',
      '--color-person-male-surface',
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

  it('uses gender-specific surfaces for known-gender person cards', () => {
    expect(appStyles).toMatch(
      /\.person-card-female\s*\{[^}]*background:\s*var\(--color-person-female-surface\);/s,
    )
    expect(appStyles).toMatch(
      /\.person-card-male\s*\{[^}]*background:\s*var\(--color-person-male-surface\);/s,
    )
  })

  it('marks reference cards without an orange shadow', () => {
    expect(appStyles).toMatch(
      /(?:^|\n)\.person-card-reference\s*\{[^}]*border-color:\s*var\(--color-state-warning-strong\);/s,
    )
    expect(appStyles).not.toMatch(
      /(?:^|\n)\.person-card-reference\s*\{[^}]*box-shadow:/s,
    )
    expect(appStyles).not.toMatch(
      /\.person-card-selected\.person-card-reference\s*\{[^}]*var\(--color-state-warning\)/s,
    )
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

  it('keeps the date cancel control on the field label line', () => {
    expect(appStyles).toMatch(
      /\.person-detail-row:has\(\.date-fieldset\) \.inline-person-editor\s*\{[^}]*position:\s*static;/s,
    )
    expect(appStyles).toMatch(
      /\.person-detail-row:has\(\.date-fieldset\) \.inline-cancel-button\s*\{[^}]*top:\s*2px;/s,
    )
  })

  it('top-aligns panel controls and removes photo container side padding', () => {
    expect(appStyles).toMatch(
      /\.person-panel-heading\s*\{[^}]*align-items:\s*flex-start;/s,
    )
    expect(appStyles).toMatch(
      /\.person-panel-title\s*\{[^}]*align-items:\s*flex-start;/s,
    )
    expect(appStyles).toMatch(
      /\.person-photo-editor\s*\{[^}]*align-items:\s*flex-start;/s,
    )
    expect(appStyles).toMatch(
      /\.panel-photo-placeholder\s*\{[^}]*aspect-ratio:\s*3 \/ 4;[^}]*padding:\s*0;/s,
    )
    expect(appStyles).toMatch(
      /\.dev-copy-uid-button\s*\{[^}]*margin-top:\s*3px;/s,
    )
    expect(appStyles).toMatch(
      /\.dialog-close-icon\s*\{[^}]*fill:\s*none;[^}]*stroke:\s*currentcolor;/s,
    )
  })

  it('keeps the full-photo viewer fixed, centered, and scroll-free', () => {
    expect(appStyles).toMatch(
      /\.photo-viewer-dialog\s*\{[^}]*height:\s*min\(720px, calc\(100svh - 48px\)\);[^}]*overflow:\s*hidden;[^}]*width:\s*min\(920px, calc\(100vw - 48px\)\);/s,
    )
    expect(appStyles).toMatch(
      /\.photo-viewer-content\s*\{[^}]*align-items:\s*center;[^}]*display:\s*flex;[^}]*justify-content:\s*center;/s,
    )
    expect(appStyles).toMatch(
      /\.photo-viewer-dialog img\s*\{[^}]*max-height:\s*100%;[^}]*max-width:\s*100%;[^}]*object-fit:\s*contain;/s,
    )
  })

  it('aligns the first event line with its dot and connects only adjacent dots', () => {
    expect(appStyles).toMatch(
      /\.event-timeline li\s*\{[^}]*min-height:\s*50px;/s,
    )
    expect(appStyles).toMatch(
      /\.event-timeline li::before\s*\{[^}]*left:\s*0\.5px;[^}]*top:\s*0;[^}]*transform:\s*translateX\(-50%\);/s,
    )
    expect(appStyles).toMatch(
      /\.event-date\s*\{[^}]*align-items:\s*center;[^}]*display:\s*flex;[^}]*height:\s*13px;/s,
    )
    expect(appStyles).toMatch(
      /\.event-timeline strong\s*\{[^}]*font-size:\s*0\.84rem;[^}]*line-height:\s*1;/s,
    )
    expect(appStyles).toMatch(
      /\.event-card-content\s*\{[^}]*align-content:\s*start;/s,
    )
    expect(appStyles).toMatch(
      /\.event-timeline li:not\(:last-child\)::after\s*\{[^}]*border-left:\s*2px solid var\(--color-graph-line\);/s,
    )
    expect(appStyles).toMatch(
      /\.event-timeline li:last-child\s*\{[^}]*padding-bottom:\s*18px;/s,
    )
    expect(appStyles).toMatch(
      /\.event-order-actions\s*\{[^}]*display:\s*flex;[^}]*flex-direction:\s*row;[^}]*gap:\s*5px;[^}]*grid-column:\s*2;[^}]*justify-self:\s*end;[^}]*opacity:\s*0;[^}]*visibility:\s*hidden;/s,
    )
    expect(appStyles).toMatch(
      /\.event-timeline li:hover \.event-order-actions,[^{]*\.event-timeline li:focus-within \.event-order-actions\s*\{[^}]*opacity:\s*1;[^}]*visibility:\s*visible;/s,
    )
    expect(appStyles).toMatch(
      /\.event-order-actions button:disabled\s*\{[^}]*color:\s*var\(--color-text-muted\);[^}]*cursor:\s*default;[^}]*opacity:\s*0\.45;/s,
    )
  })

  it('keeps the person deletion action close to the panel edge', () => {
    expect(appStyles).toMatch(
      /\.danger-zone\s*\{[^}]*padding-bottom:\s*0;/s,
    )
  })

  it('uses one colored, interactive size for every addition action', () => {
    expect(appStyles).toMatch(
      /\.add-button\s*\{[^}]*background:\s*var\(--color-accent\);[^}]*font-family:\s*var\(--font-body\);[^}]*font-size:\s*0\.78rem;[^}]*height:\s*32px;[^}]*padding:\s*0 10px;[^}]*width:\s*110px;/s,
    )
    expect(appStyles).toMatch(
      /\.person-photo-actions-empty \.add-button\s*\{[^}]*height:\s*36px;[^}]*width:\s*132px;/s,
    )
    expect(appStyles).toMatch(
      /\.add-button-icon\s*\{[^}]*stroke:\s*currentcolor;[^}]*width:\s*15px;/s,
    )
    expect(appStyles).toMatch(
      /\.add-button:hover:not\(:disabled\)\s*\{[^}]*background:\s*var\(--color-accent-hover\);[^}]*box-shadow:\s*0 5px 14px var\(--color-shadow\);/s,
    )
    expect(appStyles).not.toMatch(
      /\.add-button:hover:not\(:disabled\)\s*\{[^}]*transform:/s,
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
      /\.date-fieldset\s*\{[^}]*grid-column:\s*1 \/ -1;[^}]*width:\s*100%;/s,
    )
    expect(appStyles).toMatch(
      /\.date-kind-row\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*minmax\(0, 1fr\) auto;[^}]*min-width:\s*0;/s,
    )
    expect(appStyles).toMatch(
      /\.date-controls\s*\{[^}]*display:\s*flex;[^}]*flex:\s*1 1 auto;/s,
    )
    expect(appStyles).toMatch(
      /\.approximate-toggle\s*\{[^}]*justify-self:\s*start;/s,
    )
  })

  it('gives every button a stationary hover state', () => {
    expect(tokens).toMatch(
      /button:hover:not\(:disabled\)\s*\{[^}]*filter:\s*brightness\(0\.94\);/s,
    )
    expect(tokens).not.toMatch(
      /button:hover:not\(:disabled\)\s*\{[^}]*transform:/s,
    )
  })

  it('stacks conditional date fields below their toggles', () => {
    expect(appStyles).toMatch(
      /\.conditional-date-toggle\s*\{[^}]*grid-column:\s*1 \/ -1;/s,
    )
    expect(appStyles).toMatch(
      /\.death-date-row\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\);/s,
    )
  })

  it('uses a larger portrait card with a divider after every field', () => {
    expect(appStyles).toMatch(
      /\.person-card\s*\{[^}]*gap:\s*10px;[^}]*grid-template-columns:\s*105px minmax\(0, 1fr\);[^}]*height:\s*160px;[^}]*padding:\s*8px;[^}]*width:\s*286px;/s,
    )
    expect(appStyles).toMatch(
      /\.person-card-details strong span\s*\{[^}]*color:\s*inherit;[^}]*font-size:\s*inherit;[^}]*overflow:\s*hidden;[^}]*text-overflow:\s*ellipsis;[^}]*white-space:\s*nowrap;/s,
    )
    expect(appStyles).toMatch(
      /\.person-photo-placeholder\s*\{[^}]*border:\s*1px solid var\(--color-accent\);[^}]*border-radius:\s*9px;[^}]*height:\s*140px;[^}]*width:\s*105px;/s,
    )
    expect(appStyles).not.toMatch(
      /\.person-details-section > \.person-detail-row:last-child,[^{]*\{[^}]*border-bottom:\s*0;/s,
    )
  })

  it('sizes relationship-map tooltips to their full name and life-period content', () => {
    expect(appStyles).toMatch(
      /\.blood-network-tooltip\s*\{[^}]*white-space:\s*nowrap;[^}]*width:\s*max-content;/s,
    )
    expect(appStyles).not.toMatch(
      /\.blood-network-tooltip\s*\{[^}]*max-width:/s,
    )
  })

  it('applies the same graph line style to React Flow and child brackets', () => {
    expect(appStyles).toMatch(
      /\.react-flow__edge-path\.tree-edge\s*\{[^}]*stroke:\s*var\(--color-graph-line\);[^}]*stroke-linejoin:\s*round;[^}]*stroke-width:\s*2\.4;/s,
    )
    expect(appStyles).not.toMatch(/\.tree-edge-child\s*\{[^}]*stroke-/s)
    expect(appStyles).not.toMatch(
      /\.react-flow__edge-path\.tree-edge-reference\s*\{[^}]*filter:/s,
    )
  })

  it('uses a distinct drag cursor only over movable blood-map nodes', () => {
    expect(appStyles).toMatch(
      /\.tree-canvas \.react-flow__pane\.draggable:not\(\.dragging\)\s*\{[^}]*cursor:\s*default;/s,
    )
    expect(appStyles).toMatch(
      /\.blood-network-person,\s*\.blood-network-family\s*\{[^}]*cursor:\s*grab;/s,
    )
    expect(appStyles).toMatch(
      /\.react-flow__node\.dragging \.blood-network-person,\s*\.react-flow__node\.dragging \.blood-network-family,[^}]*cursor:\s*grabbing;/s,
    )
  })

  it('uses a circular cursor and visible pivot for blood-map rotation', () => {
    expect(appStyles).toMatch(
      /\.blood-network-canvas\s*\{[^}]*height:\s*100%;[^}]*width:\s*100%;/s,
    )
    expect(appStyles).toMatch(
      /\.blood-network-rotation-mode \.react-flow__pane,[^}]*cursor:\s*url\(/s,
    )
    expect(appStyles).toMatch(/\.blood-network-rotation-pivot\s*\{[^}]*box-shadow:/s)
  })

  it('styles blood-map preset controls like the rest of the interface', () => {
    expect(appStyles).toMatch(
      /\.blood-network-preset-field select,[^}]*background:\s*var\(--color-surface\);[^}]*border-radius:\s*8px;[^}]*min-height:\s*36px;/s,
    )
    expect(appStyles).toMatch(
      /\.blood-network-preset-field select:focus-visible,[^}]*outline:\s*3px solid var\(--focus-ring\);/s,
    )
    expect(appStyles).toMatch(
      /\.blood-network-preset-action\s*\{[^}]*height:\s*34px;[^}]*width:\s*34px;/s,
    )
  })
})
