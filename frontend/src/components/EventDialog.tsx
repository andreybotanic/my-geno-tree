import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  GenealogicalDate,
  GenealogicalEvent,
  ManualEventRequest,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  dateValue,
  emptyDate,
  unknownDate,
  type DateInputState,
} from '../personFormValues'
import { StructuredDateInput } from './PersonFormDialog'

type EventDialogProps = {
  event?: GenealogicalEvent
  onCancel: () => void
  onSave: (event: ManualEventRequest) => Promise<void>
}

export function EventDialog({
  event,
  onCancel,
  onSave,
}: EventDialogProps) {
  const { t } = useTranslation()
  const [type, setType] = useState<'custom' | 'surname_change'>(
    event?.type === 'surname_change' ? 'surname_change' : 'custom',
  )
  const [date, setDate] = useState(
    () => event ? dateDraft(event.date) : emptyDate(),
  )
  const [title, setTitle] = useState(event?.title ?? '')
  const [description, setDescription] = useState(event?.description ?? '')
  const [newSurname, setNewSurname] = useState(
    typeof event?.data.newSurname === 'string'
      ? event.data.newSurname
      : '',
  )
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (formEvent: FormEvent) => {
    formEvent.preventDefault()
    const dateValueResult = dateValue(date)
    const normalizedTitle = title.trim()
    const normalizedSurname = newSurname.trim()
    if (
      dateValueResult === null
      || (
        type === 'custom'
        && (!normalizedTitle || normalizedTitle.length > 200)
      )
      || (
        type === 'surname_change'
        && (!normalizedSurname || normalizedSurname.length > 100)
      )
      || description.length > 5000
    ) {
      setError(t('person.invalidForm'))
      return
    }
    const payload: ManualEventRequest = type === 'custom'
      ? {
          type,
          date: dateValueResult,
          title: normalizedTitle,
          description: description || null,
        }
      : {
          type,
          date: dateValueResult,
          newSurname: normalizedSurname,
        }
    setSaving(true)
    setError('')
    try {
      await onSave(payload)
    } catch (caught) {
      setError(
        caught instanceof ApiRequestError
          && caught.body.code === 'TREE_VERSION_CONFLICT'
          ? t('editor.versionConflict')
          : t('events.saveFailed'),
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="event-dialog-title"
        aria-modal="true"
        className="dialog-card person-dialog event-dialog"
        role="dialog"
      >
        <h2 id="event-dialog-title">
          {t(event ? 'events.editTitle' : 'events.createTitle')}
        </h2>
        <form onSubmit={submit}>
          <div className="person-form-grid">
            <label className="person-select-field relationship-wide-field">
              <span>{t('events.type')}</span>
              <select
                aria-label={t('events.type')}
                onChange={(changeEvent) => setType(
                  changeEvent.target.value as typeof type,
                )}
                value={type}
              >
                <option value="custom">{t('events.custom')}</option>
                <option value="surname_change">
                  {t('events.surnameChange')}
                </option>
              </select>
            </label>
            <StructuredDateInput
              label={t('events.date')}
              onChange={setDate}
              state={date}
            />
            {type === 'custom' ? (
              <>
                <label className="person-select-field">
                  <span>{t('events.title')}</span>
                  <input
                    maxLength={201}
                    onChange={(changeEvent) => setTitle(
                      changeEvent.target.value,
                    )}
                    value={title}
                  />
                </label>
                <label className="person-select-field relationship-wide-field">
                  <span>{t('events.description')}</span>
                  <textarea
                    maxLength={5001}
                    onChange={(changeEvent) => setDescription(
                      changeEvent.target.value,
                    )}
                    rows={7}
                    value={description}
                  />
                </label>
              </>
            ) : (
              <label className="person-select-field">
                <span>{t('events.newSurname')}</span>
                <input
                  maxLength={101}
                  onChange={(changeEvent) => setNewSurname(
                    changeEvent.target.value,
                  )}
                  value={newSurname}
                />
              </label>
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
