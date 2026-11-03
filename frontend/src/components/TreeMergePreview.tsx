import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { GenealogicalDate, KnownText, PersonPatchRequest, TreeMergeDraft, TreeMergeProposal } from '../api/generated'
import { formatGenealogicalDate } from '../personFormatting'
import { personFormValue, type PersonFormState } from '../personFormState'
import { dateValue, unknownDate, type DateInputState } from '../personFormValues'
import { PersonFormFields } from './PersonFormDialog'

type Props = {
  draft: TreeMergeDraft & { proposal: TreeMergeProposal }
  busy: boolean
  onSave: (proposal: TreeMergeProposal) => Promise<void>
  onApply: () => Promise<void>
  onRevalidate: () => Promise<void>
}

function dateState(date: GenealogicalDate | undefined | null): DateInputState {
  if (!date || date.state === 'unknown') return unknownDate()
  return {
    unknown: false, kind: date.kind, year: String(date.year),
    month: date.month ? String(date.month) : '', day: date.day ? String(date.day) : '',
    endYear: date.kind === 'between' && date.end ? String(date.end.year) : '',
    endMonth: date.kind === 'between' && date.end?.month ? String(date.end.month) : '',
    endDay: date.kind === 'between' && date.end?.day ? String(date.end.day) : '',
  }
}

function textState(value: KnownText | undefined) {
  return { unknown: !value || value.state === 'unknown', value: value?.state === 'known' ? value.value : '' }
}

function formState(fields: PersonPatchRequest): PersonFormState {
  return {
    givenName: textState(fields.givenName), birthSurname: textState(fields.birthSurname),
    patronymic: textState(fields.patronymic), gender: fields.gender ?? 'unknown',
    birthDate: dateState(fields.birthDate), deathDate: dateState(fields.deathDate),
    deceased: fields.deathDate !== null, birthPlace: null, deathPlace: null,
  }
}

