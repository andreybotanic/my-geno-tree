import { useTranslation } from 'react-i18next'
import type { MarriageFormState } from '../marriageFormState'
import { StructuredDateInput } from './PersonFormDialog'

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

  return (
    <>
      <StructuredDateInput
        label={t('relationships.marriageDate')}
        onChange={(marriageDate) => onChange({ ...state, marriageDate })}
        state={state.marriageDate}
      />
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
          })}
          type="checkbox"
        />
        <span>{t('relationships.dissolved')}</span>
      </label>
      {state.dissolved && (
        <StructuredDateInput
          label={t('relationships.dissolutionDate')}
          onChange={(dissolutionDate) => onChange({
            ...state,
            dissolutionDate,
          })}
          state={state.dissolutionDate}
        />
      )}
    </>
  )
}
