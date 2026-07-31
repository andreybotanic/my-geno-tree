import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  MarriagePeriod,
  ParentLink,
  Person,
  TreeSnapshot,
} from '../api/generated'
import {
  formatGenealogicalDate,
  formatPersonName,
} from '../personFormatting'
import type { RelativeAction } from './RelativeDialog'
import {
  canRemoveMarriage,
  canRemoveParentLink,
} from '../relationshipPermissions'

type PersonRelationshipsProps = {
  onAdd: (action: RelativeAction, secondParentId?: string) => void
  onEditMarriage: (marriage: MarriagePeriod) => void
  onRemoveMarriage: (marriage: MarriagePeriod) => void
  onRemoveParentLink: (link: ParentLink) => void
  person: Person
  snapshot: TreeSnapshot
}

export function PersonRelationships({
  onAdd,
  onEditMarriage,
  onRemoveMarriage,
  onRemoveParentLink,
  person,
  snapshot,
}: PersonRelationshipsProps) {
  const { t, i18n } = useTranslation()
  const people = useMemo(
    () => new Map(snapshot.people.map((item) => [item.id, item])),
    [snapshot.people],
  )
  const parentLinks = snapshot.parentLinks.filter(
    (link) => link.childId === person.id,
  )
  const childLinks = snapshot.parentLinks.filter(
    (link) => link.parentId === person.id,
  )
  const father = parentLinks.find((link) => link.role === 'father')
  const mother = parentLinks.find((link) => link.role === 'mother')
  const marriages = snapshot.marriages.filter((marriage) => (
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
  const families = Array.from(familyMap)
  const children = childLinks
    .map((link) => people.get(link.childId))
    .filter((child) => child !== undefined)
  const outsideMarriage = children.filter((child) => {
    const otherParents = snapshot.parentLinks
      .filter((link) => (
        link.childId === child.id && link.parentId !== person.id
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
          person={father ? people.get(father.parentId) : undefined}
          removable={!father || canRemoveParentLink(snapshot, father)}
        />
        <RelativeRow
          action="mother"
          link={mother}
          onAdd={onAdd}
          onRemove={onRemoveParentLink}
          person={mother ? people.get(mother.parentId) : undefined}
          removable={!mother || canRemoveParentLink(snapshot, mother)}
        />
      </section>

      <section className="person-details-section relationship-section">
        <div className="relationship-heading">
          <h3>{t('relationships.families')}</h3>
          <button
            className="secondary-button compact-button"
            onClick={() => onAdd('spouse')}
            type="button"
          >
            {t('relationships.add.spouse')}
          </button>
        </div>
        {families.length === 0 ? (
          <p className="panel-empty">{t('relationships.noFamilies')}</p>
        ) : families.map(([spouseId, periods]) => {
          const spouse = people.get(spouseId)
          const commonChildren = spouse
            ? children.filter((child) => hasParent(
              snapshot,
              child.id,
              spouse.id,
            ))
            : []

          return (
            <article className="family-block" key={spouseId}>
              <strong>
                {spouse ? formatPersonName(spouse, t) : t('person.unknown')}
              </strong>
              <ul className="marriage-period-list">
                {periods.map((marriage) => (
                  <li key={marriage.id}>
                    <span>
                      {formatGenealogicalDate(
                        marriage.marriageDate,
                        language,
                        t,
                      )}
                      {' — '}
                      {marriage.dissolutionDate
                        ? formatGenealogicalDate(
                          marriage.dissolutionDate,
                          language,
                          t,
                        )
                        : t('relationships.currentMarriage')}
                    </span>
                    <div className="relationship-actions">
                      <button
                        className="secondary-button compact-button"
                        onClick={() => onEditMarriage(marriage)}
                        type="button"
                      >
                        {t('common.edit')}
                      </button>
                      {canRemoveMarriage(snapshot, marriage) && (
                        <button
                          className="danger-link"
                          onClick={() => onRemoveMarriage(marriage)}
                          type="button"
                        >
                          {t('common.remove')}
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
              {commonChildren.length > 0 && (
                <small>
                  {t('relationships.commonChildren')}: {' '}
                  {commonChildren
                    .map((child) => formatPersonName(child, t))
                    .join(', ')}
                </small>
              )}
              <button
                className="secondary-button compact-button"
                onClick={() => onAdd('child', spouseId)}
                type="button"
              >
                {t('relationships.add.child')}
              </button>
            </article>
          )
        })}
      </section>

      <section className="person-details-section relationship-section">
        <div className="relationship-heading">
          <h3>{t('relationships.childrenOutsideMarriage')}</h3>
          <button
            className="secondary-button compact-button"
            onClick={() => onAdd('child')}
            type="button"
          >
            {t('relationships.add.child')}
          </button>
        </div>
        {outsideMarriage.length === 0 ? (
          <p className="panel-empty">{t('relationships.noChildren')}</p>
        ) : (
          <ul className="relationship-list">
            {outsideMarriage.map((child) => (
              <li key={child.id}>{formatPersonName(child, t)}</li>
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
  person: Person | undefined
  removable: boolean
}

function RelativeRow({
  action,
  link,
  onAdd,
  onRemove,
  person,
  removable,
}: RelativeRowProps) {
  const { t } = useTranslation()

  return (
    <div className="relationship-row">
      <span>{t(`relationships.${action}`)}</span>
      {link && person ? (
        <>
          <strong>{formatPersonName(person, t)}</strong>
          {removable && (
            <button
              aria-label={t('relationships.removeParent', {
                role: t(`relationships.${action}`),
              })}
              className="danger-link"
              onClick={() => onRemove(link)}
              type="button"
            >
              {t('common.remove')}
            </button>
          )}
        </>
      ) : (
        <button
          className="secondary-button compact-button"
          onClick={() => onAdd(action)}
          type="button"
        >
          {t(`relationships.add.${action}`)}
        </button>
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
  snapshot: Pick<TreeSnapshot, 'parentLinks'>,
  childId: string,
  parentId: string,
) {
  return snapshot.parentLinks.some((link) => (
    link.childId === childId && link.parentId === parentId
  ))
}
