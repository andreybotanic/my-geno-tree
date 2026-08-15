import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  MarriagePeriod,
  ParentLink,
  Person,
  PersonDetailsPermissions,
  PersonRelationshipContext,
  PersonRelationshipPerson,
  TreeSnapshot,
} from '../api/generated'
import {
  formatGenealogicalDate,
  formatUnbreakableDateText,
  formatPersonName,
} from '../personFormatting'
import type { RelativeAction } from './RelativeDialog'
import {
  canRemoveMarriage,
  canRemoveParentLink,
} from '../relationshipPermissions'
import { AddButton } from './AddButton'
import { ActionIcon } from './ActionIcon'
import {
  relationshipPerson,
  type RelationshipNavigation,
} from '../pathDisplayPlan'

type PersonRelationshipsProps = {
  context?: PersonRelationshipContext
  onAdd: (action: RelativeAction, secondParentId?: string) => void
  onEditMarriage: (marriage: MarriagePeriod) => void
  onRemoveMarriage: (marriage: MarriagePeriod) => void
  onRemoveParentLink: (link: ParentLink) => void
  onSelectPerson: (navigation: RelationshipNavigation) => void
  person: Person
  permissions?: PersonDetailsPermissions
  snapshot?: TreeSnapshot
}

export function PersonRelationships({
  onAdd,
  onEditMarriage,
  onRemoveMarriage,
  onRemoveParentLink,
  onSelectPerson,
  person,
  context,
  permissions,
  snapshot,
}: PersonRelationshipsProps) {
  const { t, i18n } = useTranslation()
  const relationshipContext: PersonRelationshipContext | null = context ?? (snapshot ? {
    marriages: snapshot.marriages,
    parentLinks: snapshot.parentLinks,
    people: snapshot.people
      .filter((candidate) => candidate.id !== person.id)
      .map(relationshipPerson),
  } : null)
  if (!relationshipContext) {
    return null
  }
  const people = new Map(relationshipContext.people.map((item) => [item.id, item]))
  const parentLinks = relationshipContext.parentLinks.filter(
    (link) => link.childId === person.id,
  )
  const childLinks = relationshipContext.parentLinks.filter(
    (link) => link.parentId === person.id,
  )
  const father = parentLinks.find((link) => link.role === 'father')
  const mother = parentLinks.find((link) => link.role === 'mother')
  const marriages = relationshipContext.marriages.filter((marriage) => (
    marriage.malePersonId === person.id
    || marriage.femalePersonId === person.id
  ))
  const spouseIds = new Set(marriages.map((marriage) => partnerId(
    marriage,
    person.id,
  )))
  const familyMap = new Map<string, MarriagePeriod[]>()
  marriages.forEach((marriage) => {
    const spouseId = partnerId(marriage, person.id)
    familyMap.set(spouseId, [
      ...(familyMap.get(spouseId) ?? []),
      marriage,
    ])
  })
  const families = Array.from(familyMap, ([spouseId, periods]) => [
    spouseId,
    periods.toSorted((left, right) => left.id.localeCompare(right.id)),
  ] as const)
  const children = childLinks.flatMap((link) => {
    const child = people.get(link.childId)
    return child ? [{ link, person: child }] : []
  })
  const outsideMarriage = children.filter((child) => {
    const otherParents = relationshipContext.parentLinks
      .filter((link) => (
        link.childId === child.person.id && link.parentId !== person.id
      ))
      .map((link) => link.parentId)

    return !otherParents.some((parentId) => spouseIds.has(parentId))
  })
  const language = i18n.resolvedLanguage ?? 'en'

  return (
    <>
      <section className="person-details-section relationship-section">
        <h3>{t('relationships.parents')}</h3>
        <RelativeRow
          action="father"
          link={father}
          onAdd={onAdd}
          onRemove={onRemoveParentLink}
          onSelectPerson={onSelectPerson}
          person={father ? people.get(father.parentId) : undefined}
          removable={!father || (permissions
            ? permissions.removableParentLinkIds.includes(father.id)
            : Boolean(snapshot && canRemoveParentLink(snapshot, father)))}
        />
        <RelativeRow
          action="mother"
          link={mother}
          onAdd={onAdd}
          onRemove={onRemoveParentLink}
          onSelectPerson={onSelectPerson}
          person={mother ? people.get(mother.parentId) : undefined}
          removable={!mother || (permissions
            ? permissions.removableParentLinkIds.includes(mother.id)
            : Boolean(snapshot && canRemoveParentLink(snapshot, mother)))}
        />
      </section>

      <section className="person-details-section relationship-section">
        <div className="relationship-heading">
          <h3>{t('relationships.families')}</h3>
          <AddButton
            onClick={() => onAdd('spouse')}
          />
        </div>
        {families.length === 0 ? (
          <p className="panel-empty">{t('relationships.noFamilies')}</p>
        ) : families.map(([spouseId, periods]) => {
          const spouse = people.get(spouseId)
          const commonChildren = spouse
            ? children.filter((child) => hasParent(
              relationshipContext,
              child.person.id,
              spouse.id,
            ))
            : []

          return (
            <article className="family-block" key={spouseId}>
              {spouse ? (
                <button
                  className="relationship-person-button"
                  onClick={() => onSelectPerson({
                    person: spouse,
                    relationship: {
                      type: 'marriage',
                      marriage: periods[0]!,
                    },
                  })}
                  type="button"
                >
                  {formatPersonName(spouse, t)}
                </button>
              ) : <strong>{t('person.unknown')}</strong>}
              <ul className="marriage-period-list">
                {periods.map((marriage) => (
                  <li key={marriage.id}>
                    <span>{marriagePeriodLabel(
                      marriage,
                      person,
                      spouse,
                      language,
                      t,
                    )}</span>
                    <div className="relationship-actions">
                      <button
                        aria-label={t('common.edit')}
                        className="relationship-edit-button"
                        onClick={() => onEditMarriage(marriage)}
                        title={t('common.edit')}
                        type="button"
                      >
                        <ActionIcon name="edit" />
                      </button>
                      {(permissions
                        ? permissions.removableMarriageIds.includes(marriage.id)
                        : Boolean(snapshot && canRemoveMarriage(snapshot, marriage))) && (
                        <button
                          aria-label={t('common.remove')}
                          className="danger-link"
                          onClick={() => onRemoveMarriage(marriage)}
                          title={t('common.remove')}
                          type="button"
                        >
                          <ActionIcon name="delete" />
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              <section className="family-children">
                <div className="relationship-heading">
                  <h4>{t('relationships.commonChildren')}</h4>
                  <AddButton
                    onClick={() => onAdd('child', spouseId)}
                  />
                </div>
                {commonChildren.length === 0 ? (
                  <p className="panel-empty">
                    {t('relationships.noChildren')}
                  </p>
                ) : (
                  <ul className="relationship-list">
                    {commonChildren.map((child) => (
                      <li key={child.person.id}>
                        <button
                          className="relationship-person-button"
                          onClick={() => onSelectPerson({
                            person: child.person,
                            relationship: { type: 'parent', link: child.link },
                          })}
                          type="button"
                        >
                          {formatPersonName(child.person, t)}
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </article>
          )
        })}
      </section>

      <section className="person-details-section relationship-section">
        <div className="relationship-heading">
          <h3>{t('relationships.childrenOutsideMarriage')}</h3>
          <AddButton
            onClick={() => onAdd('child')}
          />
        </div>
        {outsideMarriage.length === 0 ? (
          <p className="panel-empty">
            {t('relationships.noChildrenOutsideMarriage')}
          </p>
        ) : (
          <ul className="relationship-list">
            {outsideMarriage.map((child) => (
              <li key={child.person.id}>
                <button
                  className="relationship-person-button"
                  onClick={() => onSelectPerson({
                    person: child.person,
                    relationship: { type: 'parent', link: child.link },
                  })}
                  type="button"
                >
                  {formatPersonName(child.person, t)}
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

    </>
  )
}

type RelativeRowProps = {
  action: 'father' | 'mother'
  link: ParentLink | undefined
  onAdd: (action: RelativeAction, secondParentId?: string) => void
  onRemove: (link: ParentLink) => void
  onSelectPerson: (navigation: RelationshipNavigation) => void
  person: PersonRelationshipPerson | undefined
  removable: boolean
}

function RelativeRow({
  action,
  link,
  onAdd,
  onRemove,
  onSelectPerson,
  person,
  removable,
}: RelativeRowProps) {
  const { t } = useTranslation()

  return (
    <div className="relationship-row">
      <span>{t(`relationships.${action}`)}</span>
      {link && person ? (
        <>
          <button
            className="relationship-person-button"
            onClick={() => onSelectPerson({
              person,
              relationship: { type: 'parent', link },
            })}
            type="button"
          >
            {formatPersonName(person, t)}
          </button>
          {removable && (
            <button
              aria-label={t('relationships.removeParent', {
                role: t(`relationships.${action}`),
              })}
              className="danger-link"
              onClick={() => onRemove(link)}
              title={t('relationships.removeParent', {
                role: t(`relationships.${action}`),
              })}
              type="button"
            >
              <ActionIcon name="delete" />
            </button>
          )}
        </>
      ) : (
        <AddButton
          onClick={() => onAdd(action)}
        />
      )}
    </div>
  )
}

function partnerId(marriage: MarriagePeriod, personId: string) {
  return marriage.malePersonId === personId
    ? marriage.femalePersonId
    : marriage.malePersonId
}

function hasParent(
  context: Pick<PersonRelationshipContext, 'parentLinks'>,
  childId: string,
  parentId: string,
) {
  return context.parentLinks.some((link) => (
    link.childId === childId && link.parentId === parentId
  ))
}

function marriageEndLabel(
  marriage: MarriagePeriod,
  person: Person,
  spouse: PersonRelationshipPerson | undefined,
  language: string,
  translate: (key: string) => string,
) {
  if (marriage.dissolutionDate) {
    return formatGenealogicalDate(marriage.dissolutionDate, language, translate)
  }

  const deathDates = [person.deathDate, spouse?.deathDate]
    .filter((date): date is GenealogicalDate => date !== null && date !== undefined)
  if (deathDates.length === 0) {
    return formatUnbreakableDateText(translate('relationships.currentMarriage'))
  }

  const knownDeathDates = deathDates.filter(
    (date): date is Exclude<GenealogicalDate, { state: 'unknown' }> => (
      date.state === 'known'
    ),
  )
  if (knownDeathDates.length === 0) {
    return translate('person.unknown')
  }

  const earliestDeathDate = knownDeathDates.reduce((earliest, date) => (
    compareKnownDates(date, earliest) < 0 ? date : earliest
  ))

  return formatGenealogicalDate(earliestDeathDate, language, translate)
}

function marriagePeriodLabel(
  marriage: MarriagePeriod,
  person: Person,
  spouse: PersonRelationshipPerson | undefined,
  language: string,
  translate: (key: string) => string,
) {
  if (
    marriage.marriageDate.state === 'unknown'
    && marriage.dissolutionDate?.state === 'unknown'
  ) {
    return translate('relationships.dissolved')
  }

  return `${formatGenealogicalDate(marriage.marriageDate, language, translate)} – ${marriageEndLabel(marriage, person, spouse, language, translate)}`
}

function compareKnownDates(
  first: Exclude<GenealogicalDate, { state: 'unknown' }>,
  second: Exclude<GenealogicalDate, { state: 'unknown' }>,
) {
  return dateSortValue(first) - dateSortValue(second)
}

function dateSortValue(
  date: Exclude<GenealogicalDate, { state: 'unknown' }>,
) {
  const month = date.month ?? 1
  const day = date.day ?? 1

  return Date.UTC(date.year, month - 1, day)
}
