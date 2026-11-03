import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Person, TreeMergeDraft, TreeMergeProposal } from '../api/generated'
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
  proposalVersion: 1, proposal, questions: [], result: null, state: 'ready', treeEtag: 'etag',
  messages: [], createdAt: 'now', updatedAt: 'now',
}
function setup(value = draft) {
  const onSave = vi.fn(async () => {})
  const onApply = vi.fn(async () => {})
  const onRevalidate = vi.fn(async () => {})
  render(<TreeMergePreview draft={value} busy={false} onSave={onSave} onApply={onApply} onRevalidate={onRevalidate} />)
  return { onSave, onApply, onRevalidate }
}

describe('TreeMergePreview', () => {
  beforeEach(async () => { await i18n.changeLanguage('en') })

  it('requires explicit confirmation of the reviewed version and escapes evidence', () => {
    const { onApply } = setup()
    const apply = screen.getByRole('button', { name: 'Confirm and apply changes' })
    expect(apply).toBeDisabled()
    expect(screen.getByText('<b>Ирина</b>')).toBeInTheDocument()
    expect(document.querySelector('blockquote b')).toBeNull()
    fireEvent.click(screen.getByRole('checkbox', { name: 'I have reviewed proposal version 1' }))
    fireEvent.click(apply)
    expect(onApply).toHaveBeenCalledOnce()
  })

  it('edits fields and a person match without applying and preserves structured dates', async () => {
    const { onSave, onApply } = setup()
    fireEvent.click(screen.getByRole('checkbox', { name: 'I have reviewed proposal version 1' }))
    fireEvent.change(screen.getByLabelText('Match in this tree'), { target: { value: 'existing' } })
    fireEvent.change(screen.getByRole('textbox', { name: 'Given name' }), { target: { value: 'Ирина Петровна' } })
    expect(screen.getByRole('button', { name: 'Confirm and apply changes' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Check corrections' }))
    await waitFor(() => expect(onSave).toHaveBeenCalledOnce())
    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ people: [
      expect.objectContaining({ resolution: 'existing', existingPersonId: 'existing',
        fields: expect.objectContaining({ givenName: { state: 'known', value: 'Ирина Петровна' },
          birthDate: { state: 'known', kind: 'approximate', year: 1960 }, deathDate: { state: 'unknown' } }) }),
    ] }))
    expect(onApply).not.toHaveBeenCalled()
  })

  it('requires valid date inputs before saving corrections', () => {
    setup()
    fireEvent.change(screen.getByLabelText('Birth date: Year'), { target: { value: '1500' } })
    expect(screen.getByRole('button', { name: 'Check corrections' })).toBeDisabled()
    expect(screen.getByRole('alert')).toHaveTextContent('Complete the fields')
  })

  it('clarifies life status and clears questions only through an explicit answer', async () => {
    const { onSave } = setup({ ...draft, state: 'questions', questions: ['life_status:i', 'person_match:i', 'Which name?', 'ANCESTOR_CYCLE'],
      proposal: { ...proposal, people: [{ ...proposal.people[0]!, lifeStatus: 'uncertain', resolution: 'undecided' }], questions: ['Which name?'] } })
    expect(screen.getByText('Clarify whether Ирина is alive or deceased.')).toBeInTheDocument()
    expect(screen.getByText('Choose who Ирина refers to.')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Life status'), { target: { value: 'alive' } })
    fireEvent.change(screen.getByLabelText('Match in this tree'), { target: { value: 'new' } })
    fireEvent.click(screen.getByRole('checkbox', { name: 'I have answered these questions in the fields above' }))
    fireEvent.click(screen.getByRole('button', { name: 'Check corrections' }))
    await waitFor(() => expect(onSave).toHaveBeenCalledWith(expect.objectContaining({
      questions: [], people: [expect.objectContaining({ lifeStatus: 'alive', fields: expect.objectContaining({ deathDate: null }) })],
    })))
  })

  it('allows excluding relationships and explicitly choosing a new marriage period', async () => {
    const { onSave } = setup({ ...draft, proposal: { ...proposal,
      parents: [{ parent: 'i', child: 'other', role: 'mother', evidence: [{ messageId: 'm', quote: 'mother' }] }],
      marriages: [{ first: 'i', second: 'other', marriageDate: { state: 'unknown' }, dissolutionDate: null,
        newPeriod: false, evidence: [{ messageId: 'm', quote: 'spouses' }] }],
    } })
    fireEvent.click(screen.getByRole('checkbox', { name: /Mother of/ }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Create a separate marriage period' }))
    fireEvent.click(screen.getByRole('button', { name: 'Check corrections' }))
    await waitFor(() => expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ parents: [],
      marriages: [expect.objectContaining({ newPeriod: true })] })))
  })

  it('shows existing values and before-after comparisons including life dates', () => {
    const before: Person = {
      id: 'existing', birthSurname: { state: 'known', value: 'Петрова' }, givenName: { state: 'known', value: 'Ирина' },
      patronymic: { state: 'unknown' }, gender: 'female', birthDate: { state: 'known', kind: 'between', year: 1959,
        month: 1, day: 1, end: { year: 1961, month: 2, day: 2 } },
      deathDate: null, birthPlace: null, deathPlace: null, photoThumbnailUrl: null, createdAt: 'now', updatedAt: 'now',
    }
    setup({ ...draft, proposal: { ...proposal, people: [{ ...proposal.people[0]!, before, resolution: 'existing', existingPersonId: 'existing' }] } })
    expect(screen.getByText(/Петрова →/)).toBeInTheDocument()
    expect(screen.getByText(/Alive →/)).toBeInTheDocument()
    expect(screen.getByText(/1959/)).toBeInTheDocument()
  })

  it('shows no-change and applied states without allowing another application', () => {
    setup({ ...draft, state: 'no_changes' })
    expect(screen.getByText('This proposal does not change the tree.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Confirm and apply changes' })).toBeDisabled()
  })

  it('revalidates conflicts explicitly', () => {
    const { onRevalidate } = setup({ ...draft, state: 'conflict' })
    fireEvent.click(screen.getByRole('button', { name: 'Check against the current tree' }))
    expect(onRevalidate).toHaveBeenCalledOnce()
  })

  it('localizes the completed preview in Russian', async () => {
    await i18n.changeLanguage('ru')
    setup({ ...draft, state: 'applied' })
    expect(screen.getByRole('status')).toHaveTextContent('Изменения применены')
    expect(screen.queryByRole('button', { name: 'Подтвердить и применить изменения' })).not.toBeInTheDocument()
  })
})
