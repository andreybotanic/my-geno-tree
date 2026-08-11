import { useMemo, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  ParentRole,
  Person,
  ResolvedEventPlace,
  RelativePerson,
  TreeSnapshot,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  emptyMarriageForm,
  marriageFormValue,
  type MarriageFormState,
} from '../marriageFormState'
import { formatPersonName } from '../personFormatting'
import { MarriageFormFields } from './MarriageFormFields'
import { PersonFormDialog } from './PersonFormDialog'

export type RelativeAction = 'child' | 'father' | 'mother' | 'spouse'

export type RelativeOptions = {
  dissolutionDate: GenealogicalDate | null
  dissolutionPlace: ResolvedEventPlace | null
  femaleNewSurname: string | null
  maleNewSurname: string | null
  marriageDate: GenealogicalDate
  marriagePlace: ResolvedEventPlace | null
  parentRole: ParentRole
  secondParentId: string | null
}

type RelativeDialogProps = {
  action: RelativeAction
  anchor: Person
  fixedParentIds?: readonly [string, string] | undefined
  fixedRelativeId?: string | undefined
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
  fixedParentIds,
  fixedRelativeId,
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
  const [marriage, setMarriage] = useState(emptyMarriageForm)
  const candidates = useMemo(
    () => snapshot.people.filter((person) => person.id !== anchor.id),
    [anchor.id, snapshot.people],
  )
  const fixedParents = fixedParentIds?.map((id) => (
    snapshot.people.find((person) => person.id === id)
  )).filter((person): person is Person => person !== undefined)
  const hasFixedRelative = fixedRelativeId !== undefined
  const title = t(`relationships.add.${action}`)

  const options = (): RelativeOptions | null => {
    const marriageValue = action === 'spouse'
      ? marriageFormValue(marriage)
      : null
    if (action === 'spouse' && marriageValue === null) {
      return null
    }

    return {
      dissolutionDate: marriageValue?.dissolutionDate ?? null,
      dissolutionPlace: marriageValue?.dissolutionPlace ?? null,
      femaleNewSurname: marriageValue?.femaleNewSurname ?? null,
      maleNewSurname: marriageValue?.maleNewSurname ?? null,
      marriageDate: marriageValue?.marriageDate ?? { state: 'unknown' },
      marriagePlace: marriageValue?.marriagePlace ?? null,
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
    const personId = fixedRelativeId ?? existingPersonId
    if (!personId) {
      setError(t('relationships.choosePerson'))
      return
    }
    const values = options()
    if (values === null) {
      setError(t('relationships.marriageDatesRequired'))
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

  const relationshipFields = (
    <RelationshipFields
      action={action}
      anchor={anchor}
      candidates={candidates}
      fixedParents={fixedParents}
      marriage={marriage}
      parentRole={parentRole}
      secondParentId={secondParentId}
      setMarriage={setMarriage}
      setParentRole={setParentRole}
      setSecondParentId={setSecondParentId}
    />
  )

  if (mode === 'new' && !hasFixedRelative) {
    return (
      <PersonFormDialog
        initialGender={action === 'father'
          ? 'male'
          : action === 'mother'
            ? 'female'
            : action === 'spouse'
              ? spouseGender(anchor)
              : 'unknown'}
        onCancel={onCancel}
        onSave={(person) => save({ person })}
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
  fixedParents: Person[] | undefined
  marriage: MarriageFormState
  parentRole: ParentRole
  secondParentId: string
  setMarriage: (value: MarriageFormState) => void
  setParentRole: (value: ParentRole) => void
  setSecondParentId: (value: string) => void
}

function RelationshipFields({
  action,
  anchor,
  candidates,
  fixedParents,
  marriage,
  parentRole,
  secondParentId,
  setMarriage,
  setParentRole,
  setSecondParentId,
}: RelationshipFieldsProps) {
  const { t } = useTranslation()
  if (action === 'child') {
    if (fixedParents) {
      return (
        <div className="person-select-field relationship-wide-field">
          <span>{t('relationships.parents')}</span>
          <strong>{fixedParents.map((person) => formatPersonName(person, t)).join(', ')}</strong>
        </div>
      )
    }

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
    <MarriageFormFields onChange={setMarriage} state={marriage} />
  )
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
