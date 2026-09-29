import { useRef, useState } from 'react'
import { Camera, KeyRound, Trash2 } from 'lucide-react'
import { changePassword, removeAvatar, uploadAvatar } from '../api'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import Avatar from '../components/Avatar'
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
      <PageHeader title="My profile" description="Your photo, account details and password." />
      <PhotoCard />
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

const MAX_BYTES = 5 * 1024 * 1024

/** Upload / change / remove the profile photo; the header updates straight away. */
function PhotoCard() {
  const { user, updateUser } = useAuth()
  const toast = useToast()
  const input = useRef(null)
  const [busy, setBusy] = useState(null) // 'upload' | 'remove'

  async function run(kind, request, message) {
    setBusy(kind)
    try {
      updateUser(await request())
      toast(message)
    } catch (e) {
      const { message: error, errors } = parseApiError(e)
      toast(errors?.avatar?.[0] ?? error, 'error')
    } finally {
      setBusy(null)
    }
  }

  function pick(event) {
    const file = event.target.files?.[0]
    event.target.value = '' // allow choosing the same file again
    if (!file) return
    if (!file.type.startsWith('image/')) return toast('Choose an image (JPG, PNG or WebP).', 'error')
    if (file.size > MAX_BYTES) return toast('The photo must be 5 MB or smaller.', 'error')
    run('upload', () => uploadAvatar(file), 'Profile photo updated.')
  }

  return (
    <Card className="mb-6">
      <div className="flex flex-col items-center gap-5 sm:flex-row">
        <button
          type="button"
          onClick={() => input.current?.click()}
          className="group relative rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 focus-visible:ring-offset-2"
          aria-label="Change profile photo"
        >
          <Avatar user={user} size="lg" className="ring-4 ring-slate-100" />
          <span className="absolute inset-0 grid place-items-center rounded-full bg-slate-900/45 text-white opacity-0 transition group-hover:opacity-100">
            <Camera size={22} aria-hidden />
          </span>
        </button>

        <div className="text-center sm:text-left">
          <p className="text-base font-semibold text-slate-900">{user.name}</p>
          <p className="text-sm text-slate-500">{user.email}</p>
          <p className="mt-1 text-xs text-slate-400">JPG, PNG or WebP, up to 5 MB. It's cropped to a square and shown in the header.</p>
          <div className="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
            <Button size="sm" icon={Camera} loading={busy === 'upload'} disabled={busy !== null} onClick={() => input.current?.click()}>
              {user.avatar ? 'Change photo' : 'Upload photo'}
            </Button>
            {user.avatar && (
              <Button
                size="sm"
                variant="secondary"
                icon={Trash2}
                loading={busy === 'remove'}
                disabled={busy !== null}
                onClick={() => run('remove', removeAvatar, 'Profile photo removed.')}
              >
                Remove
              </Button>
            )}
          </div>
        </div>

        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={pick} />
      </div>
    </Card>
  )
}
