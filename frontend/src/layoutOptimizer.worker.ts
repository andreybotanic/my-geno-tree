/// <reference lib="webworker" />

import { optimizeTreeLayout } from './layoutOptimizer'
import type { TreeSnapshot } from './api/generated'

let cancelled = false

self.onmessage = (event: MessageEvent<
  | { type: 'cancel' }
  | { type: 'start'; snapshot: TreeSnapshot; limit?: bigint }
>) => {
  if (event.data.type === 'cancel') {
    cancelled = true
    return
  }

  cancelled = false
  void optimizeTreeLayout(event.data.snapshot, {
    ...(event.data.limit === undefined ? {} : { limit: event.data.limit }),
    isCancelled: () => cancelled,
    onProgress: (progress) => self.postMessage({ type: 'progress', ...progress }),
    yieldControl: () => new Promise((resolve) => setTimeout(resolve, 0)),
  }).then((result) => {
    self.postMessage({ type: 'result', result })
  })
}
