import { useBranding } from '../../branding/BrandingContext'
import { storeAddress } from './printing'

const cell = 'border border-slate-400 px-1.5 py-1'

/**
 * Printable A4 layout shared by quotations, delivery challans and transfer
 * challans: store header, a party box, a meta box, a lines table and totals.
 *
 * columns: [{ key, label, align: 'right' | 'center' }]; rows: plain objects
 * keyed by column key. meta / totals: [label, value] pairs.
 */
export default function DocumentSheet({ ref, title, subtitle, store, party, meta = [], columns, rows, totals = [], notes, footer, signatures = [] }) {
  const { logo } = useBranding()
  // Who issues the document: company legal details, or the store's own (set by the API).
  const seller = store?.seller ?? store
  const align = (a) => (a === 'right' ? 'text-right tabular-nums' : a === 'center' ? 'text-center' : 'text-left')

  return (
    <div ref={ref} className="w-full bg-white p-6 text-[11px] leading-snug text-slate-900 print:p-0">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <img src={logo} alt="" className="h-14 w-14 object-contain" />
          <div>
            <p className="text-lg font-bold">{seller?.name}</p>
            {seller?.branch && <p className="text-slate-500">Branch: {seller.branch}</p>}
            {storeAddress(seller) && <p>{storeAddress(seller)}</p>}
            {seller?.phone && <p>Ph: {seller.phone}{seller.email && ` · ${seller.email}`}</p>}
            {seller?.gstin && <p className="font-semibold">GSTIN: {seller.gstin}{seller.pan && ` · PAN: ${seller.pan}`}</p>}
          </div>
        </div>
        <div className="text-right">
          <p className="text-base font-bold tracking-widest">{title}</p>
          {subtitle && <p className="text-slate-500">{subtitle}</p>}
        </div>
      </div>

      <div className="mt-3 grid grid-cols-2 border border-slate-400">
        <div className="border-r border-slate-400 p-2">
          <p className="text-[9px] uppercase tracking-wide text-slate-500">{party.label}</p>
          <p className="font-semibold">{party.name}</p>
          {party.lines?.filter(Boolean).map((line) => <p key={line}>{line}</p>)}
        </div>
        <dl className="grid grid-cols-[auto_1fr] content-start gap-x-3 gap-y-0.5 p-2">
          {meta.filter(([, value]) => value).map(([label, value]) => (
            <div key={label} className="contents">
              <dt className="text-slate-500">{label}</dt>
              <dd className="text-right font-medium">{value}</dd>
            </div>
          ))}
        </dl>
      </div>

      <table className="mt-3 w-full border-collapse">
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} className={`${cell} bg-slate-100 text-[10px] font-semibold uppercase ${align(c.align)}`}>{c.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={i}>
              {columns.map((c) => (
                <td key={c.key} className={`${cell} ${align(c.align)}`}>{row[c.key]}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>

      {totals.length > 0 && (
        <dl className="ml-auto mt-3 w-64 border border-slate-400 p-2">
          {totals.map(([label, value], i) => (
            <div key={label} className={`flex justify-between ${i === totals.length - 1 ? 'mt-1 border-t border-slate-400 pt-1 text-sm font-bold' : ''}`}>
              <dt>{label}</dt>
              <dd className="tabular-nums">{value}</dd>
            </div>
          ))}
        </dl>
      )}

      {notes && (
        <div className="mt-3">
          <p className="text-[9px] uppercase tracking-wide text-slate-500">Notes</p>
          <p className="whitespace-pre-line">{notes}</p>
        </div>
      )}
      {footer && <p className="mt-3 text-slate-500">{footer}</p>}

      {signatures.length > 0 && (
        <div className="mt-12 flex justify-between gap-6">
          {signatures.map((s) => (
            <p key={s} className="min-w-40 border-t border-slate-400 pt-1 text-center">{s}</p>
          ))}
        </div>
      )}
    </div>
  )
}
