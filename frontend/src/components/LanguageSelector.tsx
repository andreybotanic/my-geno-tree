import { useTranslation } from 'react-i18next'
import i18n, { saveGuestLanguage, type Language } from '../i18n'

const languages: ReadonlyArray<{
  code: Language
  flag: string
  label: string
}> = [
  { code: 'en', flag: '🇺🇸', label: 'English' },
  { code: 'ru', flag: '🇷🇺', label: 'Русский' },
]

export function LanguageSelector() {
  const { t } = useTranslation()

  const changeLanguage = (language: Language) => {
    saveGuestLanguage(language)
    void i18n.changeLanguage(language)
  }

  return (
    <label className="language-selector">
      <span className="sr-only">{t('header.language')}</span>
      <select
        aria-label={t('header.language')}
        value={i18n.resolvedLanguage === 'ru' ? 'ru' : 'en'}
        onChange={(event) => changeLanguage(event.target.value as Language)}
      >
        {languages.map((language) => (
          <option key={language.code} value={language.code}>
            {language.flag} {language.label}
          </option>
        ))}
      </select>
    </label>
  )
}
