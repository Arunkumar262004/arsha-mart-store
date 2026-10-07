import { useCallback, useEffect, useState } from 'react'
import { Lock, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import { createRole, deleteRole, getPermissionCatalog, getRoles, updateRole } from '../../api'
import { parseApiError } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'
import { useConfirm } from '../../components/ConfirmDialog'

export default function Roles() {
  const confirm = useConfirm()
  const toast = useToast()
  const [roles, setRoles] = useState(null)
  const [catalog, setCatalog] = useState([])
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(null)

  const load = useCallback(() => {
    Promise.all([getRoles(), getPermissionCatalog()])
      .then(([r, c]) => {
        setRoles(r)
        setCatalog(c)
      })
      .catch((e) => setError(parseApiError(e).message))
  }, [])

  useEffect(load, [load])

  const labelFor = (key) => catalog.flatMap((g) => g.permissions).find((p) => p.key === key)?.label ?? key

  async function remove(role) {
    if (!(await confirm({ title: `Delete the "${role.name}" role?`, message: 'This cannot be undone.', confirmLabel: 'Delete role' }))) return
    try {
      await deleteRole(role.id)
      toast(`Role "${role.name}" deleted.`)
      load()
    } catch (e) {
      toast(parseApiError(e).message, 'error')
    }
  }

  return (
    <>
      <PageHeader
        title="Roles & permissions"
        description="Create roles, choose what each can do, then assign them to employees."
        actions={<Button icon={Plus} onClick={() => setEditing('new')} disabled={!catalog.length}>New role</Button>}
      />

      {error && <Alert>{error}</Alert>}
      {!roles && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {roles?.map((role) => (
          <Card key={role.id} className="flex flex-col">
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className={`rounded-xl p-2.5 ${role.is_admin ? 'bg-brand-50 text-brand-600' : 'bg-slate-100 text-slate-500'}`}>
                  {role.is_admin ? <Lock size={18} /> : <ShieldCheck size={18} />}
                </div>
                <div>
                  <h3 className="font-semibold text-slate-900">{role.name}</h3>
                  <p className="text-xs text-slate-500">
                    {role.users_count} employee{role.users_count === 1 ? '' : 's'}
                  </p>
                </div>
              </div>
              {!role.is_admin && (
                <div className="flex gap-1">
                  <Button variant="ghost" size="sm" icon={Pencil} onClick={() => setEditing(role)} aria-label={`Edit ${role.name}`} />
                  <Button variant="ghost" size="sm" icon={Trash2} onClick={() => remove(role)} aria-label={`Delete ${role.name}`} disabled={role.users_count > 0} title={role.users_count > 0 ? 'Reassign its employees first' : undefined} />
                </div>
              )}
            </div>
            {role.description && <p className="mt-3 text-sm text-slate-600">{role.description}</p>}
            <div className="mt-4 flex flex-wrap gap-1.5">
              {role.is_admin ? (
                <Badge tone="brand">Full access + Settings</Badge>
              ) : role.permissions.length === 0 ? (
                <span className="text-xs text-slate-400">No permissions: profile only</span>
              ) : (
                role.permissions.map((p) => <Badge key={p}>{labelFor(p)}</Badge>)
              )}
            </div>
          </Card>
        ))}
      </div>

      {editing && (
        <RoleModal
          role={editing === 'new' ? null : editing}
          catalog={catalog}
          onClose={() => setEditing(null)}
          onSaved={(saved, isNew) => {
            setEditing(null)
            toast(isNew ? `Role "${saved.name}" created.` : `Role "${saved.name}" updated.`)
            load()
          }}
        />
      )}
    </>
  )
}

function RoleModal({ role, catalog, onClose, onSaved }) {
  const isNew = role === null
  const [name, setName] = useState(role?.name ?? '')
  const [description, setDescription] = useState(role?.description ?? '')
  const [permissions, setPermissions] = useState(new Set(role?.permissions ?? []))
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  const toggle = (key) =>
    setPermissions((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    const body = { name, description: description || null, permissions: [...permissions] }
    try {
      const saved = isNew ? await createRole(body) : await updateRole(role.id, body)
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
      title={isNew ? 'New role' : `Edit ${role.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="role-form" loading={saving}>{isNew ? 'Create role' : 'Save changes'}</Button>
        </>
      }
    >
      <form id="role-form" onSubmit={submit} className="space-y-5" noValidate>
        {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Role name" error={error?.errors?.name?.[0]}>
            <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Stock Keeper" autoFocus />
          </Field>
          <Field label="Description (optional)">
            <input className={inputClass} value={description} onChange={(e) => setDescription(e.target.value)} />
          </Field>
        </div>

        <div>
          <p className="mb-2 text-xs font-medium text-slate-600">Permissions</p>
          <div className="grid gap-3 sm:grid-cols-2">
            {catalog.map((group) => (
              <fieldset key={group.group} className="rounded-xl border border-slate-200 p-4">
                <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{group.group}</legend>
                <div className="space-y-2">
                  {group.permissions.map((p) => (
                    <label key={p.key} className="flex cursor-pointer items-start gap-2.5 text-sm">
                      <input type="checkbox" checked={permissions.has(p.key)} onChange={() => toggle(p.key)} className="mt-0.5 h-4 w-4 accent-brand-600" />
                      <span>
                        <span className="text-slate-800">{p.label}</span>
                        <span className="block font-mono text-[11px] text-slate-400">{p.key}</span>
                      </span>
                    </label>
                  ))}
                </div>
              </fieldset>
            ))}
          </div>
          <p className="mt-3 flex items-center gap-1.5 text-xs text-slate-500">
            <Lock size={12} /> Settings (employees, roles, password resets) is reserved for the Admin role.
          </p>
        </div>
      </form>
    </Modal>
  )
}
