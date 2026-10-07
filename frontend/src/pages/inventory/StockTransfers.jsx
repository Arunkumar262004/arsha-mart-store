import { useCallback, useEffect, useMemo, useState } from 'react'
import { ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, PackageCheck, Send, XCircle } from 'lucide-react'
import { cancelTransfer, createTransfer, getTransfer, getTransferDestinations, getTransfers, receiveTransfer, getDocumentProducts } from '../../api/documents'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import StoreRequired from '../../components/StoreRequired'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import DocumentSheet from '../../components/documents/DocumentSheet'
import LineItemsEditor, { linesPayload, newLine } from '../../components/documents/LineItemsEditor'
import Pager from '../../components/documents/Pager'
import PrintModal from '../../components/documents/PrintModal'
import { formatDate } from '../../components/documents/printing'
import { useConfirm } from '../../components/ConfirmDialog'

const STATUS = {
  in_transit: { label: 'In transit', tone: 'amber' },
  received: { label: 'Received', tone: 'green' },
  cancelled: { label: 'Cancelled', tone: 'red' },
}
const TABS = [
  { value: '', label: 'All', icon: ArrowRightLeft },
  { value: 'incoming', label: 'Incoming', icon: ArrowDownToLine },
  { value: 'outgoing', label: 'Outgoing', icon: ArrowUpFromLine },
]

