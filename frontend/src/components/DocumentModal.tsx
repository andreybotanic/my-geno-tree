import { useLayoutEffect, useEffectEvent, useRef, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import CloseIcon from '../assets/icons/close.svg?react'

type Props = { title: string; active?: boolean; className?: string; onClose: () => void; children: ReactNode }

export function DocumentModal({ title, active = true, className = '', onClose, children }: Props) {
  const { t } = useTranslation()
  const dialog = useRef<HTMLElement>(null)
  const launcher = useRef(document.activeElement)
  useLayoutEffect(() => {
    const previous = launcher.current
    return () => { if (previous instanceof HTMLElement && previous.isConnected) previous.focus() }
  }, [])
  const close = useEffectEvent(onClose)
  useLayoutEffect(() => {
    if (!active) return
    dialog.current?.querySelector<HTMLButtonElement>('button')?.focus()
    const handleKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        event.stopPropagation()
        close()
      } else if (event.key === 'Tab') {
        const controls = Array.from(dialog.current?.querySelectorAll<HTMLElement>('*') ?? [])
          .filter((element) => element.tabIndex >= 0 && !element.matches(':disabled'))
        const first = controls[0]
        const last = controls[controls.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last?.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first?.focus()
        }
      }
    }
    document.addEventListener('keydown', handleKey, true)
    return () => {
      document.removeEventListener('keydown', handleKey, true)
    }
  }, [active])

  return <div className="dialog-backdrop" hidden={!active}>
    <section ref={dialog} role="dialog" aria-modal="true" aria-label={title}
      className={`dialog-card tree-dialog ${className}`}>
      <button type="button" className="dialog-close" onClick={onClose} aria-label={t('common.close')}>
        <CloseIcon className="dialog-close-icon" aria-hidden="true" />
      </button>
      <header className="documents-heading">
        <h2>{title}</h2>
      </header>
      {children}
    </section>
  </div>
}
