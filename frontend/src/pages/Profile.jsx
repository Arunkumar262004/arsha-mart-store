import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Camera, Check, ImagePlus, KeyRound, Pencil, Trash2, Upload, X } from 'lucide-react'
import { removeAvatar, updateProfile, uploadAvatar } from '../api'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import Avatar from '../components/Avatar'
import { useToast } from '../components/Toast'
import { Alert, Badge, Button, Card, Field, PageHeader, inputClass } from '../components/ui'

export default function Profile() {
  const { user, permissions } = useAuth()
  const [editing, setEditing] = useState(false)

  return (
    <>
      <PageHeader title="My profile" description="Your photo and account details." />
      <PhotoCard />
      <Card
        title="Account"
        actions={
          !editing && (
            <Button variant="secondary" size="sm" icon={Pencil} onClick={() => setEditing(true)}>
              Edit profile
            </Button>
          )
        }
      >
        {editing ? (
          <EditProfileForm onDone={() => setEditing(false)} />
        ) : (
          <dl className="divide-y divide-slate-100 text-sm">
            <div className="flex justify-between gap-4 py-3 first:pt-0">
              <dt className="text-slate-500">Name</dt>
              <dd className="font-medium">{user.name}</dd>
            </div>
            <div className="flex justify-between gap-4 py-3">
              <dt className="text-slate-500">Email</dt>
              <dd className="text-right">
                <span className="font-medium">{user.email}</span>
                <span className="block text-xs text-slate-400">You sign in with this</span>
              </dd>
            </div>
            <div className="flex justify-between gap-4 py-3">
              <dt className="text-slate-500">Role</dt>
              <dd>
                <Badge tone={user.is_admin ? 'brand' : 'slate'}>{user.role?.name ?? 'None'}</Badge>
              </dd>
            </div>
            <div className="flex items-center justify-between gap-4 py-3">
              <dt className="text-slate-500">Password</dt>
              <dd>
                <Link to="/change-password" className="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:text-brand-700">
                  <KeyRound size={15} aria-hidden /> Change password
                </Link>
              </dd>
            </div>
            <div className="pt-3">
              <dt className="mb-2 text-slate-500">Access</dt>
              <dd className="flex flex-wrap gap-1.5">
                {permissions.map((p) => (
                  <Badge key={p}>{p}</Badge>
                ))}
              </dd>
            </div>
          </dl>
        )}
      </Card>
    </>
  )
}

/**
 * Edit your own name and email. The email is the login, so changing it
 * asks for the current password (the server checks it too).
 */
