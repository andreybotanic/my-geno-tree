import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import type { PersonInput } from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  dateDayInputValue,
  emptyDate,
  isValidDateDay,
  type DateInputState,
} from '../personFormValues'
import {
  emptyPersonForm,
  personFormValue,
  type PersonFormState,
  type TextInputState,
} from '../personFormState'
import { UnknownToggleButton } from './UnknownToggleButton'
import { PlaceDialog, PlaceTextButton } from './PlaceDialog'

type KnownTextInputProps = {
  autoFocus?: boolean
  children?: ReactNode | undefined
  label: string
  onChange: (state: TextInputState) => void
  state: TextInputState
}

function KnownTextInput({
  autoFocus = false,
  children,
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
      {children}
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

  const dateParts = (prefix: '' | 'end', rowLabel: string) => {
    const yearKey = prefix ? 'endYear' : 'year'
    const monthKey = prefix ? 'endMonth' : 'month'
    const dayKey = prefix ? 'endDay' : 'day'

    const retainedDay = (year: string, month: string) => (
      isValidDateDay(state[dayKey], year, month)
        ? state[dayKey]
        : ''
    )

    return (
      <div
        aria-label={state.kind === 'between' ? rowLabel : undefined}
        className="date-controls"
        role={state.kind === 'between' ? 'group' : undefined}
      >
        <label>
          <span>{t('person.year')}</span>
          <input
            aria-label={`${rowLabel}: ${t('person.year')}`}
            autoFocus={autoFocus && prefix === ''}
            disabled={state.unknown}
            inputMode="numeric"
            max="9999"
            min="1582"
            onChange={(event) => onChange({
              ...state,
              [dayKey]: retainedDay(event.target.value, state[monthKey]),
              [yearKey]: event.target.value,
            })}
            type="number"
            value={state[yearKey]}
          />
        </label>
        <label>
          <span>{t('person.month')}</span>
          <select
            aria-label={`${rowLabel}: ${t('person.month')}`}
            disabled={state.unknown}
            onChange={(event) => onChange({
              ...state,
              [dayKey]: event.target.value === ''
                ? ''
                : retainedDay(state[yearKey], event.target.value),
              [monthKey]: event.target.value,
            })}
            value={state[monthKey]}
          >
            <option value="" />
            {months.map((month) => <option key={month.value} value={month.value}>{month.label}</option>)}
          </select>
        </label>
        <label>
          <span>{t('person.day')}</span>
          <input
            aria-label={`${rowLabel}: ${t('person.day')}`}
            disabled={state.unknown || state[monthKey] === ''}
            inputMode="numeric"
            maxLength={2}
            onChange={(event) => {
              const value = dateDayInputValue(
                event.target.value,
                state[yearKey],
                state[monthKey],
              )
              if (null === value) {
                event.currentTarget.value = state[dayKey]

                return
              }
              onChange({ ...state, [dayKey]: value })
            }}
            pattern="[0-9]*"
            type="text"
            value={state[dayKey]}
          />
        </label>
      </div>
    )
  }

  const controls = (
    <div className="date-field-control">
      <div className="date-kind-row">
        <label className="person-select-field">
          <span>{t('person.dateKind')}</span>
          <select
            aria-label={`${label}: ${t('person.dateKind')}`}
            disabled={state.unknown}
            onChange={(event) => onChange({
              ...state,
              endDay: event.target.value === 'between' && state.endDay === '' ? state.day : state.endDay,
              endMonth: event.target.value === 'between' && state.endMonth === '' ? state.month : state.endMonth,
              endYear: event.target.value === 'between' && state.endYear === '' ? state.year : state.endYear,
              kind: event.target.value as DateInputState['kind'],
            })}
            value={state.kind}
          >
            <option value="exact">{t('person.dateKindExact')}</option>
            <option value="approximate">{t('person.dateKindApproximate')}</option>
            <option value="before">{t('person.dateKindBefore')}</option>
            <option value="after">{t('person.dateKindAfter')}</option>
            <option value="between">{t('person.dateKindBetween')}</option>
          </select>
        </label>
        <UnknownToggleButton
          active={state.unknown}
          onChange={(unknown) => onChange(unknown ? { ...emptyDate(), unknown: true } : { ...state, unknown: false })}
          tabIndex={unknownToggleTabIndex}
          title={t('person.unknown')}
        />
      </div>
      {state.kind === 'between' ? (
        <>
          {dateParts('', t('person.from'))}
          {dateParts('end', t('person.to'))}
        </>
      ) : dateParts('', label)}
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
  beforeFields?: ReactNode | undefined
  children?: ReactNode | ((person: PersonFormState) => ReactNode)
  fixedGender?: boolean
  initialGender?: PersonInput['gender'] | undefined
  onCancel: () => void
  onSave: (person: PersonInput) => Promise<void>
  patronymicSuggestion?: PatronymicSuggestion | undefined
  surnameSuggestions?: SurnameSuggestions | undefined
  title?: string
}

type PersonFormFieldsProps = {
  autoFocus?: boolean
  fixedGender?: boolean
  showPlaces?: boolean
  showLifeStatus?: boolean
  onChange: (state: PersonFormState) => void
  patronymicSuggestion?: PatronymicSuggestion | undefined
  surnameSuggestions?: SurnameSuggestions | undefined
  state: PersonFormState
}

export type PatronymicSuggestion = {
  fatherGivenName: string | undefined
  onDetermine: (
    fatherGivenName: string,
    childGender: 'female' | 'male',
  ) => Promise<string | null>
}

export type SurnameSuggestionSource = {
  gender: 'female' | 'male'
  surname: string
}

export type SurnameSuggestions = {
  father: SurnameSuggestionSource | undefined
  mother: SurnameSuggestionSource | undefined
  onLocalize: (
    surname: string,
    sourceGender: 'female' | 'male',
    targetGender: 'female' | 'male',
  ) => Promise<string>
}

export function PersonFormFields({
  autoFocus = false,
  fixedGender = false,
  showPlaces = true,
  showLifeStatus = true,
  onChange,
  patronymicSuggestion,
  surnameSuggestions,
  state,
}: PersonFormFieldsProps) {
  const { i18n, t } = useTranslation()
  const [placeEditing, setPlaceEditing] = useState<'birth' | 'death' | null>(
    null,
  )
  const [patronymicError, setPatronymicError] = useState('')
  const [suggestedPatronymic, setSuggestedPatronymic] = useState<string | null>(
    null,
  )
  const [suggestedSurnames, setSuggestedSurnames] = useState<Partial<Record<
    'father' | 'mother',
    string
  >>>({})
  const fatherGivenName = patronymicSuggestion?.fatherGivenName
  const determinePatronymic = patronymicSuggestion?.onDetermine
  const fatherSurname = surnameSuggestions?.father?.surname
  const fatherSurnameGender = surnameSuggestions?.father?.gender
  const motherSurname = surnameSuggestions?.mother?.surname
  const motherSurnameGender = surnameSuggestions?.mother?.gender
  const localizeSurname = surnameSuggestions?.onLocalize
  const editingPlace = placeEditing === 'birth'
    ? state.birthPlace
    : state.deathPlace

  useEffect(() => {
    setSuggestedPatronymic(null)
    setPatronymicError('')
    if (
      i18n.resolvedLanguage !== 'ru'
      || !fatherGivenName
      || state.gender === 'unknown'
      || !determinePatronymic
    ) {
      return
    }

    let cancelled = false
    void determinePatronymic(fatherGivenName, state.gender).then((patronymic) => {
      if (!cancelled) {
        setSuggestedPatronymic(patronymic)
      }
    }).catch(() => {
      if (!cancelled) {
        setPatronymicError(t('person.patronymicDeterminationFailed'))
      }
    })

    return () => {
      cancelled = true
    }
  }, [determinePatronymic, fatherGivenName, i18n.resolvedLanguage, state.gender, t])

  useEffect(() => {
    setSuggestedSurnames({})
    const childGender = state.gender
    if (
      childGender === 'unknown'
      || !localizeSurname
    ) {
      return
    }

    const sources = [
      fatherSurname && fatherSurnameGender
        ? { gender: fatherSurnameGender, role: 'father' as const, surname: fatherSurname }
        : null,
      motherSurname && motherSurnameGender
        ? { gender: motherSurnameGender, role: 'mother' as const, surname: motherSurname }
        : null,
    ].filter((source): source is SurnameSuggestionSource & {
      role: 'father' | 'mother'
    } => source !== null)
    if (sources.length === 0) {
      return
    }

    let cancelled = false
    void Promise.all(sources.map(async (source) => [
      source.role,
      await localizeSurname(source.surname, source.gender, childGender),
    ] as const)).then((localized) => {
      if (!cancelled) {
        setSuggestedSurnames(Object.fromEntries(localized))
      }
    })

    return () => {
      cancelled = true
    }
  }, [
    fatherSurname,
    fatherSurnameGender,
    localizeSurname,
    motherSurname,
    motherSurnameGender,
    state.gender,
  ])

  return (
    <>
      <label className="person-select-field person-gender-field">
        <span>{t('person.gender')}</span>
        <select
          disabled={fixedGender}
          onChange={(event) => onChange({
            ...state,
            gender: event.target.value as PersonInput['gender'],
          })}
          value={state.gender}
        >
          <option value="unknown">{t('person.unknown')}</option>
          <option value="male">{t('person.male')}</option>
          <option value="female">{t('person.female')}</option>
        </select>
      </label>
      <div className="person-name-fields">
        <KnownTextInput
          autoFocus={autoFocus}
          label={t('person.birthSurname')}
          onChange={(birthSurname) => onChange({ ...state, birthSurname })}
          state={state.birthSurname}
        >
          {surnameSuggestions && (
            <div className="surname-suggestion-control">
              {state.gender !== 'unknown' && (
                <>
                  {suggestedSurnames.father && (
                    <button
                      className="link-button surname-adoption-button"
                      onClick={() => onChange({
                        ...state,
                        birthSurname: { unknown: false, value: suggestedSurnames.father! },
                      })}
                      type="button"
                    >
                      {suggestedSurnames.father}
                    </button>
                  )}
                  {suggestedSurnames.father && suggestedSurnames.mother && (
                    <span className="surname-suggestion-separator">,</span>
                  )}
                  {suggestedSurnames.mother && (
                    <button
                      className="link-button surname-adoption-button"
                      onClick={() => onChange({
                        ...state,
                        birthSurname: { unknown: false, value: suggestedSurnames.mother! },
                      })}
                      type="button"
                    >
                      {suggestedSurnames.mother}
                    </button>
                  )}
                </>
              )}
            </div>
          )}
        </KnownTextInput>
        <KnownTextInput
          label={t('person.givenName')}
          onChange={(givenName) => onChange({ ...state, givenName })}
          state={state.givenName}
        />
        <KnownTextInput
          label={t('person.patronymic')}
          onChange={(patronymic) => onChange({ ...state, patronymic })}
          state={state.patronymic}
        >
          {i18n.resolvedLanguage === 'ru' && patronymicSuggestion && (
            <div className="patronymic-suggestion-control">
              {suggestedPatronymic && (
                <button
                  className="link-button surname-adoption-button"
                  onClick={() => {
                    onChange({
                      ...state,
                      patronymic: { unknown: false, value: suggestedPatronymic },
                    })
                  }}
                  type="button"
                >
                  {suggestedPatronymic}
                </button>
              )}
            </div>
          )}
          {patronymicError && (
            <small className="patronymic-suggestion-error" role="alert">
              {patronymicError}
            </small>
          )}
        </KnownTextInput>
      </div>
      <div className="date-place-field">
        <StructuredDateInput
          label={t('person.birthDate')}
          onChange={(birthDate) => onChange({ ...state, birthDate })}
          state={state.birthDate}
          unknownToggleTabIndex={-1}
        />
        {showPlaces && <PlaceTextButton
          onClick={() => setPlaceEditing('birth')}
          place={state.birthPlace}
        />}
      </div>
      <div className="death-date-row">
        {showLifeStatus && <label
          className="deceased-toggle choice-control conditional-date-toggle"
        >
          <input
            checked={state.deceased}
            onChange={(event) => onChange({
              ...state,
              deceased: event.target.checked,
              deathPlace: event.target.checked ? state.deathPlace : null,
            })}
            type="checkbox"
          />
          <span>{t('person.deceased')}</span>
        </label>}
        {state.deceased && (
          <div className="date-place-field">
            <StructuredDateInput
              label={t('person.deathDate')}
              onChange={(deathDate) => onChange({ ...state, deathDate })}
              state={state.deathDate}
              unknownToggleTabIndex={-1}
            />
            {showPlaces && <PlaceTextButton
              onClick={() => setPlaceEditing('death')}
              place={state.deathPlace}
            />}
          </div>
        )}
      </div>
      {placeEditing && (
        <PlaceDialog
          onCancel={() => setPlaceEditing(null)}
          {...(editingPlace ? {
            onDelete: () => {
              onChange({
                ...state,
                [placeEditing === 'birth' ? 'birthPlace' : 'deathPlace']: null,
              })
              setPlaceEditing(null)
            },
          } : {})}
          onSave={(place) => {
            onChange({
              ...state,
              [placeEditing === 'birth' ? 'birthPlace' : 'deathPlace']: place,
            })
            setPlaceEditing(null)
          }}
          place={editingPlace}
        />
      )}
    </>
  )
}

export function PersonFormDialog({
  beforeFields,
  children,
  fixedGender = false,
  initialGender = 'unknown',
  onCancel,
  onSave,
  patronymicSuggestion,
  surnameSuggestions,
  title,
}: PersonFormDialogProps) {
  const { t } = useTranslation()
  const [person, setPerson] = useState(
    () => emptyPersonForm(initialGender),
  )
  const [error, setError] = useState('')
  const [isSaving, setIsSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const value = personFormValue(person)
    if (value === null) {
      setError(t('person.invalidForm'))
      return
    }

    setError('')
    setIsSaving(true)
    try {
      await onSave(value)
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
            {beforeFields}
            <PersonFormFields
              autoFocus
              fixedGender={fixedGender}
              onChange={setPerson}
              patronymicSuggestion={patronymicSuggestion}
              surnameSuggestions={surnameSuggestions}
              state={person}
            />
            {typeof children === 'function' ? children(person) : children}
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
