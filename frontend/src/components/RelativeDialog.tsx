import { useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  ParentRole,
  Person,
  RelativePerson,
  TreeSnapshot,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  dateValue,
  emptyDate,
  type DateInputState,
} from '../personFormValues'
import { formatPersonName } from '../personFormatting'
import {
  PersonFormDialog,
  StructuredDateInput,
} from './PersonFormDialog'

export type RelativeAction = 'child' | 'father' | 'mother' | 'spouse'

export type RelativeOptions = {
  dissolutionDate: GenealogicalDate | null
  femaleNewSurname: string | null
  maleNewSurname: string | null
  marriageDate: GenealogicalDate
  parentRole: ParentRole
  secondParentId: string | null
}

type RelativeDialogProps = {
  action: RelativeAction
  anchor: Person
  initialSecondParentId?: string | undefined
  onCancel: () => void
  onSave: (
    relative: RelativePerson,
    options: RelativeOptions,
  ) => Promise<void>
  snapshot: TreeSnapshot
}

const defaultRole = (person: Person): ParentRole => (
  person.gender === 'female' ? 'mother' : 'father'
)

export function RelativeDialog({
  action,
  anchor,
  initialSecondParentId,
  onCancel,
  onSave,
  snapshot,
}: RelativeDialogProps) {
  const { t } = useTranslation()
  const [mode, setMode] = useState<'existing' | 'new'>('new')
  const [existingPersonId, setExistingPersonId] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [parentRole, setParentRole] = useState<ParentRole>(
    defaultRole(anchor),
  )
  const [secondParentId, setSecondParentId] = useState(
    initialSecondParentId ?? '',
  )
  const [marriageDate, setMarriageDate] = useState(emptyDate)
  const [dissolved, setDissolved] = useState(false)
  const [dissolutionDate, setDissolutionDate] = useState(emptyDate)
  const [maleNewSurname, setMaleNewSurname] = useState('')
  const [femaleNewSurname, setFemaleNewSurname] = useState('')
  const candidates = useMemo(
    () => snapshot.people.filter((person) => person.id !== anchor.id),
    [anchor.id, snapshot.people],
  )
  const title = t(`relationships.add.${action}`)

  const options = (): RelativeOptions | null => {
    const marriage = dateValue(marriageDate)
    const dissolution = dissolved ? dateValue(dissolutionDate) : null
    if (
      action === 'spouse'
      && (marriage === null || (dissolved && dissolution === null))
    ) {
      return null
    }

    return {
      dissolutionDate: action === 'spouse' ? dissolution : null,
      femaleNewSurname: optionalText(femaleNewSurname),
      maleNewSurname: optionalText(maleNewSurname),
      marriageDate: marriage ?? { state: 'unknown' },
      parentRole,
      secondParentId: secondParentId || null,
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
    if (!existingPersonId) {
      setError(t('relationships.choosePerson'))
      return
    }
    setSaving(true)
    setError('')
    try {
      await save({ personId: existingPersonId })
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

  const relationshipFields = (
    <RelationshipFields
      action={action}
      anchor={anchor}
      candidates={candidates}
      dissolved={dissolved}
      dissolutionDate={dissolutionDate}
      femaleNewSurname={femaleNewSurname}
      maleNewSurname={maleNewSurname}
      marriageDate={marriageDate}
      parentRole={parentRole}
      secondParentId={secondParentId}
      setDissolved={setDissolved}
      setDissolutionDate={setDissolutionDate}
      setFemaleNewSurname={setFemaleNewSurname}
      setMaleNewSurname={setMaleNewSurname}
      setMarriageDate={setMarriageDate}
      setParentRole={setParentRole}
      setSecondParentId={setSecondParentId}
    />
  )

  if (mode === 'new') {
    return (
      <PersonFormDialog
        onCancel={onCancel}
        onSave={(person) => save({
          person,
          coordinates: { x: 0, y: 0 },
        })}
        title={title}
      >
        <ModeSelector
          candidatesAvailable={candidates.length > 0}
          mode={mode}
          setMode={setMode}
        />
        {relationshipFields}
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
            {relationshipFields}
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
  dissolved: boolean
  dissolutionDate: DateInputState
  femaleNewSurname: string
  maleNewSurname: string
  marriageDate: DateInputState
  parentRole: ParentRole
  secondParentId: string
  setDissolved: (value: boolean) => void
  setDissolutionDate: (value: DateInputState) => void
  setFemaleNewSurname: (value: string) => void
  setMaleNewSurname: (value: string) => void
  setMarriageDate: (value: DateInputState) => void
  setParentRole: (value: ParentRole) => void
  setSecondParentId: (value: string) => void
}

function RelationshipFields({
  action,
  anchor,
  candidates,
  dissolved,
  dissolutionDate,
  femaleNewSurname,
  maleNewSurname,
  marriageDate,
  parentRole,
  secondParentId,
  setDissolved,
  setDissolutionDate,
  setFemaleNewSurname,
  setMaleNewSurname,
  setMarriageDate,
  setParentRole,
  setSecondParentId,
}: RelationshipFieldsProps) {
  const { t } = useTranslation()
  if (action === 'child') {
    return (
      <>
        <label className="person-select-field">
          <span>{t('relationships.selectedParentRole')}</span>
          <select
            onChange={(event) => setParentRole(
              event.target.value as ParentRole,
            )}
            value={parentRole}
          >
            <option value="father">{t('relationships.father')}</option>
            <option value="mother">{t('relationships.mother')}</option>
          </select>
        </label>
        <label className="person-select-field">
          <span>{t('relationships.secondParent')}</span>
          <select
            onChange={(event) => setSecondParentId(event.target.value)}
            value={secondParentId}
          >
            <option value="">{t('relationships.unknownParent')}</option>
            {candidates
              .filter((person) => person.id !== anchor.id)
              .map((person) => (
                <option key={person.id} value={person.id}>
                  {formatPersonName(person, t)}
                </option>
              ))}
          </select>
        </label>
      </>
    )
  }
  if (action !== 'spouse') {
    return null
  }

  return (
    <>
      <StructuredDateInput
        label={t('relationships.marriageDate')}
        onChange={setMarriageDate}
        state={marriageDate}
      />
      <label
        className="deceased-toggle choice-control conditional-date-toggle"
      >
        <input
          checked={dissolved}
          onChange={(event) => setDissolved(event.target.checked)}
          type="checkbox"
        />
        <span>{t('relationships.dissolved')}</span>
      </label>
      {dissolved && (
        <StructuredDateInput
          label={t('relationships.dissolutionDate')}
          onChange={setDissolutionDate}
          state={dissolutionDate}
        />
      )}
      <label className="person-select-field">
        <span>{t('relationships.maleNewSurname')}</span>
        <input
          maxLength={100}
          onChange={(event) => setMaleNewSurname(event.target.value)}
          value={maleNewSurname}
        />
      </label>
      <label className="person-select-field">
        <span>{t('relationships.femaleNewSurname')}</span>
        <input
          maxLength={100}
          onChange={(event) => setFemaleNewSurname(event.target.value)}
          value={femaleNewSurname}
        />
      </label>
    </>
  )
}

function optionalText(value: string) {
  const trimmed = value.trim()
  return trimmed || null
}
