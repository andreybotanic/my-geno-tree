import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { resources } from './locales'

export type Language = 'en' | 'ru'

export const guestLanguageCookie = 'mygenotree_language'

export function isLanguage(value: string | undefined): value is Language {
  return value === 'en' || value === 'ru'
}

export function readCookie(
  cookieHeader: string,
  name: string,
): string | undefined {
  const prefix = `${encodeURIComponent(name)}=`
  const item = cookieHeader
    .split(';')
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix))

  return item ? decodeURIComponent(item.slice(prefix.length)) : undefined
}

export function determineGuestLanguage(
  cookieHeader: string,
  browserLanguages: readonly string[],
): Language {
  const saved = readCookie(cookieHeader, guestLanguageCookie)
  if (isLanguage(saved)) {
    return saved
  }

  const detected = browserLanguages
    .map((language) => language.split('-')[0]?.toLowerCase())
    .find(isLanguage)

  return detected ?? 'en'
}

export function saveGuestLanguage(language: Language): void {
  document.cookie = `${guestLanguageCookie}=${language}; Max-Age=31536000; Path=/; SameSite=Lax; Secure`
}

export function currentGuestLanguage(): Language {
  return determineGuestLanguage(document.cookie, navigator.languages)
}

void i18n.use(initReactI18next).init({
  lng: typeof document === 'undefined' ? 'en' : currentGuestLanguage(),
  fallbackLng: 'en',
  supportedLngs: ['en', 'ru'],
  resources,
  interpolation: {
    escapeValue: false,
  },
})

export default i18n
