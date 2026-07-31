import { useTranslation } from 'react-i18next'

export function TreesPage() {
  const { t } = useTranslation()

  return (
    <main className="content-page">
      <div className="page-heading">
        <p className="eyebrow">MyGenoTree</p>
        <h1>{t('trees.title')}</h1>
        <p>{t('trees.nextSlice')}</p>
      </div>
    </main>
  )
}
