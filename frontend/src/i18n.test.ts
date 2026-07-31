import { beforeEach, describe, expect, it } from 'vitest'
import {
  determineGuestLanguage,
  guestLanguageCookie,
  readCookie,
  saveGuestLanguage,
} from './i18n'

describe('guest language selection', () => {
  beforeEach(() => {
    document.cookie = `${guestLanguageCookie}=; Max-Age=0; Path=/`
  })

  it('uses a saved supported language before browser preferences', () => {
    expect(
      determineGuestLanguage(`${guestLanguageCookie}=ru`, ['en-US']),
    ).toBe('ru')
  })

  it('detects supported browser languages and falls back to English', () => {
    expect(determineGuestLanguage('', ['de-DE', 'ru-RU'])).toBe('ru')
    expect(determineGuestLanguage('', ['de-DE'])).toBe('en')
  })

  it('saves a manual selection in a cookie', () => {
    saveGuestLanguage('ru')

    expect(readCookie(document.cookie, guestLanguageCookie)).toBe('ru')
  })

  it('decodes cookie values and ignores unrelated values', () => {
    expect(readCookie('other=x; display%20name=hello%20world', 'display name'))
      .toBe('hello world')
    expect(readCookie('other=x', 'missing')).toBeUndefined()
  })
})
