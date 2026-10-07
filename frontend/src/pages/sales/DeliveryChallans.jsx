import { useCallback, useEffect, useMemo, useState } from 'react'
import { PackageCheck, ReceiptText, Search, Truck, Undo2, XCircle } from 'lucide-react'
import { cancelChallan, createChallan, getChallan, getChallans, invoiceChallans, returnChallan, getDocumentProducts } from '../../api/documents'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import StoreRequired from '../../components/StoreRequired'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import BillDialog from '../../components/documents/BillDialog'
import DocumentSheet from '../../components/documents/DocumentSheet'
import LineItemsEditor, { linesPayload, newLine } from '../../components/documents/LineItemsEditor'
import Pager from '../../components/documents/Pager'
import PartyFields, { EMPTY_PARTY, partyPayload } from '../../components/documents/PartyFields'
import PrintModal from '../../components/documents/PrintModal'
import { formatDate, localDate } from '../../components/documents/printing'
import { formatINR } from '../../lib/money'
import { useConfirm } from '../../components/ConfirmDialog'

const STATUS = {
  issued: { label: 'Issued', tone: 'brand' },
  invoiced: { label: 'Invoiced', tone: 'green' },
  returned: { label: 'Returned', tone: 'amber' },
  cancelled: { label: 'Cancelled', tone: 'red' },
}
const PURPOSES = [
  { value: 'sale', label: 'Sale (invoice later)' },
  { value: 'approval', label: 'On approval' },
  { value: 'job_work', label: 'Job work' },
  { value: 'other', label: 'Other' },
]
const purposeLabel = (value) => PURPOSES.find((p) => p.value === value)?.label ?? value

/** Who a challan is for, to check that selected challans can share one bill. */
const customerKey = (c) => (c.customer_email ? `e:${c.customer_email.toLowerCase()}` : `n:${c.customer_name.trim().toLowerCase()}`)

