import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TreeMergePendingProposal, TreeMergeProposal } from '../api/generated'
import i18n from '../i18n'
import { TreeMergePreview } from './TreeMergePreview'

const proposal: TreeMergeProposal = {
  people: [{ id: 'i', resolution: 'new', existingPersonId: null, lifeStatus: 'deceased',
    fields: { givenName: { state: 'known', value: 'Ирина' }, birthSurname: { state: 'unknown' },
      patronymic: { state: 'unknown' }, gender: 'female',
      birthDate: { state: 'known', kind: 'approximate', year: 1960 }, deathDate: { state: 'unknown' } },
    evidence: [{ messageId: 'm', quote: '<b>Ирина</b>' }] }],
  parents: [], marriages: [], questions: [],
}
const draft: TreeMergePendingProposal = { id: 'proposal', treeId: 'tree', version: 1, state: 'ready', treeEtag: 'etag', proposal }
function setup(value = draft, busy = false) {
  const onApply = vi.fn(async () => {})
  const onRevalidate = vi.fn(async () => {})
  render(<TreeMergePreview proposal={value} busy={busy} onApply={onApply} onRevalidate={onRevalidate} />)
  return { onApply, onRevalidate }
}

describe('TreeMergePreview', () => {
  beforeEach(async () => { await i18n.changeLanguage('en') })

  it('confirms a checked proposal with one explicit click, without a checkbox or detailed forms', () => {
    const { onApply } = setup()
    expect(onApply).not.toHaveBeenCalled()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    expect(screen.queryByText('<b>Ирина</b>')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and apply changes' }))
    expect(onApply).toHaveBeenCalledOnce()
  })

  it.each(['questions', 'conflict', 'no_changes'] as const)(
    'does not apply an unchecked proposal in the %s state', (state) => {
      const { onApply } = setup({ ...draft, state })
      const apply = screen.getByRole('button', { name: 'Confirm and apply changes' })
      expect(apply).toBeDisabled()
      fireEvent.click(apply)
      expect(onApply).not.toHaveBeenCalled()
    },
  )

  it('disables both actions while a request is running', () => {
    const { onApply, onRevalidate } = setup({ ...draft, state: 'conflict' }, true)
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and apply changes' }))
    fireEvent.click(screen.getByRole('button', { name: 'Check against the current tree' }))
    expect(onApply).not.toHaveBeenCalled()
    expect(onRevalidate).not.toHaveBeenCalled()
  })

  it('explains why a proposal with no changes cannot be applied', () => {
    setup({ ...draft, state: 'no_changes' })
    expect(screen.getByText('This proposal does not change the tree.')).toBeInTheDocument()
  })

  it('revalidates conflicts without applying them', () => {
    const { onRevalidate, onApply } = setup({ ...draft, state: 'conflict' })
    fireEvent.click(screen.getByRole('button', { name: 'Check against the current tree' }))
    expect(onRevalidate).toHaveBeenCalledOnce()
    expect(onApply).not.toHaveBeenCalled()
  })

  it('localizes the confirmation in Russian', async () => {
    await i18n.changeLanguage('ru')
    setup()
    expect(screen.getByRole('button', { name: 'Подтвердить и применить изменения' })).toBeEnabled()
  })
})
