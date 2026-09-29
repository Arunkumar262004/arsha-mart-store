import { useEffect, useMemo, useState } from 'react'
import { KeyRound, Search, Wand2 } from 'lucide-react'
import { getUsers, resetUserPassword } from '../../api'
import { parseApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { useToast } from '../../components/Toast'
import { Alert, Badge, Button, Card, Field, Modal, PageHeader, Spinner, inputClass } from '../../components/ui'

// Readable temporary password: letters + digits, no look-alikes (0/O, 1/l).
function generatePassword() {
  const letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'
  const digits = '23456789'
  const pick = (set) => set[crypto.getRandomValues(new Uint32Array(1))[0] % set.length]
  return Array.from({ length: 6 }, () => pick(letters)).join('') + pick(digits) + pick(digits) + pick(digits)
}

export default function PasswordReset() {
  const { user: me } = useAuth()
  const toast = useToast()
  const [users, setUsers] = useState(null)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')
  const [target, setTarget] = useState(null)

  useEffect(() => {
    getUsers()
      .then(setUsers)
      .catch((e) => setError(parseApiError(e).message))
  }, [])

  const rows = useMemo(() => {
    const q = search.trim().toLowerCase()
    return (users ?? []).filter((u) => !q || u.name.toLowerCase().includes(q) || u.email.includes(q))
  }, [users, search])

  return (
    <>
      <PageHeader
        title="Password reset"
        description="Set a new password for a staff member who forgot theirs. They'll be signed out everywhere."
      />
      {error && <Alert>{error}</Alert>}

      <Card padded={false}>
        <div className="border-b border-slate-100 p-4">
          <div className="relative sm:w-72">
            <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input className={`${inputClass} pl-9`} placeholder="Search users" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>
        {!users && !error && <div className="grid place-items-center py-16 text-slate-400"><Spinner size={24} /></div>}
        <ul className="divide-y divide-slate-100">
          {rows.map((u) => (
            <li key={u.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
              <div>
                <p className="text-sm font-medium text-slate-800">{u.name}</p>
                <p className="text-xs text-slate-500">{u.email}</p>
              </div>
              <div className="flex items-center gap-3">
                <Badge tone={u.role?.is_admin ? 'brand' : 'slate'}>{u.role?.name}</Badge>
                {u.id === me.id ? (
                  <span className="text-xs text-slate-400">Use “Change password” in your profile</span>
                ) : (
                  <Button variant="secondary" size="sm" icon={KeyRound} onClick={() => setTarget(u)}>Reset password</Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </Card>

      {target && (
        <ResetModal
          user={target}
          onClose={() => setTarget(null)}
          onDone={() => {
            toast(`Password reset for ${target.name}. Share the new password with them securely.`)
            setTarget(null)
          }}
        />
      )}
    </>
  )
}

function ResetModal({ user, onClose, onDone }) {
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState(false)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  function generate() {
    const value = generatePassword()
    setPassword(value)
    setConfirm(value)
    setShow(true)
  }

  async function submit(e) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await resetUserPassword(user.id, { password, password_confirmation: confirm })
      onDone()
    } catch (err) {
      setError(parseApiError(err))
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      size="sm"
      title={`Reset password · ${user.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="reset-form" variant="danger" loading={saving}>Reset password</Button>
        </>
      }
    >
      <form id="reset-form" onSubmit={submit} className="space-y-4" noValidate>
        <Alert tone="warning">{user.name} will be signed out of every device and must use the new password.</Alert>
        <Field label="New password" error={error?.errors?.password?.[0]} hint="8+ characters with letters and numbers.">
          <div className="flex gap-2">
            <input type={show ? 'text' : 'password'} className={`${inputClass} font-mono`} value={password} onChange={(e) => setPassword(e.target.value)} autoFocus />
            <Button variant="secondary" icon={Wand2} onClick={generate} title="Generate a password">Generate</Button>
          </div>
        </Field>
        <Field label="Confirm password">
          <input type={show ? 'text' : 'password'} className={`${inputClass} font-mono`} value={confirm} onChange={(e) => setConfirm(e.target.value)} />
        </Field>
        <label className="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={show} onChange={(e) => setShow(e.target.checked)} className="accent-brand-600" /> Show password
        </label>
        {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
      </form>
    </Modal>
  )
}
