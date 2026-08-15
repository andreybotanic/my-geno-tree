import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { ActionIcon } from './ActionIcon'

describe('ActionIcon', () => {
  it.each([
    ['edit', 2],
    ['delete', 1],
  ] as const)('renders the %s icon from the shared source', (name, paths) => {
    const { container } = render(<ActionIcon name={name} />)

    expect(container.querySelector('svg')).toHaveClass('action-icon')
    expect(container.querySelectorAll('path')).toHaveLength(paths)
  })
})