/** Goods sent before invoicing: stock leaves now, the bill comes later (or the goods come back). */
export default function DeliveryChallans() {
  const confirm = useConfirm()
  const { can, isAllStores } = useAuth()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [notice, setNotice] = useState(null)

  const [creating, setCreating] = useState(false)
  const [viewing, setViewing] = useState(null)
  const [selected, setSelected] = useState([]) // challan objects picked for one bill
  const [billing, setBilling] = useState(false)

  const reload = useCallback(() => setReloadKey((k) => k + 1), [])

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search)
      setPage(1)
    }, 350)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    let cancelled = false
    getChallans({ status, search: query, page })
      .then((res) => {
        if (cancelled) return
        setResult(res)
        setError(null)
      })
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [status, query, page, reloadKey])

  const selectedIds = new Set(selected.map((c) => c.id))
  const canPick = (c) =>
    c.status === 'issued' && (selected.length === 0 || (customerKey(selected[0]) === customerKey(c) && selected[0].store_id === c.store_id))

  function toggle(c) {
    setSelected((prev) => (prev.some((s) => s.id === c.id) ? prev.filter((s) => s.id !== c.id) : [...prev, c]))
  }

  async function close(c, action) {
    const verb = action === 'return' ? 'Mark the goods of' : 'Cancel'
    if (!(await confirm({ title: `${verb} challan ${c.number}?`, message: 'The goods go back into stock.', confirmLabel: action === 'return' ? 'Mark returned' : 'Cancel challan', cancelLabel: 'Keep it', tone: action === 'return' ? 'primary' : 'danger' }))) return
    try {
      const updated = await (action === 'return' ? returnChallan(c.id) : cancelChallan(c.id))
      setNotice({ tone: 'success', text: `Challan ${c.number} ${updated.status}; stock restored.` })
      setViewing((v) => (v?.id === c.id ? null : v))
      setSelected((prev) => prev.filter((s) => s.id !== c.id))
      reload()
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    }
  }

  async function view(id) {
    try {
      setViewing(await getChallan(id))
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    }
  }

  const rows = result?.data ?? []
  const selectedValue = selected.reduce((t, c) => t + Number(c.approx_value ?? 0), 0)

  return (
    <>
      <PageHeader
        title="Delivery Challans"
        description="Goods sent before invoicing. Select issued challans of one customer to bill them together."
        actions={<Button icon={Truck} disabled={isAllStores} onClick={() => setCreating(true)}>New challan</Button>}
      />
      <div className="mb-4 space-y-3">
        <StoreRequired what="delivery challans" />
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
        {selected.length > 0 && (
          <div className="flex flex-wrap items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
            <span>
              <b>{selected.length}</b> challan{selected.length === 1 ? '' : 's'} for <b>{selected[0].customer_name}</b> · approx. {formatINR(selectedValue)} before tax
            </span>
            <div className="ml-auto flex gap-2">
              <Button size="sm" variant="ghost" onClick={() => setSelected([])}>Clear</Button>
              {can('billing.create') && <Button size="sm" icon={ReceiptText} onClick={() => setBilling(true)}>Create invoice</Button>}
            </div>
          </div>
        )}
      </div>

      <Card padded={false}>
        <div className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-5">
          <select className={`${inputClass} w-auto`} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }} aria-label="Status">
            <option value="">All statuses</option>
            {Object.entries(STATUS).map(([value, s]) => <option key={value} value={value}>{s.label}</option>)}
          </select>
          <div className="relative ml-auto w-full sm:w-64">
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-8`} placeholder="Number or customer" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={Truck} title="No delivery challans" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="w-10 px-5 py-3" />
                  <th className="px-5 py-3">Challan</th>
                  <th className="px-5 py-3">Customer</th>
                  <th className="px-5 py-3">Purpose</th>
                  <th className="px-5 py-3 text-right">Qty</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((c) => (
                  <tr key={c.id} className={selectedIds.has(c.id) ? 'bg-brand-50/50' : 'hover:bg-slate-50/60'}>
                    <td className="px-5 py-3">
                      {c.status === 'issued' && (
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300 accent-brand-600"
                          checked={selectedIds.has(c.id)}
                          disabled={!selectedIds.has(c.id) && !canPick(c)}
                          onChange={() => toggle(c)}
                          aria-label={`Select ${c.number}`}
                          title={!selectedIds.has(c.id) && !canPick(c) ? 'Only challans of the same customer and store can be billed together' : undefined}
                        />
                      )}
                    </td>
                    <td className="px-5 py-3">
                      <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => view(c.id)}>{c.number}</button>
                      <p className="text-xs text-slate-500">{formatDate(c.date)}{isAllStores && c.store && ` · ${c.store.name}`}</p>
                    </td>
                    <td className="px-5 py-3">
                      <p className="text-slate-800">{c.customer_name}</p>
                      {c.customer_email && <p className="text-xs text-slate-500">{c.customer_email}</p>}
                    </td>
                    <td className="px-5 py-3 text-slate-600">{purposeLabel(c.purpose)}</td>
                    <td className="px-5 py-3 text-right tabular-nums">{c.total_quantity}</td>
                    <td className="px-5 py-3">
                      <Badge tone={STATUS[c.status]?.tone}>{STATUS[c.status]?.label ?? c.status}</Badge>
                      {c.invoice_number && <p className="mt-0.5 text-xs text-slate-500">{c.invoice_number}</p>}
                    </td>
                    <td className="px-5 py-3">
                      {c.status === 'issued' && (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" variant="ghost" icon={Undo2} onClick={() => close(c, 'return')}>Returned</Button>
                          <Button size="sm" variant="ghost" icon={XCircle} onClick={() => close(c, 'cancel')}>Cancel</Button>
                        </div>
                      )}
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
        <ChallanForm
          onClose={() => setCreating(false)}
          onSaved={(c) => {
            setCreating(false)
            setNotice({ tone: 'success', text: `Challan ${c.number} issued; stock reduced.` })
            setViewing(c)
            reload()
          }}
        />
      )}

      {viewing && (
        <PrintModal title={`Delivery challan · ${viewing.number}`} documentTitle={viewing.number} onClose={() => setViewing(null)}>
          {(ref) => <ChallanSheet ref={ref} challan={viewing} />}
        </PrintModal>
      )}

      {billing && (
        <BillDialog
          title="Invoice delivery challans"
          needsEmail={!selected.some((c) => c.customer_email)}
          defaultName={selected[0]?.customer_name}
          submit={(payload) => invoiceChallans({ ...payload, challan_ids: selected.map((c) => c.id) })}
          onDone={() => {
            setSelected([])
            reload()
          }}
          onClose={() => setBilling(false)}
        >
          <p className="text-sm text-slate-600">
            One bill for <b>{selected[0]?.customer_name}</b> covering {selected.map((c) => c.number).join(', ')}, at today's prices.
          </p>
        </BillDialog>
      )}
    </>
  )
}

function ChallanSheet({ challan: c, ref }) {
  return (
    <DocumentSheet
      ref={ref}
      title="DELIVERY CHALLAN"
      subtitle={purposeLabel(c.purpose)}
      store={c.store}
      party={{
        label: 'Deliver to',
        name: c.customer_name,
        lines: [c.delivery_address, c.customer_phone && `Ph: ${c.customer_phone}`, c.customer_gstin && `GSTIN: ${c.customer_gstin}`],
      }}
      meta={[
        ['Challan no', c.number],
        ['Date', formatDate(c.date)],
        ['Vehicle', c.vehicle_number],
        ['Transporter', c.transporter],
        ['Status', STATUS[c.status]?.label],
        ['Invoice', c.invoice_number],
      ]}
      columns={[
        { key: 'sr', label: '#', align: 'center' },
        { key: 'name', label: 'Item' },
        { key: 'hsn', label: 'HSN', align: 'center' },
        { key: 'qty', label: 'Qty', align: 'right' },
        { key: 'rate', label: 'Rate', align: 'right' },
        { key: 'value', label: 'Value', align: 'right' },
      ]}
      rows={c.items.map((item, i) => ({
        sr: i + 1,
        name: item.product_name,
        hsn: item.hsn_code ?? '-',
        qty: `${item.quantity} ${item.unit ?? ''}`,
        rate: Number(item.unit_price).toFixed(2),
        value: (Number(item.unit_price) * item.quantity).toFixed(2),
      }))}
      totals={[['Total quantity', c.total_quantity], ['Approx. value (before tax)', `₹ ${Number(c.approx_value).toFixed(2)}`]]}
      notes={c.notes}
      footer="This is a delivery challan, not a tax invoice. Goods are sent for the purpose stated above."
      signatures={["Receiver's signature", `For ${c.store?.name ?? ''}`]}
    />
  )
}

function ChallanForm({ onClose, onSaved }) {
  const [products, setProducts] = useState([])
  const [party, setParty] = useState(EMPTY_PARTY)
  const [date, setDate] = useState(localDate())
  const [purpose, setPurpose] = useState('sale')
  const [vehicle, setVehicle] = useState('')
  const [transporter, setTransporter] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState([newLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    getDocumentProducts().then(setProducts).catch((e) => setError(parseApiError(e)))
  }, [])

  const productById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products])
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function save() {
    setSaving(true)
    setError(null)
    try {
      onSaved(
        await createChallan({
          ...partyPayload(party),
          delivery_address: party.address.trim() || null,
          date,
          purpose,
          vehicle_number: vehicle.trim() || null,
          transporter: transporter.trim() || null,
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
      title="New delivery challan"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button icon={PackageCheck} loading={saving} onClick={save}>Issue challan</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error.message}</Alert>}
        <PartyFields party={party} setParty={setParty} fieldError={fieldError} addressLabel="Delivery address" />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Date" error={fieldError('date')}>
            <input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
          <Field label="Purpose" error={fieldError('purpose')}>
            <select className={inputClass} value={purpose} onChange={(e) => setPurpose(e.target.value)}>
              {PURPOSES.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
            </select>
          </Field>
          <Field label="Vehicle number" error={fieldError('vehicle_number')}>
            <input className={`${inputClass} uppercase`} value={vehicle} onChange={(e) => setVehicle(e.target.value)} placeholder="e.g. KA01AB1234" />
          </Field>
          <Field label="Transporter" error={fieldError('transporter')}>
            <input className={inputClass} value={transporter} onChange={(e) => setTransporter(e.target.value)} />
          </Field>
        </div>
        <div>
          <p className="mb-1 text-xs font-medium text-slate-600">Goods</p>
          <LineItemsEditor products={products} productById={productById} lines={lines} setLines={setLines} checkStock fieldError={fieldError} />
        </div>
        <Field label="Notes" error={fieldError('notes')}>
          <textarea rows={2} className={inputClass} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}
