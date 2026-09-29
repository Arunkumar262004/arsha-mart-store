import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, Check, Circle, Eye, EyeOff, KeyRound, LockKeyhole, MonitorSmartphone, ShieldCheck } from 'lucide-react'
import { changePassword } from '../api'
import { parseApiError } from '../api/client'
import { useToast } from '../components/Toast'
import { Alert, Button, Field, PageHeader, inputClass } from '../components/ui'

const EMPTY = { current_password: '', password: '', password_confirmation: '' }

// Mirrors the server rule: Password::min(8)->letters()->numbers(), confirmed, different from current.
const RULES = [
  ['At least 8 characters', (f) => f.password.length >= 8],
  ['Contains a letter', (f) => /[a-z]/i.test(f.password)],
  ['Contains a number', (f) => /\d/.test(f.password)],
  ['Different from your current password', (f) => f.password !== '' && f.password !== f.current_password],
  ['Both new passwords match', (f) => f.password !== '' && f.password === f.password_confirmation],
]

export default function ChangePassword() {
  const toast = useToast()
  const [form, setForm] = useState(EMPTY)
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const fieldError = (key) => error?.errors?.[key]?.[0]
  const passed = RULES.map(([, test]) => test(form))
  const ready = form.current_password !== '' && passed.every(Boolean)

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
      <PageHeader
        title="Change password"
        description="Choose a strong password you don't use anywhere else."
        actions={
          <Link to="/profile" className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-brand-600">
            <ArrowLeft size={16} aria-hidden /> Back to profile
          </Link>
        }
      />

      <div className="grid max-w-5xl overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm lg:grid-cols-[1fr_1.3fr]">
        {/* Guidance panel */}
        <aside className="bg-gradient-to-br from-brand-600 to-brand-800 p-6 text-white sm:p-8">
          <div className="grid h-12 w-12 place-items-center rounded-xl bg-white/15">
            <LockKeyhole size={24} aria-hidden />
          </div>
          <h2 className="mt-5 text-lg font-semibold">Keep your account safe</h2>
          <p className="mt-1 text-sm text-white/75">Your password protects the bills, stock and reports you can see.</p>

          <ul className="mt-6 space-y-4 text-sm">
            <li className="flex gap-3">
              <MonitorSmartphone size={18} className="mt-0.5 shrink-0 text-white/80" aria-hidden />
              <span>
                <span className="font-medium">Other devices are signed out.</span>
                <span className="block text-white/70">This one stays signed in.</span>
              </span>
            </li>
            <li className="flex gap-3">
              <ShieldCheck size={18} className="mt-0.5 shrink-0 text-white/80" aria-hidden />
              <span>
                <span className="font-medium">Use a phrase, not a word.</span>
                <span className="block text-white/70">e.g. three words and a number: “mango-bus-lamp7”.</span>
              </span>
            </li>
            <li className="flex gap-3">
              <KeyRound size={18} className="mt-0.5 shrink-0 text-white/80" aria-hidden />
              <span>
                <span className="font-medium">Forgot your current password?</span>
                <span className="block text-white/70">Ask an admin to reset it for you.</span>
              </span>
            </li>
          </ul>
        </aside>

        {/* Form */}
        <form onSubmit={submit} className="space-y-5 p-6 sm:p-8" noValidate>
          {error && !Object.keys(error.errors).length && <Alert>{error.message}</Alert>}

          <PasswordField
            label="Current password"
            autoComplete="current-password"
            value={form.current_password}
            onChange={set('current_password')}
            error={fieldError('current_password')}
          />

          <div className="border-t border-dashed border-slate-200" />

          <PasswordField label="New password" autoComplete="new-password" value={form.password} onChange={set('password')} error={fieldError('password')} />
          <PasswordField
            label="Confirm new password"
            autoComplete="new-password"
            value={form.password_confirmation}
            onChange={set('password_confirmation')}
            error={fieldError('password_confirmation')}
          />

          <ul className="grid gap-2 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2" aria-label="Password requirements">
            {RULES.map(([label], i) => (
              <li key={label} className={`flex items-center gap-2 ${passed[i] ? 'text-emerald-700' : 'text-slate-500'}`}>
                {passed[i] ? <Check size={16} className="shrink-0" aria-hidden /> : <Circle size={14} className="shrink-0 text-slate-300" aria-hidden />}
                <span>{label}</span>
              </li>
            ))}
          </ul>

          <Button type="submit" loading={saving} disabled={!ready} icon={KeyRound} className="w-full sm:w-auto">
            Update password
          </Button>
        </form>
      </div>
    </>
  )
}

/** A password input with a show / hide toggle. */
function PasswordField({ label, error, ...props }) {
  const [visible, setVisible] = useState(false)

  return (
    <Field label={label} error={error}>
      <div className="relative">
        <input type={visible ? 'text' : 'password'} className={`${inputClass} pr-11`} {...props} />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          className="absolute inset-y-0 right-0 grid w-11 place-items-center text-slate-400 hover:text-slate-700"
          aria-label={visible ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
        >
          {visible ? <EyeOff size={17} /> : <Eye size={17} />}
        </button>
      </div>
    </Field>
  )
}
