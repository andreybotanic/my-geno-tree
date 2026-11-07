import { render, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { AmplitudeHistory } from '../voice/amplitudeHistory'
import { ChatAudioHistogram } from './ChatAudioHistogram'
import '../i18n'

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals() })

it('renders a localized, responsive histogram at no more than thirty frames a second', () => {
  let draw!: FrameRequestCallback
  const request = vi.fn((callback: FrameRequestCallback) => { draw = callback; return 7 })
  const cancel = vi.fn()
  vi.stubGlobal('requestAnimationFrame', request)
  vi.stubGlobal('cancelAnimationFrame', cancel)
  const context = { clearRect: vi.fn(), fillRect: vi.fn(), setTransform: vi.fn() }
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as unknown as CanvasRenderingContext2D)
  const history = new AmplitudeHistory()
  for (let index = 0; index < 4; index++) history.push(0.5, -80 + index * 20)
  const view = render(<ChatAudioHistogram history={history} />)
  expect(screen.getByRole('img')).toHaveAccessibleName(/10/)
  draw(0)
  draw(16)
  draw(34)
  expect(context.clearRect).toHaveBeenCalledTimes(2)
  const first = context.fillRect.mock.calls[0]!
  const second = context.fillRect.mock.calls[1]!
  expect(second[0]).toBeLessThan(first[0])
  expect(second.slice(1)).toEqual(first.slice(1))
  expect(context.setTransform).toHaveBeenCalled()
  view.unmount()
  expect(cancel).toHaveBeenCalledWith(7)
})

it('does not schedule animation when a canvas context is unavailable', () => {
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null)
  const request = vi.fn()
  vi.stubGlobal('requestAnimationFrame', request)
  render(<ChatAudioHistogram history={new AmplitudeHistory()} />)
  expect(request).not.toHaveBeenCalled()
})
