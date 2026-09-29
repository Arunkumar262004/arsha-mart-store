import { useState } from 'react'
import { formatINR } from '../lib/money'

const niceMax = (value) => {
  if (value <= 0) return 100
  const magnitude = 10 ** Math.floor(Math.log10(value))
  return Math.ceil(value / magnitude) * magnitude
}

const dayLabel = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short' })
const fullDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' })

/**
 * Single-series column chart of daily sales. One hue, rounded data-ends on a
 * shared baseline, recessive grid, per-column hover tooltip, and a visually
 * hidden table for screen readers.
 */
export default function SalesChart({ days }) {
  const [active, setActive] = useState(null)
  const values = days.map((d) => Number(d.total))
  const max = niceMax(Math.max(...values, 0))
  const ticks = [max, max / 2, 0]

  return (
    <div>
      <div className="relative flex h-48 gap-3">
        {/* y-axis labels */}
        <div className="flex w-12 shrink-0 flex-col justify-between text-right text-[11px] tabular-nums text-slate-400">
          {ticks.map((t) => (
            <span key={t} className="-translate-y-1/2 first:translate-y-0 last:translate-y-0">
              {t >= 1000 ? `₹${(t / 1000).toFixed(t % 1000 ? 1 : 0)}k` : `₹${t}`}
            </span>
          ))}
        </div>

        <div className="relative flex-1">
          {/* grid */}
          <div className="pointer-events-none absolute inset-0 flex flex-col justify-between">
            {ticks.map((t) => (
              <div key={t} className={`border-t ${t === 0 ? 'border-slate-300' : 'border-dashed border-slate-200'}`} />
            ))}
          </div>

          {/* columns */}
          <div className="absolute inset-0 flex items-end gap-[2px]" onMouseLeave={() => setActive(null)}>
            {days.map((d, i) => {
              const height = (values[i] / max) * 100
              const isActive = active === i
              return (
                <div
                  key={d.date}
                  className="relative flex h-full flex-1 cursor-default items-end justify-center"
                  onMouseEnter={() => setActive(i)}
                  onFocus={() => setActive(i)}
                  onBlur={() => setActive(null)}
                  tabIndex={0}
                  aria-label={`${fullDate(d.date)}: ${formatINR(d.total)}, ${d.orders} orders`}
                >
                  <div
                    className={`w-full max-w-10 rounded-t-[4px] transition-colors ${isActive ? 'bg-brand-600' : 'bg-brand-500'}`}
                    style={{ height: `${Math.max(height, values[i] > 0 ? 1.5 : 0)}%` }}
                  />
                  {isActive && (
                    <div className="pointer-events-none absolute bottom-full z-10 mb-2 whitespace-nowrap rounded-lg bg-slate-900 px-3 py-2 text-xs text-white shadow-lg">
                      <p className="font-medium">{fullDate(d.date)}</p>
                      <p className="mt-0.5 tabular-nums text-slate-300">
                        {formatINR(d.total)} · {d.orders} order{d.orders === 1 ? '' : 's'}
                      </p>
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      </div>

      {/* x-axis labels */}
      <div className="mt-2 flex gap-3">
        <div className="w-12 shrink-0" />
        <div className="flex flex-1 gap-[2px]">
          {days.map((d, i) => (
            <span key={d.date} className={`flex-1 text-center text-[11px] ${i === days.length - 1 ? 'font-semibold text-slate-700' : 'text-slate-500'}`}>
              {i === days.length - 1 ? 'Today' : dayLabel(d.date)}
            </span>
          ))}
        </div>
      </div>

      <table className="sr-only">
        <caption>Sales for the last 7 days</caption>
        <thead>
          <tr>
            <th>Date</th>
            <th>Sales</th>
            <th>Orders</th>
          </tr>
        </thead>
        <tbody>
          {days.map((d) => (
            <tr key={d.date}>
              <td>{d.date}</td>
              <td>{formatINR(d.total)}</td>
              <td>{d.orders}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
