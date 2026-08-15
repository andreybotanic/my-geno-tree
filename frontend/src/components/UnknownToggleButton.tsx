type UnknownToggleButtonProps = {
  active: boolean
  onChange: (active: boolean) => void
  tabIndex?: number | undefined
  title: string
}

export function UnknownToggleButton({
  active,
  onChange,
  tabIndex,
  title,
}: UnknownToggleButtonProps) {
  return (
    <button
      aria-label={title}
      aria-pressed={active}
      className="unknown-toggle-button"
      onClick={() => onChange(!active)}
      tabIndex={tabIndex}
      title={title}
      type="button"
    >
      <UnknownIcon aria-hidden="true" focusable="false" />
    </button>
  )
}
import UnknownIcon from '../assets/icons/unknown.svg?react'
