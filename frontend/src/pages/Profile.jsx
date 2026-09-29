import { useState } from 'react'
import { KeyRound } from 'lucide-react'
import { changePassword } from '../api'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { useToast } from '../components/Toast'
import { Alert, Badge, Button, Card, Field, PageHeader, inputClass } from '../components/ui'

const EMPTY = { current_password: '', password: '', password_confirmation: '' }

export default function Profile() {
  const { user, permissions } = useAuth()
  const toast = useToast()
  const [form, setForm] = useState(EMPTY)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]

  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      await changePassword(form)
      setForm(EMPTY)
      toast('Password updated. Other devices have been signed out.')
    } catch (e) {
      setError(parseApiError(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader title="My profile" description="Your account details and password." />
      <div className="grid gap-6 lg:grid-cols-[1fr_1.2fr]">
        <Card title="Account">
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500">Name</dt>
              <dd className="font-medium">{user.name}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500">Email</dt>
              <dd className="font-medium">{user.email}</dd>
            </div>
            <div className="flex justify-between gap-4">
              <dt className="text-slate-500">Role</dt>
              <dd>
                <Badge tone={user.is_admin ? 'brand' : 'slate'}>{user.role?.name ?? 'None'}</Badge>
              </dd>
            </div>
            <div>
              <dt className="mb-2 text-slate-500">Access</dt>
              <dd className="flex flex-wrap gap-1.5">
                {permissions.map((p) => (
                  <Badge key={p}>{p}</Badge>
                ))}
              </dd>
            </div>
          </dl>
        </Card>

        <Card title="Change password">
          <form onSubmit={submit} className="space-y-4" noValidate>
            {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}
            <Field label="Current password" error={fieldError('current_password')}>
              <input type="password" autoComplete="current-password" className={inputClass} value={form.current_password} onChange={set('current_password')} />
            </Field>
            <Field label="New password" error={fieldError('password')} hint="At least 8 characters, with letters and numbers.">
              <input type="password" autoComplete="new-password" className={inputClass} value={form.password} onChange={set('password')} />
            </Field>
            <Field label="Confirm new password">
              <input type="password" autoComplete="new-password" className={inputClass} value={form.password_confirmation} onChange={set('password_confirmation')} />
            </Field>
            <Button type="submit" loading={saving} icon={KeyRound}>
              Update password
            </Button>
          </form>
        </Card>
      </div>
    </>
  )
}
