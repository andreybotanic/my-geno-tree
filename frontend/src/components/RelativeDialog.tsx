import { useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  ParentRole,
  Person,
  RelativePerson,
  ResolvedEventPlace,
  TreeSnapshot,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  emptyMarriageForm,
  marriageFormValue,
  type MarriageFormState,
} from '../marriageFormState'
import {
  emptyPersonForm,
  personFormValue,
  type PersonFormState,
} from '../personFormState'
import { formatPersonName } from '../personFormatting'
import {
  MarriageFormFields,
  type SurnameSource,
} from './MarriageFormFields'
import { PersonFormDialog, PersonFormFields } from './PersonFormDialog'

export type RelativeAction = 'child' | 'father' | 'mother' | 'spouse'

export type RelativeOptions = {
  dissolutionDate: GenealogicalDate | null
  dissolutionPlace: ResolvedEventPlace | null
  femaleNewSurname: string | null
  maleNewSurname: string | null
  marriageDate: GenealogicalDate
  marriagePlace: ResolvedEventPlace | null
  parentRole: ParentRole
  secondParent: RelativePerson | null
}

type RelativeDialogProps = {
  action: RelativeAction
  anchor: Person
  fixedParentIds?: readonly [string, string] | undefined
  fixedRelativeId?: string | undefined
  initialSecondParentId?: string | undefined
  onCancel: () => void
  onDeterminePatronymic?: ((
    fatherGivenName: string,
    childGender: 'female' | 'male',
  ) => Promise<string | null>) | undefined
  onLocalizeSurname?: ((
    surname: string,
    sourceGender: SurnameSource['gender'],
    targetGender: SurnameSource['gender'],
  ) => Promise<string>) | undefined
  onSave: (
    relative: RelativePerson,
    options: RelativeOptions,
  ) => Promise<void>
  snapshot: TreeSnapshot
}

type ParentSource = 'existing' | 'new' | 'unknown'

const parentRoleFor = (person: Person): ParentRole | '' => (
  person.gender === 'female'
    ? 'mother'
    : person.gender === 'male'
      ? 'father'
      : ''
)

const oppositeParentRole = (role: ParentRole): ParentRole => (
  role === 'father' ? 'mother' : 'father'
)

const genderForParentRole = (role: ParentRole): 'female' | 'male' => (
  role === 'father' ? 'male' : 'female'
)

