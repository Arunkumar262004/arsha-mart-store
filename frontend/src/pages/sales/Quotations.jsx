import { useCallback, useEffect, useMemo, useState } from 'react'
import { FilePlus2, FileText, Pencil, ReceiptText, Search, Trash2 } from 'lucide-react'
import {
  convertQuotation,
  createQuotation,
  deleteQuotation,
  getDocumentProducts,
  getQuotation,
  getQuotations,
  setQuotationStatus,
  updateQuotation,
} from '../../api/documents'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import StoreRequired from '../../components/StoreRequired'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import BillDialog from '../../components/documents/BillDialog'
import DocumentSheet from '../../components/documents/DocumentSheet'
import LineItemsEditor, { lineTotals, linesPayload, newLine } from '../../components/documents/LineItemsEditor'
import Pager from '../../components/documents/Pager'
import PartyFields, { EMPTY_PARTY, partyPayload } from '../../components/documents/PartyFields'
import PrintModal from '../../components/documents/PrintModal'
import { formatDate, localDate, money } from '../../components/documents/printing'
import { formatINR } from '../../lib/money'
import { useConfirm } from '../../components/ConfirmDialog'

const STATUSES = [
  { value: 'draft', label: 'Draft', tone: 'slate' },
  { value: 'sent', label: 'Sent', tone: 'brand' },
  { value: 'accepted', label: 'Accepted', tone: 'green' },
  { value: 'expired', label: 'Expired', tone: 'amber' },
  { value: 'converted', label: 'Converted', tone: 'green' },
  { value: 'cancelled', label: 'Cancelled', tone: 'red' },
]
const statusOf = (value) => STATUSES.find((s) => s.value === value) ?? STATUSES[0]
const isOpen = (q) => !['converted', 'cancelled'].includes(q.stored_status)

