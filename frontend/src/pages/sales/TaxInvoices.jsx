import { useEffect, useState } from 'react'
import { FileText, Search } from 'lucide-react'
import { getInvoices } from '../../api/documents'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { Alert, Badge, Card, EmptyState, Field, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import Pager from '../../components/documents/Pager'
import TaxInvoiceModal from '../../components/documents/TaxInvoiceModal'
import { formatDate, localDate } from '../../components/documents/printing'
import { PAYMENT_MODES, paymentLabel } from '../../components/receipt/paymentModes'
import { formatINR } from '../../lib/money'

const today = () => localDate()
const monthStart = () => today().slice(0, 8) + '01'

/** Every bill as a GST invoice: filter, open the A4 invoice, print or download it. */
export default function TaxInvoices() {
  const { isAllStores } = useAuth()
  const [filters, setFilters] = useState({ from: monthStart(), to: today(), search: '', kind: '', payment_mode: '' })
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [openId, setOpenId] = useState(null)

  // Typing in the search box waits a moment before asking the server.
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((f) => (f.search === search ? f : { ...f, search }))
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    let cancelled = false
    getInvoices({ ...filters, page })
      .then((res) => {
        if (cancelled) return
        setResult(res)
        setError(null)
      })
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [filters, page])

  const setFilter = (key, value) => {
    setFilters((f) => ({ ...f, [key]: value }))
    setPage(1)
  }

  const summary = result?.summary
  const rows = result?.data ?? []

  return (
    <>
      <PageHeader title="Tax Invoices" description="GST invoices for every bill. Open one to print the A4 invoice or download the PDF." />

      {summary && (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatTile label="Invoices" value={summary.invoices} icon={FileText} />
          <StatTile label="Taxable value" value={formatINR(summary.taxable)} />
          <StatTile
            label="GST"
            value={formatINR(summary.tax)}
            sub={`CGST ${formatINR(summary.cgst)} · SGST ${formatINR(summary.sgst)} · IGST ${formatINR(summary.igst)}`}
            tone="amber"
          />
          <StatTile label="Invoice value" value={formatINR(summary.total)} tone="green" />
        </div>
      )}

      <Card padded={false}>
        <div className="grid gap-3 border-b border-slate-100 p-5 sm:grid-cols-2 lg:grid-cols-5">
          <Field label="From">
            <input type="date" className={inputClass} value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
          </Field>
          <Field label="To">
            <input type="date" className={inputClass} value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
          </Field>
          <Field label="Type">
            <select className={inputClass} value={filters.kind} onChange={(e) => setFilter('kind', e.target.value)}>
              <option value="">All invoices</option>
              <option value="b2b">B2B (with GSTIN)</option>
              <option value="b2c">B2C (consumers)</option>
            </select>
          </Field>
          <Field label="Payment">
            <select className={inputClass} value={filters.payment_mode} onChange={(e) => setFilter('payment_mode', e.target.value)}>
              <option value="">Any</option>
              {PAYMENT_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </Field>
          <Field label="Search">
            <div className="relative">
              <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                className={`${inputClass} pl-8`}
                placeholder="Invoice no, customer, GSTIN"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </Field>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={FileText} title="No invoices match these filters" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Invoice</th>
                  <th className="px-5 py-3">Date</th>
                  <th className="px-5 py-3">Customer</th>
                  {isAllStores && <th className="px-5 py-3">Store</th>}
                  <th className="px-5 py-3">Payment</th>
                  <th className="px-5 py-3 text-right">Taxable</th>
                  <th className="px-5 py-3 text-right">GST</th>
                  <th className="px-5 py-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((inv) => (
                  <tr key={inv.id} className="cursor-pointer hover:bg-slate-50/60" onClick={() => setOpenId(inv.id)}>
                    <td className="px-5 py-3">
                      <button type="button" className="font-medium text-brand-700 hover:underline">{inv.invoice_number ?? inv.order_number}</button>
                      <div className="mt-0.5 flex gap-1">
                        <Badge tone={inv.kind === 'b2b' ? 'brand' : 'slate'}>{inv.kind.toUpperCase()}</Badge>
                        {inv.is_interstate && <Badge tone="amber">IGST</Badge>}
                      </div>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{formatDate(inv.date, true)}</td>
                    <td className="px-5 py-3">
                      <p className="text-slate-800">{inv.customer_name}</p>
                      {inv.customer_gstin && <p className="text-xs text-slate-500">GSTIN {inv.customer_gstin}</p>}
                    </td>
                    {isAllStores && <td className="px-5 py-3 text-slate-600">{inv.store?.name}</td>}
                    <td className="px-5 py-3 text-slate-600">{paymentLabel(inv.payment_mode)}</td>
                    <td className="px-5 py-3 text-right tabular-nums">{formatINR(inv.subtotal)}</td>
                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{formatINR(inv.tax_total)}</td>
                    <td className="px-5 py-3 text-right font-semibold tabular-nums">{formatINR(inv.grand_total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={setPage} />
      </Card>

      {openId && <TaxInvoiceModal orderId={openId} onClose={() => setOpenId(null)} />}
    </>
  )
}
