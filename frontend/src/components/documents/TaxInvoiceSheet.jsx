import { useBranding } from '../../branding/BrandingContext'
import { formatDate, money, rate, storeAddress } from './printing'

const th = 'border border-slate-400 bg-slate-100 px-1.5 py-1 text-[10px] font-semibold uppercase'
const td = 'border-x border-slate-400 px-1.5 py-1 align-top'

/**
 * A4 GST tax invoice (or bill of supply) from GET /api/invoices/{id}. Same
 * content as the server PDF; attach `ref` to print it with react-to-print.
 */
export default function TaxInvoiceSheet({ invoice, ref }) {
  const { logo } = useBranding()
  const { store, customer, totals } = invoice
  const igst = invoice.is_interstate
  const exempt = invoice.title === 'BILL OF SUPPLY'
  const taxCols = exempt ? 0 : igst ? 1 : 2

  return (
    <div ref={ref} className="w-full bg-white p-6 text-[11px] leading-snug text-slate-900 print:p-0">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <img src={logo} alt="" className="h-14 w-14 object-contain" />
          <div>
          <p className="text-lg font-bold">{store.name}</p>
          {store.branch && <p className="text-slate-500">Branch: {store.branch}</p>}
          {storeAddress(store) && <p>{storeAddress(store)}</p>}
          {store.phone && <p>Ph: {store.phone}{store.email && ` · ${store.email}`}</p>}
          {store.gstin && <p className="font-semibold">GSTIN: {store.gstin}{store.pan && ` · PAN: ${store.pan}`}</p>}
          {store.state && <p>State: {store.state}{store.state_code && ` (Code ${store.state_code})`}</p>}
          </div>
        </div>
        <div className="text-right">
          <p className="text-base font-bold tracking-widest">{invoice.title}</p>
          <p className="text-slate-500">{invoice.copy}</p>
        </div>
      </div>

      <div className="mt-3 grid grid-cols-2 border border-slate-400">
        <div className="border-r border-slate-400 p-2">
          <p className="text-[9px] uppercase tracking-wide text-slate-500">Billed to</p>
          <p className="font-semibold">{customer.name}</p>
          {customer.billing_address && <p>{customer.billing_address}</p>}
          {customer.phone && <p>Ph: {customer.phone}</p>}
          {customer.email && <p>{customer.email}</p>}
          {customer.gstin ? <p className="font-semibold">GSTIN: {customer.gstin}</p> : <p className="text-slate-500">Unregistered (B2C)</p>}
        </div>
        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 p-2">
          <dt className="text-slate-500">Invoice no</dt>
          <dd className="text-right font-semibold">{invoice.invoice_number}</dd>
          <dt className="text-slate-500">Date</dt>
          <dd className="text-right">{formatDate(invoice.date, true)}</dd>
          <dt className="text-slate-500">Place of supply</dt>
          <dd className="text-right">
            {invoice.place_of_supply ? `${invoice.place_of_supply.state ?? ''} (${invoice.place_of_supply.code})` : '-'}
          </dd>
          <dt className="text-slate-500">Supply</dt>
          <dd className="text-right">{igst ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'}</dd>
          <dt className="text-slate-500">Payment</dt>
          <dd className="text-right uppercase">{invoice.payment_mode}</dd>
        </dl>
      </div>

      <table className="mt-3 w-full border-collapse">
        <thead>
          <tr>
            <th className={th}>#</th>
            <th className={`${th} text-left`}>Item</th>
            <th className={th}>HSN</th>
            <th className={`${th} text-right`}>Qty</th>
            <th className={`${th} text-right`}>Rate</th>
            <th className={`${th} text-right`}>Taxable</th>
            {taxCols === 1 && <th className={`${th} text-right`}>IGST</th>}
            {taxCols === 2 && (
              <>
                <th className={`${th} text-right`}>CGST</th>
                <th className={`${th} text-right`}>SGST</th>
              </>
            )}
            <th className={`${th} text-right`}>Amount</th>
          </tr>
        </thead>
        <tbody>
          {invoice.lines.map((line) => (
            <tr key={line.sr}>
              <td className={`${td} text-center`}>{line.sr}</td>
              <td className={td}>{line.name}</td>
              <td className={`${td} text-center`}>{line.hsn_code ?? '-'}</td>
              <td className={`${td} text-right tabular-nums`}>{line.quantity} {line.unit}</td>
              <td className={`${td} text-right tabular-nums`}>{money(line.rate)}</td>
              <td className={`${td} text-right tabular-nums`}>{money(line.taxable_value)}</td>
              {taxCols === 1 && <TaxCell amount={line.igst_amount} percent={line.igst_percent} />}
              {taxCols === 2 && (
                <>
                  <TaxCell amount={line.cgst_amount} percent={line.cgst_percent} />
                  <TaxCell amount={line.sgst_amount} percent={line.sgst_percent} />
                </>
              )}
              <td className={`${td} text-right tabular-nums`}>{money(line.total)}</td>
            </tr>
          ))}
        </tbody>
        <tfoot className="font-semibold">
          <tr className="bg-slate-50">
            <td colSpan={3} className="border border-slate-400 px-1.5 py-1 text-right">Total</td>
            <td className="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{totals.quantity}</td>
            <td className="border border-slate-400" />
            <td className="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{money(totals.taxable_value)}</td>
            {taxCols === 1 && <td className="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{money(totals.igst)}</td>}
            {taxCols === 2 && (
              <>
                <td className="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{money(totals.cgst)}</td>
                <td className="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{money(totals.sgst)}</td>
              </>
            )}
            <td className="border border-slate-400 px-1.5 py-1 text-right tabular-nums">{money(totals.grand_total)}</td>
          </tr>
        </tfoot>
      </table>

      <div className="mt-3 grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] gap-4">
        <div>
          {!exempt && (
            <>
              <p className="mb-1 text-[9px] uppercase tracking-wide text-slate-500">HSN-wise tax summary</p>
              <table className="w-full border-collapse text-[10px]">
                <thead>
                  <tr>
                    <th className={th}>HSN</th>
                    <th className={th}>Rate</th>
                    <th className={`${th} text-right`}>Taxable</th>
                    {igst ? (
                      <th className={`${th} text-right`}>IGST</th>
                    ) : (
                      <>
                        <th className={`${th} text-right`}>CGST</th>
                        <th className={`${th} text-right`}>SGST</th>
                      </>
                    )}
                    <th className={`${th} text-right`}>Total tax</th>
                  </tr>
                </thead>
                <tbody>
                  {invoice.hsn_summary.map((row) => (
                    <tr key={`${row.hsn_code}-${row.tax_percent}`}>
                      <td className="border border-slate-400 px-1.5 py-0.5">{row.hsn_code ?? '-'}</td>
                      <td className="border border-slate-400 px-1.5 py-0.5">{rate(row.tax_percent)}</td>
                      <td className="border border-slate-400 px-1.5 py-0.5 text-right tabular-nums">{money(row.taxable_value)}</td>
                      {igst ? (
                        <td className="border border-slate-400 px-1.5 py-0.5 text-right tabular-nums">{money(row.igst_amount)}</td>
                      ) : (
                        <>
                          <td className="border border-slate-400 px-1.5 py-0.5 text-right tabular-nums">{money(row.cgst_amount)}</td>
                          <td className="border border-slate-400 px-1.5 py-0.5 text-right tabular-nums">{money(row.sgst_amount)}</td>
                        </>
                      )}
                      <td className="border border-slate-400 px-1.5 py-0.5 text-right tabular-nums">{money(row.tax)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
          <p className="mt-2 text-[9px] uppercase tracking-wide text-slate-500">Amount in words</p>
          <p className="font-semibold">{invoice.amount_in_words}</p>
        </div>
        <dl className="self-start border border-slate-400 p-2">
          <Row label="Taxable value" value={totals.taxable_value} />
          {!exempt && (igst ? <Row label="IGST" value={totals.igst} /> : (
            <>
              <Row label="CGST" value={totals.cgst} />
              <Row label="SGST" value={totals.sgst} />
            </>
          ))}
          <div className="mt-1 flex justify-between border-t border-slate-400 pt-1 text-sm font-bold">
            <dt>Grand total</dt>
            <dd className="tabular-nums">₹ {money(totals.grand_total)}</dd>
          </div>
        </dl>
      </div>

      <div className="mt-8 flex items-end justify-between gap-4">
        <p className="text-slate-500">
          {exempt && 'Supply of goods exempt from GST. '}Goods once sold can't be exchanged or returned.
          <br />
          This is a computer-generated invoice.
        </p>
        <div className="text-right">
          <p className="font-semibold">For {store.name}</p>
          <div className="h-12" />
          <p>Authorised signatory</p>
        </div>
      </div>
    </div>
  )
}

function TaxCell({ amount, percent }) {
  return (
    <td className={`${td} text-right tabular-nums`}>
      {money(amount)}
      <span className="block text-[9px] text-slate-500">{rate(percent)}</span>
    </td>
  )
}

function Row({ label, value }) {
  return (
    <div className="flex justify-between">
      <dt>{label}</dt>
      <dd className="tabular-nums">{money(value)}</dd>
    </div>
  )
}
