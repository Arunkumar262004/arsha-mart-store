import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, Ban, Eye, PackageCheck, Plus, Printer, Search } from 'lucide-react'
import { cancelPurchase, createPurchase, getPurchase, getPurchases, getPurchasingProducts, getSuppliers } from '../../api/purchasing'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import { formatINR, toCents } from '../../lib/money'
import ProductLines from '../../components/purchasing/ProductLines'
import { Pager, PeriodFilter, PrintableDocument, SelectStoreAlert, TotalRow } from '../../components/purchasing/shared'
import usePrintDocument from '../../components/purchasing/usePrintDocument'
import { MODE_LABELS, emptyLine, formatDate, periodRange, sumLines, today } from '../../components/purchasing/helpers'
import { useConfirm } from '../../components/ConfirmDialog'

export default function Purchases() {
  const { isAllStores, currentStore } = useAuth()
  const toast = useToast()
  const [creating, setCreating] = useState(false)
  const [viewing, setViewing] = useState(null) // purchase id

  const [period, setPeriod] = useState({ preset: 'this_month', from: '', to: '' })
  const [supplierId, setSupplierId] = useState('')
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [suppliers, setSuppliers] = useState([])

  useEffect(() => {
    getSuppliers().then((res) => setSuppliers(res.data)).catch(() => {})
  }, [])

  useEffect(() => {
    const t = setTimeout(() => setQuery(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(() => {
    getPurchases({ ...periodRange(period.preset, period), supplier_id: supplierId, status, search: query, page })
      .then((res) => {
        setResult(res)
        setError(null)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [period, supplierId, status, query, page])

  useEffect(load, [load])

  // Back to the first page whenever a filter changes.
  const filter = (setter) => (value) => {
    setPage(1)
    setter(value)
  }

  if (creating) {
    return (
      <NewPurchase
        suppliers={suppliers.filter((s) => s.is_active)}
        store={currentStore}
        onCancel={() => setCreating(false)}
        onSaved={(p) => {
          setCreating(false)
          toast(`Purchase ${p.number} saved. Stock added.`)
          setViewing(p.id)
          load()
        }}
      />
    )
  }

  const rows = result?.data ?? []
  const totals = result?.meta?.totals

  return (
    <>
      <PageHeader
        title="Purchases"
        description={`Goods received from suppliers${isAllStores ? ' in all stores' : currentStore ? ` at ${currentStore.name}` : ''}. Saving a purchase adds the stock and books it in the accounts.`}
        actions={<Button icon={Plus} onClick={() => setCreating(true)} disabled={isAllStores}>New purchase</Button>}
      />
      {isAllStores && <SelectStoreAlert what="purchases" />}

      <div className="mb-6 grid gap-4 sm:grid-cols-2">
        <StatTile label="Purchases" value={totals ? totals.count : '-'} sub="Posted, in the selected period" icon={PackageCheck} />
        <StatTile label="Purchase value" value={totals ? formatINR(totals.grand_total) : '-'} sub={totals ? `incl. GST ${formatINR(totals.tax_total)}` : ''} tone="green" />
      </div>

      <Card padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <PeriodFilter value={period} onChange={filter(setPeriod)} />
          <select className={`${inputClass} w-auto`} value={supplierId} onChange={(e) => filter(setSupplierId)(e.target.value)} aria-label="Supplier">
            <option value="">All suppliers</option>
            {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
          <select className={`${inputClass} w-auto`} value={status} onChange={(e) => filter(setStatus)(e.target.value)} aria-label="Status">
            <option value="">Any status</option>
            <option value="posted">Posted</option>
            <option value="cancelled">Cancelled</option>
          </select>
          <div className="relative ml-auto w-full sm:w-64">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-9`} placeholder="Number, supplier bill, supplier" value={search}
              onChange={(e) => { setPage(1); setSearch(e.target.value) }} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={PackageCheck} title="No purchases in this period" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Purchase</th>
                  <th className="px-5 py-3">Supplier</th>
                  <th className="px-5 py-3">Supplier bill</th>
                  <th className="px-5 py-3">Payment</th>
                  <th className="px-5 py-3 text-right">Total</th>
                  <th className="px-5 py-3 text-right" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <p className="font-mono text-xs font-medium text-slate-800">{p.number}</p>
                      <p className="text-xs text-slate-500">
                        {formatDate(p.date)} · {p.items_count} item{p.items_count === 1 ? '' : 's'}
                        {isAllStores && p.store && ` · ${p.store.code}`}
                      </p>
                    </td>
                    <td className="px-5 py-3 text-slate-700">{p.supplier?.name}</td>
                    <td className="px-5 py-3 text-slate-600">
                      {p.supplier_invoice_number || '-'}
                      {p.supplier_invoice_date && <span className="block text-xs text-slate-400">{formatDate(p.supplier_invoice_date)}</span>}
                    </td>
                    <td className="px-5 py-3">
                      {p.status === 'cancelled' ? <Badge tone="red">Cancelled</Badge> : <Badge tone={p.payment_mode === 'credit' ? 'amber' : 'green'}>{MODE_LABELS[p.payment_mode]}</Badge>}
                    </td>
                    <td className={`px-5 py-3 text-right font-medium tabular-nums ${p.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-slate-900'}`}>{formatINR(p.grand_total)}</td>
                    <td className="px-5 py-3 text-right">
                      <Button variant="ghost" size="sm" icon={Eye} onClick={() => setViewing(p.id)} aria-label={`View ${p.number}`} title="View / print" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={setPage} />
      </Card>

      {viewing && (
        <PurchaseModal
          id={viewing}
          onClose={() => setViewing(null)}
          onCancelled={(p) => {
            toast(`Purchase ${p.number} cancelled. Stock and accounts reversed.`)
            load()
          }}
        />
      )}
    </>
  )
}

function NewPurchase({ suppliers, store, onCancel, onSaved }) {
  const [products, setProducts] = useState(null)
  const [form, setForm] = useState({
    supplier_id: '',
    supplier_invoice_number: '',
    supplier_invoice_date: '',
    date: today(),
    interstate: 'auto',
    payment_mode: 'credit',
    amount_paid: '',
    freight: '',
    notes: '',
  })
  const [lines, setLines] = useState([emptyLine()])
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  useEffect(() => {
    getPurchasingProducts().then(setProducts).catch((e) => setError(parseApiError(e)))
  }, [])

  const supplier = suppliers.find((s) => String(s.id) === String(form.supplier_id))
  // Same rule as the server: inter-state when both state codes are known and differ.
  const autoInterstate = Boolean(supplier?.state_code && store?.state_code && supplier.state_code !== store.state_code)
  const interstate = form.interstate === 'auto' ? autoInterstate : form.interstate === 'yes'

  const filled = lines.filter((l) => l.product_id)
  // Live preview; the server's figures are authoritative. Rounded to the nearest rupee like the server.
  const lineTotals = sumLines(filled, interstate)
  const beforeRounding = lineTotals.total + toCents(form.freight)
  const grand = Math.floor((beforeRounding + 50) / 100) * 100
  const totals = { ...lineTotals, roundOff: grand - beforeRounding, grand }

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const saved = await createPurchase({
        supplier_id: form.supplier_id || null,
        supplier_invoice_number: form.supplier_invoice_number.trim() || null,
        supplier_invoice_date: form.supplier_invoice_date || null,
        date: form.date || null,
        is_interstate: form.interstate === 'auto' ? null : form.interstate === 'yes',
        payment_mode: form.payment_mode,
        amount_paid: form.payment_mode === 'credit' || form.amount_paid === '' ? null : form.amount_paid,
        freight: form.freight === '' ? null : form.freight,
        notes: form.notes.trim() || null,
        items: filled.map((l) => ({ product_id: l.product_id, quantity: Number(l.quantity), unit_cost: l.unit === '' ? null : l.unit, tax_percent: l.rate })),
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
        title="New purchase"
        description={store ? `Goods received at ${store.name}.` : ''}
        actions={<Button variant="secondary" icon={ArrowLeft} onClick={onCancel}>Back to purchases</Button>}
      />
      <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div className="space-y-6">
          <Card title="Supplier & bill">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Supplier" error={fieldError('supplier_id')} className="sm:col-span-2">
                <select className={inputClass} value={form.supplier_id} onChange={set('supplier_id')}>
                  <option value="">Choose a supplier</option>
                  {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}{s.gstin ? ` · ${s.gstin}` : ''}</option>)}
                </select>
              </Field>
              <Field label="Supplier invoice no." error={fieldError('supplier_invoice_number')}>
                <input className={inputClass} value={form.supplier_invoice_number} onChange={set('supplier_invoice_number')} />
              </Field>
              <Field label="Supplier invoice date" error={fieldError('supplier_invoice_date')}>
                <input type="date" className={inputClass} value={form.supplier_invoice_date} onChange={set('supplier_invoice_date')} />
              </Field>
              <Field label="Received on" error={fieldError('date')}>
                <input type="date" className={inputClass} value={form.date} max={today()} onChange={set('date')} />
              </Field>
              <Field label="GST type" hint={form.interstate === 'auto' ? (interstate ? 'Supplier is in another state: IGST.' : 'Same state (or unknown): CGST + SGST.') : null}>
                <select className={inputClass} value={form.interstate} onChange={set('interstate')}>
                  <option value="auto">Automatic (from state codes)</option>
                  <option value="no">Within state (CGST + SGST)</option>
                  <option value="yes">Inter-state (IGST)</option>
                </select>
              </Field>
            </div>
          </Card>

          <Card title="Items">
            {!products ? (
              <div className="grid place-items-center py-8 text-slate-400"><Spinner /></div>
            ) : (
              <ProductLines
                products={products}
                lines={lines}
                onChange={setLines}
                interstate={interstate}
                errors={error?.errors}
                onProductCreated={(p) => setProducts((list) => [...list, p].sort((x, y) => x.name.localeCompare(y.name)))}
              />
            )}
            {fieldError('items') && <p className="mt-2 text-xs text-red-600">{fieldError('items')}</p>}
          </Card>

          <Card title="Notes">
            <textarea className={inputClass} rows={2} value={form.notes} onChange={set('notes')} placeholder="Optional" />
          </Card>
        </div>

        <div className="space-y-6">
          <Card title="Totals">
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
              <div className="flex items-center justify-between gap-4 text-slate-700">
                <span>Freight</span>
                <input type="number" min="0" step="0.01" className={`${inputClass} w-28 text-right`} value={form.freight} onChange={set('freight')} placeholder="0.00" aria-label="Freight" />
              </div>
              {fieldError('freight') && <p className="text-xs text-red-600">{fieldError('freight')}</p>}
              <TotalRow label="Round off" value={formatINR(totals.roundOff, { cents: true })} muted />
              <div className="border-t border-slate-200 pt-2">
                <TotalRow label="Grand total" value={formatINR(totals.grand, { cents: true })} strong />
              </div>
            </div>
          </Card>

          <Card title="Payment">
            <div className="space-y-4">
              <div className="grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1 text-sm">
                {['credit', 'cash', 'bank'].map((mode) => (
                  <button type="button" key={mode} onClick={() => setForm((f) => ({ ...f, payment_mode: mode }))}
                    className={`rounded-md px-2 py-1.5 font-medium ${form.payment_mode === mode ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'}`}>
                    {MODE_LABELS[mode]}
                  </button>
                ))}
              </div>
              {form.payment_mode === 'credit' ? (
                <p className="text-xs text-slate-500">Pay later: the total is added to what you owe {supplier?.name ?? 'the supplier'}.</p>
              ) : (
                <Field label="Amount paid now" error={fieldError('amount_paid')} hint="Leave empty to pay the full total.">
                  <input type="number" min="0" step="0.01" className={inputClass} value={form.amount_paid} onChange={set('amount_paid')} placeholder={(totals.grand / 100).toFixed(2)} />
                </Field>
              )}
            </div>
          </Card>

          {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
          {error && Object.keys(error.errors).length > 0 && <Alert>Please fix the highlighted fields.</Alert>}
          <Button type="submit" variant="success" size="lg" className="w-full" loading={saving} disabled={!filled.length || !form.supplier_id}>
            Save purchase
          </Button>
        </div>
      </form>
    </>
  )
}

/** One purchase with print and cancel. */
function PurchaseModal({ id, onClose, onCancelled }) {
  const confirm = useConfirm()
  const [purchase, setPurchase] = useState(null)
  const [error, setError] = useState(null)
  const [cancelling, setCancelling] = useState(false)
  const { contentRef, print } = usePrintDocument(purchase ? `Purchase ${purchase.number}` : 'Purchase')

  useEffect(() => {
    let cancelled = false
    getPurchase(id)
      .then((p) => !cancelled && setPurchase(p))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [id])

  async function cancel() {
    if (!(await confirm({ title: 'Cancel this purchase?', message: 'The stock is taken back out and the accounts entries are reversed.', confirmLabel: 'Cancel purchase', cancelLabel: 'Keep it' }))) return
    setCancelling(true)
    setError(null)
    try {
      const p = await cancelPurchase(purchase.id)
      setPurchase((old) => ({ ...old, ...p }))
      onCancelled(p)
    } catch (e) {
      const parsed = parseApiError(e)
      const first = Object.values(parsed.errors)[0]?.[0]
      setError(first ? `${parsed.message} ${first}` : parsed.message)
    } finally {
      setCancelling(false)
    }
  }

  const isCancelled = purchase?.status === 'cancelled'

  return (
    <Modal
      open
      size="xl"
      title={purchase ? `Purchase ${purchase.number}` : 'Purchase'}
      onClose={onClose}
      footer={
        purchase && (
          <>
            {!isCancelled && !purchase.has_returns && (
              <Button variant="danger" icon={Ban} loading={cancelling} onClick={cancel}>Cancel purchase</Button>
            )}
            <Button variant="secondary" icon={Printer} onClick={print}>Print</Button>
          </>
        )
      }
    >
      {error && <div className="mb-3"><Alert>{error}</Alert></div>}
      {!purchase && !error && <div className="grid place-items-center py-10 text-slate-400"><Spinner /></div>}
      {purchase && (
        <>
          <div className="mb-4 flex flex-wrap gap-2">
            {isCancelled ? <Badge tone="red">Cancelled</Badge> : <Badge tone="green">Posted</Badge>}
            <Badge tone={purchase.payment_mode === 'credit' ? 'amber' : 'slate'}>{MODE_LABELS[purchase.payment_mode]}</Badge>
            {purchase.is_interstate && <Badge tone="brand">IGST</Badge>}
            {purchase.has_returns && <Badge tone="amber">Has returns</Badge>}
          </div>
          <div className="grid gap-4 text-sm sm:grid-cols-2">
            <div>
              <p className="text-xs uppercase text-slate-500">Supplier</p>
              <p className="font-medium text-slate-800">{purchase.supplier?.name}</p>
              {purchase.supplier?.gstin && <p className="font-mono text-xs text-slate-500">{purchase.supplier.gstin}</p>}
            </div>
            <div className="sm:text-right">
              <p>Received {formatDate(purchase.date)} at {purchase.store?.name}</p>
              {purchase.supplier_invoice_number && <p className="text-slate-500">Supplier bill {purchase.supplier_invoice_number} · {formatDate(purchase.supplier_invoice_date)}</p>}
              <p className="text-xs text-slate-400">By {purchase.created_by ?? '-'}</p>
            </div>
          </div>

          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="text-left text-xs uppercase text-slate-500">
                  <th className="py-2">Product</th>
                  <th className="py-2 text-right">Qty</th>
                  <th className="py-2 text-right">Cost</th>
                  <th className="py-2 text-right">GST</th>
                  <th className="py-2 text-right">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {purchase.items.map((item) => (
                  <tr key={item.id}>
                    <td className="py-2">{item.product?.name}</td>
                    <td className="py-2 text-right tabular-nums">{item.quantity}</td>
                    <td className="py-2 text-right tabular-nums">{formatINR(item.unit_cost)}</td>
                    <td className="py-2 text-right tabular-nums text-slate-500">{Number(item.tax_percent)}%</td>
                    <td className="py-2 text-right tabular-nums">{formatINR(item.line_total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 ml-auto max-w-xs space-y-1 text-sm">
            <TotalRow label="Taxable value" value={formatINR(purchase.subtotal)} />
            {purchase.is_interstate ? (
              <TotalRow label="IGST" value={formatINR(purchase.igst_amount)} muted />
            ) : (
              <>
                <TotalRow label="CGST" value={formatINR(purchase.cgst_amount)} muted />
                <TotalRow label="SGST" value={formatINR(purchase.sgst_amount)} muted />
              </>
            )}
            {Number(purchase.freight) > 0 && <TotalRow label="Freight" value={formatINR(purchase.freight)} muted />}
            <TotalRow label="Round off" value={formatINR(purchase.round_off)} muted />
            <div className="border-t border-slate-200 pt-1"><TotalRow label="Grand total" value={formatINR(purchase.grand_total)} strong /></div>
            <TotalRow label="Paid" value={formatINR(purchase.amount_paid)} muted />
          </div>
          {purchase.notes && <p className="mt-4 text-sm text-slate-600"><b>Notes:</b> {purchase.notes}</p>}

          <div className="hidden">
            <PrintableDocument
              ref={contentRef}
              title="Purchase / Goods received"
              number={purchase.number}
              date={purchase.date}
              store={purchase.store}
              partyLabel="Supplier"
              party={purchase.supplier}
              cancelled={isCancelled}
              meta={[
                ['Supplier bill', purchase.supplier_invoice_number],
                ['Bill date', purchase.supplier_invoice_date && formatDate(purchase.supplier_invoice_date)],
                ['Payment', MODE_LABELS[purchase.payment_mode]],
              ]}
              lines={purchase.items.map((item) => ({
                key: item.id,
                name: item.product?.name,
                code: item.product?.code,
                hsn: item.product?.hsn_code,
                quantity: item.quantity,
                rate: item.unit_cost,
                taxPercent: item.tax_percent,
                taxable: item.line_subtotal,
                tax: item.line_tax,
                total: item.line_total,
              }))}
              totals={[
                ['Taxable value', purchase.subtotal],
                ...(purchase.is_interstate ? [['IGST', purchase.igst_amount]] : [['CGST', purchase.cgst_amount], ['SGST', purchase.sgst_amount]]),
                ...(Number(purchase.freight) > 0 ? [['Freight', purchase.freight]] : []),
                ['Round off', purchase.round_off],
                ['Grand total', purchase.grand_total],
              ]}
              notes={purchase.notes}
            />
          </div>
        </>
      )}
    </Modal>
  )
}
