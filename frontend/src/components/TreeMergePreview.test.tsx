import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TreeMergeDraft, TreeMergeProposal } from '../api/generated'
import i18n from '../i18n'
import { TreeMergePreview } from './TreeMergePreview'

const proposal: TreeMergeProposal = {
  people: [{ id: 'i', resolution: 'new', existingPersonId: null, lifeStatus: 'deceased',
    fields: { givenName: { state: 'known', value: 'Ирина' }, birthSurname: { state: 'unknown' },
      patronymic: { state: 'unknown' }, gender: 'female',
      birthDate: { state: 'known', kind: 'approximate', year: 1960 }, deathDate: { state: 'unknown' } },
    evidence: [{ messageId: 'm', quote: '<b>Ирина</b>' }], candidates: [{ id: 'existing', fullName: 'Ирина Петрова', birthYear: 1960, deathYear: null }] }],
  parents: [], marriages: [], questions: [],
}
const draft: TreeMergeDraft & { proposal: TreeMergeProposal } = {
  id: 'draft', treeId: 'tree', referencePersonId: null, version: 2, sourceVersion: 1,
  progress: [], tokenBudget: { remaining: 10000000, limit: 10000000 }, tokenUsage: { input: 0, output: 0, total: 0 }, attemptId: null, attemptSourceVersion: null, attemptStartedAt: null, proposalVersion: 1, proposal, questions: [], result: null, state: 'ready', treeEtag: 'etag',
  messages: [], createdAt: 'now', updatedAt: 'now',
}
function setup(value = draft, busy = false) {
  const onApply = vi.fn(async () => {})
  const onRevalidate = vi.fn(async () => {})
  render(<TreeMergePreview draft={value} busy={busy} onApply={onApply} onRevalidate={onRevalidate} />)
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

  it.each(['collecting', 'processing', 'questions', 'error', 'conflict', 'no_changes', 'cancelled'] as const)(
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

  it('localizes the completed preview in Russian', async () => {
    await i18n.changeLanguage('ru')
    setup({ ...draft, state: 'applied' })
    expect(screen.getByRole('status')).toHaveTextContent('Изменения применены')
    expect(screen.queryByRole('button', { name: 'Подтвердить и применить изменения' })).not.toBeInTheDocument()
  })
})