/** Move stock between stores: dispatch from here, receive at the other end. */
export default function StockTransfers() {
  const confirm = useConfirm()
  const { currentStore, isAllStores } = useAuth()
  const [direction, setDirection] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [notice, setNotice] = useState(null)
  const [creating, setCreating] = useState(false)
  const [viewing, setViewing] = useState(null)
  const [busy, setBusy] = useState(null)

  const reload = useCallback(() => setReloadKey((k) => k + 1), [])

  useEffect(() => {
    let cancelled = false
    getTransfers({ direction, status, page })
      .then((res) => {
        if (cancelled) return
        setResult(res)
        setError(null)
      })
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [direction, status, page, reloadKey])

  async function act(t, action) {
    if (action === 'cancel' && !(await confirm({ title: `Cancel transfer ${t.number}?`, message: `The goods go back into ${t.from_store?.name}'s stock.`, confirmLabel: 'Cancel transfer', cancelLabel: 'Keep it' }))) return
    setBusy(t.id)
    try {
      const updated = await (action === 'receive' ? receiveTransfer(t.id) : cancelTransfer(t.id))
      setNotice({
        tone: 'success',
        text: action === 'receive' ? `Transfer ${t.number} received at ${updated.to_store?.name}.` : `Transfer ${t.number} cancelled; stock is back at ${updated.from_store?.name}.`,
      })
      setViewing((v) => (v?.id === t.id ? updated : v))
      reload()
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    } finally {
      setBusy(null)
    }
  }

  async function view(id) {
    try {
      setViewing(await getTransfer(id))
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    }
  }

  const rows = result?.data ?? []

  return (
    <>
      <PageHeader
        title="Stock Transfers"
        description={currentStore ? `Stock moving in and out of ${currentStore.name}` : 'Move stock between stores'}
        actions={<Button icon={Send} disabled={isAllStores} onClick={() => setCreating(true)}>New transfer</Button>}
      />
      <div className="mb-4 space-y-3">
        <StoreRequired what="stock transfers" />
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>

      <Card padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-5">
          <div className="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="tablist">
            {TABS.map(({ value, label, icon: Icon }) => (
              <button
                key={value}
                type="button"
                role="tab"
                aria-selected={direction === value}
                onClick={() => {
                  setDirection(value)
                  setPage(1)
                }}
                className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium transition ${direction === value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
              >
                <Icon size={14} aria-hidden /> {label}
              </button>
            ))}
          </div>
          <select className={`${inputClass} ml-auto w-auto`} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} aria-label="Status">
            <option value="">All statuses</option>
            {Object.entries(STATUS).map(([value, s]) => <option key={value} value={value}>{s.label}</option>)}
          </select>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={ArrowRightLeft} title="No stock transfers" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[800px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Transfer</th>
                  <th className="px-5 py-3">From → To</th>
                  <th className="px-5 py-3 text-right">Qty</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((t) => (
                  <tr key={t.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => view(t.id)}>{t.number}</button>
                      <p className="text-xs text-slate-500">{formatDate(t.dispatched_at, true)} · {t.dispatched_by_name}</p>
                    </td>
                    <td className="px-5 py-3 text-slate-700">
                      {t.from_store?.name} <span className="text-slate-400">→</span> {t.to_store?.name}
                      <p className="text-xs text-slate-500">{t.items.length} product{t.items.length === 1 ? '' : 's'}</p>
                    </td>
                    <td className="px-5 py-3 text-right tabular-nums">{t.total_quantity}</td>
                    <td className="px-5 py-3">
                      <Badge tone={STATUS[t.status]?.tone}>{STATUS[t.status]?.label}</Badge>
                      {t.received_at && <p className="mt-0.5 text-xs text-slate-500">{formatDate(t.received_at, true)}</p>}
                    </td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        {t.can_receive && (
                          <Button size="sm" icon={PackageCheck} loading={busy === t.id} onClick={() => act(t, 'receive')}>Receive</Button>
                        )}
                        {t.can_cancel && (
                          <Button size="sm" variant="ghost" icon={XCircle} disabled={busy === t.id} onClick={() => act(t, 'cancel')}>Cancel</Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pager meta={result?.meta} onPage={setPage} />
      </Card>

      {creating && (
        <TransferForm
          fromStore={currentStore}
          onClose={() => setCreating(false)}
          onSaved={(t) => {
            setCreating(false)
            setNotice({ tone: 'success', text: `Transfer ${t.number} dispatched to ${t.to_store?.name}. It reaches their stock when they receive it.` })
            setViewing(t)
            reload()
          }}
        />
      )}

      {viewing && (
        <PrintModal title={`Stock transfer · ${viewing.number}`} documentTitle={viewing.number} onClose={() => setViewing(null)}>
          {(ref) => <TransferSheet ref={ref} transfer={viewing} />}
        </PrintModal>
      )}
    </>
  )
}

function TransferSheet({ transfer: t, ref }) {
  const to = t.to_store ?? {}
  return (
    <DocumentSheet
      ref={ref}
      title="STOCK TRANSFER CHALLAN"
      subtitle="Branch transfer, not a sale"
      store={t.from_store}
      party={{ label: 'Send to', name: to.name, lines: [[to.address, to.city, to.state].filter(Boolean).join(', '), to.gstin && `GSTIN: ${to.gstin}`] }}
      meta={[
        ['Transfer no', t.number],
        ['Dispatched', formatDate(t.dispatched_at, true)],
        ['By', t.dispatched_by_name],
        ['Vehicle', t.vehicle_number],
        ['Status', STATUS[t.status]?.label],
        ['Received', t.received_at && `${formatDate(t.received_at, true)}${t.received_by_name ? ` by ${t.received_by_name}` : ''}`],
      ]}
      columns={[
        { key: 'sr', label: '#', align: 'center' },
        { key: 'code', label: 'Code' },
        { key: 'name', label: 'Item' },
        { key: 'qty', label: 'Qty', align: 'right' },
      ]}
      rows={t.items.map((item, i) => ({ sr: i + 1, code: item.product_code, name: item.product_name, qty: `${item.quantity} ${item.unit ?? ''}` }))}
      totals={[['Total quantity', t.total_quantity]]}
      notes={t.notes}
      signatures={['Dispatched by', 'Received by']}
    />
  )
}

function TransferForm({ fromStore, onClose, onSaved }) {
  const [products, setProducts] = useState([])
  const [destinations, setDestinations] = useState([])
  const [toStoreId, setToStoreId] = useState('')
  const [vehicle, setVehicle] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState([newLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    // Products carry the stock at the current (source) store.
    Promise.all([getDocumentProducts(), getTransferDestinations()])
      .then(([p, d]) => {
        setProducts(p)
        setDestinations(d)
        if (d.length === 1) setToStoreId(String(d[0].id))
      })
      .catch((e) => setError(parseApiError(e)))
  }, [])

  const productById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products])
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function save() {
    setSaving(true)
    setError(null)
    try {
      onSaved(
        await createTransfer({
          to_store_id: toStoreId ? Number(toStoreId) : null,
          vehicle_number: vehicle.trim() || null,
          notes: notes.trim() || null,
          items: linesPayload(lines),
        }),
      )
    } catch (e) {
      setError(parseApiError(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      size="xl"
      title="New stock transfer"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button icon={Send} loading={saving} onClick={save} disabled={destinations.length === 0}>Dispatch</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error.message}</Alert>}
        {destinations.length === 0 && !error && products.length > 0 && <Alert tone="info">There is no other active store to send stock to.</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="From">
            <input className={inputClass} value={fromStore?.name ?? ''} readOnly />
          </Field>
          <Field label="To store" error={fieldError('to_store_id')}>
            <select className={inputClass} value={toStoreId} onChange={(e) => setToStoreId(e.target.value)}>
              <option value="">Select a store…</option>
              {destinations.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.code})</option>)}
            </select>
          </Field>
          <Field label="Vehicle number" error={fieldError('vehicle_number')}>
            <input className={`${inputClass} uppercase`} value={vehicle} onChange={(e) => setVehicle(e.target.value)} />
          </Field>
        </div>
        <div>
          <p className="mb-1 text-xs font-medium text-slate-600">Products <span className="font-normal text-slate-400">(stock shown is at {fromStore?.name ?? 'this store'})</span></p>
          <LineItemsEditor products={products} productById={productById} lines={lines} setLines={setLines} checkStock fieldError={fieldError} />
        </div>
        <Field label="Notes" error={fieldError('notes')}>
          <textarea rows={2} className={inputClass} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}
