import { useTranslation } from 'react-i18next'
import { AuthForm } from '../components/AuthForm'

export function AuthPage() {
  const { t } = useTranslation()

  return (
    <main className="auth-page">
      <div className="auth-page-copy">
        <p className="eyebrow">MyGenoTree</p>
        <h1>{t('auth.pageTitle')}</h1>
        <p>{t('auth.pageCopy')}</p>
      </div>
      <section className="auth-page-card">
        <AuthForm onSuccess={() => undefined} />
      </section>
    </main>
  )
}
