import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { MarriageFormState } from '../marriageFormState'
import { StructuredDateInput } from './PersonFormDialog'
import { PlaceDialog, PlaceTextButton } from './PlaceDialog'

type MarriageFormFieldsProps = {
  femaleSurnameLabel?: string
  maleSurnameLabel?: string
  onChange: (state: MarriageFormState) => void
  state: MarriageFormState
}

export function MarriageFormFields({
  femaleSurnameLabel,
  maleSurnameLabel,
  onChange,
  state,
}: MarriageFormFieldsProps) {
  const { t } = useTranslation()
  const [placeEditing, setPlaceEditing] = useState<
    'dissolution' | 'marriage' | null
  >(null)
  const editingPlace = placeEditing === 'marriage'
    ? state.marriagePlace
    : state.dissolutionPlace

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
      </label>
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
