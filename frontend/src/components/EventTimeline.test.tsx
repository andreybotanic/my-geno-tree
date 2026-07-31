import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { GenealogicalEvent } from '../api/generated'
import i18n from '../i18n'
import { EventTimeline } from './EventTimeline'

const automatic: GenealogicalEvent = {
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a7',
  personId: 'person',
  type: 'birth',
  source: 'automatic',
  date: {
    state: 'known',
    precision: 'year',
    year: 1900,
    approximate: false,
  },
  title: null,
  description: null,
  data: {},
  sourceEntityType: 'person',
  sourceEntityId: 'person',
  manualOrder: null,
  createdAt: '2026-07-31T00:00:00+00:00',
  updatedAt: '2026-07-31T00:00:00+00:00',
}
const manual: GenealogicalEvent = {
  ...automatic,
  id: '019861c4-6a93-7a2e-8ca8-51813740d4a8',
  type: 'custom',
  source: 'manual',
  title: 'Moved to Novosibirsk',
  description: '<strong>Plain text</strong>\nSecond line',
  sourceEntityType: null,
  sourceEntityId: null,
}

describe('EventTimeline', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('keeps automatic events immutable and expands plain-text descriptions', async () => {
    const onEdit = vi.fn()
    const onDelete = vi.fn()
    render(
      <EventTimeline
        conflict={false}
        events={[automatic, manual]}
        onCreate={vi.fn()}
        onDelete={onDelete}
        onEdit={onEdit}
        onReorder={vi.fn(async () => {})}
      />,
    )

    expect(screen.getByText('Automatic event')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Edit' })).toHaveLength(1)
    expect(screen.queryByText('<strong>Plain text</strong>'))
      .not.toBeInTheDocument()

    await userEvent.click(
      screen.getByRole('button', { name: 'Show description' }),
    )
    expect(screen.getByText(/<strong>Plain text<\/strong>/))
      .toHaveTextContent('<strong>Plain text</strong> Second line')
    expect(document.querySelector('.event-description strong')).toBeNull()

    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))
    await userEvent.click(screen.getByRole('button', { name: 'Remove' }))
    expect(onEdit).toHaveBeenCalledWith(manual)
    expect(onDelete).toHaveBeenCalledWith(manual)
  })

  it('reorders only events inside an ambiguous group', async () => {
    const onReorder = vi.fn(async () => {})
    render(
      <EventTimeline
        conflict={false}
        events={[automatic, manual]}
        onCreate={vi.fn()}
        onDelete={vi.fn()}
        onEdit={vi.fn()}
        onReorder={onReorder}
      />,
    )

    expect(
      screen.getByRole('button', { name: 'Move Birth up' }),
    ).toBeDisabled()
    await userEvent.click(
      screen.getByRole('button', {
        name: 'Move Moved to Novosibirsk up',
      }),
    )
    expect(onReorder).toHaveBeenCalledWith([manual.id, automatic.id])
  })

  it('disables all mutations after a version conflict', () => {
    render(
      <EventTimeline
        conflict
        events={[automatic, manual]}
        onCreate={vi.fn()}
        onDelete={vi.fn()}
        onEdit={vi.fn()}
        onReorder={vi.fn(async () => {})}
      />,
    )

    expect(screen.getByRole('button', { name: 'Add event' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Remove' })).toBeDisabled()
  })
})
