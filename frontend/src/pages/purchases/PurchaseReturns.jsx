import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, CornerUpLeft, Eye, Plus, Printer, Search } from 'lucide-react'
import {
  createPurchaseReturn,
  getPurchaseReturn,
  getPurchaseReturns,
  getPurchasingProducts,
  getReturnablePurchases,
  getSuppliers,
} from '../../api/purchasing'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import { formatINR } from '../../lib/money'
import ProductLines from '../../components/purchasing/ProductLines'
import { Pager, PeriodFilter, PrintableDocument, SelectStoreAlert, TotalRow } from '../../components/purchasing/shared'
import usePrintDocument from '../../components/purchasing/usePrintDocument'
import { emptyLine, formatDate, periodRange, sumLines, today } from '../../components/purchasing/helpers'

const REFUND_LABELS = { credit: 'Adjust against dues', cash: 'Cash refund', bank: 'Bank refund' }

export default function PurchaseReturns() {
  const { isAllStores, currentStore } = useAuth()
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
    getPurchaseReturns({ ...periodRange(period.preset, period), search: query, page })
      .then((res) => {
        setResult(res)
        setError(null)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [period, query, page])

  useEffect(load, [load])

  if (creating) {
    return (
      <NewPurchaseReturn
        store={currentStore}
        onCancel={() => setCreating(false)}
        onSaved={(r) => {
          setCreating(false)
          toast(`Debit note ${r.number} saved.`)
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
        title="Purchase returns"
        description="Goods sent back to suppliers (debit notes). The stock leaves the store and the amount is adjusted against what you owe, or refunded."
        actions={<Button icon={Plus} onClick={() => setCreating(true)} disabled={isAllStores}>New return</Button>}
      />
      {isAllStores && <SelectStoreAlert what="purchase returns" />}

      <Card padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <PeriodFilter value={period} onChange={(v) => { setPage(1); setPeriod(v) }} />
          {result?.meta?.totals && (
            <span className="text-sm text-slate-500">Total <b className="tabular-nums text-slate-800">{formatINR(result.meta.totals.grand_total)}</b></span>
          )}
          <div className="relative ml-auto w-full sm:w-64">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-9`} placeholder="Number or supplier" value={search} onChange={(e) => { setPage(1); setSearch(e.target.value) }} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={CornerUpLeft} title="No purchase returns in this period" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Debit note</th>
                  <th className="px-5 py-3">Supplier</th>
                  <th className="px-5 py-3">Against</th>
                  <th className="px-5 py-3">Settlement</th>
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
                    <td className="px-5 py-3 text-slate-700">{r.supplier?.name}</td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-500">{r.purchase?.number ?? '-'}</td>
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

      {viewing && <PurchaseReturnModal id={viewing} onClose={() => setViewing(null)} />}
    </>
  )
}

function NewPurchaseReturn({ store, onCancel, onSaved }) {
  const [suppliers, setSuppliers] = useState([])
  const [products, setProducts] = useState(null)
  const [purchases, setPurchases] = useState(null)
  const [form, setForm] = useState({ supplier_id: '', purchase_id: '', date: today(), refund_mode: 'credit', reason: '' })
  // Linked to a purchase: quantity per purchase line. Otherwise free product lines.
  const [quantities, setQuantities] = useState({})
  const [lines, setLines] = useState([emptyLine()])
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const fieldError = (key) => error?.errors?.[key]?.[0]

  useEffect(() => {
    getSuppliers().then((res) => setSuppliers(res.data)).catch(() => {})
    getPurchasingProducts().then(setProducts).catch((e) => setError(parseApiError(e)))
  }, [])

  useEffect(() => {
    if (!form.supplier_id) return
    let cancelled = false
    getReturnablePurchases(form.supplier_id).then((rows) => !cancelled && setPurchases(rows)).catch(() => !cancelled && setPurchases([]))
    return () => {
      cancelled = true
    }
  }, [form.supplier_id])

  const supplier = suppliers.find((s) => String(s.id) === String(form.supplier_id))
  const purchase = purchases?.find((p) => String(p.id) === String(form.purchase_id))
  const interstate = purchase
    ? purchase.is_interstate
    : Boolean(supplier?.state_code && store?.state_code && supplier.state_code !== store.state_code)

  const chosen = purchase
    ? purchase.items
        .filter((item) => Number(quantities[item.id]) > 0)
        .map((item) => ({ product_id: item.product_id, quantity: Number(quantities[item.id]), unit: item.unit_cost, rate: item.tax_percent }))
    : lines.filter((l) => l.product_id)
  const totals = sumLines(chosen, interstate)

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const saved = await createPurchaseReturn({
        supplier_id: form.supplier_id || null,
        purchase_id: form.purchase_id || null,
        date: form.date || null,
        refund_mode: form.refund_mode,
        reason: form.reason.trim() || null,
        items: purchase
          ? chosen.map((l) => ({ product_id: l.product_id, quantity: l.quantity }))
          : chosen.map((l) => ({ product_id: l.product_id, quantity: Number(l.quantity), unit_cost: l.unit === '' ? null : l.unit, tax_percent: l.rate })),
      })
      onSaved(saved)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader
        title="New purchase return"
        description="Linked to a purchase, the cost and GST come from that purchase and the goods leave the store that received them."
        actions={<Button variant="secondary" icon={ArrowLeft} onClick={onCancel}>Back</Button>}
      />
      <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div className="space-y-6">
          <Card title="Supplier">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Supplier" error={fieldError('supplier_id')}>
                <select className={inputClass} value={form.supplier_id}
                  onChange={(e) => { setPurchases(null); setQuantities({}); setForm((f) => ({ ...f, supplier_id: e.target.value, purchase_id: '' })) }}>
                  <option value="">Choose a supplier</option>
                  {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </Field>
              <Field label="Against purchase" error={fieldError('purchase_id')} hint="Optional; recommended.">
                <select className={inputClass} value={form.purchase_id} disabled={!purchases}
                  onChange={(e) => { setQuantities({}); setForm((f) => ({ ...f, purchase_id: e.target.value })) }}>
                  <option value="">Not linked to a purchase</option>
                  {(purchases ?? []).map((p) => (
                    <option key={p.id} value={p.id}>{p.number} · {formatDate(p.date)} · {formatINR(p.grand_total)}</option>
                  ))}
                </select>
              </Field>
              <Field label="Return date" error={fieldError('date')}>
                <input type="date" className={inputClass} value={form.date} max={today()} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} />
              </Field>
              <Field label="Reason" error={fieldError('reason')}>
                <input className={inputClass} value={form.reason} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} placeholder="e.g. Damaged in transit" />
              </Field>
            </div>
          </Card>

          <Card title="Items to return">
            {purchase ? (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                  <thead>
                    <tr className="text-left text-xs uppercase text-slate-500">
                      <th className="py-2">Product</th>
                      <th className="py-2 text-right">Bought</th>
                      <th className="py-2 text-right">Returnable</th>
                      <th className="py-2 text-right">Cost</th>
                      <th className="w-28 py-2 text-right">Return qty</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {purchase.items.map((item) => {
                      const index = chosen.findIndex((l) => l.product_id === item.product_id)
                      const err = index >= 0 ? error?.errors?.[`items.${index}.quantity`]?.[0] : null
                      return (
                        <tr key={item.id}>
                          <td className="py-2">{item.product?.name}</td>
                          <td className="py-2 text-right tabular-nums">{item.quantity}</td>
                          <td className="py-2 text-right tabular-nums">{item.returnable}</td>
                          <td className="py-2 text-right tabular-nums">{formatINR(item.unit_cost)} <span className="text-xs text-slate-400">+{Number(item.tax_percent)}%</span></td>
                          <td className="py-2">
                            <input type="number" min="0" max={item.returnable} disabled={!item.returnable} className={`${inputClass} text-right`}
                              value={quantities[item.id] ?? ''} onChange={(e) => setQuantities((q) => ({ ...q, [item.id]: e.target.value }))} aria-label={`Return quantity of ${item.product?.name}`} />
                            {err && <p className="mt-0.5 text-xs text-red-600">{err}</p>}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            ) : !products ? (
              <div className="grid place-items-center py-8 text-slate-400"><Spinner /></div>
            ) : (
              <ProductLines products={products} lines={lines} onChange={setLines} interstate={interstate} errors={error?.errors} />
            )}
            {fieldError('items') && <p className="mt-2 text-xs text-red-600">{fieldError('items')}</p>}
          </Card>
        </div>

        <div className="space-y-6">
          <Card title="Debit note">
            <div className="space-y-1.5 text-sm">
              <TotalRow label="Taxable value" value={formatINR(totals.subtotal, { cents: true })} />
              {interstate ? (
                <TotalRow label="IGST" value={formatINR(totals.igst, { cents: true })} muted />
              ) : (
                <>
                  <TotalRow label="CGST" value={formatINR(totals.cgst, { cents: true })} muted />
                  <TotalRow label="SGST" value={formatINR(totals.sgst, { cents: true })} muted />
                </>
              )}
              <div className="border-t border-slate-200 pt-2"><TotalRow label="Total" value={formatINR(totals.total, { cents: true })} strong /></div>
            </div>
          </Card>
          <Card title="Settlement">
            <div className="space-y-2">
              {Object.entries(REFUND_LABELS).map(([mode, label]) => (
                <label key={mode} className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="radio" name="refund_mode" checked={form.refund_mode === mode} onChange={() => setForm((f) => ({ ...f, refund_mode: mode }))} />
                  {label}
                </label>
              ))}
              <p className="text-xs text-slate-500">"Adjust against dues" reduces what you owe the supplier.</p>
            </div>
          </Card>
          {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
          {error && Object.keys(error.errors).length > 0 && <Alert>Please fix the highlighted fields.</Alert>}
          <Button type="submit" variant="success" size="lg" className="w-full" loading={saving} disabled={!chosen.length || !form.supplier_id}>
            Save return
          </Button>
        </div>
      </form>
    </>
  )
}

function PurchaseReturnModal({ id, onClose }) {
  const [doc, setDoc] = useState(null)
  const [error, setError] = useState(null)
  const { contentRef, print } = usePrintDocument(doc ? `Debit note ${doc.number}` : 'Debit note')

  useEffect(() => {
    let cancelled = false
    getPurchaseReturn(id)
      .then((r) => !cancelled && setDoc(r))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [id])

  return (
    <Modal open size="xl" title={doc ? `Debit note ${doc.number}` : 'Debit note'} onClose={onClose}
      footer={doc && <Button variant="secondary" icon={Printer} onClick={print}>Print</Button>}>
      {error && <Alert>{error}</Alert>}
      {!doc && !error && <div className="grid place-items-center py-10 text-slate-400"><Spinner /></div>}
      {doc && (
        <>
          <div className="grid gap-2 text-sm sm:grid-cols-2">
            <div>
              <p className="text-xs uppercase text-slate-500">Supplier</p>
              <p className="font-medium text-slate-800">{doc.supplier?.name}</p>
              {doc.purchase && <p className="text-xs text-slate-500">Against {doc.purchase.number}</p>}
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
            <TotalRow label="Total" value={formatINR(doc.grand_total)} strong />
          </div>
          <div className="hidden">
            <PrintableDocument
              ref={contentRef}
              title="Debit note"
              number={doc.number}
              date={doc.date}
              store={doc.store}
              partyLabel="Returned to"
              party={doc.supplier}
              meta={[['Against purchase', doc.purchase?.number], ['Settlement', REFUND_LABELS[doc.refund_mode]], ['Reason', doc.reason]]}
              lines={doc.items.map((item) => ({
                key: item.id, name: item.product?.name, code: item.product?.code, hsn: item.product?.hsn_code,
                quantity: item.quantity, rate: item.unit_cost, taxPercent: item.tax_percent,
                taxable: item.line_subtotal, tax: item.line_tax, total: item.line_total,
              }))}
              totals={[
                ['Taxable value', doc.subtotal],
                ...(doc.is_interstate ? [['IGST', doc.igst_amount]] : [['CGST', doc.cgst_amount], ['SGST', doc.sgst_amount]]),
                ['Total', doc.grand_total],
              ]}
            />
          </div>
        </>
      )}
    </Modal>
  )
}
