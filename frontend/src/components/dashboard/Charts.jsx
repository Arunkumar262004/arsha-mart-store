import { useState } from 'react'
import { MONTHS, compactRupees, niceTicks } from './format'

/**
 * A row of thin bars for a KPI card (last 30 days). Hovering a bar shows its
 * day and value; the hit area is the full column, wider than the bar.
 */
export function SparkBars({ series, color, format, label }) {
  const [hover, setHover] = useState(null)
  const max = Math.max(...series, 0)
  const start = new Date()
  start.setDate(start.getDate() - (series.length - 1))
  const dayOf = (i) => {
    const d = new Date(start)
    d.setDate(d.getDate() + i)
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
  }

  return (
    <div className="relative mt-4" onMouseLeave={() => setHover(null)}>
      <div className="flex h-10 items-end gap-[2px]" role="img" aria-label={`${label}, last 30 days`}>
        {series.map((v, i) => (
          <div key={i} className="flex h-full flex-1 items-end" onMouseEnter={() => setHover(i)}>
            <div
              className="w-full rounded-t-[2px] transition-opacity"
              style={{
                height: `${max > 0 ? Math.max((v / max) * 100, v > 0 ? 10 : 6) : 6}%`,
                background: v > 0 ? color : '#e9edf3',
                opacity: hover === null || hover === i ? 1 : 0.35,
              }}
            />
          </div>
        ))}
      </div>
      {hover !== null && (
        <div
          className="pointer-events-none absolute bottom-full z-10 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] text-white shadow"
          style={{ left: `${((hover + 0.5) / series.length) * 100}%` }}
        >
          {dayOf(hover)} · <b className="font-semibold">{format(series[hover])}</b>
        </div>
      )}
    </div>
  )
}

/**
 * Monthly sales for one year. Bars are neutral; the current month (or the
 * hovered one) is drawn in the accent colour with its value in a pill.
 */
export function MonthlyBars({ months, highlight, color }) {
  const [hover, setHover] = useState(null)
  const values = months.map((m) => Number(m.total))
  const ticks = niceTicks(Math.max(...values, 0))
  const top = ticks[ticks.length - 1]
  const active = hover ?? highlight

  return (
    <div className="mt-5 flex gap-3">
      <div className="relative h-60 w-10 shrink-0 text-right text-[11px] text-slate-400" aria-hidden>
        {ticks.map((t) => (
          <span key={t} className="absolute right-0 -translate-y-1/2" style={{ bottom: `${(t / top) * 100}%` }}>
            {t === 0 ? '0' : compactRupees(t).replace('₹', '')}
          </span>
        ))}
      </div>

      <div className="min-w-0 flex-1">
        <div className="relative h-60" onMouseLeave={() => setHover(null)}>
          {ticks.map((t) => (
            <div key={t} className="absolute inset-x-0 border-t border-slate-100" style={{ bottom: `${(t / top) * 100}%` }} />
          ))}
          <div className="absolute inset-0 flex items-end gap-2 sm:gap-3">
            {months.map((m, i) => {
              const v = values[i]
              const on = active === i
              return (
                <div key={m.month} className="relative flex h-full flex-1 items-end justify-center" onMouseEnter={() => setHover(i)}>
                  <div
                    className="w-full max-w-8 rounded-t-[4px] transition-colors"
                    style={{
                      height: `${top > 0 ? Math.max((v / top) * 100, v > 0 ? 1.5 : 0) : 0}%`,
                      background: on ? color : '#eef1f6',
                    }}
                  />
                  {on && v > 0 && (
                    <div
                      className="pointer-events-none absolute z-10 -translate-y-full whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-[11px] font-semibold text-white shadow"
                      style={{ bottom: `calc(${(v / top) * 100}% + 8px)` }}
                    >
                      {compactRupees(v)}
                      <span className="font-normal text-slate-300"> · {m.orders} bills</span>
                      <span className="absolute left-1/2 top-full -translate-x-1/2 border-x-4 border-t-4 border-x-transparent border-t-slate-900" />
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        </div>
        <div className="mt-2 flex gap-2 sm:gap-3">
          {months.map((m, i) => (
            <span key={m.month} className={`flex-1 text-center text-[11px] ${active === i ? 'font-semibold text-slate-800' : 'text-slate-400'}`}>
              {MONTHS[m.month - 1]}
            </span>
          ))}
        </div>
      </div>
    </div>
  )
}

/**
 * Ring chart with a 3px gap between segments, a centre figure and a legend
 * (label, detail line, share) so identity never depends on colour alone.
 *
 * segments: [{ key, label, value, color, display }]
 */
export function Donut({ segments, centre, centreLabel, format }) {
  const [hover, setHover] = useState(null)
  const total = segments.reduce((s, x) => s + x.value, 0)
  const size = 164
  const stroke = 20
  const r = (size - stroke) / 2
  const circumference = 2 * Math.PI * r
  const gap = total > 0 && segments.filter((s) => s.value > 0).length > 1 ? 3 : 0
  const lengths = segments.map((s) => (total > 0 ? (s.value / total) * circumference : 0))
  const arcs = segments.map((s, i) => ({
    ...s,
    dash: Math.max(lengths[i] - gap, 0),
    offset: lengths.slice(0, i).reduce((sum, l) => sum + l, 0),
  }))
  const focus = hover !== null ? segments[hover] : null

  return (
    <div className="flex flex-col items-center gap-5 sm:flex-row">
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90" role="img" aria-label={centreLabel}>
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#f1f5f9" strokeWidth={stroke} />
          {arcs.map((a, i) =>
            a.dash > 0 ? (
              <circle
                key={a.key}
                cx={size / 2}
                cy={size / 2}
                r={r}
                fill="none"
                stroke={a.color}
                strokeWidth={hover === i ? stroke + 4 : stroke}
                strokeDasharray={`${a.dash} ${circumference - a.dash}`}
                strokeDashoffset={-a.offset}
                className="transition-[stroke-width]"
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
              />
            ) : null,
          )}
        </svg>
        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
          <div>
            <p className="text-lg font-semibold tabular-nums text-slate-900">{focus ? format(focus.value) : centre}</p>
            <p className="text-[11px] text-slate-500">{focus ? focus.label : centreLabel}</p>
          </div>
        </div>
      </div>

      <ul className="w-full min-w-0 flex-1 space-y-2.5">
        {segments.map((s, i) => (
          <li
            key={s.key}
            className={`flex items-center gap-2.5 text-sm transition-opacity ${hover !== null && hover !== i ? 'opacity-50' : ''}`}
            onMouseEnter={() => setHover(i)}
            onMouseLeave={() => setHover(null)}
          >
            <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: s.color }} aria-hidden />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-slate-700">{s.label}</span>
              {s.display && <span className="block text-[11px] text-slate-400">{s.display}</span>}
            </span>
            <span className="font-medium tabular-nums text-slate-800">{total > 0 ? `${((s.value / total) * 100).toFixed(1)}%` : '0%'}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
