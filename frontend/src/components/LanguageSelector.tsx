import { useTranslation } from 'react-i18next'
import i18n, { saveGuestLanguage, type Language } from '../i18n'
import { LanguagePicker } from './LanguagePicker'

export function LanguageSelector() {
  const { t } = useTranslation()

  const changeLanguage = (language: Language) => {
    saveGuestLanguage(language)
    void i18n.changeLanguage(language)
  }

  return (
    <div className="language-selector">
      <LanguagePicker
        label={t('header.language')}
        onChange={changeLanguage}
        value={i18n.resolvedLanguage === 'ru' ? 'ru' : 'en'}
      />
    </div>
  )
}