/** Price offers to customers; an accepted one becomes a bill at the quoted prices. */
export default function Quotations() {
  const confirm = useConfirm()
  const { can, isAllStores } = useAuth()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)

  const [editing, setEditing] = useState(null) // null | 'new' | quotation
  const [viewing, setViewing] = useState(null)
  const [converting, setConverting] = useState(null)
  const [notice, setNotice] = useState(null)

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
    getQuotations({ status, search: query, page })
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

  async function open(id, then) {
    try {
      then(await getQuotation(id))
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    }
  }

  async function changeStatus(q, next) {
    try {
      const updated = await setQuotationStatus(q.id, next)
      setViewing((v) => (v?.id === q.id ? updated : v))
      reload()
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    }
  }

  async function remove(q) {
    if (!(await confirm({ title: `Delete quotation ${q.number}?`, message: 'This draft is removed for good.', confirmLabel: 'Delete quotation' }))) return
    try {
      await deleteQuotation(q.id)
      setNotice({ tone: 'success', text: `Quotation ${q.number} deleted.` })
      reload()
    } catch (e) {
      setNotice({ tone: 'error', text: parseApiError(e).message })
    }
  }

  const rows = result?.data ?? []

  return (
    <>
      <PageHeader
        title="Quotations"
        description="Price offers that convert into bills"
        actions={<Button icon={FilePlus2} disabled={isAllStores} onClick={() => setEditing('new')}>New quotation</Button>}
      />
      <div className="mb-4 space-y-3">
        <StoreRequired what="quotations" />
        {notice && <Alert tone={notice.tone}>{notice.text}</Alert>}
      </div>

      <Card padded={false}>
        <div className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-5">
          <div className="inline-flex flex-wrap rounded-lg bg-slate-100 p-1 text-sm" role="radiogroup" aria-label="Status">
            {[{ value: '', label: 'All' }, ...STATUSES].map((s) => (
              <button
                key={s.value}
                type="button"
                role="radio"
                aria-checked={status === s.value}
                onClick={() => {
                  setStatus(s.value)
                  setPage(1)
                }}
                className={`rounded-md px-3 py-1.5 font-medium transition ${status === s.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}
              >
                {s.label}
              </button>
            ))}
          </div>
          <div className="relative ml-auto w-full sm:w-64">
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-8`} placeholder="Number, customer, GSTIN" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!result && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result && rows.length === 0 && <EmptyState icon={FileText} title="No quotations yet" />}

        {rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[780px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Quotation</th>
                  <th className="px-5 py-3">Customer</th>
                  <th className="px-5 py-3">Valid until</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3 text-right">Total</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((q) => (
                  <tr key={q.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <button type="button" className="font-medium text-brand-700 hover:underline" onClick={() => open(q.id, setViewing)}>
                        {q.number}
                      </button>
                      <p className="text-xs text-slate-500">{formatDate(q.date)} · {q.items_count} item{q.items_count === 1 ? '' : 's'}</p>
                    </td>
                    <td className="px-5 py-3">
                      <p className="text-slate-800">{q.customer_name}</p>
                      {q.customer_gstin && <p className="text-xs text-slate-500">GSTIN {q.customer_gstin}</p>}
                    </td>
                    <td className="px-5 py-3 text-slate-600">{q.valid_until ? formatDate(q.valid_until) : '-'}</td>
                    <td className="px-5 py-3">
                      <Badge tone={statusOf(q.status).tone}>{statusOf(q.status).label}</Badge>
                      {q.converted_invoice_number && <p className="mt-0.5 text-xs text-slate-500">{q.converted_invoice_number}</p>}
                    </td>
                    <td className="px-5 py-3 text-right font-semibold tabular-nums">{formatINR(q.grand_total)}</td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        {isOpen(q) && (
                          <Button size="sm" variant="ghost" icon={Pencil} onClick={() => open(q.id, setEditing)}>Edit</Button>
                        )}
                        {isOpen(q) && can('billing.create') && (
                          <Button size="sm" variant="secondary" icon={ReceiptText} onClick={() => open(q.id, setConverting)}>Bill</Button>
                        )}
                        {q.stored_status === 'draft' && (
                          <Button size="sm" variant="ghost" icon={Trash2} onClick={() => remove(q)} aria-label="Delete" />
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

      {editing && (
        <QuotationForm
          quotation={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(q) => {
            setEditing(null)
            setNotice({ tone: 'success', text: `Quotation ${q.number} saved.` })
            reload()
          }}
        />
      )}

      {viewing && (
        <PrintModal
          title={`Quotation · ${viewing.number}`}
          documentTitle={viewing.number}
          onClose={() => setViewing(null)}
          actions={
            isOpen(viewing) && (
              <select
                className={`${inputClass} w-auto py-1.5`}
                value={viewing.stored_status}
                onChange={(e) => changeStatus(viewing, e.target.value)}
                aria-label="Status"
              >
                {STATUSES.filter((s) => ['draft', 'sent', 'accepted', 'cancelled'].includes(s.value)).map((s) => (
                  <option key={s.value} value={s.value}>Mark {s.label.toLowerCase()}</option>
                ))}
              </select>
            )
          }
        >
          {(ref) => <QuotationSheet ref={ref} quotation={viewing} />}
        </PrintModal>
      )}

      {converting && (
        <BillDialog
          title={`Bill quotation ${converting.number}`}
          total={converting.grand_total}
          needsEmail={!converting.customer_email}
          defaultName={converting.customer_name}
          submit={(payload) => convertQuotation(converting.id, payload).then((r) => r.data)}
          onDone={reload}
          onClose={() => setConverting(null)}
        >
          <p className="text-sm text-slate-600">
            A bill for <b>{converting.customer_name}</b> with the quoted prices. Stock is taken from{' '}
            <b>{converting.store?.name}</b>.
          </p>
        </BillDialog>
      )}
    </>
  )
}

function QuotationSheet({ quotation: q, ref }) {
  const igst = q.is_interstate
  return (
    <DocumentSheet
      ref={ref}
      title="QUOTATION"
      store={q.store}
      party={{
        label: 'Quotation for',
        name: q.customer_name,
        lines: [q.customer_phone && `Ph: ${q.customer_phone}`, q.customer_email, q.customer_gstin && `GSTIN: ${q.customer_gstin}`],
      }}
      meta={[
        ['Quotation no', q.number],
        ['Date', formatDate(q.date)],
        ['Valid until', q.valid_until && formatDate(q.valid_until)],
        ['Supply', igst ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'],
      ]}
      columns={[
        { key: 'sr', label: '#', align: 'center' },
        { key: 'name', label: 'Item' },
        { key: 'hsn', label: 'HSN', align: 'center' },
        { key: 'qty', label: 'Qty', align: 'right' },
        { key: 'rate', label: 'Rate', align: 'right' },
        { key: 'gst', label: 'GST', align: 'right' },
        { key: 'taxable', label: 'Taxable', align: 'right' },
        { key: 'total', label: 'Amount', align: 'right' },
      ]}
      rows={q.items.map((item, i) => ({
        sr: i + 1,
        name: item.product_name,
        hsn: item.hsn_code ?? '-',
        qty: `${item.quantity} ${item.unit ?? ''}`,
        rate: money(item.unit_price),
        gst: `${Number(item.tax_percent)}%`,
        taxable: money(item.line_subtotal),
        total: money(item.line_total),
      }))}
      totals={[
        ['Taxable value', money(q.subtotal)],
        ...(igst ? [['IGST', money(q.igst_amount)]] : [['CGST', money(q.cgst_amount)], ['SGST', money(q.sgst_amount)]]),
        ['Total', `₹ ${money(q.grand_total)}`],
      ]}
      notes={q.notes}
      footer="Prices are valid until the date shown. This is a quotation, not a tax invoice."
      signatures={[`For ${q.store?.name ?? ''}`]}
    />
  )
}

function QuotationForm({ quotation, onClose, onSaved }) {
  const [products, setProducts] = useState([])
  const [party, setParty] = useState(() =>
    quotation
      ? {
          ...EMPTY_PARTY,
          customer_id: quotation.customer_id,
          customer_name: quotation.customer_name ?? '',
          customer_email: quotation.customer_email ?? '',
          customer_phone: quotation.customer_phone ?? '',
          customer_gstin: quotation.customer_gstin ?? '',
        }
      : EMPTY_PARTY,
  )
  const [date, setDate] = useState(quotation?.date ?? localDate())
  // Lazy initialiser: new quotations are valid for 15 days by default.
  const [validUntil, setValidUntil] = useState(() =>
    quotation ? (quotation.valid_until ?? '') : localDate(new Date(Date.now() + 15 * 86400000)),
  )
  const [interstate, setInterstate] = useState(quotation?.is_interstate ?? false)
  const [notes, setNotes] = useState(quotation?.notes ?? '')
  const [lines, setLines] = useState(() =>
    quotation
      ? quotation.items.map((item) => newLine({ productId: String(item.product_id), quantity: item.quantity, unitPrice: String(Number(item.unit_price)) }))
      : [newLine()],
  )
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    getDocumentProducts().then(setProducts).catch((e) => setError(parseApiError(e)))
  }, [])

  const productById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products])
  const totals = lineTotals(lines, productById, { interstate, withPrice: true })
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function save() {
    setSaving(true)
    setError(null)
    const payload = {
      ...partyPayload(party),
      date,
      valid_until: validUntil || null,
      is_interstate: interstate,
      notes: notes.trim() || null,
      items: linesPayload(lines, { withPrice: true }),
    }
    try {
      onSaved(quotation ? await updateQuotation(quotation.id, payload) : await createQuotation(payload))
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
      title={quotation ? `Edit quotation ${quotation.number}` : 'New quotation'}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button loading={saving} onClick={save}>Save quotation</Button>
        </>
      }
    >
      <div className="space-y-5">
        {error && <Alert>{error.message}</Alert>}
        <PartyFields party={party} setParty={setParty} fieldError={fieldError} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Date" error={fieldError('date')}>
            <input type="date" className={inputClass} value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
          <Field label="Valid until" error={fieldError('valid_until')}>
            <input type="date" className={inputClass} value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
          </Field>
        </div>

        <div>
          <p className="mb-1 text-xs font-medium text-slate-600">Products <span className="font-normal text-slate-400">(leave the price empty for the list price)</span></p>
          <LineItemsEditor
            products={products}
            productById={productById}
            lines={lines}
            setLines={setLines}
            withPrice
            totals={totals}
            fieldError={fieldError}
          />
        </div>

        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" className="h-4 w-4 rounded border-slate-300 accent-brand-600" checked={interstate} onChange={(e) => setInterstate(e.target.checked)} />
          Customer in another state (IGST)
        </label>

        <dl className="space-y-1 rounded-xl bg-slate-50 p-4 text-sm">
          <div className="flex justify-between text-slate-600"><dt>Subtotal</dt><dd className="tabular-nums">{formatINR(totals.subtotal, { cents: true })}</dd></div>
          {interstate ? (
            <div className="flex justify-between text-slate-600"><dt>IGST</dt><dd className="tabular-nums">{formatINR(totals.igst, { cents: true })}</dd></div>
          ) : (
            <>
              <div className="flex justify-between text-slate-600"><dt>CGST</dt><dd className="tabular-nums">{formatINR(totals.cgst, { cents: true })}</dd></div>
              <div className="flex justify-between text-slate-600"><dt>SGST</dt><dd className="tabular-nums">{formatINR(totals.sgst, { cents: true })}</dd></div>
            </>
          )}
          <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900">
            <dt>Total</dt><dd className="tabular-nums">{formatINR(totals.total, { cents: true })}</dd>
          </div>
        </dl>

        <Field label="Notes" error={fieldError('notes')}>
          <textarea rows={2} className={inputClass} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Delivery terms, payment terms…" />
        </Field>
      </div>
    </Modal>
  )
}
