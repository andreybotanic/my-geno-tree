import { useEffect } from 'react'
import CloseIcon from '../assets/icons/close.svg?react'

type ToastNoticeProps = {
  closeLabel: string
  message: string
  onClose: () => void
  timeoutMs?: number
}

export function ToastNotice({
  closeLabel,
  message,
  onClose,
  timeoutMs = 4_000,
}: ToastNoticeProps) {
  useEffect(() => {
    const timeout = window.setTimeout(onClose, timeoutMs)
    return () => window.clearTimeout(timeout)
  }, [onClose, timeoutMs])

  return (
    <div className="toast-notice" role="status">
      <span>{message}</span>
      <button aria-label={closeLabel} onClick={onClose} title={closeLabel} type="button">
        <CloseIcon aria-hidden="true" focusable="false" />
      </button>
    </div>
  )
}