export function RelativeDialog({
  action,
  anchor,
  fixedParentIds,
  fixedRelativeId,
  initialSecondParentId,
  onCancel,
  onDeterminePatronymic,
  onLocalizeSurname,
  onSave,
  snapshot,
}: RelativeDialogProps) {
  const { t } = useTranslation()
  const [mode, setMode] = useState<'existing' | 'new'>('new')
  const [existingPersonId, setExistingPersonId] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [parentRole, setParentRole] = useState<ParentRole | ''>(
    () => parentRoleFor(anchor),
  )
  const [secondParentId, setSecondParentId] = useState(
    initialSecondParentId ?? '',
  )
  const [secondParentSource, setSecondParentSource] = useState<ParentSource>(
    initialSecondParentId ? 'existing' : 'unknown',
  )
  const [newSecondParent, setNewSecondParent] = useState(() => emptyPersonForm(
    parentRoleFor(anchor) === 'father' ? 'female' : 'male',
  ))
  const [marriage, setMarriage] = useState(emptyMarriageForm)
  const candidates = useMemo(
    () => snapshot.people.filter((person) => person.id !== anchor.id),
    [anchor.id, snapshot.people],
  )
  const spouseIds = useMemo(() => {
    const ids = new Set<string>()
    for (const marriage of snapshot.marriages) {
      if (marriage.malePersonId === anchor.id) {
        ids.add(marriage.femalePersonId)
      } else if (marriage.femalePersonId === anchor.id) {
        ids.add(marriage.malePersonId)
      }
    }

    return ids
  }, [anchor.id, snapshot.marriages])
  const fixedParents = fixedParentIds?.map((id) => (
    snapshot.people.find((person) => person.id === id)
  )).filter((person): person is Person => person !== undefined)
  const hasFixedRelative = fixedRelativeId !== undefined
  const title = t(`relationships.add.${action}`)

  const changeParentRole = (value: ParentRole) => {
    setParentRole(value)
    setSecondParentId('')
    setSecondParentSource('unknown')
    setNewSecondParent(emptyPersonForm(genderForParentRole(oppositeParentRole(value))))
  }

  const options = (): RelativeOptions | null => {
    const marriageValue = action === 'spouse'
      ? marriageFormValue(marriage)
      : null
    if (action === 'spouse' && marriageValue === null) {
      return null
    }
    if (action === 'child' && !fixedParents && parentRole === '') {
      return null
    }

    const newSecondParentValue = secondParentSource === 'new'
      ? personFormValue(newSecondParent)
      : null
    if (secondParentSource === 'new' && newSecondParentValue === null) {
      return null
    }

    return {
      dissolutionDate: marriageValue?.dissolutionDate ?? null,
      dissolutionPlace: marriageValue?.dissolutionPlace ?? null,
      femaleNewSurname: marriageValue?.femaleNewSurname ?? null,
      maleNewSurname: marriageValue?.maleNewSurname ?? null,
      marriageDate: marriageValue?.marriageDate ?? { state: 'unknown' },
      marriagePlace: marriageValue?.marriagePlace ?? null,
      parentRole: parentRole || 'father',
      secondParent: secondParentSource === 'existing' && secondParentId
        ? { personId: secondParentId }
        : secondParentSource === 'new' && newSecondParentValue
          ? { person: newSecondParentValue }
          : null,
    }
  }

  const save = async (relative: RelativePerson) => {
    const values = options()
    if (values === null) {
      throw new Error('invalid relationship values')
    }
    await onSave(relative, values)
  }

  const saveExisting = async (event: FormEvent) => {
    event.preventDefault()
    const personId = fixedRelativeId ?? existingPersonId
    if (!personId) {
      setError(t('relationships.choosePerson'))
      return
    }
    const values = options()
    if (values === null) {
      setError(action === 'child'
        ? t('relationships.chooseParentRole')
        : t('relationships.marriageDatesRequired'))
      return
    }
    setSaving(true)
    setError('')
    try {
      await onSave({ personId }, values)
    } catch (caught) {
      setError(
        caught instanceof ApiRequestError
          && caught.body.code === 'TREE_VERSION_CONFLICT'
          ? t('editor.versionConflict')
          : t('relationships.saveFailed'),
      )
    } finally {
      setSaving(false)
    }
  }

  const fatherGivenName = () => {
    if (fixedParents) {
      return knownGivenName(fixedParents.find((person) => person.gender === 'male'))
    }
    if (parentRole === 'father') {
      return knownGivenName(anchor)
    }
    if (parentRole === 'mother' && secondParentSource === 'new') {
      return knownGivenName(newSecondParent)
    }
    if (parentRole === 'mother' && secondParentSource === 'existing') {
      return knownGivenName(candidates.find((person) => person.id === secondParentId))
    }

    return undefined
  }

  const relationshipFields = (newPerson?: PersonFormState) => (
    <RelationshipFields
      action={action}
      anchor={anchor}
      candidates={candidates}
      existingPerson={candidates.find((person) => (
        person.id === (fixedRelativeId ?? existingPersonId)
      ))}
      fixedParents={fixedParents}
      marriage={marriage}
      newPerson={newPerson}
      newSecondParent={newSecondParent}
      onLocalizeSurname={onLocalizeSurname}
      parentRole={parentRole}
      secondParentId={secondParentId}
      secondParentSource={secondParentSource}
      setMarriage={setMarriage}
      setNewSecondParent={setNewSecondParent}
      setParentRole={changeParentRole}
      setSecondParentId={setSecondParentId}
      setSecondParentSource={setSecondParentSource}
      spouseIds={spouseIds}
    />
  )

  if (mode === 'new' && !hasFixedRelative) {
    return (
      <PersonFormDialog
        beforeFields={action === 'child' ? (
          <>
            {relationshipFields()}
            <ModeSelector
              candidatesAvailable={candidates.length > 0}
              mode={mode}
              setMode={setMode}
            />
          </>
        ) : undefined}
        initialGender={action === 'father'
          ? 'male'
          : action === 'mother'
            ? 'female'
            : action === 'spouse'
              ? spouseGender(anchor)
              : 'unknown'}
        onCancel={onCancel}
        onSave={(person) => save({ person })}
        patronymicSuggestion={action === 'child' && onDeterminePatronymic ? {
          fatherGivenName: fatherGivenName(),
          onDetermine: onDeterminePatronymic,
        } : undefined}
        title={title}
      >
        {action === 'child' ? undefined : (newPerson) => (
          <>
            <ModeSelector
              candidatesAvailable={candidates.length > 0}
              mode={mode}
              setMode={setMode}
            />
            {relationshipFields(newPerson)}
          </>
        )}
      </PersonFormDialog>
    )
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="relative-dialog-title"
        aria-modal="true"
        className="dialog-card person-dialog"
        role="dialog"
      >
        <h2 id="relative-dialog-title">{title}</h2>
        <form onSubmit={saveExisting}>
          <div className="person-form-grid">
            {action === 'child' && relationshipFields()}
            {!hasFixedRelative && (
              <>
                <ModeSelector
                  candidatesAvailable={candidates.length > 0}
                  mode={mode}
                  setMode={setMode}
                />
                <label className="person-select-field relationship-wide-field">
                  <span>{t('relationships.person')}</span>
                  <select
                    aria-label={t('relationships.person')}
                    onChange={(event) => setExistingPersonId(event.target.value)}
                    value={existingPersonId}
                  >
                    <option value="">{t('relationships.choosePerson')}</option>
                    {candidates.map((person) => (
                      <option key={person.id} value={person.id}>
                        {formatPersonName(person, t)}
                      </option>
                    ))}
                  </select>
                </label>
              </>
            )}
            {action !== 'child' && relationshipFields()}
          </div>
          {error && <p className="form-error" role="alert">{error}</p>}
          <div className="dialog-actions">
            <button
              className="secondary-button"
              onClick={onCancel}
              type="button"
            >
              {t('common.cancel')}
            </button>
            <button className="primary-button" disabled={saving}>
              {saving ? t('common.saving') : t('common.save')}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}

type ModeSelectorProps = {
  candidatesAvailable: boolean
  mode: 'existing' | 'new'
  setMode: (mode: 'existing' | 'new') => void
}

function ModeSelector({
  candidatesAvailable,
  mode,
  setMode,
}: ModeSelectorProps) {
  const { t } = useTranslation()

  return (
    <fieldset className="relationship-mode relationship-wide-field">
      <legend>{t('relationships.source')}</legend>
      <label className="choice-control">
        <input
          aria-label={t('relationships.newPerson')}
          checked={mode === 'new'}
          onChange={() => setMode('new')}
          type="radio"
        />
        <span>{t('relationships.newPerson')}</span>
      </label>
      <label className="choice-control">
        <input
          aria-label={t('relationships.existingPerson')}
          checked={mode === 'existing'}
          disabled={!candidatesAvailable}
          onChange={() => setMode('existing')}
          type="radio"
        />
        <span>{t('relationships.existingPerson')}</span>
      </label>
    </fieldset>
  )
}

type RelationshipFieldsProps = {
  action: RelativeAction
  anchor: Person
  candidates: Person[]
  existingPerson: Person | undefined
  fixedParents: Person[] | undefined
  marriage: MarriageFormState
  newPerson: PersonFormState | undefined
  newSecondParent: PersonFormState
  onLocalizeSurname?: ((
    surname: string,
    sourceGender: SurnameSource['gender'],
    targetGender: SurnameSource['gender'],
  ) => Promise<string>) | undefined
  parentRole: ParentRole | ''
  secondParentId: string
  secondParentSource: ParentSource
  setMarriage: (value: MarriageFormState) => void
  setNewSecondParent: (value: PersonFormState) => void
  setParentRole: (value: ParentRole) => void
  setSecondParentId: (value: string) => void
  setSecondParentSource: (value: ParentSource) => void
  spouseIds: ReadonlySet<string>
}

function RelationshipFields({
  action,
  anchor,
  candidates,
  existingPerson,
  fixedParents,
  marriage,
  newPerson,
  newSecondParent,
  onLocalizeSurname,
  parentRole,
  secondParentId,
  secondParentSource,
  setMarriage,
  setNewSecondParent,
  setParentRole,
  setSecondParentId,
  setSecondParentSource,
  spouseIds,
}: RelationshipFieldsProps) {
  const { t } = useTranslation()
  if (action === 'child') {
    if (fixedParents) {
      return (
        <>
          <ParentSummary
            person={fixedParents.find((person) => person.gender === 'female')}
            role="mother"
          />
          <ParentSummary
            person={fixedParents.find((person) => person.gender === 'male')}
            role="father"
          />
        </>
      )
    }

    if (parentRole === '') {
      return (
        <label className="person-select-field relationship-wide-field">
          <span>{t('relationships.selectedParentRole')}</span>
          <select
            aria-label={t('relationships.selectedParentRole')}
            onChange={(event) => setParentRole(event.target.value as ParentRole)}
            required
            value=""
          >
            <option value="">{t('relationships.chooseParentRole')}</option>
            <option value="father">{t('relationships.father')}</option>
            <option value="mother">{t('relationships.mother')}</option>
          </select>
        </label>
      )
    }

    const secondParentRole = oppositeParentRole(parentRole)
    const secondParent = candidates.find((person) => person.id === secondParentId)

    return (
      <>
        {parentRole === 'father' ? (
          <SecondParentField
            candidates={candidates}
            person={secondParent}
            role="mother"
            secondParentSource={secondParentSource}
            setNewSecondParent={setNewSecondParent}
            setSecondParentId={setSecondParentId}
            setSecondParentSource={setSecondParentSource}
            spouseIds={spouseIds}
          />
        ) : (
          <ParentSummary person={anchor} role="mother" />
        )}
        {parentRole === 'mother' ? (
          <SecondParentField
            candidates={candidates}
            person={secondParent}
            role="father"
            secondParentSource={secondParentSource}
            setNewSecondParent={setNewSecondParent}
            setSecondParentId={setSecondParentId}
            setSecondParentSource={setSecondParentSource}
            spouseIds={spouseIds}
          />
        ) : (
          <ParentSummary person={anchor} role="father" />
        )}
        {secondParentSource === 'new' && (
          <fieldset className="child-new-parent-fields relationship-wide-field">
            <legend>{t(`relationships.${secondParentRole}`)}</legend>
            <div className="person-form-grid">
              <PersonFormFields
                fixedGender
                onChange={setNewSecondParent}
                state={newSecondParent}
              />
            </div>
          </fieldset>
        )}
      </>
    )
  }
  if (action !== 'spouse') {
    return null
  }

  const spouse = existingPerson ?? newPerson
  const surnames = [surnameSource(anchor), spouse && surnameSource(spouse)]
  const femaleSurnameSource = surnames.find(
    (source) => source?.gender === 'female',
  )
  const maleSurnameSource = surnames.find(
    (source) => source?.gender === 'male',
  )

  return (
    <MarriageFormFields
      {...(femaleSurnameSource ? { femaleSurnameSource } : {})}
      {...(maleSurnameSource ? { maleSurnameSource } : {})}
      onChange={setMarriage}
      onLocalizeSurname={onLocalizeSurname}
      state={marriage}
    />
  )
}

type ParentSummaryProps = {
  person: Person | undefined
  role: ParentRole
}

function ParentSummary({ person, role }: ParentSummaryProps) {
  const { t } = useTranslation()

  return (
    <div className="child-parent-summary">
      <span>{t(`relationships.${role}`)}</span>
      <strong>{person ? formatPersonName(person, t) : t('person.unknown')}</strong>
    </div>
  )
}

type SecondParentFieldProps = {
  candidates: Person[]
  person: Person | undefined
  role: ParentRole
  secondParentSource: ParentSource
  setNewSecondParent: (value: PersonFormState) => void
  setSecondParentId: (value: string) => void
  setSecondParentSource: (value: ParentSource) => void
  spouseIds: ReadonlySet<string>
}

function SecondParentField({
  candidates,
  person,
  role,
  secondParentSource,
  setNewSecondParent,
  setSecondParentId,
  setSecondParentSource,
  spouseIds,
}: SecondParentFieldProps) {
  const { t } = useTranslation()
  const eligible = candidates.filter((candidate) => (
    candidate.gender === genderForParentRole(role)
  ))
  const spouses = eligible.filter((candidate) => spouseIds.has(candidate.id))
  const otherPeople = eligible.filter((candidate) => !spouseIds.has(candidate.id))

  if (secondParentSource === 'new') {
    return (
      <div className="child-parent-summary">
        <span>{t(`relationships.${role}`)}</span>
        <strong>{t('relationships.newPerson')}</strong>
        <button
          className="surname-adoption-button"
          onClick={() => {
            setSecondParentSource('unknown')
            setSecondParentId('')
          }}
          type="button"
        >
          {t('relationships.chooseExistingParent')}
        </button>
      </div>
    )
  }

  return (
    <div className="child-parent-selector">
      <span>{t(`relationships.${role}`)}</span>
      <select
        aria-label={t(`relationships.${role}`)}
        onChange={(event) => {
          setSecondParentId(event.target.value)
          setSecondParentSource(event.target.value ? 'existing' : 'unknown')
        }}
        value={secondParentSource === 'existing' ? person?.id ?? '' : ''}
      >
        <option value="">{t('relationships.unknownParent')}</option>
        {spouses.length > 0 ? (
          <>
            <optgroup label={t('relationships.spouses')}>
              {spouses.map((candidate) => (
                <option key={candidate.id} value={candidate.id}>
                  {formatPersonName(candidate, t)}
                </option>
              ))}
            </optgroup>
            {otherPeople.length > 0 && (
              <optgroup label={t('relationships.otherPeople')}>
                {otherPeople.map((candidate) => (
                  <option key={candidate.id} value={candidate.id}>
                    {formatPersonName(candidate, t)}
                  </option>
                ))}
              </optgroup>
            )}
          </>
        ) : eligible.map((candidate) => (
          <option key={candidate.id} value={candidate.id}>
            {formatPersonName(candidate, t)}
          </option>
        ))}
      </select>
      <button
        className="surname-adoption-button"
        onClick={() => {
          setNewSecondParent(emptyPersonForm(genderForParentRole(role)))
          setSecondParentId('')
          setSecondParentSource('new')
        }}
        type="button"
      >
        {t('relationships.createSecondParent', { role: t(`relationships.${role}`).toLocaleLowerCase() })}
      </button>
    </div>
  )
}

function knownGivenName(person: Person | PersonFormState | undefined): string | undefined {
  if (!person) {
    return undefined
  }

  const givenName = person.givenName
  if ('unknown' in givenName) {
    return givenName.unknown ? undefined : givenName.value.trim() || undefined
  }

  return givenName.state === 'known' ? givenName.value : undefined
}

function surnameSource(person: Person | PersonFormState): SurnameSource | null {
  if (person.gender === 'unknown') return null

  const surname = 'unknown' in person.birthSurname
    ? person.birthSurname.unknown ? '' : person.birthSurname.value.trim()
    : person.birthSurname.state === 'known' ? person.birthSurname.value : ''

  return surname ? { gender: person.gender, surname } : null
}

function spouseGender(person: Person): Person['gender'] {
  if (person.gender === 'female') {
    return 'male'
  }

  if (person.gender === 'male') {
    return 'female'
  }

  return 'unknown'
}
