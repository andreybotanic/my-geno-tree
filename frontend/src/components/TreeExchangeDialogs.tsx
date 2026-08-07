import { useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import type { TreeImportInspection } from '../api/generated'
import {
  ApiRequestError,
  treeApi,
  type AuthorizedBlobRequest,
  type AuthorizedJsonRequest,
} from '../api/client'
import { safeTreeFileName } from '../treeFileName'

type ImportTreeDialogProps = {
  onCancel: () => void
  onImported: (treeId: string) => void
  request: AuthorizedJsonRequest
}

export function ImportTreeDialog({ onCancel, onImported, request }: ImportTreeDialogProps) {
  const { t } = useTranslation()
  const selectedFile = useRef<File | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [name, setName] = useState('')
  const [inspection, setInspection] = useState<TreeImportInspection | null>(null)
  const [state, setState] = useState<'checking' | 'error' | 'idle' | 'importing'>('idle')
  const [error, setError] = useState('')

  const chooseFile = async (nextFile: File | null) => {
    selectedFile.current = nextFile
    setFile(nextFile)
    setInspection(null)
    setError('')
    if (!nextFile) {
      setState('idle')
      return
    }
    setState('checking')
    try {
      const result = await treeApi.inspectImport(request, nextFile)
      if (selectedFile.current !== nextFile) return
      setInspection(result)
      setState('idle')
    } catch (caught) {
      if (selectedFile.current !== nextFile) return
      setState('error')
      setError(importError(caught, t))
    }
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const normalizedName = name.trim()
    if (!file || !inspection || !normalizedName || normalizedName.length > 100) return
    setState('importing')
    setError('')
    try {
      const tree = await treeApi.import(request, file, normalizedName)
      onImported(tree.id)
    } catch (caught) {
      setState('error')
      setError(importError(caught, t))
    }
  }

  const canImport = Boolean(
    file && inspection && name.trim().length > 0 && name.trim().length <= 100
      && state !== 'checking' && state !== 'importing',
  )

  return (
    <div className="dialog-backdrop" role="presentation">
      <section aria-labelledby="import-tree-title" aria-modal="true" className="dialog-card tree-dialog tree-exchange-dialog" role="dialog">
        <h2 id="import-tree-title">{t('treeImport.title')}</h2>
        <form onSubmit={(event) => void submit(event)}>
          <label className="form-field">
            <span>{t('trees.name')}</span>
            <input autoFocus maxLength={101} onChange={(event) => setName(event.target.value)} value={name} />
          </label>
          <div className="form-field">
            <label htmlFor="gedcom-file-input">{t('treeImport.file')}</label>
            <div className="file-picker">
              <input
                accept=".ged"
                className="file-picker-input"
                id="gedcom-file-input"
                onChange={(event) => void chooseFile(event.target.files?.[0] ?? null)}
                type="file"
              />
              <label className="file-picker-button" htmlFor="gedcom-file-input">
                {t('treeImport.chooseFile')}
              </label>
              <span className={`file-picker-name${file ? '' : ' is-empty'}`}>
                {file?.name ?? t('treeImport.noFile')}
              </span>
            </div>
          </div>
          {state === 'checking' && <p className="exchange-status">{t('treeImport.checking')}</p>}
          {inspection && (
            <div className="import-inspection" aria-live="polite">
              <strong>{inspection.format}</strong>
              <span>{t('treeImport.statistics', { families: inspection.familyCount, people: inspection.personCount })}</span>
              {inspection.warnings.length > 0 && (
                <div>
                  <strong>{t('treeImport.warnings')}</strong>
                  <ul>
                    {inspection.warnings.map((warning) => (
                      <li key={warning.code}>{t(`treeImport.warning.${warning.code}`, { count: warning.count, defaultValue: t('treeImport.warning.unsupported_tag', { count: warning.count }) })}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
          {error && <p className="form-error" role="alert">{error}</p>}
          <div className="dialog-actions">
            <button className="secondary-button" onClick={onCancel} type="button">{t('common.cancel')}</button>
            <button className="primary-button" disabled={!canImport}>{state === 'importing' ? t('treeImport.importing') : t('treeImport.action')}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

type ExportTreeDialogProps = {
  onCancel: () => void
  requestBlob: AuthorizedBlobRequest
  treeId: string
  treeName: string
}

export function ExportTreeDialog({ onCancel, requestBlob, treeId, treeName }: ExportTreeDialogProps) {
  const { t } = useTranslation()
  const [fileName, setFileName] = useState(() => safeTreeFileName(treeName))
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const normalizedName = safeTreeFileName(fileName)
    if (!normalizedName || normalizedName.length > 150) return
    setExporting(true)
    setError('')
    try {
      const blob = await treeApi.export(requestBlob, treeId, normalizedName)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${normalizedName}.ged`
      link.click()
      URL.revokeObjectURL(url)
      onCancel()
    } catch {
      setError(t('treeExport.failed'))
      setExporting(false)
    }
  }

  const valid = fileName.trim().length > 0 && fileName.trim().length <= 150
  return (
    <div className="dialog-backdrop" role="presentation">
      <section aria-labelledby="export-tree-title" aria-modal="true" className="dialog-card tree-dialog tree-exchange-dialog" role="dialog">
        <h2 id="export-tree-title">{t('treeExport.title')}</h2>
        <form onSubmit={(event) => void submit(event)}>
          <label className="form-field">
            <span>{t('treeExport.format')}</span>
            <select value="gedcom-5.5.1" disabled><option value="gedcom-5.5.1">GEDCOM 5.5.1</option></select>
          </label>
          <label className="form-field">
            <span>{t('treeExport.fileName')}</span>
            <input autoFocus maxLength={151} onChange={(event) => setFileName(event.target.value)} value={fileName} />
          </label>
          {error && <p className="form-error" role="alert">{error}</p>}
          <div className="dialog-actions">
            <button className="secondary-button" onClick={onCancel} type="button">{t('common.cancel')}</button>
            <button className="primary-button" disabled={!valid || exporting}>{exporting ? t('treeExport.exporting') : t('treeExport.action')}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function importError(error: unknown, t: (key: string) => string) {
  if (!(error instanceof ApiRequestError)) return t('treeImport.failed')
  if (error.body.code === 'TREE_NAME_CONFLICT') return t('trees.nameConflict')
  if (error.body.code === 'TREE_IMPORT_FILE_TOO_LARGE') return t('treeImport.tooLarge')
  if (error.body.code === 'TREE_IMPORT_ENCODING_UNSUPPORTED' || error.body.code === 'TREE_IMPORT_ENCODING_INVALID') return t('treeImport.encoding')
  if (error.body.code === 'TREE_IMPORT_FORMAT_UNSUPPORTED') return t('treeImport.unsupported')
  if (error.body.code === 'TREE_IMPORT_FORMAT_UNRECOGNIZED') return t('treeImport.unrecognized')
  if (error.body.code === 'TREE_IMPORT_DISCONNECTED') return t('treeImport.disconnected')
  return t('treeImport.invalid')
}
