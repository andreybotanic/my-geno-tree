/** Twenty-millisecond peaks covering the last ten seconds, oldest first. */
export class AmplitudeHistory {
  private readonly values = new Float32Array(500)
  private cursor = 0

  push(value: number) {
    this.values[this.cursor] = Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : 0
    this.cursor = (this.cursor + 1) % this.values.length
  }

  clear() {
    this.values.fill(0)
    this.cursor = 0
  }

  bars(width: number): number[] {
    const count = Math.max(1, Math.min(500, Math.floor(width / 6)))
    return Array.from({ length: count }, (_, bar) => {
      const from = Math.floor(bar * 500 / count)
      const to = Math.floor((bar + 1) * 500 / count)
      let peak = 0
      for (let index = from; index < to; index++) {
        peak = Math.max(peak, this.values[(this.cursor + index) % 500]!)
      }
      return peak
    })
  }
}

export function drawHistogram(
  context: CanvasRenderingContext2D,
  history: AmplitudeHistory,
  width: number,
  height: number,
  color: string,
) {
  context.clearRect(0, 0, width, height)
  context.fillStyle = color
  const bars = history.bars(width)
  const left = Math.max(0, (width - (bars.length * 6 - 2)) / 2)
  bars.forEach((peak, index) => {
    const barHeight = Math.max(2, Math.min(height, peak * height))
    context.fillRect(left + index * 6, (height - barHeight) / 2, Math.min(4, width), barHeight)
  })
}
