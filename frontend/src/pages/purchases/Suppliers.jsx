import { useCallback, useEffect, useState } from 'react'
import { Building2, Eye, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { createSupplier, deleteSupplier, getSupplier, getSuppliers, updateSupplier } from '../../api/purchasing'
import { parseApiError } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, StatTile, inputClass } from '../../components/ui'
import { formatINR, toCents } from '../../lib/money'
import { MODE_LABELS, formatDate } from '../../components/purchasing/helpers'
import { useConfirm } from '../../components/ConfirmDialog'

export default function Suppliers() {
  const confirm = useConfirm()
  const toast = useToast()
  const [suppliers, setSuppliers] = useState(null)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(null) // supplier | 'new' | null
  const [viewing, setViewing] = useState(null)

  const load = useCallback(() => {
    getSuppliers({ search: query })
      .then((res) => {
        setSuppliers(res.data)
        setError(null)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [query])

  useEffect(load, [load])

  // Search as you type, a moment after the last key.
  useEffect(() => {
    const t = setTimeout(() => setQuery(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const totalOwed = (suppliers ?? []).reduce((sum, s) => sum + Math.max(toCents(s.outstanding), 0), 0)

  async function remove(supplier) {
    if (!(await confirm({ title: `Delete ${supplier.name}?`, message: 'Suppliers with purchases or payments can only be deactivated.', confirmLabel: 'Delete supplier' }))) return
    try {
      await deleteSupplier(supplier.id)
      toast(`${supplier.name} deleted.`)
      load()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    }
  }

  return (
    <>
      <PageHeader
        title="Suppliers"
        description="Who you buy from and what you owe each of them (all stores)."
        actions={<Button icon={Plus} onClick={() => setEditing('new')}>Add supplier</Button>}
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2">
        <StatTile label="Suppliers" value={suppliers ? suppliers.length : '-'} icon={Building2} />
        <StatTile label="Total payable" value={formatINR(totalOwed, { cents: true })} sub="What you owe suppliers" tone="amber" />
      </div>

      <Card padded={false}>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="relative w-full sm:w-72">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-9`} placeholder="Search name, GSTIN, phone" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>

        {error && <div className="p-4"><Alert>{error}</Alert></div>}
        {!suppliers && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {suppliers && suppliers.length === 0 && <EmptyState icon={Building2} title="No suppliers yet">Add the vendors you buy stock from.</EmptyState>}

        {suppliers && suppliers.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Supplier</th>
                  <th className="px-5 py-3">GSTIN</th>
                  <th className="px-5 py-3">Contact</th>
                  <th className="px-5 py-3 text-right">Terms</th>
                  <th className="px-5 py-3 text-right">Outstanding</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {suppliers.map((s) => (
                  <tr key={s.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <p className="font-medium text-slate-800">
                        {s.name} {!s.is_active && <Badge>Inactive</Badge>}
                      </p>
                      <p className="text-xs text-slate-500">{[s.city, s.state].filter(Boolean).join(', ') || '-'}</p>
                    </td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-600">{s.gstin || <span className="font-sans text-slate-400">Unregistered</span>}</td>
                    <td className="px-5 py-3 text-slate-600">
                      {s.contact_person && <p>{s.contact_person}</p>}
                      <p className="text-xs text-slate-500">{s.phone || '-'}</p>
                    </td>
                    <td className="px-5 py-3 text-right text-slate-500">{s.payment_terms_days ? `${s.payment_terms_days} days` : 'Immediate'}</td>
                    <td className="px-5 py-3 text-right"><Outstanding amount={s.outstanding} /></td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="sm" icon={Eye} onClick={() => setViewing(s)} aria-label={`View ${s.name}`} title="Details" />
                        <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(s)} aria-label={`Edit ${s.name}`} />
                        <Button variant="ghost" size="sm" icon={Trash2} onClick={() => remove(s)} aria-label={`Delete ${s.name}`} />
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
        <SupplierModal
          supplier={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={(s, isNew) => {
            setEditing(null)
            toast(isNew ? `${s.name} added.` : `${s.name} updated.`)
            load()
          }}
        />
      )}
      {viewing && <SupplierDrawer supplier={viewing} onClose={() => setViewing(null)} />}
    </>
  )
}

/** Positive = we owe the supplier; negative = they owe us (advance / returns). */
function Outstanding({ amount }) {
  const cents = toCents(amount)
  if (cents === 0) return <span className="text-slate-400">Settled</span>
  if (cents < 0) return <span className="font-medium tabular-nums text-emerald-700" title="Advance paid / credit with supplier">{formatINR(-cents, { cents: true })} Dr</span>
  return <span className="font-medium tabular-nums text-slate-900">{formatINR(cents, { cents: true })}</span>
}

function SupplierModal({ supplier, onClose, onSaved }) {
  const isNew = supplier === null
  const [form, setForm] = useState({
    name: supplier?.name ?? '',
    gstin: supplier?.gstin ?? '',
    contact_person: supplier?.contact_person ?? '',
    phone: supplier?.phone ?? '',
    email: supplier?.email ?? '',
    address: supplier?.address ?? '',
    city: supplier?.city ?? '',
    state: supplier?.state ?? '',
    state_code: supplier?.state_code ?? '',
    payment_terms_days: supplier?.payment_terms_days ?? 0,
    is_active: supplier?.is_active ?? true,
    opening_balance: supplier?.opening_balance && Number(supplier.opening_balance) !== 0 ? supplier.opening_balance : '',
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
      // Empty optional fields go as null.
      const payload = Object.fromEntries(
        Object.entries(form).map(([k, v]) => [k, typeof v === 'string' && v.trim() === '' ? null : typeof v === 'string' ? v.trim() : v]),
      )
      payload.payment_terms_days = Number(form.payment_terms_days) || 0
      if (!isNew && form.opening_balance === '') payload.opening_balance = 0
      const saved = isNew ? await createSupplier(payload) : await updateSupplier(supplier.id, payload)
      onSaved(saved, isNew)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      size="lg"
      title={isNew ? 'Add supplier' : `Edit ${supplier.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="supplier-form" loading={saving}>{isNew ? 'Add supplier' : 'Save changes'}</Button>
        </>
      }
    >
      <form id="supplier-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        {error && !Object.keys(error.errors).length && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
        <Field label="Supplier name" error={fieldError('name')} className="sm:col-span-2">
          <input className={inputClass} value={form.name} onChange={set('name')} autoFocus />
        </Field>
        <Field label="GSTIN" error={fieldError('gstin')} hint="Optional. The state code is taken from it.">
          <input className={`${inputClass} font-mono uppercase`} maxLength={15} value={form.gstin} onChange={set('gstin')} />
        </Field>
        <Field label="Contact person" error={fieldError('contact_person')}>
          <input className={inputClass} value={form.contact_person} onChange={set('contact_person')} />
        </Field>
        <Field label="Phone" error={fieldError('phone')}>
          <input className={inputClass} value={form.phone} onChange={set('phone')} inputMode="tel" />
        </Field>
        <Field label="Email" error={fieldError('email')}>
          <input type="email" className={inputClass} value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Address" error={fieldError('address')} className="sm:col-span-2">
          <input className={inputClass} value={form.address} onChange={set('address')} />
        </Field>
        <Field label="City" error={fieldError('city')}>
          <input className={inputClass} value={form.city} onChange={set('city')} />
        </Field>
        <div className="grid grid-cols-3 gap-2">
          <Field label="State" error={fieldError('state')} className="col-span-2">
            <input className={inputClass} value={form.state} onChange={set('state')} />
          </Field>
          <Field label="Code" error={fieldError('state_code')}>
            <input className={`${inputClass} font-mono`} maxLength={2} inputMode="numeric" value={form.state_code} onChange={set('state_code')} placeholder="33" />
          </Field>
        </div>
        <Field label="Payment terms (days)" error={fieldError('payment_terms_days')} hint="0 = pay on delivery.">
          <input type="number" min="0" className={inputClass} value={form.payment_terms_days} onChange={set('payment_terms_days')} />
        </Field>
        <Field label="Opening balance (₹ you owe)" error={fieldError('opening_balance')} hint="Amount already owed before using this app. Negative if they owe you.">
          <input type="number" step="0.01" className={inputClass} value={form.opening_balance} onChange={set('opening_balance')} />
        </Field>
        {!isNew && (
          <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
            Active (inactive suppliers cannot be chosen on new purchases)
          </label>
        )}
      </form>
    </Modal>
  )
}

/** Supplier details with balance and recent purchases / payments. */
function SupplierDrawer({ supplier, onClose }) {
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    getSupplier(supplier.id)
      .then((res) => !cancelled && setResult(res))
      .catch((e) => !cancelled && setError(parseApiError(e).message))
    return () => {
      cancelled = true
    }
  }, [supplier.id])

  const s = result?.data ?? supplier

  return (
    <Modal open size="lg" title={s.name} onClose={onClose}>
      {error && <Alert>{error}</Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <dl className="space-y-1 text-sm">
          <Row label="GSTIN" value={s.gstin} mono />
          <Row label="Contact" value={[s.contact_person, s.phone, s.email].filter(Boolean).join(' · ')} />
          <Row label="Address" value={[s.address, s.city, s.state].filter(Boolean).join(', ')} />
          <Row label="Terms" value={s.payment_terms_days ? `${s.payment_terms_days} days` : 'Immediate'} />
        </dl>
        <div className="rounded-xl bg-slate-50 p-4 text-sm">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Outstanding</p>
          <p className="mt-1 text-2xl font-semibold"><Outstanding amount={s.outstanding} /></p>
          {s.opening_balance && Number(s.opening_balance) !== 0 && (
            <p className="mt-1 text-xs text-slate-500">Opening balance {formatINR(s.opening_balance)}</p>
          )}
        </div>
      </div>

      {!result && !error && <div className="grid place-items-center py-8 text-slate-400"><Spinner /></div>}
      {result && (
        <div className="mt-6 grid gap-6 lg:grid-cols-2">
          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-800">Recent purchases</h3>
            {result.recent_purchases.length === 0 ? (
              <p className="text-sm text-slate-400">None yet.</p>
            ) : (
              <ul className="divide-y divide-slate-100 text-sm">
                {result.recent_purchases.map((p) => (
                  <li key={p.id} className="flex justify-between gap-2 py-2">
                    <span>
                      <span className="font-mono text-xs">{p.number}</span>
                      <span className="block text-xs text-slate-500">
                        {formatDate(p.date)} · {MODE_LABELS[p.payment_mode]}{p.status === 'cancelled' && ' · cancelled'}
                      </span>
                    </span>
                    <span className={`tabular-nums ${p.status === 'cancelled' ? 'text-slate-400 line-through' : ''}`}>{formatINR(p.grand_total)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-800">Recent payments</h3>
            {result.recent_payments.length === 0 ? (
              <p className="text-sm text-slate-400">None yet.</p>
            ) : (
              <ul className="divide-y divide-slate-100 text-sm">
                {result.recent_payments.map((p) => (
                  <li key={p.id} className="flex justify-between gap-2 py-2">
                    <span>
                      <span className="font-mono text-xs">{p.number}</span>
                      <span className="block text-xs text-slate-500">{formatDate(p.date)} · {MODE_LABELS[p.mode]}{p.status === 'cancelled' && ' · cancelled'}</span>
                    </span>
                    <span className={`tabular-nums ${p.status === 'cancelled' ? 'text-slate-400 line-through' : ''}`}>{formatINR(p.total)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}

function Row({ label, value, mono = false }) {
  return (
    <div className="flex gap-3">
      <dt className="w-20 shrink-0 text-slate-500">{label}</dt>
      <dd className={`text-slate-800 ${mono ? 'font-mono text-xs leading-5' : ''}`}>{value || '-'}</dd>
    </div>
  )
}
