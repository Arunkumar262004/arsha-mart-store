import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, PackageCheck, TriangleAlert } from 'lucide-react'
import { getLowStock } from '../api'

/**
 * Raised "3D" alert card: layered gradient surface with a tinted drop
 * shadow, an embossed icon tile, and one tile per product with a meter
 * showing how far below the threshold its stock is. Tiles flow into
 * 1-4 columns depending on the card's own width (container queries).
 */
export default function LowStockAlert({ refreshKey }) {
  const [state, setState] = useState({ loading: true, products: [], threshold: null, error: false })

  useEffect(() => {
    let cancelled = false
    getLowStock()
      .then((res) => !cancelled && setState({ loading: false, products: res.data, threshold: res.meta.threshold, error: false }))
      .catch(() => !cancelled && setState((s) => ({ ...s, loading: false, error: true })))
    return () => {
      cancelled = true
    }
  }, [refreshKey])

  const { products, threshold } = state
  const outCount = products.filter((p) => p.stock === 0).length

  return (
    <aside className="@container relative overflow-hidden rounded-2xl border border-amber-200/80 bg-gradient-to-br from-amber-50 via-white to-orange-50 p-5 shadow-[0_1px_0_rgba(255,255,255,0.9)_inset,0_10px_30px_-12px_rgba(217,119,6,0.45),0_4px_10px_-6px_rgba(217,119,6,0.25)]">
      {/* soft glow for depth */}
      <div className="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-amber-300/30 blur-2xl" aria-hidden />

      <div className="relative flex flex-wrap items-center gap-3">
        <div className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.5),0_6px_14px_-4px_rgba(234,88,12,0.6)]">
          <TriangleAlert size={20} strokeWidth={2.25} aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
            Low stock
            {threshold !== null && (
              <span className="rounded-md bg-white px-1.5 py-0.5 text-xs font-semibold tabular-nums text-amber-800 shadow-sm ring-1 ring-amber-200">
                &lt; {threshold}
              </span>
            )}
          </h2>
          <p className="text-xs text-slate-500">
            {state.loading
              ? 'Checking…'
              : `${products.length} product${products.length === 1 ? '' : 's'} below the threshold${outCount ? ` · ${outCount} out of stock` : ''}`}
          </p>
        </div>
        <Link
          to="/inventory?filter=low"
          className="no-print inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-amber-800 shadow-sm ring-1 ring-amber-200 transition hover:bg-amber-50"
        >
          Manage <ArrowRight size={14} aria-hidden />
        </Link>
      </div>

      <div className="relative mt-4">
        {state.error && <p className="text-sm text-red-600">Could not load low-stock items.</p>}
        {!state.loading && !state.error && products.length === 0 && (
          <div className="flex items-center gap-2 rounded-xl bg-white/80 px-3 py-3 text-sm text-emerald-700 shadow-sm ring-1 ring-emerald-100">
            <PackageCheck size={18} aria-hidden /> All products are well stocked.
          </div>
        )}

        <ul className="grid gap-2.5 @md:grid-cols-2 @3xl:grid-cols-4">
          {products.map((p) => {
            const out = p.stock === 0
            const pct = threshold ? Math.max(4, Math.round((p.stock / threshold) * 100)) : 0
            return (
              <li
                key={p.id}
                className="rounded-xl bg-white/90 px-3 py-2.5 shadow-[0_1px_2px_rgba(15,23,42,0.06),0_2px_6px_-2px_rgba(15,23,42,0.08)] ring-1 ring-slate-900/5 transition hover:-translate-y-0.5 hover:shadow-md"
              >
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-sm font-medium text-slate-800" title={p.name}>{p.name}</span>
                  <span
                    className={`shrink-0 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums ${
                      out ? 'bg-red-50 text-red-700 ring-1 ring-red-200' : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200'
                    }`}
                  >
                    {out ? 'Out' : `${p.stock} left`}
                  </span>
                </div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100" role="img" aria-label={`${p.stock} of ${threshold} units`}>
                  <div
                    className={`h-full rounded-full ${out ? 'bg-red-500' : pct <= 40 ? 'bg-orange-500' : 'bg-amber-400'}`}
                    style={{ width: out ? '100%' : `${pct}%`, opacity: out ? 0.25 : 1 }}
                  />
                </div>
              </li>
            )
          })}
        </ul>
      </div>
    </aside>
  )
}
