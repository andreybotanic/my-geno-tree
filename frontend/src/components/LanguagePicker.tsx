import { useEffect, useRef, useState } from 'react'
import type { Language } from '../i18n'

const languages = [
  { code: 'en', label: 'English' },
  { code: 'ru', label: 'Русский' },
] as const satisfies ReadonlyArray<{
  code: Language
  label: string
}>

function LanguageFlag({ language }: { language: Language }) {
  if (language === 'ru') {
    return (
      <svg
        aria-hidden="true"
        className="language-flag"
        data-testid="language-flag-ru"
        focusable="false"
        viewBox="0 0 24 16"
      >
        <rect fill="#fff" height="16" width="24" />
        <rect fill="#0039a6" height="5.334" width="24" y="5.333" />
        <rect fill="#d52b1e" height="5.333" width="24" y="10.667" />
      </svg>
    )
  }

  const stripeHeight = 16 / 13
  const stars = [
    [1.1, 1], [2.7, 1], [4.3, 1], [5.9, 1], [7.5, 1], [9.1, 1],
    [1.9, 2.2], [3.5, 2.2], [5.1, 2.2], [6.7, 2.2], [8.3, 2.2],
    [1.1, 3.4], [2.7, 3.4], [4.3, 3.4], [5.9, 3.4], [7.5, 3.4], [9.1, 3.4],
    [1.9, 4.6], [3.5, 4.6], [5.1, 4.6], [6.7, 4.6], [8.3, 4.6],
    [1.1, 5.8], [2.7, 5.8], [4.3, 5.8], [5.9, 5.8], [7.5, 5.8], [9.1, 5.8],
    [1.9, 7], [3.5, 7], [5.1, 7], [6.7, 7], [8.3, 7],
  ] as const

  return (
    <svg
      aria-hidden="true"
      className="language-flag"
      data-testid="language-flag-en"
      focusable="false"
      viewBox="0 0 24 16"
    >
      <rect fill="#fff" height="16" width="24" />
      {Array.from({ length: 7 }, (_, index) => (
        <rect
          fill="#b22234"
          height={stripeHeight}
          key={index}
          width="24"
          y={index * stripeHeight * 2}
        />
      ))}
      <rect fill="#3c3b6e" height={stripeHeight * 7} width="10.2" />
      {stars.map(([x, y]) => (
        <circle cx={x} cy={y} fill="#fff" key={`${x}-${y}`} r="0.22" />
      ))}
    </svg>
  )
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
        <span aria-hidden="true" className="language-picker-chevron">⌄</span>
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
                {language.code === value ? '✓' : ''}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
