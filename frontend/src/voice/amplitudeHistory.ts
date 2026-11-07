type AudioBar = { id: number; peak: number; x: number; width: number }

/** Twenty-millisecond peaks grouped into immutable, recording-aligned fragments. */
export class AmplitudeHistory {
  private readonly values = new Float32Array(500)
  private samples = 0
  private samplesPerBar = 0
  private pendingPeak = 0
  private startedAt: number | undefined
  private readonly completed: { endSample: number; peak: number }[] = []

  push(value: number, now = performance.now()) {
    this.startedAt ??= now - 20
    const peak = Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : 0
    this.values[this.samples % 500] = peak
    this.samples++
    if (this.samplesPerBar) this.collect(peak, this.samples)
  }

  clear() {
    this.values.fill(0)
    this.samples = 0
    this.samplesPerBar = 0
    this.pendingPeak = 0
    this.startedAt = undefined
    this.completed.length = 0
  }

  bars(width: number, now = performance.now()): AudioBar[] {
    if (width <= 0) return []
    if (!this.samplesPerBar) {
      // Pick the density once per recording. Resizing must not regroup existing audio.
      this.samplesPerBar = Math.ceil(500 / Math.max(1, Math.floor(width / 6)))
      const retainedFrom = Math.max(0, this.samples - 500)
      const first = Math.ceil(retainedFrom / this.samplesPerBar) * this.samplesPerBar
      for (let index = first; index < this.samples; index++) {
        this.collect(this.values[index % 500]!, index + 1)
      }
    }
    const elapsed = this.startedAt === undefined ? 0 : now - this.startedAt
    const barWidth = Math.min(4, width * this.samplesPerBar / 500 * 2 / 3)
    return this.completed.map(({ endSample, peak }) => ({
      id: endSample,
      peak,
      x: width - (elapsed - endSample * 20) * width / 10000,
      width: barWidth,
    })).filter((bar) => bar.x < width && bar.x + bar.width > 0)
  }

  private collect(peak: number, endSample: number) {
    this.pendingPeak = Math.max(this.pendingPeak, peak)
    if (endSample % this.samplesPerBar !== 0) return
    this.completed.push({ endSample, peak: this.pendingPeak })
    this.pendingPeak = 0
    // Keep one fragment beyond each edge for clipping and the fading transition.
    if (this.completed.length > Math.ceil(500 / this.samplesPerBar) + 2) this.completed.shift()
  }
}

export function drawHistogram(
  context: CanvasRenderingContext2D,
  history: AmplitudeHistory,
  width: number,
  height: number,
  color: string,
  now = performance.now(),
) {
  context.clearRect(0, 0, width, height)
  context.fillStyle = color
  history.bars(width, now).forEach((bar) => {
    const barHeight = Math.max(2, Math.min(height, bar.peak * height))
    context.fillRect(bar.x, (height - barHeight) / 2, bar.width, barHeight)
  })
}
