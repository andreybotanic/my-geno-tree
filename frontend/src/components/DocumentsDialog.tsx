import { useEffect, useRef, type ComponentProps } from 'react'
import { useTranslation } from 'react-i18next'
import CloseIcon from '../assets/icons/close.svg?react'
import { DocumentsPanel } from './DocumentsPanel'

type Props = ComponentProps<typeof DocumentsPanel> & { onClose: () => void }

export function DocumentsDialog({ onClose, ...props }: Props) {
  const { t } = useTranslation()
  const dialog = useRef<HTMLElement>(null)
  useEffect(() => {
    const previous = document.activeElement
    dialog.current?.querySelector<HTMLButtonElement>('button')?.focus()
    const handleKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        event.stopPropagation()
        onClose()
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
      if (previous instanceof HTMLElement && previous.isConnected) previous.focus()
    }
  }, [onClose])

  return <div className="dialog-backdrop">
    <section ref={dialog} role="dialog" aria-modal="true" aria-label={t('documents.title')}
      className="dialog-card tree-dialog documents-dialog">
      <button type="button" className="dialog-close" onClick={onClose} aria-label={t('common.close')}>
        <CloseIcon className="dialog-close-icon" aria-hidden="true" />
      </button>
      <header className="documents-heading">
        <h2>{t('documents.title')}</h2>
      </header>
      <DocumentsPanel {...props} />
    </section>
  </div>
}
