import { useState } from 'react'
import { formatINR } from '../lib/money'

// Categorical slots in fixed order (validated for colour-blind separation);
// "Other" is a neutral gray so it never reads as another product.
const SLOTS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4']
const OTHER = '#94a3b8'

const SIZE = 120
const RADIUS = 46
const STROKE = 18
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
const GAP = 1.6 // ≈2px of white between slices at the rendered size

/**
 * Donut of this month's sales by product (top 5 + "Other"). Hovering a
 * slice or its legend row shows it in the centre; the legend always shows
 * name, amount and share, so colour is never the only cue.
 */
export default function ProductShareChart({ products }) {
  const [active, setActive] = useState(null)
  const values = products.map((p) => Number(p.total))
  const total = values.reduce((a, b) => a + b, 0)

  if (!products.length || total <= 0) {
    return <p className="grid h-48 place-items-center text-sm text-slate-500">No sales yet this month.</p>
  }

  const colorOf = (p, i) => (p.product_id === null ? OTHER : SLOTS[i % SLOTS.length])
  const share = (v) => (v / total) * 100
  const pct = (v) => `${share(v) < 1 ? share(v).toFixed(1) : Math.round(share(v))}%`

  const lengths = values.map((v) => (v / total) * CIRCUMFERENCE)
  const slices = products.map((p, i) => ({
    p,
    i,
    length: lengths[i],
    start: lengths.slice(0, i).reduce((a, b) => a + b, 0), // where this slice begins on the ring
    color: colorOf(p, i),
  }))

  const focus = active === null ? null : products[active]

  return (
    <div className="flex flex-col items-center gap-5 sm:flex-row xl:flex-col 2xl:flex-row">
      <div className="relative h-44 w-44 shrink-0">
        <svg viewBox={`0 0 ${SIZE} ${SIZE}`} className="h-full w-full -rotate-90" role="img" aria-label="Share of this month's sales by product">
          {slices.map(({ p, i, length, start: offset, color }) => {
            const visible = Math.max(length - (slices.length > 1 ? GAP : 0), 0.001)
            return (
              <circle
                key={p.product_id ?? 'other'}
                cx={SIZE / 2}
                cy={SIZE / 2}
                r={RADIUS}
                fill="none"
                stroke={color}
                strokeWidth={active === i ? STROKE + 4 : STROKE}
                strokeDasharray={`${visible} ${CIRCUMFERENCE - visible}`}
                strokeDashoffset={-offset}
                className="cursor-default transition-[stroke-width,opacity] duration-150"
                opacity={active === null || active === i ? 1 : 0.35}
                onMouseEnter={() => setActive(i)}
                onMouseLeave={() => setActive(null)}
              />
            )
          })}
        </svg>

        {/* Centre label: the whole month, or the hovered product */}
        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
          <div className="max-w-[6.5rem]">
            <p className="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500">{focus ? focus.name : 'This month'}</p>
            <p className="text-base font-semibold tabular-nums text-slate-900">{formatINR(focus ? focus.total : total)}</p>
            {focus && <p className="text-xs tabular-nums text-slate-500">{pct(values[active])}</p>}
          </div>
        </div>
      </div>

      <ul className="w-full min-w-0 space-y-1" onMouseLeave={() => setActive(null)}>
        {slices.map(({ p, i, color }) => (
          <li key={p.product_id ?? 'other'}>
            <button
              type="button"
              onMouseEnter={() => setActive(i)}
              onFocus={() => setActive(i)}
              onBlur={() => setActive(null)}
              className={`flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left text-sm transition ${active === i ? 'bg-slate-100' : 'hover:bg-slate-50'}`}
            >
              <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden />
              <span className="min-w-0 flex-1 truncate text-slate-700" title={p.name}>
                {p.name}
              </span>
              <span className="shrink-0 tabular-nums text-slate-500">{pct(values[i])}</span>
              <span className="w-20 shrink-0 text-right font-medium tabular-nums text-slate-900">{formatINR(p.total)}</span>
            </button>
          </li>
        ))}
      </ul>

      <table className="sr-only">
        <caption>This month's sales by product</caption>
        <thead>
          <tr>
            <th>Product</th>
            <th>Sales</th>
            <th>Share</th>
            <th>Units</th>
          </tr>
        </thead>
        <tbody>
          {products.map((p, i) => (
            <tr key={p.product_id ?? 'other'}>
              <td>{p.name}</td>
              <td>{formatINR(p.total)}</td>
              <td>{pct(values[i])}</td>
              <td>{p.units}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
