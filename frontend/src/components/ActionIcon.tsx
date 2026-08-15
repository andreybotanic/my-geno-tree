type ActionIconProps = {
  name: 'delete' | 'edit'
}

export function ActionIcon({ name }: ActionIconProps) {
  const Icon = name === 'edit' ? EditIcon : DeleteIcon

  return <Icon aria-hidden="true" className="action-icon" focusable="false" />
}
import DeleteIcon from '../assets/icons/delete.svg?react'
import EditIcon from '../assets/icons/edit.svg?react'
