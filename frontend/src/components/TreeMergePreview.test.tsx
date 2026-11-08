import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { TreeMergePendingProposal, TreeMergeProposal } from '../api/generated'
import i18n from '../i18n'
import { TreeMergePreview } from './TreeMergePreview'

const proposal: TreeMergeProposal = {
  people: [
    { id: 'i', resolution: 'new', existingPersonId: null, lifeStatus: 'deceased',
      fields: { givenName: { state: 'known', value: 'Ирина' }, birthSurname: { state: 'unknown' },
        patronymic: { state: 'unknown' }, gender: 'female',
        birthDate: { state: 'known', kind: 'approximate', year: 1960 }, deathDate: { state: 'unknown' } },
      evidence: [{ messageId: 'm', quote: '<b>Ирина</b>' }] }
  ],
  parents: [], marriages: [], questions: [],
}
const draft: TreeMergePendingProposal = { id: 'proposal', treeId: 'tree', version: 1, state: 'ready', treeEtag: 'etag', proposal }
function setup(value = draft, busy = false) {
  const onApply = vi.fn(async () => {})
  const onDiscard = vi.fn(async () => {})
  const onRevalidate = vi.fn(async () => {})
  render(<TreeMergePreview proposal={value} busy={busy} onApply={onApply} onDiscard={onDiscard} onRevalidate={onRevalidate} />)
  return { onApply, onDiscard, onRevalidate }
}

describe(
  'TreeMergePreview',
  () => {
    beforeEach(async () => { await i18n.changeLanguage('en') })

    it('shows every mixed operation, complete descriptions and precise dates as escaped text', () => {
      setup({ ...draft, proposal: { ...proposal,
        operationSubjects: [['Ирина'], ['Ирина', 'Мария'], ['Ирина']],
        operations: [
          { type: 'create_person', id: 'i', fields: { givenName: { state: 'known', value: 'Ирина' }, patronymic: { state: 'unknown' }, gender: 'female', birthDate: { state: 'known', kind: 'approximate', year: 1960 }, deathDate: null, birthPlace: { name: 'Village', coordinates: null } }, evidence: [{ messageId: 'm', quote: '<b>Ирина</b>' }] },
          { type: 'create_events', personIds: ['i', 'm'], event: { type: 'custom', title: 'Golden wedding celebration', date: { state: 'known', kind: 'exact', year: 2010 }, description: '<script>Full description</script>', place: null }, evidence: [] },
          { type: 'add_parent_link', parent: 'i', child: 'm', role: 'mother', evidence: [] },
        ],
      } })
      expect(screen.getByText('Create person')).toBeInTheDocument()
      expect(screen.getByText('Add events to participants')).toBeInTheDocument()
      expect(screen.getByText('Add parent link')).toBeInTheDocument()
      expect(screen.getByText('Golden wedding celebration')).toBeInTheDocument()
      expect(screen.getByText('2010')).toBeInTheDocument()
      expect(screen.getByText('Village')).toBeInTheDocument()
      expect(screen.getByText('Female')).toBeInTheDocument()
      expect(screen.getByText('Mother')).toBeInTheDocument()
      expect(screen.getByText('<script>Full description</script>')).toBeInTheDocument()
      expect(screen.getByText('<b>Ирина</b>').querySelector('b')).toBeNull()
      expect(document.querySelector('script')).toBeNull()
    })

    it(
      'confirms a checked proposal with one explicit click, without a checkbox or detailed forms',
      () => {
        const { onApply } = setup()
        expect(onApply).not.toHaveBeenCalled()
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
        expect(screen.queryByText('<b>Ирина</b>')).not.toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
        expect(onApply).toHaveBeenCalledOnce()
      }
    )

    it
      .each(
        ['questions', 'conflict', 'no_changes'] as const
      )(
        'does not apply an unchecked proposal in the %s state',
        (state) => {
          const { onApply } = setup({ ...draft, state })
          const apply = screen.getByRole('button', { name: 'Apply' })
          expect(apply).toBeDisabled()
          fireEvent.click(apply)
          expect(onApply).not.toHaveBeenCalled()
        },
      )

    it(
      'disables all actions while a request is running',
      () => {
        const { onApply, onDiscard, onRevalidate } = setup({ ...draft, state: 'conflict' }, true)
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
        fireEvent.click(screen.getByRole('button', { name: 'Recheck' }))
        fireEvent.click(screen.getByRole('button', { name: 'Discard' }))
        expect(onApply).not.toHaveBeenCalled()
        expect(onDiscard).not.toHaveBeenCalled()
        expect(onRevalidate).not.toHaveBeenCalled()
      }
    )

    it(
      'keeps discard alongside apply, including proposals that cannot be applied',
      () => {
        const { onDiscard, onApply } = setup({ ...draft, state: 'questions' })
        const preview = screen.getByRole('region', { name: 'Review changes' })
        const apply = screen.getByRole('button', { name: 'Apply' })
        const discard = screen.getByRole('button', { name: 'Discard' })
        expect(preview).toContainElement(apply)
        expect(discard.parentElement).toBe(apply.parentElement)
        expect(apply).toBeDisabled()
        fireEvent.click(discard)
        expect(onDiscard).toHaveBeenCalledOnce()
        expect(onApply).not.toHaveBeenCalled()
      }
    )

    it(
      'explains why a proposal with no changes cannot be applied',
      () => {
        setup({ ...draft, state: 'no_changes' })
        expect(screen.getByText('This proposal does not change the tree.')).toBeInTheDocument()
      }
    )

    it(
      'revalidates conflicts without applying them',
      () => {
        const { onRevalidate, onApply } = setup({ ...draft, state: 'conflict' })
        fireEvent.click(screen.getByRole('button', { name: 'Recheck' }))
        expect(onRevalidate).toHaveBeenCalledOnce()
        expect(onApply).not.toHaveBeenCalled()
      }
    )

    it(
      'localizes the confirmation in Russian',
      async () => {
        await i18n.changeLanguage('ru')
        setup()
        expect(screen.getByRole('button', { name: 'Применить' })).toBeEnabled()
        expect(screen.getByRole('button', { name: 'Отказаться' })).toBeEnabled()
        expect(screen.queryByText('Существующие персоны приглушены, новые показаны обычным цветом.')).not.toBeInTheDocument()
      }
    )
  }
)
