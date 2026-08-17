import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type {
  ParentsMarriageInput,
  PersonInput,
} from '../api/generated'
import { ApiRequestError } from '../api/client'
import {
  emptyMarriageForm,
  marriageFormValue,
} from '../marriageFormState'
import {
  emptyPersonForm,
  personFormValue,
} from '../personFormState'
import { MarriageFormFields } from './MarriageFormFields'
import { PersonFormFields } from './PersonFormDialog'

export type ParentsFormValue = {
  father: PersonInput
  marriage: ParentsMarriageInput | null
  mother: PersonInput
}

type ParentsFormDialogProps = {
  onCancel: () => void
  onLocalizeSurname?: ((
    surname: string,
    sourceGender: 'female' | 'male',
    targetGender: 'female' | 'male',
  ) => Promise<string>) | undefined
  onSave: (value: ParentsFormValue) => Promise<void>
}

export function ParentsFormDialog({
  onCancel,
  onLocalizeSurname,
  onSave,
}: ParentsFormDialogProps) {
  const { t } = useTranslation()
  const [father, setFather] = useState(() => emptyPersonForm('male'))
  const [mother, setMother] = useState(() => emptyPersonForm('female'))
  const [married, setMarried] = useState(false)
  const [marriage, setMarriage] = useState(emptyMarriageForm)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const fatherValue = personFormValue(father)
    const motherValue = personFormValue(mother)
    if (fatherValue === null || motherValue === null) {
      setError(t('person.invalidForm'))
      return
    }
    const marriageValue = married ? marriageFormValue(marriage) : null
    if (married && marriageValue === null) {
      setError(t('relationships.marriageDatesRequired'))
      return
    }

    setError('')
    setSaving(true)
    try {
      await onSave({
        father: { ...fatherValue, gender: 'male' },
        marriage: marriageValue,
        mother: { ...motherValue, gender: 'female' },
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
        aria-labelledby="parents-dialog-title"
        aria-modal="true"
        className="dialog-card person-dialog parents-dialog"
        role="dialog"
      >
        <h2 id="parents-dialog-title">{t('relationships.add.parents')}</h2>
        <form onSubmit={submit}>
          <div className="parents-form-grid">
            <fieldset className="parents-person-section">
              <legend>{t('relationships.father')}</legend>
              <div className="person-form-grid">
                <PersonFormFields
                  autoFocus
                  fixedGender
                  onChange={setFather}
                  state={father}
                />
              </div>
            </fieldset>
            <fieldset className="parents-person-section">
              <legend>{t('relationships.mother')}</legend>
              <div className="person-form-grid">
                <PersonFormFields
                  fixedGender
                  onChange={setMother}
                  state={mother}
                />
              </div>
            </fieldset>
            <fieldset className="parents-marriage-section">
              <legend>{t('relationships.marriage')}</legend>
              <label className="choice-control parents-married-toggle">
                <input
                  checked={married}
                  onChange={(event) => setMarried(event.target.checked)}
                  type="checkbox"
                />
                <span>{t('relationships.married')}</span>
              </label>
              {married && (
                <div className="person-form-grid parents-marriage-fields">
                  <MarriageFormFields
                    femaleSurnameLabel={t('relationships.wifeNewSurname')}
                    femaleSurnameSource={mother.birthSurname.unknown
                      || !mother.birthSurname.value.trim()
                      ? null
                      : { gender: 'female', surname: mother.birthSurname.value.trim() }}
                    maleSurnameLabel={t('relationships.husbandNewSurname')}
                    maleSurnameSource={father.birthSurname.unknown
                      || !father.birthSurname.value.trim()
                      ? null
                      : { gender: 'male', surname: father.birthSurname.value.trim() }}
                    onChange={setMarriage}
                    onLocalizeSurname={onLocalizeSurname}
                    state={marriage}
                  />
                </div>
              )}
            </fieldset>
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