function EditProfileForm({ onDone }) {
  const { user, updateUser } = useAuth()
  const toast = useToast()
  const [form, setForm] = useState({ name: user.name, email: user.email, current_password: '' })
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]
  const emailChanged = form.email.trim().toLowerCase() !== user.email
  const unchanged = form.name.trim() === user.name && !emailChanged

  async function save(event) {
    event.preventDefault()
    if (unchanged) return onDone()
    setSaving(true)
    setError(null)
    try {
      const payload = { name: form.name, email: form.email, ...(emailChanged ? { current_password: form.current_password } : {}) }
      updateUser(await updateProfile(payload))
      toast(emailChanged ? 'Profile updated. Sign in with your new email from now on.' : 'Profile updated.')
      onDone()
    } catch (e) {
      setError(parseApiError(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={save} className="space-y-4" noValidate onKeyDown={(e) => e.key === 'Escape' && onDone()}>
      {error && !Object.keys(error.errors ?? {}).length && <Alert>{error.message}</Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name" error={fieldError('name')}>
          <input className={inputClass} value={form.name} maxLength={100} autoFocus autoComplete="name" onChange={set('name')} />
        </Field>
        <Field label="Email" error={fieldError('email')} hint="You sign in with this email.">
          <input type="email" className={inputClass} value={form.email} maxLength={255} autoComplete="email" onChange={set('email')} />
        </Field>
      </div>

      {emailChanged && (
        <div className="rounded-xl border border-amber-200 bg-amber-50/60 p-4">
          <Field
            label="Current password"
            error={fieldError('current_password')}
            hint="Changing your login email needs your password. You'll sign in with the new email next time."
          >
            <input type="password" className={inputClass} value={form.current_password} autoComplete="current-password" onChange={set('current_password')} />
          </Field>
        </div>
      )}

      <div className="flex gap-2">
        <Button type="submit" icon={Check} loading={saving} disabled={emailChanged && !form.current_password}>
          Save changes
        </Button>
        <Button variant="secondary" icon={X} disabled={saving} onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

const MAX_BYTES = 5 * 1024 * 1024

/**
 * Profile photo: pick a file, check the preview, then upload it (or cancel).
 * The header updates as soon as it's saved.
 */
function PhotoCard() {
  const { user, updateUser } = useAuth()
  const toast = useToast()
  const input = useRef(null)
  const [pending, setPending] = useState(null) // { file, url } chosen but not uploaded yet
  const [busy, setBusy] = useState(null) // 'upload' | 'remove'

  // Free the preview's object URL when it's replaced or the page closes.
  useEffect(() => (pending ? () => URL.revokeObjectURL(pending.url) : undefined), [pending])

  const choose = () => input.current?.click()

  function pick(event) {
    const file = event.target.files?.[0]
    event.target.value = '' // allow choosing the same file again
    if (!file) return
    if (!file.type.startsWith('image/')) return toast('Choose an image (JPG, PNG or WebP).', 'error')
    if (file.size > MAX_BYTES) return toast('The photo must be 5 MB or smaller.', 'error')
    setPending({ file, url: URL.createObjectURL(file) })
  }

  async function run(kind, request, message) {
    setBusy(kind)
    try {
      updateUser(await request())
      setPending(null)
      toast(message)
    } catch (e) {
      const { message: error, errors } = parseApiError(e)
      toast(errors?.avatar?.[0] ?? error, 'error')
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card className="mb-6">
      <div className="flex flex-col items-center gap-5 sm:flex-row">
        <div className="relative">
          {pending ? (
            <img src={pending.url} alt="Preview of your new photo" className="h-24 w-24 rounded-full object-cover ring-4 ring-brand-200" />
          ) : (
            <button
              type="button"
              onClick={choose}
              className="group relative rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-300 focus-visible:ring-offset-2"
              aria-label="Choose a new profile photo"
            >
              <Avatar user={user} size="lg" className="ring-4 ring-slate-100" />
              <span className="absolute inset-0 grid place-items-center rounded-full bg-slate-900/45 text-white opacity-0 transition group-hover:opacity-100">
                <Camera size={22} aria-hidden />
              </span>
            </button>
          )}
          {pending && (
            <span className="absolute -bottom-2 left-1/2 -translate-x-1/2 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow">
              Preview
            </span>
          )}
        </div>

        <div className="text-center sm:text-left">
          <p className="text-base font-semibold text-slate-900">{user.name}</p>
          <p className="text-sm text-slate-500">{user.email}</p>

          {pending ? (
            <>
              <p className="mt-1 text-xs text-slate-500">
                <span className="font-medium text-slate-700">{pending.file.name}</span> — not saved yet. It will be cropped to a square.
              </p>
              <div className="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
                <Button size="sm" icon={Upload} loading={busy === 'upload'} onClick={() => run('upload', () => uploadAvatar(pending.file), 'Profile photo updated.')}>
                  Upload photo
                </Button>
                <Button size="sm" variant="secondary" icon={ImagePlus} disabled={busy !== null} onClick={choose}>
                  Choose another
                </Button>
                <Button size="sm" variant="ghost" icon={X} disabled={busy !== null} onClick={() => setPending(null)}>
                  Cancel
                </Button>
              </div>
            </>
          ) : (
            <>
              <p className="mt-1 text-xs text-slate-400">JPG, PNG or WebP, up to 5 MB. You'll see a preview before it's saved.</p>
              <div className="mt-3 flex flex-wrap justify-center gap-2 sm:justify-start">
                <Button size="sm" icon={Camera} disabled={busy !== null} onClick={choose}>
                  {user.avatar ? 'Change photo' : 'Choose photo'}
                </Button>
                {user.avatar && (
                  <Button
                    size="sm"
                    variant="secondary"
                    icon={Trash2}
                    loading={busy === 'remove'}
                    disabled={busy !== null}
                    onClick={() => window.confirm('Remove your profile photo?') && run('remove', removeAvatar, 'Profile photo removed.')}
                  >
                    Remove
                  </Button>
                )}
              </div>
            </>
          )}
        </div>

        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={pick} />
      </div>
    </Card>
  )
}
