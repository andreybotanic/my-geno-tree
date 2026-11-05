import { useState } from 'react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthorizedJsonRequest } from '../api/client'
import type { TreeSnapshot } from '../api/generated'
import i18n from '../i18n'
import { TreeChatDrawer } from './TreeChatDrawer'

const snapshot: TreeSnapshot = {
  tree: { id: 'tree', name: 'Family', reference: null, createdAt: '2026-11-03', updatedAt: '2026-11-03' },
  people: [], parentLinks: [], marriages: [], etag: 'etag', layoutRevision: 'layout', layout: null, bloodNetworkState: null,
}

describe('TreeChatDrawer', () => {
  beforeEach(async () => { localStorage.clear(); window.history.replaceState({}, '', '/'); await i18n.changeLanguage('en') })

  it('opens immediately into a composer and keeps input when collapsed with the same edge tab', async () => {
    const request = vi.fn(async () => ({ conversation: null, tokenBudget: { remaining: 1000, limit: 1000 } })) as unknown as AuthorizedJsonRequest
    function Harness() {
      const [open, setOpen] = useState(false)
      return <TreeChatDrawer open={open} onOpenChange={setOpen} snapshot={snapshot} request={request} onApplied={async () => {}} />
    }
    const { container } = render(<Harness />)
    const edge = screen.getByRole('button', { name: 'Open AI chat' })
    expect(edge).toHaveAttribute('aria-expanded', 'false')
    expect(edge.querySelector('svg')).not.toBeNull()
    expect(request).not.toHaveBeenCalled()
    fireEvent.click(edge)
    const input = screen.getByRole('textbox', { name: 'Message' })
    await waitFor(() => expect(input).toBeEnabled())
    expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Start conversation' })).not.toBeInTheDocument()
    expect(container.querySelector('input[type=file]')).toBeNull()
    fireEvent.change(input, { target: { value: 'My mother is Vera' } })
    fireEvent.click(screen.getByRole('button', { name: 'Collapse AI chat' }))
    expect(container.querySelector('#tree-chat')).toHaveAttribute('inert')
    expect(container.querySelector('.tree-chat-drawer')).not.toHaveClass('is-open')
    fireEvent.click(screen.getByRole('button', { name: 'Open AI chat' }))
    expect(input).toHaveValue('My mother is Vera')
    expect(request).toHaveBeenCalledOnce()
    fireEvent.keyDown(input, { key: 'Escape' })
    expect(edge).toHaveAttribute('aria-expanded', 'false')
  })
})
