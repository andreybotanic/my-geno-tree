import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Gender } from '../api/generated'
import type { MarriageFormState } from '../marriageFormState'
import { StructuredDateInput } from './PersonFormDialog'
import { PlaceDialog, PlaceTextButton } from './PlaceDialog'

type MarriageFormFieldsProps = {
  femaleSurnameLabel?: string
  femaleSurnameSource?: SurnameSource | null
  maleSurnameLabel?: string
  maleSurnameSource?: SurnameSource | null
  onChange: (state: MarriageFormState) => void
  onLocalizeSurname?: ((
    surname: string,
    sourceGender: SurnameGender,
    targetGender: SurnameGender,
  ) => Promise<string>) | undefined
  state: MarriageFormState
}

type SurnameGender = Exclude<Gender, 'unknown'>

export type SurnameSource = {
  gender: SurnameGender
  surname: string
}

export function MarriageFormFields({
  femaleSurnameLabel,
  femaleSurnameSource,
  maleSurnameLabel,
  maleSurnameSource,
  onChange,
  onLocalizeSurname,
  state,
}: MarriageFormFieldsProps) {
  const { t } = useTranslation()
  const [placeEditing, setPlaceEditing] = useState<
    'dissolution' | 'marriage' | null
  >(null)
  const editingPlace = placeEditing === 'marriage'
    ? state.marriagePlace
    : state.dissolutionPlace
  const [localizationError, setLocalizationError] = useState('')
  const [localizing, setLocalizing] = useState<'female' | 'male' | null>(null)

  const adoptSurname = async (
    target: 'female' | 'male',
    source: SurnameSource | null | undefined,
  ) => {
    if (!source || !onLocalizeSurname) return

    setLocalizing(target)
    setLocalizationError('')
    try {
      const surname = await onLocalizeSurname(
        source.surname,
        source.gender,
        target,
      )
      onChange({
        ...state,
        [target === 'male' ? 'maleNewSurname' : 'femaleNewSurname']: surname,
      })
    } catch {
      setLocalizationError(t('relationships.surnameLocalizationFailed'))
    } finally {
      setLocalizing(null)
    }
  }

  return (
    <>
      <div className="date-place-field">
        <StructuredDateInput
          label={t('relationships.marriageDate')}
          onChange={(marriageDate) => onChange({ ...state, marriageDate })}
          state={state.marriageDate}
        />
        <PlaceTextButton
          onClick={() => setPlaceEditing('marriage')}
          place={state.marriagePlace}
        />
      </div>
      <label className="person-select-field">
        <span>{maleSurnameLabel ?? t('relationships.maleNewSurname')}</span>
        <input
          maxLength={100}
          onChange={(event) => onChange({
            ...state,
            maleNewSurname: event.target.value,
          })}
          value={state.maleNewSurname}
        />
        {femaleSurnameSource && onLocalizeSurname && (
          <button
            className="link-button surname-adoption-button"
            disabled={localizing !== null}
            onClick={() => void adoptSurname('male', femaleSurnameSource)}
            type="button"
          >
            {t('relationships.takeWifeSurname')}
          </button>
        )}
      </label>
      <label className="person-select-field">
        <span>{femaleSurnameLabel ?? t('relationships.femaleNewSurname')}</span>
        <input
          maxLength={100}
          onChange={(event) => onChange({
            ...state,
            femaleNewSurname: event.target.value,
          })}
          value={state.femaleNewSurname}
        />
        {maleSurnameSource && onLocalizeSurname && (
          <button
            className="link-button surname-adoption-button"
            disabled={localizing !== null}
            onClick={() => void adoptSurname('female', maleSurnameSource)}
            type="button"
          >
            {t('relationships.takeHusbandSurname')}
          </button>
        )}
      </label>
      {localizationError && (
        <p className="form-error marriage-surname-localization-error" role="alert">
          {localizationError}
        </p>
      )}
      <label
        className="deceased-toggle choice-control conditional-date-toggle"
      >
        <input
          checked={state.dissolved}
          onChange={(event) => onChange({
            ...state,
            dissolved: event.target.checked,
            dissolutionPlace: event.target.checked
              ? state.dissolutionPlace
              : null,
          })}
          type="checkbox"
        />
        <span>{t('relationships.dissolved')}</span>
      </label>
      {state.dissolved && (
        <div className="date-place-field">
          <StructuredDateInput
            label={t('relationships.dissolutionDate')}
            onChange={(dissolutionDate) => onChange({
              ...state,
              dissolutionDate,
            })}
            state={state.dissolutionDate}
          />
          <PlaceTextButton
            onClick={() => setPlaceEditing('dissolution')}
            place={state.dissolutionPlace}
          />
        </div>
      )}
      {placeEditing && (
        <PlaceDialog
          onCancel={() => setPlaceEditing(null)}
          {...(editingPlace ? {
            onDelete: () => {
              onChange({
                ...state,
                [placeEditing === 'marriage'
                  ? 'marriagePlace'
                  : 'dissolutionPlace']: null,
              })
              setPlaceEditing(null)
            },
          } : {})}
          onSave={(place) => {
            onChange({
              ...state,
              [placeEditing === 'marriage'
                ? 'marriagePlace'
                : 'dissolutionPlace']: place,
            })
            setPlaceEditing(null)
          }}
          place={editingPlace}
        />
      )}
    </>
  )
}
