import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type {
  MarriagePeriod,
  ParentLink,
  Person,
  TreeSnapshot,
} from '../api/generated'
import i18n from '../i18n'
import { PersonRelationships } from './PersonRelationships'

const createdAt = '2026-07-31T00:00:00+00:00'

function person(id: string, name: string, gender: Person['gender']): Person {
  return {
    id,
    birthSurname: { state: 'known', value: name },
    givenName: { state: 'unknown' },
    patronymic: { state: 'unknown' },
    gender,
    birthDate: { state: 'unknown' },
    deathDate: null,
    photoThumbnailUrl: null,
    coordinates: { x: 0, y: 0 },
    createdAt,
    updatedAt: createdAt,
  }
}

const selected = person('selected', 'Petrov', 'male')
const father = person('father', 'Senior', 'male')
const spouse = person('spouse', 'Petrova', 'female')
const commonChild = person('common-child', 'Junior', 'unknown')
const outsideChild = person('outside-child', 'Outside', 'unknown')
const oneParentChild = person('one-parent-child', 'Solo', 'unknown')
const links: ParentLink[] = [
  { id: 'father-link', parentId: father.id, childId: selected.id, role: 'father' },
  { id: 'common-a', parentId: selected.id, childId: commonChild.id, role: 'father' },
  { id: 'common-b', parentId: spouse.id, childId: commonChild.id, role: 'mother' },
  { id: 'outside-a', parentId: selected.id, childId: outsideChild.id, role: 'father' },
  { id: 'outside-b', parentId: father.id, childId: outsideChild.id, role: 'mother' },
  { id: 'solo-a', parentId: selected.id, childId: oneParentChild.id, role: 'father' },
]
const periods: MarriagePeriod[] = [
  {
    id: 'marriage-1',
    malePersonId: selected.id,
    femalePersonId: spouse.id,
    marriageDate: {
      state: 'known',
      precision: 'year',
      year: 2000,
      approximate: false,
    },
    dissolutionDate: {
      state: 'known',
      precision: 'year',
      year: 2005,
      approximate: false,
    },
    createdAt,
    updatedAt: createdAt,
  },
  {
    id: 'marriage-2',
    malePersonId: selected.id,
    femalePersonId: spouse.id,
    marriageDate: { state: 'unknown' },
    dissolutionDate: null,
    createdAt,
    updatedAt: createdAt,
  },
]

function snapshot(
  parentLinks: ParentLink[] = links,
  marriages: MarriagePeriod[] = periods,
  people: Person[] = [
    selected,
    father,
    spouse,
    commonChild,
    outsideChild,
    oneParentChild,
  ],
): TreeSnapshot {
  return {
    tree: { id: 'tree', name: 'Tree', createdAt, updatedAt: createdAt },
    etag: '"tree"',
    people,
    parentLinks,
    marriages,
  }
}

describe('PersonRelationships', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('groups repeated marriage periods and exposes every family action', async () => {
    const onAdd = vi.fn()
    const onEditMarriage = vi.fn()
    const onRemoveMarriage = vi.fn()
    const onRemoveParentLink = vi.fn()
    render(
      <PersonRelationships
        onAdd={onAdd}
        onEditMarriage={onEditMarriage}
        onRemoveMarriage={onRemoveMarriage}
        onRemoveParentLink={onRemoveParentLink}
        person={selected}
        snapshot={snapshot()}
      />,
    )

    expect(screen.getAllByText('Petrova')).toHaveLength(1)
    expect(screen.getByText(/2000/)).toHaveTextContent('2000 — 2005')
    expect(screen.getByText(/— — present/)).toBeInTheDocument()
    const family = screen.getByText('Petrova').closest('article')
    expect(family).not.toBeNull()
    const childrenBlock = within(family as HTMLElement)
      .getByRole('heading', { name: 'Children' })
      .closest('section')
    expect(childrenBlock).not.toBeNull()
    expect(within(childrenBlock as HTMLElement).getByText('Junior'))
      .toBeInTheDocument()
    expect(screen.getByText('Outside')).toBeInTheDocument()
    expect(screen.getByText('Solo')).toBeInTheDocument()
    const outsideMarriage = screen.getByRole('heading', {
      name: 'Children outside marriage',
    }).closest('section')
    expect(outsideMarriage).not.toBeNull()
    expect(within(outsideMarriage as HTMLElement).queryByText('Junior'))
      .not.toBeInTheDocument()

    const familyButtons = within(family as HTMLElement).getAllByRole('button')
    await userEvent.click(familyButtons[0] as HTMLElement)
    expect(onEditMarriage).toHaveBeenCalledWith(periods[0])
    await userEvent.click(familyButtons[1] as HTMLElement)
    expect(onRemoveMarriage).toHaveBeenCalledWith(periods[0])
    await userEvent.click(
      within(family as HTMLElement).getByRole('button', { name: 'Add' }),
    )
    expect(onAdd).toHaveBeenCalledWith('child', spouse.id)

    await userEvent.click(
      screen.getByRole('button', { name: 'Remove Father link' }),
    )
    expect(onRemoveParentLink).toHaveBeenCalledWith(links[0])
  })

  it('shows missing-parent and empty-family actions', async () => {
    const onAdd = vi.fn()
    render(
      <PersonRelationships
        onAdd={onAdd}
        onEditMarriage={vi.fn()}
        onRemoveMarriage={vi.fn()}
        onRemoveParentLink={vi.fn()}
        person={selected}
        snapshot={snapshot([], [], [selected])}
      />,
    )

    expect(screen.getByText('No marriages.')).toBeInTheDocument()
    expect(screen.getByText('No children in this section.'))
      .toBeInTheDocument()
    const parents = screen.getByRole('heading', { name: 'Parents' })
      .closest('section')
    const families = screen.getByRole('heading', { name: 'Families' })
      .closest('section')
    const children = screen
      .getByRole('heading', { name: 'Children outside marriage' })
      .closest('section')
    expect(parents).not.toBeNull()
    expect(families).not.toBeNull()
    expect(children).not.toBeNull()
    const parentButtons = within(parents as HTMLElement)
      .getAllByRole('button', { name: 'Add' })
    await userEvent.click(parentButtons[0] as HTMLElement)
    await userEvent.click(parentButtons[1] as HTMLElement)
    await userEvent.click(
      within(families as HTMLElement).getByRole('button', { name: 'Add' }),
    )
    await userEvent.click(
      within(children as HTMLElement).getByRole('button', { name: 'Add' }),
    )
    expect(onAdd.mock.calls).toEqual([
      ['father'],
      ['mother'],
      ['spouse'],
      ['child'],
    ])
  })

  it('shows an explicit empty children section in every family block', () => {
    render(
      <PersonRelationships
        onAdd={vi.fn()}
        onEditMarriage={vi.fn()}
        onRemoveMarriage={vi.fn()}
        onRemoveParentLink={vi.fn()}
        person={selected}
        snapshot={snapshot([], periods, [selected, spouse])}
      />,
    )

    const family = screen.getByText('Petrova').closest('article')
    expect(family).not.toBeNull()
    const childrenBlock = within(family as HTMLElement)
      .getByRole('heading', { name: 'Children' })
      .closest('section')
    expect(childrenBlock).not.toBeNull()
    expect(within(childrenBlock as HTMLElement).getByText(
      'No children in this section.',
    )).toBeInTheDocument()
    expect(within(childrenBlock as HTMLElement).getByRole('button', {
      name: 'Add',
    })).toBeInTheDocument()
  })
})
