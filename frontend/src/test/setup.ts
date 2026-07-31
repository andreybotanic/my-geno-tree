import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

class TestResizeObserver {
  private readonly callback: ResizeObserverCallback

  constructor(callback: ResizeObserverCallback) {
    this.callback = callback
  }

  disconnect() {}
  observe(target: Element) {
    this.callback([{
      borderBoxSize: [],
      contentBoxSize: [],
      contentRect: new DOMRect(0, 0, 800, 600),
      devicePixelContentBoxSize: [],
      target,
    }], this as ResizeObserver)
  }
  unobserve() {}
}

globalThis.ResizeObserver = TestResizeObserver as typeof ResizeObserver

class TestDOMMatrixReadOnly {
  readonly m22 = 1
}

globalThis.DOMMatrixReadOnly = TestDOMMatrixReadOnly as unknown as typeof DOMMatrixReadOnly

Object.defineProperties(HTMLElement.prototype, {
  offsetHeight: {
    configurable: true,
    get: () => 144,
  },
  offsetWidth: {
    configurable: true,
    get: () => 220,
  },
})

afterEach(cleanup)
