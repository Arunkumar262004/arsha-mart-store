import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Pencil, Trash2, UserPlus, Users as UsersIcon } from 'lucide-react'
import { createUser, deleteUser, getRoles, getUsers, updateUser } from '../../api'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, EmptyState, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'

const lastLogin = (iso) => (iso ? new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : 'Never')

export default function Users() {
  const { user: me } = useAuth()
  const toast = useToast()
  const [users, setUsers] = useState(null)
  const [roles, setRoles] = useState([])
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(null)

  const load = useCallback(() => {
    Promise.all([getUsers(), getRoles()])
      .then(([u, r]) => {
        setUsers(u)
        setRoles(r)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [])

  useEffect(load, [load])

  async function remove(user) {
    if (!window.confirm(`Delete ${user.name}? They will be signed out immediately.`)) return
    try {
      await deleteUser(user.id)
      toast(`${user.name} deleted.`)
      load()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    }
  }

  return (
    <>
      <PageHeader
        title="Employees"
        description="Staff accounts. Each employee gets one role, which decides what they can see and do."
        actions={<Button icon={UserPlus} onClick={() => setEditing('new')} disabled={!roles.length}>Add employee</Button>}
      />

      {error && <Alert>{error}</Alert>}
      <Card padded={false}>
        {!users && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        {users?.length === 0 && <EmptyState icon={UsersIcon} title="No employees yet" />}
        {users?.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Employee</th>
                  <th className="px-5 py-3">Role</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Last sign-in</th>
                  <th className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.map((u) => (
                  <tr key={u.id} className="hover:bg-slate-50/60">
                    <td className="px-5 py-3">
                      <p className="font-medium text-slate-800">
                        {u.name} {u.id === me.id && <span className="text-xs font-normal text-slate-400">(you)</span>}
                      </p>
                      <p className="text-xs text-slate-500">{u.email}</p>
                    </td>
                    <td className="px-5 py-3"><Badge tone={u.role?.is_admin ? 'brand' : 'slate'}>{u.role?.name ?? 'None'}</Badge></td>
                    <td className="px-5 py-3">{u.is_active ? <Badge tone="green">Active</Badge> : <Badge tone="red">Deactivated</Badge>}</td>
                    <td className="px-5 py-3 text-slate-500">{lastLogin(u.last_login_at)}</td>
                    <td className="px-5 py-3">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(u)} aria-label={`Edit ${u.name}`} />
                        {u.id !== me.id && <Button variant="ghost" size="sm" icon={Trash2} onClick={() => remove(u)} aria-label={`Delete ${u.name}`} />}
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
        Need a new kind of access? <Link to="/settings/roles" className="font-medium text-brand-600">Create a role</Link> first, then assign it here.
      </p>

      {editing && (
        <UserModal
          user={editing === 'new' ? null : editing}
          roles={roles}
          isSelf={editing !== 'new' && editing.id === me.id}
          onClose={() => setEditing(null)}
          onSaved={(saved, isNew) => {
            setEditing(null)
            toast(isNew ? `${saved.name} can now sign in.` : `${saved.name} updated.`)
            load()
          }}
        />
      )}
    </>
  )
}

function UserModal({ user, roles, isSelf, onClose, onSaved }) {
  const isNew = user === null
  const [form, setForm] = useState({
    name: user?.name ?? '',
    email: user?.email ?? '',
    role_id: user?.role?.id ?? roles.find((r) => !r.is_admin)?.id ?? roles[0]?.id,
    is_active: user?.is_active ?? true,
    password: '',
    password_confirmation: '',
  })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    const body = { name: form.name, email: form.email, role_id: Number(form.role_id), is_active: form.is_active }
    try {
      const saved = isNew
        ? await createUser({ ...body, password: form.password, password_confirmation: form.password_confirmation })
        : await updateUser(user.id, body)
      onSaved(saved, isNew)
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      title={isNew ? 'Add employee' : `Edit ${user.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="user-form" loading={saving}>{isNew ? 'Create employee' : 'Save changes'}</Button>
        </>
      }
    >
      <form id="user-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2" noValidate>
        {error && !Object.keys(error.errors).length && <div className="sm:col-span-2"><Alert>{error.message}</Alert></div>}
        <Field label="Full name" error={fieldError('name')}>
          <input className={inputClass} value={form.name} onChange={set('name')} autoFocus />
        </Field>
        <Field label="Email (sign-in)" error={fieldError('email')}>
          <input type="email" className={inputClass} value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Role" error={fieldError('role_id')} hint={isSelf ? 'You cannot change your own role.' : null} className="sm:col-span-2">
          <select className={inputClass} value={form.role_id} onChange={set('role_id')} disabled={isSelf}>
            {roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
                {r.description ? ` · ${r.description}` : ''}
              </option>
            ))}
          </select>
        </Field>
        {isNew ? (
          <>
            <Field label="Password" error={fieldError('password')} hint="8+ characters with letters and numbers.">
              <input type="password" autoComplete="new-password" className={inputClass} value={form.password} onChange={set('password')} />
            </Field>
            <Field label="Confirm password">
              <input type="password" autoComplete="new-password" className={inputClass} value={form.password_confirmation} onChange={set('password_confirmation')} />
            </Field>
          </>
        ) : (
          <label className="flex items-center gap-2 text-sm sm:col-span-2">
            <input type="checkbox" checked={form.is_active} onChange={set('is_active')} disabled={isSelf} className="h-4 w-4 accent-brand-600" />
            Account active
            {fieldError('is_active') && <span className="text-xs text-red-600">{fieldError('is_active')}</span>}
            {!form.is_active && <span className="text-xs text-slate-500">(they'll be signed out and can't sign in)</span>}
          </label>
        )}
      </form>
    </Modal>
  )
}
