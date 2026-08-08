import { useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  KnownText,
  PersonInput,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  dateValue,
  emptyDate,
  type DateInputState,
} from '../personFormValues'
import { UnknownToggleButton } from './UnknownToggleButton'

type TextInputState = {
  unknown: boolean
  value: string
}

const emptyText = (): TextInputState => ({ unknown: false, value: '' })
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
          tabIndex={-1}
          title={t('person.unknown')}
        />
      </div>
    </div>
  )
}

type StructuredDateInputProps = {
  autoFocus?: boolean
  grouped?: boolean
  label: string
  onChange: (state: DateInputState) => void
  state: DateInputState
  unknownToggleTabIndex?: number | undefined
}

function dateInputValue(state: DateInputState): string {
  const year = Number(state.year)
  const month = Number(state.month)
  const day = Number(state.day)
  const candidate = new Date(Date.UTC(year, month - 1, day))

  if (
    !Number.isInteger(year)
    || year < 1582
    || year > 9999
    || !Number.isInteger(month)
    || !Number.isInteger(day)
    || candidate.getUTCFullYear() !== year
    || candidate.getUTCMonth() !== month - 1
    || candidate.getUTCDate() !== day
  ) {
    return ''
  }

  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

export function StructuredDateInput({
  autoFocus = false,
  grouped = true,
  label,
  onChange,
  state,
  unknownToggleTabIndex,
}: StructuredDateInputProps) {
  const { i18n, t } = useTranslation()
  const monthFormatter = new Intl.DateTimeFormat(i18n.language, {
    month: 'long',
    timeZone: 'UTC',
  })
  const months = Array.from({ length: 12 }, (_, index) => {
    const month = monthFormatter.format(new Date(Date.UTC(2000, index, 1)))

    return {
      label: month.charAt(0).toLocaleUpperCase(i18n.language) + month.slice(1),
      value: String(index + 1),
    }
  })

  const controls = (
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
            {state.precision === 'day' && (
              <label className="exact-date-control">
                <span>{t('person.exactDate')}</span>
                <input
                  aria-label={`${label}: ${t('person.exactDate')}`}
                  autoFocus={autoFocus}
                  defaultValue={dateInputValue(state)}
                  disabled={state.unknown}
                  key={`${state.unknown}-${state.year}-${state.month}-${state.day}`}
                  max="9999-12-31"
                  min="1582-01-01"
                  onChange={(event) => {
                    if ('' === event.target.value) {
                      return
                    }

                    const [year = '', month = '', day = ''] = event.target.value.split('-')
                    const value = {
                      ...state,
                      day,
                      month,
                      year,
                    }

                    if ('' === dateInputValue(value)) {
                      return
                    }

                    onChange(value)
                  }}
                  type="date"
                />
              </label>
            )}
            {state.precision === 'month' && (
              <label>
                <span>{t('person.month')}</span>
                <select
                  aria-label={`${label}: ${t('person.month')}`}
                  autoFocus={autoFocus}
                  disabled={state.unknown}
                  onChange={(event) => onChange({
                    ...state,
                    month: event.target.value,
                  })}
                  value={state.month}
                >
                  <option value="">{t('person.month')}</option>
                  {months.map((month) => (
                    <option key={month.value} value={month.value}>
                      {month.label}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {state.precision !== 'day' && (
              <label>
                <span>{t('person.year')}</span>
                <input
                  aria-label={`${label}: ${t('person.year')}`}
                  autoFocus={autoFocus}
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
            tabIndex={unknownToggleTabIndex}
            title={t('person.unknown')}
          />
        </div>
        {state.precision === 'year' && (
          <label className="approximate-toggle choice-control">
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
  )

  return grouped ? (
    <fieldset className="person-fieldset date-fieldset">
      <legend>{label}</legend>
      {controls}
    </fieldset>
  ) : (
    <div
      aria-label={label}
      className="date-fieldset date-fieldset-ungrouped"
      role="group"
    >
      {controls}
    </div>
  )
}

type PersonFormDialogProps = {
  children?: ReactNode
  initialGender?: PersonInput['gender'] | undefined
  onCancel: () => void
  onSave: (person: PersonInput) => Promise<void>
  title?: string
}

export function PersonFormDialog({
  children,
  initialGender = 'unknown',
  onCancel,
  onSave,
  title,
}: PersonFormDialogProps) {
  const { t } = useTranslation()
  const [birthSurname, setBirthSurname] = useState(emptyText)
  const [givenName, setGivenName] = useState(emptyText)
  const [patronymic, setPatronymic] = useState(emptyText)
  const [gender, setGender] = useState<PersonInput['gender']>(initialGender)
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
              unknownToggleTabIndex={-1}
            />
            <div className="death-date-row">
              <label
                className="deceased-toggle choice-control conditional-date-toggle"
              >
                <input
                  checked={deceased}
                  onChange={(event) => setDeceased(event.target.checked)}
                  type="checkbox"
                />
                <span>{t('person.deceased')}</span>
              </label>
              {deceased && (
                <StructuredDateInput
                  label={t('person.deathDate')}
                  onChange={setDeathDate}
                  state={deathDate}
                  unknownToggleTabIndex={-1}
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
