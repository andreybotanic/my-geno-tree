import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  KnownText,
  PersonInput,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  dateValue,
  unknownDate,
  type DateInputState,
} from '../personFormValues'
import { UnknownToggleButton } from './UnknownToggleButton'

type TextInputState = {
  unknown: boolean
  value: string
}

const emptyText = (): TextInputState => ({ unknown: false, value: '' })
const emptyDate = (): DateInputState => ({
  ...unknownDate(),
  unknown: false,
})

function textValue(state: TextInputState): KnownText | null {
  const value = state.value.trim()
  if (state.unknown) {
    return { state: 'unknown' }
  }

  return value && value.length <= 100
    ? { state: 'known', value }
    : null
}

type KnownTextInputProps = {
  autoFocus?: boolean
  label: string
  onChange: (state: TextInputState) => void
  state: TextInputState
}

function KnownTextInput({
  autoFocus = false,
  label,
  onChange,
  state,
}: KnownTextInputProps) {
  const { t } = useTranslation()

  return (
    <div aria-label={label} className="person-text-field" role="group">
      <span className="person-field-label">{label}</span>
      <div className="unknown-field-control">
        <input
          aria-label={label}
          autoFocus={autoFocus && !state.unknown}
          disabled={state.unknown}
          maxLength={101}
          onChange={(event) => onChange({
            ...state,
            value: event.target.value,
          })}
          value={state.value}
        />
        <UnknownToggleButton
          active={state.unknown}
          onChange={(unknown) => onChange({
            unknown,
            value: unknown ? '' : state.value,
          })}
          title={t('person.unknown')}
        />
      </div>
    </div>
  )
}

type StructuredDateInputProps = {
  label: string
  onChange: (state: DateInputState) => void
  state: DateInputState
}

export function StructuredDateInput({
  label,
  onChange,
  state,
}: StructuredDateInputProps) {
  const { t } = useTranslation()

  return (
    <fieldset className="person-fieldset date-fieldset">
      <legend>{label}</legend>
      <div className="date-field-control">
        <div className="date-input-row">
          <div className="date-controls">
            <label>
              <span>{t('person.precision')}</span>
              <select
                aria-label={`${label}: ${t('person.precision')}`}
                disabled={state.unknown}
                onChange={(event) => onChange({
                  ...state,
                  approximate: event.target.value === 'year'
                    ? state.approximate
                    : false,
                  precision: event.target.value as DateInputState['precision'],
                })}
                value={state.precision}
              >
                <option value="year">{t('person.year')}</option>
                <option value="month">{t('person.month')}</option>
                <option value="day">{t('person.day')}</option>
              </select>
            </label>
            <label>
              <span>{t('person.year')}</span>
              <input
                aria-label={`${label}: ${t('person.year')}`}
                disabled={state.unknown}
                inputMode="numeric"
                max="9999"
                min="1582"
                onChange={(event) => onChange({
                  ...state,
                  year: event.target.value,
                })}
                type="number"
                value={state.year}
              />
            </label>
            {state.precision !== 'year' && (
              <label>
                <span>{t('person.month')}</span>
                <input
                  aria-label={`${label}: ${t('person.month')}`}
                  disabled={state.unknown}
                  inputMode="numeric"
                  max="12"
                  min="1"
                  onChange={(event) => onChange({
                    ...state,
                    month: event.target.value,
                  })}
                  type="number"
                  value={state.month}
                />
              </label>
            )}
            {state.precision === 'day' && (
              <label>
                <span>{t('person.day')}</span>
                <input
                  aria-label={`${label}: ${t('person.day')}`}
                  disabled={state.unknown}
                  inputMode="numeric"
                  max="31"
                  min="1"
                  onChange={(event) => onChange({
                    ...state,
                    day: event.target.value,
                  })}
                  type="number"
                  value={state.day}
                />
              </label>
            )}
          </div>
          <UnknownToggleButton
            active={state.unknown}
            onChange={(unknown) => onChange(unknown
              ? {
                  ...state,
                  approximate: false,
                  day: '',
                  month: '',
                  unknown: true,
                  year: '',
                }
              : { ...state, unknown: false })}
            title={t('person.unknown')}
          />
        </div>
        {state.precision === 'year' && (
          <label className="approximate-toggle">
            <input
              checked={state.approximate}
              disabled={state.unknown}
              onChange={(event) => onChange({
                ...state,
                approximate: event.target.checked,
              })}
              type="checkbox"
            />
            <span>{t('person.approximate')}</span>
          </label>
        )}
      </div>
    </fieldset>
  )
}

