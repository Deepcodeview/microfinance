import { useState } from 'react'

/**
 * Small SVG chart kit. Colours come from --series-N tokens (validated palette, light + dark steps),
 * marks are thin with 4px rounded data-ends and 2px surface gaps, every chart has a hover
 * tooltip and a table view, and text never takes the series colour.
 */

export interface Series {
  key: string
  label: string
  /** Categorical slot 1–8, assigned in fixed order. */
  slot: number
}

const seriesColor = (slot: number) => `var(--series-${slot})`

function niceMax(value: number): number {
  if (value <= 0) return 1
  const exp = Math.pow(10, Math.floor(Math.log10(value)))
  const n = value / exp
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * exp
}

/** Path for a bar whose top corners (data end) are rounded and whose base sits square on the axis. */
function barPath(x: number, y: number, w: number, h: number, r = 4): string {
  if (h <= 0) return ''
  const rr = Math.min(r, w / 2, h)
  return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`
}

export function ColumnChart({
  categories,
  series,
  rows,
  stacked = false,
  format,
  height = 220,
  labelEvery = 1,
  caption,
  width = 640,
}: {
  categories: string[]
  series: Series[]
  rows: Record<string, number>[]
  stacked?: boolean
  format: (n: number) => string
  height?: number
  labelEvery?: number
  caption: string
  /** viewBox width — set close to the rendered width so tick text stays ~11px. */
  width?: number
}) {
  const [hover, setHover] = useState<number | null>(null)
  const pad = { top: 12, right: 8, bottom: 26, left: 56 }
  const plotW = width - pad.left - pad.right
  const plotH = height - pad.top - pad.bottom
  const totals = rows.map((r) => (stacked ? series.reduce((s, x) => s + (r[x.key] ?? 0), 0) : Math.max(0, ...series.map((x) => r[x.key] ?? 0))))
  const max = niceMax(Math.max(0, ...totals))
  const band = plotW / Math.max(1, categories.length)
  const groupW = Math.min(band * 0.7, stacked ? 28 : 18 * series.length + 2 * (series.length - 1))
  const barW = stacked ? groupW : (groupW - 2 * (series.length - 1)) / series.length
  const y = (v: number) => pad.top + plotH - (v / max) * plotH
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * max)

  return (
    <figure className="viz" aria-label={caption}>
      {series.length > 1 ? (
        <div className="viz__legend">
          {series.map((s) => (
            <span key={s.key} className="viz__key">
              <span className="viz__swatch" style={{ background: seriesColor(s.slot) }} />
              {s.label}
            </span>
          ))}
        </div>
      ) : null}
      <div className="viz__frame">
        <svg viewBox={`0 0 ${width} ${height}`} className="viz__svg" role="img" aria-label={caption} onMouseLeave={() => setHover(null)}>
          {ticks.map((t) => (
            <g key={t}>
              <line x1={pad.left} x2={width - pad.right} y1={y(t)} y2={y(t)} className={t === 0 ? 'viz__axis' : 'viz__grid'} />
              <text x={pad.left - 8} y={y(t) + 4} className="viz__tick" textAnchor="end">
                {format(t)}
              </text>
            </g>
          ))}
          {categories.map((cat, i) => {
            const cx = pad.left + band * i + band / 2
            let stackTop = 0
            return (
              <g key={cat} opacity={hover === null || hover === i ? 1 : 0.55}>
                {series.map((s, si) => {
                  const v = rows[i]?.[s.key] ?? 0
                  if (stacked) {
                    const below = stackTop
                    stackTop += v
                    if (v <= 0) return null
                    // A segment resting on another leaves a 2px surface gap; only the topmost gets the rounded end.
                    const gap = below > 0 ? 2 : 0
                    const yTop = y(stackTop)
                    const h = y(below) - yTop - gap
                    const isTop = series.slice(si + 1).every((n) => !(rows[i]?.[n.key] ?? 0))
                    return isTop
                      ? <path key={s.key} d={barPath(cx - barW / 2, yTop, barW, h)} fill={seriesColor(s.slot)} />
                      : <rect key={s.key} x={cx - barW / 2} y={yTop} width={barW} height={Math.max(0, h)} fill={seriesColor(s.slot)} />
                  }
                  const x = cx - groupW / 2 + si * (barW + 2)
                  return <path key={s.key} d={barPath(x, y(v), barW, y(0) - y(v))} fill={seriesColor(s.slot)} />
                })}
                {i % labelEvery === 0 ? (
                  <text x={cx} y={height - 8} className="viz__tick" textAnchor="middle">
                    {cat}
                  </text>
                ) : null}
                {/* Hit target: the whole column band, larger than the mark. */}
                <rect
                  x={pad.left + band * i}
                  y={pad.top}
                  width={band}
                  height={plotH}
                  fill="transparent"
                  tabIndex={0}
                  onMouseEnter={() => setHover(i)}
                  onFocus={() => setHover(i)}
                  onBlur={() => setHover(null)}
                  aria-label={`${cat}: ${series.map((s) => `${s.label} ${format(rows[i]?.[s.key] ?? 0)}`).join(', ')}`}
                />
              </g>
            )
          })}
        </svg>
        {hover !== null ? (
          <div className="viz__tooltip" style={{ left: `${((pad.left + band * hover + band / 2) / width) * 100}%` }}>
            <div className="viz__tooltip-title">{categories[hover]}</div>
            {series.map((s) => (
              <div key={s.key} className="viz__tooltip-row">
                <span className="viz__line-key" style={{ background: seriesColor(s.slot) }} />
                <strong>{format(rows[hover]?.[s.key] ?? 0)}</strong>
                <span className="muted">{s.label}</span>
              </div>
            ))}
          </div>
        ) : null}
      </div>
      <DataTable caption={caption} head={['', ...series.map((s) => s.label)]} rows={categories.map((c, i) => [c, ...series.map((s) => format(rows[i]?.[s.key] ?? 0))])} />
    </figure>
  )
}

/** Horizontal magnitude bars with direct labels — for ranked or categorical comparisons. */
export function BarList({
  items,
  format,
  slot = 1,
  caption,
  colors,
}: {
  items: { label: string; value: number; note?: string }[]
  format: (n: number) => string
  slot?: number
  caption: string
  /** Optional per-item fill (e.g. ordinal ramp for aging buckets). */
  colors?: string[]
}) {
  const max = Math.max(1, ...items.map((i) => i.value))
  return (
    <figure className="viz" aria-label={caption}>
      <div className="barlist">
        {items.map((item, i) => (
          <div key={item.label} className="barlist__row" title={`${item.label}: ${format(item.value)}`}>
            <div className="barlist__label">
              <span>{item.label}</span>
              <span className="barlist__value">
                {format(item.value)}
                {item.note ? <span className="muted small"> · {item.note}</span> : null}
              </span>
            </div>
            <div className="barlist__track">
              {item.value > 0 ? <div className="barlist__bar" style={{ width: `${(item.value / max) * 100}%`, background: colors?.[i] ?? seriesColor(slot) }} /> : null}
            </div>
          </div>
        ))}
      </div>
      <DataTable caption={caption} head={['', 'Value']} rows={items.map((i) => [i.label, format(i.value) + (i.note ? ` (${i.note})` : '')])} />
    </figure>
  )
}

function DataTable({ caption, head, rows }: { caption: string; head: string[]; rows: string[][] }) {
  return (
    <details className="viz__table">
      <summary>View as table</summary>
      <table className="table table--compact">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr>{head.map((h, i) => <th key={i} className={i > 0 ? 'num' : undefined}>{h}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i}>{r.map((c, j) => <td key={j} className={j > 0 ? 'num' : undefined}>{c}</td>)}</tr>
          ))}
        </tbody>
      </table>
    </details>
  )
}
