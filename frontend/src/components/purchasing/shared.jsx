// Building blocks shared by the purchasing, returns and money pages.
import { forwardRef } from 'react'
import { Button, inputClass } from '../ui'
import StoreRequired from '../StoreRequired'
import { useBranding } from '../../branding/BrandingContext'
import { formatINR } from '../../lib/money'
import { PERIODS, formatDate } from './helpers'

/** Period preset picker with custom from / to dates. value = { preset, from, to }. */
export function PeriodFilter({ value, onChange }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <select
        className={`${inputClass} w-auto`}
        value={value.preset}
        onChange={(e) => onChange({ ...value, preset: e.target.value })}
        aria-label="Period"
      >
        {PERIODS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
      </select>
      {value.preset === 'custom' && (
        <>
          <input type="date" className={`${inputClass} w-auto`} value={value.from} onChange={(e) => onChange({ ...value, from: e.target.value })} aria-label="From" />
          <span className="text-sm text-slate-400">to</span>
          <input type="date" className={`${inputClass} w-auto`} value={value.to} onChange={(e) => onChange({ ...value, to: e.target.value })} aria-label="To" />
        </>
      )}
    </div>
  )
}

/** Prev / next for a Laravel paginator's meta. */
export function Pager({ meta, onPage }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <div className="flex items-center justify-center gap-3 border-t border-slate-100 p-4 text-sm">
      <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Prev</Button>
      <span className="text-slate-600">Page {meta.current_page} of {meta.last_page}</span>
      <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</Button>
    </div>
  )
}

/** Shown on pages that create documents while "All stores" is selected (shared StoreRequired notice). */
export function SelectStoreAlert({ what }) {
  return (
    <div className="mb-4">
      <StoreRequired what={what} />
    </div>
  )
}

/** Label / value row for totals boxes. */
export function TotalRow({ label, value, strong = false, muted = false }) {
  return (
    <div className={`flex items-center justify-between gap-4 ${strong ? 'text-base font-semibold text-slate-900' : muted ? 'text-slate-500' : 'text-slate-700'}`}>
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  )
}

/**
 * An A4 document (purchase, debit note, credit note) for printing. Rendered
 * hidden on screen and printed through usePrintDocument.
 *
 * lines: [{ key, name, code, hsn, quantity, rate, taxPercent, taxable, tax, total }]
 * totals: [[label, amount]]; the last row is shown bold.
 */
export const PrintableDocument = forwardRef(function PrintableDocument(
  { title, number, date, store, partyLabel, party, meta = [], lines, totals, notes, cancelled = false },
  ref,
) {
  const { logo } = useBranding()
  // Who issues the document: company legal details, or the store's own (set by the API).
  const seller = store?.seller ?? store
  return (
    <div ref={ref} className="bg-white p-2 text-[12px] leading-snug text-black">
      <div className="flex items-start justify-between border-b border-black pb-3">
        <div className="flex items-start gap-3">
          <img src={logo} alt="" className="h-14 w-14 object-contain" />
          <div>
            <p className="text-lg font-bold">{seller?.name}</p>
            {seller?.branch && <p>Branch: {seller.branch}</p>}
            {seller?.address && <p>{[seller.address, seller.city, seller.state, seller.pincode].filter(Boolean).join(', ')}</p>}
            {seller?.gstin && <p>GSTIN: {seller.gstin}{seller.pan && ` · PAN: ${seller.pan}`}</p>}
            {seller?.phone && <p>Phone: {seller.phone}</p>}
          </div>
        </div>
        <div className="text-right">
          <p className="text-lg font-bold uppercase">{title}</p>
          {cancelled && <p className="font-bold text-red-700">CANCELLED</p>}
          <p>No: <b>{number}</b></p>
          <p>Date: {formatDate(date)}</p>
        </div>
      </div>

      <div className="mt-3 flex justify-between gap-6">
        <div>
          <p className="text-[11px] uppercase text-gray-600">{partyLabel}</p>
          <p className="font-semibold">{party?.name}</p>
          {party?.address && <p>{party.address}</p>}
          {party?.gstin && <p>GSTIN: {party.gstin}</p>}
          {party?.phone && <p>Phone: {party.phone}</p>}
        </div>
        <div className="text-right">
          {meta.filter(([, v]) => v).map(([label, value]) => (
            <p key={label}>{label}: {value}</p>
          ))}
        </div>
      </div>

      <table className="mt-4 w-full border-collapse">
        <thead>
          <tr className="border-y border-black text-left">
            <th className="py-1 pr-2">#</th>
            <th className="py-1 pr-2">Item</th>
            <th className="py-1 pr-2">HSN</th>
            <th className="py-1 pr-2 text-right">Qty</th>
            <th className="py-1 pr-2 text-right">Rate</th>
            <th className="py-1 pr-2 text-right">Taxable</th>
            <th className="py-1 pr-2 text-right">GST</th>
            <th className="py-1 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          {lines.map((line, i) => (
            <tr key={line.key} className="border-b border-gray-300">
              <td className="py-1 pr-2">{i + 1}</td>
              <td className="py-1 pr-2">{line.name}{line.code && <span className="text-gray-500"> ({line.code})</span>}</td>
              <td className="py-1 pr-2">{line.hsn || '-'}</td>
              <td className="py-1 pr-2 text-right">{line.quantity}</td>
              <td className="py-1 pr-2 text-right">{formatINR(line.rate)}</td>
              <td className="py-1 pr-2 text-right">{formatINR(line.taxable)}</td>
              <td className="py-1 pr-2 text-right">{formatINR(line.tax)} <span className="text-gray-500">({Number(line.taxPercent)}%)</span></td>
              <td className="py-1 text-right">{formatINR(line.total)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="mt-3 ml-auto w-64 space-y-0.5">
        {totals.map(([label, amount], i) => (
          <div key={label} className={`flex justify-between ${i === totals.length - 1 ? 'border-t border-black pt-1 text-sm font-bold' : ''}`}>
            <span>{label}</span>
            <span>{formatINR(amount)}</span>
          </div>
        ))}
      </div>

      {notes && <p className="mt-4"><b>Notes:</b> {notes}</p>}
      <div className="mt-12 flex justify-between text-[11px] text-gray-600">
        <span>Receiver's signature</span>
        <span>For {seller?.name}</span>
      </div>
    </div>
  )
})
