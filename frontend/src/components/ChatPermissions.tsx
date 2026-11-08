import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import AskIcon from '../assets/icons/permission-ask.svg?react'
import AutoIcon from '../assets/icons/permission-auto.svg?react'
import ChevronIcon from '../assets/icons/chevron-down.svg?react'
import CheckIcon from '../assets/icons/check.svg?react'

type Props = { automatic: boolean; disabled: boolean; onChange: (automatic: boolean) => void }

export function ChatPermissions({ automatic, disabled, onChange }: Props) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const container = useRef<HTMLDivElement>(null)
  const trigger = useRef<HTMLButtonElement>(null)
  const options = useRef<(HTMLButtonElement | null)[]>([])
  const Icon = automatic ? AutoIcon : AskIcon

  useEffect(() => {
    if (!open) return
    options.current[automatic ? 1 : 0]?.focus()
    const outside = (event: PointerEvent) => {
      if (!container.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', outside)
    return () => document.removeEventListener('pointerdown', outside)
  }, [open, automatic])

  return <div className="chat-permissions" ref={container} onKeyDown={(event) => {
    if (!open && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
      event.preventDefault()
      setOpen(true)
    }
    if (event.key === 'Escape' && open) {
      event.stopPropagation()
      setOpen(false)
      trigger.current?.focus()
    }
    if (open && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      event.preventDefault()
      const index = event.key === 'Home' ? 0 : event.key === 'End' ? 1
        : document.activeElement === options.current[0] ? 1 : 0
      options.current[index]?.focus()
    }
  }}>
    <button type="button" className="chat-permissions-trigger" ref={trigger} aria-haspopup="menu"
      aria-expanded={open} disabled={disabled} title={t('chat.permissions.title')}
      onClick={() => setOpen((value) => !value)}>
      <Icon aria-hidden="true" /><span>{t(automatic ? 'chat.permissions.auto' : 'chat.permissions.ask')}</span>
      <ChevronIcon aria-hidden="true" />
    </button>
    {open && <div className="chat-permissions-menu" role="menu" aria-label={t('chat.permissions.title')}>
      {[false, true].map((value, index) => {
        const OptionIcon = value ? AutoIcon : AskIcon
        return <button type="button" role="menuitemradio" aria-checked={automatic === value} key={String(value)}
          ref={(element) => { options.current[index] = element }} disabled={disabled}
          onClick={() => {
            setOpen(false)
            trigger.current?.focus()
            if (value !== automatic) onChange(value)
          }}>
          <OptionIcon aria-hidden="true" /><span>{t(value ? 'chat.permissions.auto' : 'chat.permissions.ask')}</span>
          {automatic === value && <CheckIcon className="chat-permissions-check" aria-hidden="true" />}
        </button>
      })}
    </div>}
  </div>
}
