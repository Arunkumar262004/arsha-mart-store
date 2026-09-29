import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { History, PackagePlus, Pencil, Plus, Search } from 'lucide-react'
import { adjustStock, createProduct, getLowStock, getProducts, getStockMovements, updateProduct } from '../api'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../components/ui'
import { formatINR } from '../lib/money'

const refreshBell = () => window.dispatchEvent(new Event('store:refresh-notifications'))

export default function Inventory() {
  const { can } = useAuth()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const filter = params.get('filter') === 'low' ? 'low' : 'all'

  const [products, setProducts] = useState(null)
  const [threshold, setThreshold] = useState('')
  const [appliedThreshold, setAppliedThreshold] = useState('')
  const [search, setSearch] = useState('')
  const [error, setError] = useState(null)

  const [editing, setEditing] = useState(null) // product | 'new' | null
  const [adjusting, setAdjusting] = useState(null)
  const [historyFor, setHistoryFor] = useState(null)

  const load = useCallback(() => {
    const request =
      filter === 'low'
        ? getLowStock(appliedThreshold).then((res) => {
            setThreshold((t) => (t === '' ? String(res.meta.threshold) : t))
            return res.data
          })
        : getProducts()
    request
      .then((rows) => {
        setProducts(rows)
        setError(null)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [filter, appliedThreshold])

  useEffect(load, [load])

  // Deep link from the dashboard / notifications: /inventory?restock=<id>
  const restockId = params.get('restock')
  useEffect(() => {
    if (!restockId || !products || !can('stock.adjust')) return
    const product = products.find((p) => String(p.id) === restockId)
    if (product) setAdjusting(product)
    setParams((p) => {
      p.delete('restock')
      return p
    }, { replace: true })
  }, [restockId, products, can, setParams])

  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!products) return []
    return q ? products.filter((p) => p.name.toLowerCase().includes(q) || p.code.toLowerCase().includes(q)) : products
  }, [products, search])

  function saved(message) {
    toast(message)
    load()
    refreshBell()
  }

  return (
    <>
      <PageHeader
        title="Inventory"
        description="Products, prices and stock levels. Every stock change is logged."
        actions={can('products.manage') && <Button icon={Plus} onClick={() => setEditing('new')}>Add product</Button>}
      />

      <Card padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="inline-flex rounded-lg bg-slate-100 p-1 text-sm">
            {[
              ['all', 'All products'],
              ['low', 'Low stock'],
            ].map(([key, label]) => (
              <button
                key={key}
                onClick={() => {
                  setProducts(null)
                  setParams(key === 'low' ? { filter: 'low' } : {})
                }}
                className={`rounded-md px-3 py-1.5 font-medium transition ${filter === key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
              >
                {label}
              </button>
            ))}
          </div>

          {filter === 'low' && (
            <form
              className="flex items-center gap-2 text-sm"
              onSubmit={(e) => {
                e.preventDefault()
                setAppliedThreshold(threshold)
              }}
            >
              <span className="text-slate-500">Below</span>
              <input type="number" min="0" value={threshold} onChange={(e) => setThreshold(e.target.value)} className={`${inputClass} w-20`} aria-label="Low-stock threshold" />
              <Button type="submit" variant="secondary" size="sm">Apply</Button>
            </form>
          )}

          <div className="relative ml-auto w-full sm:w-64">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-9`} placeholder="Search name or code" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!products && !error && (
          <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>
        )}
        {products && rows.length === 0 && (
          <EmptyState icon={PackagePlus} title={filter === 'low' ? 'No products below the threshold' : 'No products found'} />
        )}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Product</th>
                  <th className="px-5 py-3 text-right">Price</th>
                  <th className="px-5 py-3 text-right">Tax</th>
                  <th className="px-5 py-3 text-right">Stock</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((p) => (
                  <tr key={p.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <p className="font-medium text-slate-800">{p.name}</p>
                      <p className="font-mono text-xs text-slate-500">{p.code}</p>
                    </td>
                    <td className="px-5 py-3 text-right tabular-nums">{formatINR(p.price)}</td>
                    <td className="px-5 py-3 text-right text-slate-500">{Number(p.tax_percent)}%</td>
                    <td className="px-5 py-3 text-right"><StockBadge stock={p.stock} /></td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        {can('stock.adjust') && (
                          <Button variant="secondary" size="sm" icon={PackagePlus} onClick={() => setAdjusting(p)}>Stock</Button>
                        )}
                        {can('products.manage') && (
                          <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(p)} aria-label={`Edit ${p.name}`} />
                        )}
                        <Button variant="ghost" size="sm" icon={History} onClick={() => setHistoryFor(p)} aria-label={`Stock history for ${p.name}`} />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editing && (
        <ProductModal
          product={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(p, isNew) => {
            setEditing(null)
            saved(isNew ? `${p.name} added.` : `${p.name} updated.`)
          }}
        />
      )}
      {adjusting && (
        <StockModal
          product={adjusting}
          onClose={() => setAdjusting(null)}
          onSaved={(p) => {
            setAdjusting(null)
            saved(`${p.name}: stock is now ${p.stock}.`)
          }}
        />
      )}
      {historyFor && <HistoryModal product={historyFor} onClose={() => setHistoryFor(null)} />}
    </>
  )
}

function StockBadge({ stock }) {
  if (stock === 0) return <Badge tone="red">Out of stock</Badge>
  if (stock < 10) return <Badge tone="amber">{stock} left</Badge>
  return <span className="font-medium tabular-nums text-slate-800">{stock}</span>
}

function ProductModal({ product, onClose, onSaved }) {
  const isNew = product === null
  const [form, setForm] = useState({
    name: product?.name ?? '',
    code: product?.code ?? '',
    price: product?.price ?? '',
    tax_percent: product ? Number(product.tax_percent) : 0,
    stock: 0,
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const { stock, ...details } = form
      const saved = isNew ? await createProduct({ ...details, stock: Number(stock) }) : await updateProduct(product.id, details)
      onSaved(saved, isNew)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={isNew ? 'Add product' : `Edit ${product.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="product-form" loading={saving}>{isNew ? 'Add product' : 'Save changes'}</Button>
        </>
      }
    >
      <form id="product-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        {error && !Object.keys(error.errors).length && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
        <Field label="Product name" error={fieldError('name')} className="sm:col-span-2">
          <input className={inputClass} value={form.name} onChange={set('name')} autoFocus />
        </Field>
        <Field label="Code (SKU)" error={fieldError('code')} hint="Unique, e.g. AMUL-BUT-100">
          <input className={`${inputClass} font-mono uppercase`} value={form.code} onChange={set('code')} />
        </Field>
        <Field label="Price per unit (₹)" error={fieldError('price')}>
          <input type="number" min="0" step="0.01" className={inputClass} value={form.price} onChange={set('price')} />
        </Field>
        <Field label="Tax (GST %)" error={fieldError('tax_percent')}>
          <select className={inputClass} value={form.tax_percent} onChange={set('tax_percent')}>
            {[0, 5, 12, 18, 28].map((t) => <option key={t} value={t}>{t}%</option>)}
          </select>
        </Field>
        {isNew ? (
          <Field label="Opening stock" error={fieldError('stock')}>
            <input type="number" min="0" className={inputClass} value={form.stock} onChange={set('stock')} />
          </Field>
        ) : (
          <div className="self-end text-xs text-slate-500">Change stock with the <b>Stock</b> button so it's logged.</div>
        )}
      </form>
    </Modal>
  )
}

function StockModal({ product, onClose, onSaved }) {
  const [type, setType] = useState('restock')
  const [direction, setDirection] = useState('remove')
  const [quantity, setQuantity] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  const qty = Number(quantity) || 0
  const signed = type === 'restock' || direction === 'add' ? qty : -qty
  const after = product.stock + signed

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const res = await adjustStock(product.id, { type, quantity: signed, note: note.trim() || null })
      onSaved(res.data)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      size="sm"
      title={`Update stock · ${product.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="stock-form" loading={saving} disabled={!qty}>Save</Button>
        </>
      }
    >
      <form id="stock-form" onSubmit={submit} className="space-y-4" noValidate>
        <div className="grid grid-cols-2 gap-2">
          {[
            ['restock', 'Restock', 'New delivery arrived'],
            ['correction', 'Correction', 'Damaged, lost, recount'],
          ].map(([key, label, hint]) => (
            <button
              type="button"
              key={key}
              onClick={() => setType(key)}
              className={`rounded-lg border p-3 text-left transition ${type === key ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'}`}
            >
              <span className="block text-sm font-medium text-slate-900">{label}</span>
              <span className="block text-xs text-slate-500">{hint}</span>
            </button>
          ))}
        </div>

        {type === 'correction' && (
          <div className="inline-flex rounded-lg bg-slate-100 p-1 text-sm">
            {['remove', 'add'].map((d) => (
              <button type="button" key={d} onClick={() => setDirection(d)}
                className={`rounded-md px-3 py-1 font-medium capitalize ${direction === d ? 'bg-white shadow-sm' : 'text-slate-600'}`}>
                {d} units
              </button>
            ))}
          </div>
        )}

        <Field label="Quantity" error={error?.errors?.quantity?.[0]}>
          <input type="number" min="1" className={inputClass} value={quantity} onChange={(e) => setQuantity(e.target.value)} autoFocus />
        </Field>
        <Field label={type === 'correction' ? 'Reason (required)' : 'Note (optional)'} error={error?.errors?.note?.[0]}>
          <input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} placeholder={type === 'correction' ? 'e.g. 2 packs damaged' : 'e.g. Invoice #4411'} />
        </Field>

        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-4 py-3 text-sm">
          <span className="text-slate-500">Stock</span>
          <span className="tabular-nums">
            {product.stock} → <b className={after < 0 ? 'text-red-600' : 'text-slate-900'}>{after}</b>
          </span>
        </div>
        {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
      </form>
    </Modal>
  )
}

const TYPE_TONES = { sale: 'slate', restock: 'green', correction: 'amber', initial: 'brand' }

function HistoryModal({ product, onClose }) {
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)

  useEffect(() => {
    let cancelled = false
    getStockMovements(product.id, page).then((res) => !cancelled && setResult(res))
    return () => {
      cancelled = true
    }
  }, [product.id, page])

  return (
    <Modal open size="lg" title={`Stock history · ${product.name}`} onClose={onClose}>
      {!result ? (
        <div className="grid place-items-center py-10 text-slate-400"><Spinner /></div>
      ) : result.data.length === 0 ? (
        <EmptyState icon={History} title="No stock changes yet" />
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[520px] text-sm">
              <thead>
                <tr className="text-left text-xs uppercase text-slate-500">
                  <th className="py-2">When</th>
                  <th className="py-2">Type</th>
                  <th className="py-2 text-right">Change</th>
                  <th className="py-2 text-right">After</th>
                  <th className="py-2 pl-4">By / note</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.data.map((m) => (
                  <tr key={m.id}>
                    <td className="py-2 text-slate-500">{new Date(m.created_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}</td>
                    <td className="py-2"><Badge tone={TYPE_TONES[m.type]}>{m.type}</Badge></td>
                    <td className={`py-2 text-right font-medium tabular-nums ${m.quantity > 0 ? 'text-emerald-700' : 'text-slate-700'}`}>
                      {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                    </td>
                    <td className="py-2 text-right tabular-nums">{m.stock_after}</td>
                    <td className="py-2 pl-4 text-slate-600">
                      {m.user ?? 'System'}
                      {m.note && <span className="block text-xs text-slate-400">{m.note}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {result.meta.last_page > 1 && (
            <div className="mt-4 flex items-center justify-center gap-3 text-sm">
              <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Prev</Button>
              <span>Page {result.meta.current_page} of {result.meta.last_page}</span>
              <Button variant="secondary" size="sm" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)}>Next</Button>
            </div>
          )}
        </>
      )}
    </Modal>
  )
}
