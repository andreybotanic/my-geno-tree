import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { FamilyTree } from '../api/generated'
import { treeApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { navigate } from '../routing'

type TreeEditorPageProps = {
  treeId: string
}

export function TreeEditorPage({ treeId }: TreeEditorPageProps) {
  const { t } = useTranslation()
  const { request } = useAuth()
  const [tree, setTree] = useState<FamilyTree | null>(null)
  const [missing, setMissing] = useState(false)

  useEffect(() => {
    let active = true
    treeApi
      .list(request)
      .then(({ items }) => {
        if (!active) {
          return
        }
        const current = items.find((item) => item.id === treeId)
        if (current) {
          setTree(current)
        } else {
          setMissing(true)
        }
      })
      .catch(() => {
        if (active) {
          setMissing(true)
        }
      })

    return () => {
      active = false
    }
  }, [request, treeId])

  return (
    <main className="tree-editor-page">
      <div className="editor-toolbar">
        <button
          className="back-button"
          onClick={() => navigate('/trees')}
          type="button"
        >
          ← {t('trees.back')}
        </button>
        <strong>{tree?.name ?? t('trees.loading')}</strong>
      </div>
      <section className="empty-tree-canvas">
        {missing ? (
          <p role="alert">{t('trees.notFound')}</p>
        ) : (
          <p>{tree ? t('trees.editorEmpty') : t('trees.loading')}</p>
        )}
      </section>
    </main>
  )
}
