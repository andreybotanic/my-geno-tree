import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { FamilyTree } from '../api/generated'
import { treeApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import {
  DeleteTreeDialog,
  TreeNameDialog,
} from '../components/TreeDialogs'
import { ImportTreeDialog } from '../components/TreeExchangeDialogs'
import { navigate, treeEditorPath } from '../routing'

type DialogState =
  | { type: 'create' }
  | { type: 'import' }
  | { type: 'delete'; tree: FamilyTree }
  | { type: 'rename'; tree: FamilyTree }
  | null

export function TreesPage() {
  const { t, i18n } = useTranslation()
  const { request } = useAuth()
  const [trees, setTrees] = useState<FamilyTree[]>([])
  const [dialog, setDialog] = useState<DialogState>(null)
  const [status, setStatus] = useState<'error' | 'loading' | 'ready'>('loading')

  const loadTrees = useCallback(async () => {
    setStatus('loading')
    try {
      setTrees((await treeApi.list(request)).items)
      setStatus('ready')
    } catch {
      setStatus('error')
    }
  }, [request])

  useEffect(() => {
    void loadTrees()
  }, [loadTrees])

  const create = async (name: string) => {
    const tree = await treeApi.create(request, name)
    setDialog(null)
    navigate(treeEditorPath(tree.id, 'tree'))
  }

  const rename = async (tree: FamilyTree, name: string) => {
    await treeApi.rename(request, tree.id, name, tree.etag)
    setDialog(null)
    await loadTrees()
  }

  const remove = async (tree: FamilyTree) => {
    await treeApi.remove(request, tree.id, tree.etag)
    setTrees((current) => current.filter((item) => item.id !== tree.id))
    setDialog(null)
  }

  return (
    <main className="content-page trees-page">
      <div className="trees-heading">
        <div className="page-heading">
          <p className="eyebrow">MyGenoTree</p>
          <h1>{t('trees.title')}</h1>
        </div>
        <div className="tree-heading-actions">
          <button className="secondary-button tree-heading-button" onClick={() => setDialog({ type: 'import' })} type="button">
            {t('treeImport.action')}
          </button>
          <button
            className="primary-button tree-heading-button"
            onClick={() => setDialog({ type: 'create' })}
            type="button"
          >
            {t('trees.create')}
          </button>
        </div>
      </div>

      {status === 'loading' && (
        <p className="empty-state">{t('trees.loading')}</p>
      )}
      {status === 'error' && (
        <div className="empty-state">
          <p role="alert">{t('trees.requestFailed')}</p>
          <button
            className="secondary-button"
            onClick={() => void loadTrees()}
            type="button"
          >
            {t('trees.retry')}
          </button>
        </div>
      )}
      {status === 'ready' && trees.length === 0 && (
        <p className="empty-state">{t('trees.empty')}</p>
      )}
      {status === 'ready' && trees.length > 0 && (
        <section className="tree-grid" aria-label={t('trees.title')}>
          {trees.map((tree) => (
            <article className="tree-card" key={tree.id}>
              <div>
                <h2>{tree.name}</h2>
                <p>
                  {t('trees.peopleCount', { count: tree.personCount })}
                  {' · '}
                  {t('trees.updated', {
                    date: new Intl.DateTimeFormat(i18n.resolvedLanguage, {
                      dateStyle: 'medium',
                    }).format(new Date(tree.updatedAt)),
                  })}
                </p>
              </div>
              <div className="tree-actions">
                <button
                  className="primary-button"
                  onClick={() => navigate(treeEditorPath(tree.id, 'tree'))}
                  type="button"
                >
                  {t('trees.open')}
                </button>
                <button
                  className="secondary-button"
                  onClick={() => setDialog({ type: 'rename', tree })}
                  type="button"
                >
                  {t('trees.rename')}
                </button>
                <button
                  className="link-danger-button"
                  onClick={() => setDialog({ type: 'delete', tree })}
                  type="button"
                >
                  {t('trees.delete')}
                </button>
              </div>
            </article>
          ))}
        </section>
      )}

      {dialog?.type === 'create' && (
        <TreeNameDialog
          mode="create"
          onCancel={() => setDialog(null)}
          onSave={create}
        />
      )}
      {dialog?.type === 'import' && (
        <ImportTreeDialog
          onCancel={() => setDialog(null)}
          onImported={(treeId) => {
            setDialog(null)
            navigate(treeEditorPath(treeId, 'tree'))
          }}
          request={request}
        />
      )}
      {dialog?.type === 'rename' && (
        <TreeNameDialog
          initialName={dialog.tree.name}
          mode="rename"
          onCancel={() => setDialog(null)}
          onSave={(name) => rename(dialog.tree, name)}
        />
      )}
      {dialog?.type === 'delete' && (
        <DeleteTreeDialog
          tree={dialog.tree}
          onCancel={() => setDialog(null)}
          onDelete={() => remove(dialog.tree)}
        />
      )}
    </main>
  )
}