type PersonFormDialogProps = {
  children?: ReactNode
  onCancel: () => void
  onSave: (person: PersonInput) => Promise<void>
  title?: string
}

export function PersonFormDialog({
  children,
  onCancel,
  onSave,
  title,
}: PersonFormDialogProps) {
  const { t } = useTranslation()
  const [birthSurname, setBirthSurname] = useState(emptyText)
  const [givenName, setGivenName] = useState(emptyText)
  const [patronymic, setPatronymic] = useState(emptyText)
  const [gender, setGender] = useState<PersonInput['gender']>('unknown')
  const [birthDate, setBirthDate] = useState(emptyDate)
  const [deathDate, setDeathDate] = useState(emptyDate)
  const [deceased, setDeceased] = useState(false)
  const [error, setError] = useState('')
  const [isSaving, setIsSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const surnameValue = textValue(birthSurname)
    const givenNameValue = textValue(givenName)
    const patronymicValue = textValue(patronymic)
    const birthDateValue = dateValue(birthDate)
    const deathDateValue = deceased ? dateValue(deathDate) : null
    if (
      surnameValue === null
      || givenNameValue === null
      || patronymicValue === null
      || birthDateValue === null
      || (deceased && deathDateValue === null)
    ) {
      setError(t('person.invalidForm'))
      return
    }

    setError('')
    setIsSaving(true)
    try {
      await onSave({
        birthSurname: surnameValue,
        givenName: givenNameValue,
        patronymic: patronymicValue,
        gender,
        birthDate: birthDateValue,
        deathDate: deathDateValue,
      })
    } catch (caught) {
      setError(
        caught instanceof ApiRequestError
          && caught.body.code === 'TREE_VERSION_CONFLICT'
          ? t('trees.versionConflict')
          : t('person.saveFailed'),
      )
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="person-dialog-title"
        aria-modal="true"
        className="dialog-card person-dialog"
        role="dialog"
      >
        <h2 id="person-dialog-title">{title ?? t('person.createTitle')}</h2>
        <form onSubmit={submit}>
          <div className="person-form-grid">
            <KnownTextInput
              autoFocus
              label={t('person.birthSurname')}
              onChange={setBirthSurname}
              state={birthSurname}
            />
            <KnownTextInput
              label={t('person.givenName')}
              onChange={setGivenName}
              state={givenName}
            />
            <KnownTextInput
              label={t('person.patronymic')}
              onChange={setPatronymic}
              state={patronymic}
            />
            <label className="person-select-field">
              <span>{t('person.gender')}</span>
              <select
                onChange={(event) => setGender(
                  event.target.value as PersonInput['gender'],
                )}
                value={gender}
              >
                <option value="unknown">{t('person.unknown')}</option>
                <option value="male">{t('person.male')}</option>
                <option value="female">{t('person.female')}</option>
              </select>
            </label>
            <StructuredDateInput
              label={t('person.birthDate')}
              onChange={setBirthDate}
              state={birthDate}
            />
            <div className="death-date-row">
              <label className="deceased-toggle">
                <input
                  checked={deceased}
                  onChange={(event) => setDeceased(event.target.checked)}
                  type="checkbox"
                />
                {t('person.deceased')}
              </label>
              {deceased && (
                <StructuredDateInput
                  label={t('person.deathDate')}
                  onChange={setDeathDate}
                  state={deathDate}
                />
              )}
            </div>
            {children}
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
            <button className="primary-button" disabled={isSaving}>
              {isSaving ? t('common.saving') : t('common.save')}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}
