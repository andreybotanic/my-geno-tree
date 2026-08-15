import { useEffect, useRef, useState } from 'react'
import ChevronDownIcon from '../assets/icons/chevron-down.svg?react'
import CheckIcon from '../assets/icons/check.svg?react'
import EnglishFlagIcon from '../assets/flags/en.svg?react'
import RussianFlagIcon from '../assets/flags/ru.svg?react'
import type { Language } from '../i18n'

const languages = [
  { code: 'en', label: 'English' },
  { code: 'ru', label: 'Русский' },
] as const satisfies ReadonlyArray<{
  code: Language
  label: string
}>

function LanguageFlag({ language }: { language: Language }) {
  const Icon = language === 'ru' ? RussianFlagIcon : EnglishFlagIcon

  return <Icon aria-hidden="true" className="language-flag" data-testid={`language-flag-${language}`} focusable="false" />
}

interface LanguagePickerProps {
  disabled?: boolean
  label: string
  onChange: (language: Language) => void
  value: Language
}

export function LanguagePicker({
  disabled = false,
  label,
  onChange,
  value,
}: LanguagePickerProps) {
  const [open, setOpen] = useState(false)
  const pickerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const selectedLanguage = languages.find((language) => language.code === value)
    ?? languages[0]

  useEffect(() => {
    if (!open) {
      return
    }

    const closeOutside = (event: PointerEvent) => {
      if (!pickerRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('pointerdown', closeOutside)
    document.addEventListener('keydown', closeOnEscape)

    return () => {
      document.removeEventListener('pointerdown', closeOutside)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [open])

  return (
    <div className="language-picker" ref={pickerRef}>
      <button
        aria-expanded={open}
        aria-haspopup="listbox"
        aria-label={label}
        className="language-picker-trigger"
        disabled={disabled}
        onClick={() => setOpen((current) => !current)}
        ref={triggerRef}
        type="button"
      >
        <LanguageFlag language={selectedLanguage.code} />
        <span>{selectedLanguage.label}</span>
        <ChevronDownIcon aria-hidden="true" className="language-picker-chevron" focusable="false" />
      </button>
      {open && (
        <div aria-label={label} className="language-picker-menu" role="listbox">
          {languages.map((language) => (
            <button
              aria-selected={language.code === value}
              className="language-picker-option"
              key={language.code}
              onClick={() => {
                onChange(language.code)
                setOpen(false)
                triggerRef.current?.focus()
              }}
              role="option"
              type="button"
            >
              <LanguageFlag language={language.code} />
              <span>{language.label}</span>
              <span aria-hidden="true" className="language-picker-check">
                {language.code === value && <CheckIcon focusable="false" />}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