export function TreeMergePreview({ draft, busy, onSave, onApply, onRevalidate }: Props) {
  const { t, i18n } = useTranslation()
  const [rows, setRows] = useState(() => draft.proposal.people.map((person) => ({
    person,
    form: { ...formState({ ...person.before, ...person.fields }), deceased: person.lifeStatus === 'deceased' },
  })))
  const [dirty, setDirty] = useState(false)
  const [confirmed, setConfirmed] = useState(false)
  const [removedParents, setRemovedParents] = useState<number[]>([])
  const [parentRoles, setParentRoles] = useState(() => draft.proposal.parents.map((link) => link.role))
  const [removedMarriages, setRemovedMarriages] = useState<number[]>([])
  const [newPeriods, setNewPeriods] = useState(() => draft.proposal.marriages.map((marriage) => marriage.newPeriod))
  const [answered, setAnswered] = useState(false)

  function change(index: number, row: (typeof rows)[number]) {
    setRows((current) => current.map((item, rowIndex) => rowIndex === index ? row : item))
    setDirty(true)
    setConfirmed(false)
  }

  const valid = rows.every((row) => personFormValue(row.form) !== null)
  async function save() {
    if (!valid) return
    const people = rows.map(({ person, form }) => {
      const input = personFormValue(form)!
      return {
        id: person.id, existingPersonId: person.existingPersonId, resolution: person.resolution,
        lifeStatus: person.lifeStatus, evidence: person.evidence,
        fields: { birthSurname: input.birthSurname, givenName: input.givenName, patronymic: input.patronymic,
          gender: input.gender, birthDate: input.birthDate, deathDate: input.deathDate },
      }
    })
    await onSave({
      people,
      parents: draft.proposal.parents.flatMap((link, index) => removedParents.includes(index)
        ? [] : [{ ...link, role: parentRoles[index] ?? link.role }]),
      marriages: draft.proposal.marriages.flatMap((marriage, index) => removedMarriages.includes(index)
        ? [] : [{ ...marriage, newPeriod: newPeriods[index] ?? false }]),
      questions: answered ? [] : draft.proposal.questions,
    })
  }

  function name(id: string) {
    const person = rows.find((row) => row.person.id === id)
    return person?.form.givenName.value || t('person.unknown')
  }

  return (
    <section aria-label={t('treeMerge.preview')} className="tree-merge-preview">
      <h3>{t('treeMerge.preview')} · {t('treeMerge.version', { version: draft.proposalVersion })}</h3>
      {draft.questions.length > 0 && (
        <div className="tree-merge-questions">
          <h4>{t('treeMerge.questions')}</h4>
          <ul>{draft.questions.map((question, index) => (
            <li key={index}>{question.startsWith('life_status:') ? t('treeMerge.askLifeStatus', { name: name(question.slice(12)) })
              : question.startsWith('person_match:') ? t('treeMerge.askMatch', { name: name(question.slice(13)) })
                : /^[A-Z_]+$/.test(question) ? t('treeMerge.invalidProposal') : question}</li>
          ))}</ul>
        </div>
      )}
      {rows.map((row, index) => (
        <details className="tree-merge-person" key={row.person.id} open={rows.length === 1 || row.person.resolution === 'undecided'}>
          <summary>{name(row.person.id)} · {t(row.person.resolution === 'existing' ? 'treeMerge.existingPerson' : 'treeMerge.newPerson')}</summary>
          <fieldset disabled={busy || draft.state === 'applied'}>
            <label>{t('treeMerge.match')}
              <select value={row.person.resolution === 'existing' ? row.person.existingPersonId ?? '' : row.person.resolution}
                onChange={(event) => {
                  const value = event.target.value
                  change(index, { ...row, person: { ...row.person,
                    existingPersonId: value === 'new' || value === 'undecided' ? null : value,
                    resolution: value === 'new' || value === 'undecided' ? value : 'existing' } })
                }}>
                <option value="undecided">{t('treeMerge.chooseMatch')}</option>
                <option value="new">{t('treeMerge.newPerson')}</option>
                {row.person.candidates?.map((candidate) => <option key={candidate.id} value={candidate.id}>
                  {candidate.fullName} {candidate.birthYear ?? ''}
                </option>)}
                {row.person.existingPersonId && !row.person.candidates?.some((person) => person.id === row.person.existingPersonId)
                  && <option value={row.person.existingPersonId}>{t('treeMerge.existingPerson')}</option>}
              </select>
            </label>
            <label>{t('treeMerge.lifeStatus')}
              <select value={row.person.lifeStatus} onChange={(event) => {
                const lifeStatus = event.target.value as typeof row.person.lifeStatus
                change(index, { person: { ...row.person, lifeStatus }, form: { ...row.form, deceased: lifeStatus === 'deceased' } })
              }}>
                <option value="uncertain">{t('treeMerge.lifeUncertain')}</option>
                <option value="alive">{t('treeMerge.alive')}</option>
                <option value="deceased">{t('person.deceased')}</option>
              </select>
            </label>
            <PersonFormFields state={row.form} onChange={(form) => change(index, { ...row, form })}
              showPlaces={false} showLifeStatus={false} />
            {row.person.before && (
              <dl className="tree-merge-before">
                {(['birthSurname', 'givenName', 'patronymic'] as const).map((field) => (
                  <div key={field}><dt>{t(`person.${field}`)}</dt><dd>
                    {row.person.before![field].state === 'known' ? row.person.before![field].value : t('person.unknown')} → {row.form[field].unknown ? t('person.unknown') : row.form[field].value}
                  </dd></div>
                ))}
                <div><dt>{t('person.birthDate')}</dt><dd>
                  {formatGenealogicalDate(row.person.before.birthDate, i18n.language, t)} → {formatGenealogicalDate(dateValue(row.form.birthDate) ?? { state: 'unknown' }, i18n.language, t)}
                </dd></div>
                <div><dt>{t('person.gender')}</dt><dd>{t(`person.${row.person.before.gender}`)} → {t(`person.${row.form.gender}`)}</dd></div>
                <div><dt>{t('person.deathDate')}</dt><dd>
                  {row.person.before.deathDate === null ? t('treeMerge.alive') : formatGenealogicalDate(row.person.before.deathDate, i18n.language, t)}
                  {' → '}{row.person.lifeStatus === 'uncertain' ? t('treeMerge.lifeUncertain') : row.person.lifeStatus === 'alive' ? t('treeMerge.alive')
                    : formatGenealogicalDate(dateValue(row.form.deathDate) ?? { state: 'unknown' }, i18n.language, t)}
                </dd></div>
              </dl>
            )}
            {row.person.evidence.map((item, itemIndex) => <blockquote key={itemIndex}>{item.quote}</blockquote>)}
          </fieldset>
        </details>
      ))}
      {draft.proposal.parents.map((link, index) => (
        <div key={`parent-${index}`} className="tree-merge-relation"><label>
          <input type="checkbox" disabled={busy || draft.state === 'applied'} checked={!removedParents.includes(index)}
            onChange={(event) => { setRemovedParents((current) => event.target.checked ? current.filter((item) => item !== index) : [...current, index]); setDirty(true); setConfirmed(false) }} />
          {t('treeMerge.parentRelation', { parent: name(link.parent), child: name(link.child), role: t(`relationships.${parentRoles[index] ?? link.role}`) })}
          </label>
          <label>{t('treeMerge.parentRole', { parent: name(link.parent), child: name(link.child) })}
            <select value={parentRoles[index]} disabled={busy || draft.state === 'applied' || removedParents.includes(index)}
              onChange={(event) => { const role = event.target.value === 'mother' ? 'mother' : 'father'; setParentRoles((current) => current.map((value, at) => at === index ? role : value)); setDirty(true); setConfirmed(false) }}>
              <option value="mother">{t('relationships.mother')}</option>
              <option value="father">{t('relationships.father')}</option>
            </select>
          </label>
          {link.evidence.map((item, itemIndex) => <blockquote key={itemIndex}>{item.quote}</blockquote>)}
        </div>
      ))}
      {draft.proposal.marriages.map((link, index) => (
        <div key={`marriage-${index}`} className="tree-merge-relation">
          <label><input type="checkbox" disabled={busy || draft.state === 'applied'} checked={!removedMarriages.includes(index)}
            onChange={(event) => { setRemovedMarriages((current) => event.target.checked ? current.filter((item) => item !== index) : [...current, index]); setDirty(true); setConfirmed(false) }} />
            {t('treeMerge.marriageRelation', { first: name(link.first), second: name(link.second) })}
          </label>
          <p>{formatGenealogicalDate(link.marriageDate, i18n.language, t)} — {
            link.dissolutionDate === null ? t('relationships.currentMarriage') : formatGenealogicalDate(link.dissolutionDate, i18n.language, t)
          }</p>
          <label><input type="checkbox" checked={newPeriods[index]} disabled={busy || draft.state === 'applied'}
            onChange={(event) => { setNewPeriods((current) => current.map((value, at) => at === index ? event.target.checked : value)); setDirty(true); setConfirmed(false) }} />
            {t('treeMerge.newMarriagePeriod')}
          </label>
          {link.evidence.map((item, itemIndex) => <blockquote key={itemIndex}>{item.quote}</blockquote>)}
        </div>
      ))}
      {draft.state === 'applied' ? <p role="status">{t('treeMerge.applied')}</p> : (
        <div className="tree-merge-confirm">
          {draft.proposal.questions.length > 0 && <label><input type="checkbox" checked={answered}
            onChange={(event) => { setAnswered(event.target.checked); setDirty(true); setConfirmed(false) }} />
            {t('treeMerge.answeredInFields')}
          </label>}
          <button type="button" className="secondary-button" disabled={busy || !dirty || !valid} onClick={() => void save()}>
            {t('treeMerge.checkCorrections')}
          </button>
          {!valid && <p role="alert">{t('treeMerge.invalidFields')}</p>}
          {draft.state === 'conflict' && <button type="button" disabled={busy || dirty} onClick={() => void onRevalidate()}>
            {t('treeMerge.revalidate')}
          </button>}
          {draft.state === 'no_changes' && <p>{t('treeMerge.noChanges')}</p>}
          <label><input type="checkbox" checked={confirmed} disabled={busy || dirty || draft.state !== 'ready'}
            onChange={(event) => setConfirmed(event.target.checked)} />
            {t('treeMerge.confirmVersion', { version: draft.proposalVersion })}
          </label>
          <button type="button" className="primary-button" disabled={busy || dirty || !confirmed || draft.state !== 'ready'}
            onClick={() => void onApply()}>{t('treeMerge.apply')}</button>
        </div>
      )}
    </section>
  )
}
