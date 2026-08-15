import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type { FamilyTree } from '../api/generated'
import { ApiRequestError } from '../api/client'
import { ActionIcon } from './ActionIcon'

type TreeNameDialogProps = {
  initialName?: string
  mode: 'create' | 'rename'
  onCancel: () => void
  onSave: (name: string) => Promise<void>
}

export function TreeNameDialog({
  initialName = '',
  mode,
  onCancel,
  onSave,
}: TreeNameDialogProps) {
  const { t } = useTranslation()
  const [name, setName] = useState(initialName)
  const [error, setError] = useState('')
  const [isSaving, setIsSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const normalized = name.trim()
    if (!normalized || normalized.length > 100) {
      setError(t('trees.nameInvalid'))
      return
    }

    setError('')
    setIsSaving(true)
    try {
      await onSave(normalized)
    } catch (caught) {
      if (
        caught instanceof ApiRequestError
        && caught.body.fields.name?.[0] === 'tree_name_not_unique'
      ) {
        setError(t('trees.nameConflict'))
      } else if (
        caught instanceof ApiRequestError
        && caught.body.code === 'TREE_VERSION_CONFLICT'
      ) {
        setError(t('trees.versionConflict'))
      } else {
        setError(t('trees.requestFailed'))
      }
    } finally {
      setIsSaving(false)
    }
  }

  const title = mode === 'create'
    ? t('trees.createTitle')
    : t('trees.renameTitle')

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="tree-dialog-title"
        aria-modal="true"
        className="dialog-card tree-dialog"
        role="dialog"
      >
        <h2 id="tree-dialog-title">{title}</h2>
        <form onSubmit={submit}>
          <label className="form-field">
            <span>{t('trees.name')}</span>
            <input
              autoFocus
              maxLength={101}
              onChange={(event) => setName(event.target.value)}
              value={name}
            />
            {error && <small role="alert">{error}</small>}
          </label>
          <div className="dialog-actions">
            <button
              className="secondary-button"
              onClick={onCancel}
              type="button"
            >
              {t('common.cancel')}
            </button>
            <button className="primary-button" disabled={isSaving}>
              {isSaving
                ? t('common.saving')
                : mode === 'create'
                  ? t('trees.create')
                  : t('common.save')}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}

type DeleteTreeDialogProps = {
  tree: FamilyTree
  onCancel: () => void
  onDelete: () => Promise<void>
}

export function DeleteTreeDialog({
  tree,
  onCancel,
  onDelete,
}: DeleteTreeDialogProps) {
  const { t } = useTranslation()
  const [error, setError] = useState('')
  const [isDeleting, setIsDeleting] = useState(false)

  const remove = async () => {
    setIsDeleting(true)
    setError('')
    try {
      await onDelete()
    } catch (caught) {
      setError(
        caught instanceof ApiRequestError
          && caught.body.code === 'TREE_VERSION_CONFLICT'
          ? t('trees.versionConflict')
          : t('trees.requestFailed'),
      )
    } finally {
      setIsDeleting(false)
    }
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="delete-tree-title"
        aria-modal="true"
        className="dialog-card tree-dialog"
        role="alertdialog"
      >
        <h2 id="delete-tree-title">{t('trees.deleteTitle')}</h2>
        <p>
          {t('trees.deleteWarning', { name: tree.name })}
        </p>
        {error && (
          <p className="form-error" role="alert">
            {error}
          </p>
        )}
        <div className="dialog-actions">
          <button
            className="secondary-button"
            onClick={onCancel}
            type="button"
          >
            {t('common.cancel')}
          </button>
          <button
            aria-label={t('trees.delete')}
            className="danger-button"
            disabled={isDeleting}
            onClick={() => void remove()}
            title={t('trees.delete')}
            type="button"
          >
            {isDeleting ? t('trees.deleting') : <ActionIcon name="delete" />}
          </button>
        </div>
      </section>
    </div>
  )
}
