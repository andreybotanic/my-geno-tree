import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import i18n from '../i18n'
import { PhotoViewerDialog } from './PhotoViewerDialog'

vi.mock('./AuthenticatedImage', () => ({
  AuthenticatedImage: ({
    alt,
    className,
  }: {
    alt: string
    className?: string
  }) => <img alt={alt} className={className} src="blob:photo" />,
}))

describe('PhotoViewerDialog', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
  })

  it('uses a top close icon and centers the full photo without a visible title', async () => {
    const onClose = vi.fn()
    render(
      <PhotoViewerDialog
        onClose={onClose}
        title="Full photo"
        url="/private-photo"
      />,
    )

    expect(screen.getByRole('dialog', { name: 'Full photo' })).toBeInTheDocument()
    expect(screen.queryByRole('heading')).not.toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Full photo' }))
      .toHaveClass('photo-viewer-image')
    expect(document.querySelector('.photo-viewer-content'))
      .toContainElement(screen.getByRole('img', { name: 'Full photo' }))

    const closeButton = screen.getByRole('button', { name: 'Close' })
    expect(closeButton).toContainElement(document.querySelector('.dialog-close-icon'))
    await userEvent.click(closeButton)
    expect(onClose).toHaveBeenCalledOnce()
  })
})
