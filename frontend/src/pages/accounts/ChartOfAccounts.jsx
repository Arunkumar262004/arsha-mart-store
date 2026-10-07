import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BookOpen, ListTree, Lock, Pencil, Plus, Search, Trash2 } from 'lucide-react'
import { createAccount, deleteAccount, getAccounts, updateAccount } from '../../api/accounts'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import { drCr, headRowClass, money, tdClass, thClass } from '../../components/accounts/format'
import useLoad from '../../components/accounts/useLoad'
import { useConfirm } from '../../components/ConfirmDialog'

const TYPES = [
  ['', 'All types'],
  ['asset', 'Assets'],
  ['liability', 'Liabilities'],
  ['equity', 'Capital'],
  ['income', 'Income'],
  ['expense', 'Expenses'],
]

export default function ChartOfAccounts() {
  const confirm = useConfirm()
  const { can } = useAuth()
  const toast = useToast()
  const canManage = can('accounts.manage')
  const [filters, setFilters] = useState({ type: '', search: '', include_parties: false })
  const [editing, setEditing] = useState(null) // null | 'new' | account
  const [busyId, setBusyId] = useState(null)

  const params = { ...filters, include_parties: filters.include_parties ? 1 : 0 }
  const { data: result, loading, error, reload } = useLoad(() => getAccounts(params), JSON.stringify(params))
  const groups = result?.meta?.groups ?? []

  async function remove(account) {
    if (!(await confirm({ title: `Delete ${account.name}?`, message: 'This cannot be undone.', confirmLabel: 'Delete account' }))) return
    setBusyId(account.id)
    try {
      await deleteAccount(account.id)
      toast(`${account.name} deleted.`)
      reload()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Chart of Accounts"
        description={`Every ledger by group, with its balance${result?.meta?.all_stores ? ' across all stores (opening balances included)' : ' in this store'}.`}
        actions={canManage && <Button icon={Plus} onClick={() => setEditing('new')}>Add account</Button>}
      />

      <div className="grid gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:grid-cols-3">
        <Field label="Type">
          <select className={inputClass} value={filters.type} onChange={(e) => setFilters((f) => ({ ...f, type: e.target.value }))}>
            {TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </Field>
        <Field label="Search">
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
            <input className={`${inputClass} pl-9`} placeholder="Name or code" value={filters.search} onChange={(e) => setFilters((f) => ({ ...f, search: e.target.value }))} />
          </div>
        </Field>
        <label className="flex items-end gap-2 pb-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 rounded border-slate-300" checked={filters.include_parties} onChange={(e) => setFilters((f) => ({ ...f, include_parties: e.target.checked }))} />
          Show customer & supplier ledgers {result && <span className="text-slate-400">({result.meta.party_ledgers})</span>}
        </label>
      </div>

      {error && <Alert>{error}</Alert>}

      <Card padded={false}>
        {!result && loading && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {result?.data.length === 0 && <EmptyState icon={ListTree} title="No accounts match" />}
        {result?.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr className={headRowClass}>
                  <th className={thClass}>Account</th>
                  <th className={`${thClass} text-right`}>Opening balance</th>
                  <th className={`${thClass} text-right`}>Current balance</th>
                  <th className={`${thClass} text-right`}>Actions</th>
                </tr>
              </thead>
              {result.data.map((type) => (
                <tbody key={type.type} className="divide-y divide-slate-100">
                  <tr className="bg-brand-50/60">
                    <td colSpan={4} className={`${tdClass} text-xs font-semibold uppercase tracking-wide text-brand-800`}>{type.label}</td>
                  </tr>
                  {type.groups.map((group) => (
                    <GroupRows key={group.group} group={group} canManage={canManage} busyId={busyId} onEdit={setEditing} onDelete={remove} />
                  ))}
                </tbody>
              ))}
            </table>
          </div>
        )}
      </Card>
      <p className="text-xs text-slate-500">
        Built-in accounts are used by billing, purchases and GST; only their opening balance can change. Customer and supplier ledgers are
        created automatically and edited from their party. An account with entries cannot be deleted — deactivate it instead.
      </p>

      {editing && (
        <AccountModal
          account={editing === 'new' ? null : editing}
          groups={groups}
          onClose={() => setEditing(null)}
          onSaved={(saved, isNew) => {
            toast(`${saved.name} ${isNew ? 'added' : 'saved'}.`)
            setEditing(null)
            reload()
          }}
        />
      )}
    </div>
  )
}

function GroupRows({ group, canManage, busyId, onEdit, onDelete }) {
  const total = Number(group.balance)
  return (
    <>
      <tr className="bg-slate-50/70">
        <td className={`${tdClass} pl-6 font-medium text-slate-700`}>{group.label}</td>
        <td />
        <td className={`${tdClass} text-right font-medium tabular-nums text-slate-700`}>{drCr(Math.abs(total), total > 0 ? 'Dr' : total < 0 ? 'Cr' : null)}</td>
        <td />
      </tr>
      {group.accounts.map((a) => (
        <tr key={a.id} className={`hover:bg-slate-50/60 ${a.is_active ? '' : 'opacity-60'}`}>
          <td className={`${tdClass} pl-10`}>
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-xs text-slate-500">{a.code}</span>
              <span className="font-medium text-slate-800">{a.name}</span>
              {a.is_system && <Badge><Lock size={10} aria-hidden /> Built-in</Badge>}
              {a.is_party && <Badge tone="brand">{a.party_type ?? 'party'}</Badge>}
              {!a.is_active && <Badge tone="red">Inactive</Badge>}
            </div>
          </td>
          <td className={`${tdClass} text-right tabular-nums text-slate-600`}>
            {Number(a.opening_balance) === 0 ? '—' : `${money(a.opening_balance)} ${a.opening_side === 'cr' ? 'Cr' : 'Dr'}`}
          </td>
          <td className={`${tdClass} text-right font-medium tabular-nums text-slate-800`}>{drCr(a.balance, a.balance_side)}</td>
          <td className={tdClass}>
            <div className="flex justify-end gap-1">
              <Link to={`/accounts/ledger?account=${a.id}`} className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800" title="Open ledger" aria-label={`Ledger of ${a.name}`}>
                <BookOpen size={14} />
              </Link>
              {canManage && <Button variant="ghost" size="sm" icon={Pencil} onClick={() => onEdit(a)} title="Edit" aria-label={`Edit ${a.name}`} />}
              {canManage && a.can_delete && (
                <Button variant="ghost" size="sm" icon={Trash2} disabled={busyId === a.id} onClick={() => onDelete(a)} title="Delete" aria-label={`Delete ${a.name}`} />
              )}
            </div>
          </td>
        </tr>
      ))}
    </>
  )
}

function AccountModal({ account, groups, onClose, onSaved }) {
  const isNew = !account
  const limited = account && (account.is_system || account.is_party)
  const [form, setForm] = useState({
    name: account?.name ?? '',
    code: account?.code ?? '',
    group: account?.group ?? 'indirect_expense',
    opening_balance: account ? account.opening_balance : '',
    opening_side: account?.opening_side ?? 'dr',
    is_active: account?.is_active ?? true,
  })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    setMessage(null)
    const payload = limited
      ? { opening_balance: form.opening_balance || 0, opening_side: form.opening_side }
      : { ...form, code: form.code || null, opening_balance: form.opening_balance || 0 }
    try {
      const saved = isNew ? await createAccount(payload) : await updateAccount(account.id, payload)
      onSaved(saved, isNew)
    } catch (err) {
      const { message: msg, errors: errs } = parseApiError(err)
      setErrors(errs)
      setMessage(msg)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={isNew ? 'Add account' : `Edit ${account.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="account-form" loading={saving}>Save</Button>
        </>
      }
    >
      <form id="account-form" onSubmit={save} className="space-y-4">
        {message && Object.keys(errors).length === 0 && <Alert>{message}</Alert>}
        {limited && (
          <Alert tone="info">
            {account.is_system ? 'This built-in account is used by the software:' : 'This ledger belongs to a customer or supplier:'} only its opening balance can be changed here.
          </Alert>
        )}
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Name" error={errors.name?.[0]} className="sm:col-span-2">
            <input className={inputClass} value={form.name} onChange={set('name')} disabled={limited} required={!limited} autoFocus={!limited} />
          </Field>
          <Field label="Code" error={errors.code?.[0]} hint={isNew ? 'Blank = next free number' : undefined}>
            <input className={`${inputClass} font-mono uppercase`} value={form.code} onChange={set('code')} disabled={limited} />
          </Field>
        </div>
        <Field label="Group" error={errors.group?.[0]} hint="Decides where the account appears in the P&L and balance sheet.">
          <select className={inputClass} value={form.group} onChange={set('group')} disabled={limited}>
            {['asset', 'liability', 'equity', 'income', 'expense'].map((type) => (
              <optgroup key={type} label={type[0].toUpperCase() + type.slice(1)}>
                {groups.filter((g) => g.type === type).map((g) => <option key={g.group} value={g.group}>{g.label}</option>)}
              </optgroup>
            ))}
          </select>
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Opening balance (₹)" error={errors.opening_balance?.[0]} hint="Balance before the first entry, for the whole business.">
            <input type="number" min="0" step="0.01" className={inputClass} value={form.opening_balance} onChange={set('opening_balance')} />
          </Field>
          <Field label="Side" error={errors.opening_side?.[0]}>
            <select className={inputClass} value={form.opening_side} onChange={set('opening_side')}>
              <option value="dr">Debit (Dr)</option>
              <option value="cr">Credit (Cr)</option>
            </select>
          </Field>
        </div>
        {!limited && !isNew && (
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="size-4 rounded border-slate-300" checked={form.is_active} onChange={set('is_active')} />
            Active (inactive accounts can't be used in new entries)
          </label>
        )}
        {errors.is_active && <p className="text-xs text-red-600">{errors.is_active[0]}</p>}
      </form>
    </Modal>
  )
}
