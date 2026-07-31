import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  MarriagePeriod,
  MarriagePeriodPatchRequest,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  dateValue,
  unknownDate,
  type DateInputState,
} from '../personFormValues'
import { StructuredDateInput } from './PersonFormDialog'

type MarriagePeriodDialogProps = {
  marriage: MarriagePeriod
  onCancel: () => void
  onSave: (patch: MarriagePeriodPatchRequest) => Promise<void>
}

export function MarriagePeriodDialog({
  marriage,
  onCancel,
  onSave,
}: MarriagePeriodDialogProps) {
  const { t } = useTranslation()
  const [marriageDate, setMarriageDate] = useState(
    () => dateDraft(marriage.marriageDate),
  )
  const [dissolved, setDissolved] = useState(
    marriage.dissolutionDate !== null,
  )
  const [dissolutionDate, setDissolutionDate] = useState(
    () => dateDraft(marriage.dissolutionDate ?? { state: 'unknown' }),
  )
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const marriageValue = dateValue(marriageDate)
    const dissolutionValue = dissolved ? dateValue(dissolutionDate) : null
    if (marriageValue === null || (dissolved && dissolutionValue === null)) {
      setError(t('person.invalidForm'))
      return
    }
    setSaving(true)
    setError('')
    try {
      await onSave({
        marriageDate: marriageValue,
        dissolutionDate: dissolutionValue,
      })
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

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="marriage-period-dialog-title"
        aria-modal="true"
        className="dialog-card person-dialog marriage-period-dialog"
        role="dialog"
      >
        <h2 id="marriage-period-dialog-title">
          {t('relationships.editMarriage')}
        </h2>
        <form onSubmit={submit}>
          <div className="person-form-grid">
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

function dateDraft(value: GenealogicalDate): DateInputState {
  if (value.state === 'unknown') {
    return unknownDate()
  }

  return {
    approximate: value.precision === 'year' && value.approximate,
    day: value.precision === 'day' ? String(value.day) : '',
    month: value.precision !== 'year' ? String(value.month) : '',
    precision: value.precision,
    unknown: false,
    year: String(value.year),
  }
}
