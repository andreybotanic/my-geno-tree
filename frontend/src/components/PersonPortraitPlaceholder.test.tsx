import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PersonPortraitPlaceholder } from './PersonPortraitPlaceholder'

describe('PersonPortraitPlaceholder', () => {
  it('renders a silhouette as a presentational SVG', () => {
    const { container } = render(<PersonPortraitPlaceholder />)
    const placeholder = container.querySelector('.person-portrait-placeholder')

    expect(placeholder).toHaveAttribute('aria-hidden', 'true')
    expect(placeholder?.tagName).toBe('svg')
    expect(placeholder?.querySelector('circle')).toBeInTheDocument()
    expect(placeholder?.querySelector('path')).toBeInTheDocument()
  })
})
