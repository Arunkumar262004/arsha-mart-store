import { useCallback, useEffect, useState } from 'react'
import { Pencil, Plus, Power, Store as StoreIcon, Trash2 } from 'lucide-react'
import { createStore, deleteStore, getAllStores, updateStore } from '../../api'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import { GST_STATES, stateCode } from '../../lib/states'
import { useConfirm } from '../../components/ConfirmDialog'

const FIELDS = ['name', 'code', 'gstin', 'phone', 'email', 'address', 'city', 'state', 'state_code', 'pincode']

export default function Stores() {
  const confirm = useConfirm()
  const { refreshStores } = useAuth()
  const toast = useToast()
  const [stores, setStores] = useState(null)
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(null) // null | 'new' | store
  const [busyId, setBusyId] = useState(null)

  const load = useCallback(() => {
    getAllStores()
      .then(setStores)
      .catch((e) => setError(parseApiError(e).message))
  }, [])

  useEffect(load, [load])

  // After any change the header's store list must follow.
  const changed = useCallback(() => {
    load()
    refreshStores().catch(() => {})
  }, [load, refreshStores])

  async function toggleActive(store) {
    const verb = store.is_active ? 'Deactivate' : 'Activate'
    if (store.is_active && !(await confirm({ title: `Deactivate ${store.name}?`, message: 'Nobody can bill in it until it is activated again.', confirmLabel: 'Deactivate' }))) return
    setBusyId(store.id)
    try {
      // Fields left out are not changed; name and code are required.
      await updateStore(store.id, { name: store.name, code: store.code, is_active: !store.is_active })
      toast(`${store.name} ${store.is_active ? 'deactivated' : 'activated'}.`)
      changed()
    } catch (e) {
      const { message, errors } = parseApiError(e)
      toast(errors.is_active?.[0] ?? message ?? `${verb} failed.`, 'error')
    } finally {
      setBusyId(null)
    }
  }

  async function remove(store) {
    if (!(await confirm({ title: `Delete ${store.name}?`, message: 'This cannot be undone.', confirmLabel: 'Delete store' }))) return
    setBusyId(store.id)
    try {
      await deleteStore(store.id)
      toast(`${store.name} deleted.`)
      changed()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <>
      <PageHeader
        title="Stores"
        description="Branches, their GSTIN and address. Bills, stock and accounts are kept per store."
        actions={<Button icon={Plus} onClick={() => setEditing('new')}>Add store</Button>}
      />

      {error && <Alert>{error}</Alert>}
      <Card padded={false}>
        {!stores && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {stores?.length === 0 && <EmptyState icon={StoreIcon} title="No stores yet" />}
        {stores?.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Store</th>
                  <th className="px-5 py-3">GSTIN</th>
                  <th className="px-5 py-3">City / State</th>
                  <th className="px-5 py-3 text-right">Employees</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {stores.map((s, i) => (
                  <tr key={s.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <p className="font-medium text-slate-800">
                        {s.name} {i === 0 && <span className="text-xs font-normal text-slate-400">(main)</span>}
                      </p>
                      <p className="font-mono text-xs text-slate-500">{s.code}</p>
                    </td>
                    <td className="px-5 py-3 font-mono text-xs text-slate-600">{s.gstin || <span className="font-sans text-slate-400">Not set</span>}</td>
                    <td className="px-5 py-3 text-slate-600">
                      {[s.city, s.state].filter(Boolean).join(', ') || <span className="text-slate-400">-</span>}
                      {s.state_code && <span className="ml-1 text-xs text-slate-400">({s.state_code})</span>}
                    </td>
                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{s.users_count ?? 0}</td>
                    <td className="px-5 py-3">{s.is_active ? <Badge tone="green">Active</Badge> : <Badge tone="red">Inactive</Badge>}</td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(s)} aria-label={`Edit ${s.name}`} title="Edit" />
                        {/* The first store is the main store: it is always active and cannot be deleted. */}
                        {i !== 0 && (
                          <>
                            <Button
                              variant="ghost"
                              size="sm"
                              icon={Power}
                              loading={busyId === s.id}
                              onClick={() => toggleActive(s)}
                              aria-label={`${s.is_active ? 'Deactivate' : 'Activate'} ${s.name}`}
                              title={s.is_active ? 'Deactivate' : 'Activate'}
                            />
                            <Button
                              variant="ghost"
                              size="sm"
                              icon={Trash2}
                              disabled={busyId === s.id}
                              onClick={() => remove(s)}
                              aria-label={`Delete ${s.name}`}
                              title="Delete"
                            />
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <p className="mt-3 text-xs text-slate-500">
        A store with bills, stock or accounts entries cannot be deleted; deactivate it instead. Assign employees to a store on the Employees page.
      </p>

      {editing && (
        <StoreModal
          store={editing === 'new' ? null : editing}
          isMain={editing !== 'new' && stores?.[0]?.id === editing.id}
          onClose={() => setEditing(null)}
          onSaved={(saved, isNew) => {
            setEditing(null)
            toast(isNew ? `${saved.name} added.` : `${saved.name} updated.`)
            changed()
          }}
        />
      )}
    </>
  )
}

/** The editable fields of a store, with nulls as empty strings for inputs. */
function pick(store) {
  return Object.fromEntries(FIELDS.map((f) => [f, store?.[f] ?? '']))
}

function StoreModal({ store, isMain, onClose, onSaved }) {
  const isNew = store === null
  const [form, setForm] = useState(() => ({ ...pick(store), is_active: store?.is_active ?? true }))
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  // Picking a state fills in its GST code; a typed GSTIN also implies the state.
  const setState = (e) => {
    const name = e.target.value
    setForm((f) => ({ ...f, state: name, state_code: stateCode(name) ?? f.state_code }))
  }
  const setGstin = (e) => {
    const gstin = e.target.value.toUpperCase()
    setForm((f) => {
      const code = /^\d{2}/.exec(gstin)?.[0]
      const known = code && GST_STATES.find((s) => s.code === code)
      return known && !f.state ? { ...f, gstin, state: known.name, state_code: code } : { ...f, gstin }
    })
  }

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    // Empty optional fields go as null so the API clears them.
    const body = Object.fromEntries(FIELDS.map((f) => [f, form[f].trim() === '' ? null : form[f].trim()]))
    body.is_active = form.is_active
    try {
      const saved = isNew ? await createStore(body) : await updateStore(store.id, body)
      onSaved(saved, isNew)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  const stateKnown = GST_STATES.some((s) => s.name === form.state)

  return (
    <Modal
      open
      size="lg"
      title={isNew ? 'Add store' : `Edit ${store.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="store-form" loading={saving}>{isNew ? 'Create store' : 'Save changes'}</Button>
        </>
      }
    >
      <form id="store-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        {error && !Object.keys(error.errors).length && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
        <Field label="Store name" error={fieldError('name')}>
          <input className={inputClass} value={form.name} onChange={set('name')} autoFocus placeholder="Arsha Mart - Anna Nagar" />
        </Field>
        <Field label="Code" error={fieldError('code')} hint="Short, used in invoice numbers, e.g. MAIN or BR2.">
          <input
            className={`${inputClass} font-mono uppercase`}
            value={form.code}
            maxLength={10}
            onChange={(e) => setForm((f) => ({ ...f, code: e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') }))}
          />
        </Field>
        <Field label="GSTIN" error={fieldError('gstin')} hint="Optional. 15 characters, e.g. 33ABCDE1234F1Z5." className="sm:col-span-2">
          <input className={`${inputClass} font-mono uppercase`} value={form.gstin} maxLength={15} onChange={setGstin} />
        </Field>
        <Field label="Address" error={fieldError('address')} className="sm:col-span-2">
          <input className={inputClass} value={form.address} onChange={set('address')} placeholder="Door no, street, area" />
        </Field>
        <Field label="City" error={fieldError('city')}>
          <input className={inputClass} value={form.city} onChange={set('city')} />
        </Field>
        <Field label="Pincode" error={fieldError('pincode')}>
          <input className={inputClass} value={form.pincode} onChange={set('pincode')} inputMode="numeric" maxLength={10} />
        </Field>
        <Field label="State" error={fieldError('state')}>
          <select className={inputClass} value={form.state} onChange={setState}>
            <option value="">Select state</option>
            {/* Keep an older free-text value selectable. */}
            {form.state && !stateKnown && <option value={form.state}>{form.state}</option>}
            {GST_STATES.map((s) => (
              <option key={s.code} value={s.name}>{s.name}</option>
            ))}
          </select>
        </Field>
        <Field label="GST state code" error={fieldError('state_code')} hint="Filled in from the state.">
          <input
            className={`${inputClass} font-mono`}
            value={form.state_code}
            maxLength={2}
            inputMode="numeric"
            onChange={(e) => setForm((f) => ({ ...f, state_code: e.target.value.replace(/\D/g, '') }))}
          />
        </Field>
        <Field label="Phone" error={fieldError('phone')}>
          <input className={inputClass} value={form.phone} onChange={set('phone')} inputMode="tel" />
        </Field>
        <Field label="Email" error={fieldError('email')}>
          <input type="email" className={inputClass} value={form.email} onChange={set('email')} />
        </Field>
        <label className="flex items-center gap-2 text-sm sm:col-span-2">
          <input type="checkbox" checked={form.is_active} onChange={set('is_active')} disabled={isMain} className="h-4 w-4 accent-brand-600" />
          Store active
          {isMain && <span className="text-xs text-slate-500">(the main store is always active)</span>}
          {fieldError('is_active') && <span className="text-xs text-red-600">{fieldError('is_active')}</span>}
        </label>
      </form>
    </Modal>
  )
}
