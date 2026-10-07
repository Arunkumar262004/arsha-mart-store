import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, Eye, Plus, Printer, Search, Undo2 } from 'lucide-react'
import { createSalesReturn, getSalesReturn, getSalesReturns, lookupBillForReturn } from '../../api/purchasing'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import { formatINR, taxOn, toCents } from '../../lib/money'
import { Pager, PeriodFilter, PrintableDocument, SelectStoreAlert, TotalRow } from '../../components/purchasing/shared'
import usePrintDocument from '../../components/purchasing/usePrintDocument'
import { formatDate, periodRange, today } from '../../components/purchasing/helpers'

const REFUND_LABELS = { cash: 'Cash refund', bank: 'Bank / UPI refund', credit: 'Store credit / reduce dues' }

export default function SalesReturns() {
  const { isAllStores } = useAuth()
  const toast = useToast()
  const [creating, setCreating] = useState(false)
  const [viewing, setViewing] = useState(null)

  const [period, setPeriod] = useState({ preset: 'this_month', from: '', to: '' })
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    const t = setTimeout(() => setQuery(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(() => {
    getSalesReturns({ ...periodRange(period.preset, period), search: query, page })
      .then((res) => {
        setResult(res)
        setError(null)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [period, query, page])

  useEffect(load, [load])

  if (creating) {
    return (
      <NewSalesReturn
        onCancel={() => setCreating(false)}
        onSaved={(r) => {
          setCreating(false)
          toast(`Credit note ${r.number} saved. Stock is back on the shelf.`)
          setViewing(r.id)
          load()
        }}
      />
    )
  }

  const rows = result?.data ?? []

  return (
    <>
      <PageHeader
        title="Sales returns"
        description="Goods customers bring back against a bill (credit notes). Prices and GST come from the original bill."
        actions={<Button icon={Plus} onClick={() => setCreating(true)} disabled={isAllStores}>New return</Button>}
      />
      {isAllStores && <SelectStoreAlert what="sales returns" />}

      <Card padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <PeriodFilter value={period} onChange={(v) => { setPage(1); setPeriod(v) }} />
          {result?.meta?.totals && (
            <span className="text-sm text-slate-500">Total <b className="tabular-nums text-slate-800">{formatINR(result.meta.totals.grand_total)}</b></span>
          )}
          <div className="relative ml-auto w-full sm:w-64">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-9`} placeholder="Number, invoice, customer" value={search} onChange={(e) => { setPage(1); setSearch(e.target.value) }} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={Undo2} title="No sales returns in this period" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Credit note</th>
                  <th className="px-5 py-3">Bill</th>
                  <th className="px-5 py-3">Customer</th>
                  <th className="px-5 py-3">Refund</th>
                  <th className="px-5 py-3 text-right">Amount</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <p className="font-mono text-xs font-medium text-slate-800">{r.number}</p>
                      <p className="text-xs text-slate-500">{formatDate(r.date)}{isAllStores && r.store && ` · ${r.store.code}`}</p>
                    </td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-600">{r.order?.invoice_number}</td>
                    <td className="px-5 py-3 text-slate-700">{r.customer?.name ?? '-'}</td>
                    <td className="px-5 py-3"><Badge tone={r.refund_mode === 'credit' ? 'amber' : 'green'}>{REFUND_LABELS[r.refund_mode]}</Badge></td>
                    <td className="px-5 py-3 text-right font-medium tabular-nums">{formatINR(r.grand_total)}</td>
                    <td className="px-5 py-3 text-right">
                      <Button variant="ghost" size="sm" icon={Eye} onClick={() => setViewing(r.id)} aria-label={`View ${r.number}`} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={setPage} />
      </Card>

      {viewing && <SalesReturnModal id={viewing} onClose={() => setViewing(null)} />}
    </>
  )
}

/** Preview of a line's refund, prorating the bill's own GST split. */
function lineRefund(item, quantity) {
  const subtotal = toCents(item.unit_price) * quantity
  const tax = taxOn(subtotal, item.cgst_percent) + taxOn(subtotal, item.sgst_percent) + taxOn(subtotal, item.igst_percent)
  return { subtotal, tax }
}

function NewSalesReturn({ onCancel, onSaved }) {
  const [number, setNumber] = useState('')
  const [bill, setBill] = useState(null)
  const [finding, setFinding] = useState(false)
  const [quantities, setQuantities] = useState({})
  const [form, setForm] = useState({ refund_mode: 'cash', reason: '', date: today() })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  async function find(e) {
    e.preventDefault()
    if (!number.trim()) return
    setFinding(true)
    setError(null)
    setBill(null)
    setQuantities({})
    try {
      const found = await lookupBillForReturn(number.trim())
      setBill(found)
      // A bill sold on credit is usually settled against the customer's dues.
      setForm((f) => ({ ...f, refund_mode: found.payment_mode === 'credit' ? 'credit' : found.payment_mode === 'cash' ? 'cash' : 'bank' }))
    } catch (err) {
      setError(parseApiError(err))
    } finally {
      setFinding(false)
    }
  }

  const chosen = (bill?.items ?? [])
    .map((item, index) => ({ item, index, quantity: Number(quantities[item.id]) || 0 }))
    .filter((l) => l.quantity > 0)
  const totals = chosen.reduce(
    (t, l) => {
      const r = lineRefund(l.item, l.quantity)
      return { subtotal: t.subtotal + r.subtotal, tax: t.tax + r.tax }
    },
    { subtotal: 0, tax: 0 },
  )

  async function submit() {
    setSaving(true)
    setError(null)
    try {
      const saved = await createSalesReturn({
        order_id: bill.id,
        refund_mode: form.refund_mode,
        reason: form.reason.trim() || null,
        date: form.date || null,
        items: chosen.map((l) => ({ order_item_id: l.item.id, quantity: l.quantity })),
      })
      onSaved(saved)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  // Server errors are indexed by the submitted lines; map them back to bill lines.
  const lineError = (itemId) => {
    const i = chosen.findIndex((l) => l.item.id === itemId)
    return i >= 0 ? error?.errors?.[`items.${i}.quantity`]?.[0] : null
  }

  return (
    <>
      <PageHeader
        title="New sales return"
        description="Find the bill, enter what came back, choose how to refund."
        actions={<Button variant="secondary" icon={ArrowLeft} onClick={onCancel}>Back</Button>}
      />

      <Card className="mb-6">
        <form onSubmit={find} className="flex flex-wrap items-end gap-3">
          <Field label="Invoice or order number" className="w-full sm:w-96">
            <input className={`${inputClass} font-mono uppercase`} value={number} onChange={(e) => setNumber(e.target.value)} placeholder="MAIN/INV/26-27/00012" autoFocus />
          </Field>
          <Button type="submit" icon={Search} loading={finding}>Find bill</Button>
        </form>
        {error && !bill && <div className="mt-3"><Alert>{error.message}</Alert></div>}
      </Card>

      {bill && (
        <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
          <Card title={`Bill ${bill.invoice_number}`}>
            <p className="mb-3 text-sm text-slate-600">
              {bill.customer?.name} · {formatDate(bill.created_at)} · {bill.store?.name} · paid by {bill.payment_mode}
              {bill.is_interstate && ' · IGST'}
            </p>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[600px] text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase text-slate-500">
                    <th className="py-2">Product</th>
                    <th className="py-2 text-right">Price</th>
                    <th className="py-2 text-right">Sold</th>
                    <th className="py-2 text-right">Returned</th>
                    <th className="w-28 py-2 text-right">Return now</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {bill.items.map((item) => (
                    <tr key={item.id}>
                      <td className="py-2">{item.product?.name}</td>
                      <td className="py-2 text-right tabular-nums">{formatINR(item.unit_price)} <span className="text-xs text-slate-400">+{Number(item.tax_percent)}%</span></td>
                      <td className="py-2 text-right tabular-nums">{item.quantity}</td>
                      <td className="py-2 text-right tabular-nums text-slate-500">{item.returned}</td>
                      <td className="py-2">
                        <input type="number" min="0" max={item.returnable} disabled={!item.returnable} className={`${inputClass} text-right`}
                          value={quantities[item.id] ?? ''} onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))}
                          aria-label={`Return quantity of ${item.product?.name}`} />
                        {lineError(item.id) && <p className="mt-0.5 text-xs text-red-600">{lineError(item.id)}</p>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="Reason" error={error?.errors?.reason?.[0]}>
                <input className={inputClass} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} placeholder="e.g. Wrong size" />
              </Field>
              <Field label="Return date" error={error?.errors?.date?.[0]}>
                <input type="date" className={inputClass} value={form.date} max={today()} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} />
              </Field>
            </div>
          </Card>

          <div className="space-y-6">
            <Card title="Credit note">
              <div className="space-y-1.5 text-sm">
                <TotalRow label="Taxable value" value={formatINR(totals.subtotal, { cents: true })} />
                <TotalRow label="GST reversed" value={formatINR(totals.tax, { cents: true })} muted />
                <div className="border-t border-slate-200 pt-2"><TotalRow label="Refund" value={formatINR(totals.subtotal + totals.tax, { cents: true })} strong /></div>
              </div>
            </Card>
            <Card title="Refund by">
              <div className="space-y-2">
                {Object.entries(REFUND_LABELS).map(([mode, label]) => (
                  <label key={mode} className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="radio" name="refund_mode" checked={form.refund_mode === mode} onChange={() => setForm((f) => ({ ...f, refund_mode: mode }))} />
                    {label}
                  </label>
                ))}
                <p className="text-xs text-slate-500">Store credit is kept on {bill.customer?.name ?? 'the customer'}'s account and reduces what they owe.</p>
              </div>
            </Card>
            {error && bill && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
            {error && bill && Object.keys(error.errors).length > 0 && <Alert>Please fix the highlighted quantities.</Alert>}
            <Button variant="success" size="lg" className="w-full" loading={saving} disabled={!chosen.length} onClick={submit}>
              Save return
            </Button>
          </div>
        </div>
      )}
    </>
  )
}

function SalesReturnModal({ id, onClose }) {
  const [doc, setDoc] = useState(null)
  const [error, setError] = useState(null)
  const { contentRef, print } = usePrintDocument(doc ? `Credit note ${doc.number}` : 'Credit note')

  useEffect(() => {
    let cancelled = false
    getSalesReturn(id)
      .then((r) => !cancelled && setDoc(r))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [id])

  const igst = doc && Number(doc.igst_amount) > 0

  return (
    <Modal open size="xl" title={doc ? `Credit note ${doc.number}` : 'Credit note'} onClose={onClose}
      footer={doc && <Button variant="secondary" icon={Printer} onClick={print}>Print</Button>}>
      {error && <Alert>{error}</Alert>}
      {!doc && !error && <div className="grid place-items-center py-10 text-slate-400"><Spinner /></div>}
      {doc && (
        <>
          <div className="grid gap-2 text-sm sm:grid-cols-2">
            <div>
              <p className="text-xs uppercase text-slate-500">Customer</p>
              <p className="font-medium text-slate-800">{doc.customer?.name ?? '-'}</p>
              <p className="text-xs text-slate-500">Against bill {doc.order?.invoice_number}</p>
            </div>
            <div className="sm:text-right">
              <p>{formatDate(doc.date)} · {doc.store?.name}</p>
              <p className="text-slate-500">{REFUND_LABELS[doc.refund_mode]}</p>
              {doc.reason && <p className="text-xs text-slate-500">{doc.reason}</p>}
            </div>
          </div>
          <ul className="mt-4 divide-y divide-slate-100 text-sm">
            {doc.items.map((item) => (
              <li key={item.id} className="flex justify-between py-2">
                <span>{item.product?.name} × {item.quantity}</span>
                <span className="tabular-nums">{formatINR(item.line_total)}</span>
              </li>
            ))}
          </ul>
          <div className="mt-3 ml-auto max-w-xs space-y-1 text-sm">
            <TotalRow label="Taxable value" value={formatINR(doc.subtotal)} />
            <TotalRow label="GST" value={formatINR(doc.tax_total)} muted />
            <TotalRow label="Refund" value={formatINR(doc.grand_total)} strong />
          </div>
          <div className="hidden">
            <PrintableDocument
              ref={contentRef}
              title="Credit note"
              number={doc.number}
              date={doc.date}
              store={doc.store}
              partyLabel="Customer"
              party={doc.customer}
              meta={[['Against invoice', doc.order?.invoice_number], ['Refund', REFUND_LABELS[doc.refund_mode]], ['Reason', doc.reason]]}
              lines={doc.items.map((item) => ({
                key: item.id, name: item.product?.name, code: item.product?.code, hsn: item.product?.hsn_code,
                quantity: item.quantity, rate: item.unit_price, taxPercent: item.tax_percent,
                taxable: item.line_subtotal, tax: item.line_tax, total: item.line_total,
              }))}
              totals={[
                ['Taxable value', doc.subtotal],
                ...(igst ? [['IGST', doc.igst_amount]] : [['CGST', doc.cgst_amount], ['SGST', doc.sgst_amount]]),
                ['Total refund', doc.grand_total],
              ]}
            />
          </div>
        </>
      )}
    </Modal>
  )
}
