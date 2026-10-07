import { useState } from 'react'
import { ArrowRight, Eye, EyeOff, LockKeyhole, Mail, ShieldCheck } from 'lucide-react'
import { useBranding } from '../branding/BrandingContext'
import { parseApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Alert, Spinner } from '../components/ui'

const DEMO_ACCOUNTS = [
  { label: 'Admin', email: 'admin@store.com' },
  { label: 'Manager', email: 'manager@store.com' },
  { label: 'Cashier', email: 'cashier@store.com' },
]

const STATS = [
  { value: 'GST', label: 'Ready bills' },
  { value: 'Live', label: 'Stock tracking' },
  { value: 'Instant', label: 'Receipts' },
]

const fieldClass =
  'w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-11 pr-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-100'

const labelClass = 'mb-1.5 block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600'

export default function Login() {
  const { login } = useAuth()
  const { name: companyName, tagline, mark: logo } = useBranding()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(email.trim(), password)
    } catch (e) {
      setError(parseApiError(e))
      setSubmitting(false)
    }
  }

  const passwordError = error?.errors?.password?.[0]

  return (
    <div className="grid min-h-screen font-display lg:h-screen lg:grid-cols-[1.8fr_1fr] lg:overflow-hidden">
      <div className="relative hidden overflow-hidden bg-[#0b1024] lg:block">
        <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_20%_45%,rgba(67,56,202,0.45),transparent_60%)]" />
        <div className="absolute inset-0 bg-[radial-gradient(circle_at_85%_90%,rgba(14,165,233,0.15),transparent_50%)]" />

        <div className="relative flex h-full items-center px-12 xl:px-[18%]">
          <div className="max-w-2xl">
            <span className="inline-block rounded-full border border-white/25 px-5 py-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] text-white/90">
              {companyName}
            </span>

            <h2 className="mt-8 text-4xl font-bold leading-[1.1] tracking-tight text-white xl:text-5xl">
              Bill Faster,
              <br />
              <span className="text-brand-400">Track Stock,</span>
              <br />
              Run Your Store.
            </h2>

            <p className="mt-6 max-w-lg text-sm leading-relaxed text-slate-400">
              Your all-in-one counter to create GST bills, keep inventory in sync with every sale, and get reminded before
              products run out, all from one place.
            </p>

            <dl className="mt-10 flex divide-x divide-white/15">
              {STATS.map((stat) => (
                <div key={stat.label} className="px-8 first:pl-0">
                  <dt className="text-xl font-bold text-white">{stat.value}</dt>
                  <dd className="mt-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{stat.label}</dd>
                </div>
              ))}
            </dl>
          </div>
        </div>
      </div>

      <div className="flex items-center justify-center overflow-y-auto bg-white px-6 py-10 sm:px-12">
        <div className="w-full max-w-sm">
          <div className="mb-4 flex justify-center">
            <img src={logo} alt={companyName} className="h-16 w-16 object-contain" />
          </div>

          <h1 className="text-2xl font-bold tracking-tight text-slate-900">Welcome back</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to {companyName}{tagline ? ` · ${tagline}` : ''}</p>

          <form onSubmit={handleSubmit} className="mt-6 space-y-4" noValidate>
            {error && <Alert>{error.errors?.email?.[0] ?? error.message}</Alert>}

            <div>
              <label htmlFor="email" className={labelClass}>
                Email address
              </label>
              <div className="relative">
                <Mail size={18} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden />
                <input
                  id="email"
                  type="email"
                  autoComplete="username"
                  className={fieldClass}
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="you@store.com"
                  required
                  autoFocus
                />
              </div>
            </div>

            <div>
              <label htmlFor="password" className={labelClass}>
                Password
              </label>
              <div className="relative">
                <LockKeyhole
                  size={18}
                  className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"
                  aria-hidden
                />
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  className={`${fieldClass} pr-12`}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((s) => !s)}
                  className="absolute inset-y-0 right-0 px-4 text-slate-400 hover:text-slate-600"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {passwordError && <p className="mt-1.5 text-xs text-rose-600">{passwordError}</p>}
            </div>

            <button
              type="submit"
              disabled={submitting}
              className="flex w-full items-center justify-center gap-3 rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-900/20 transition hover:bg-slate-800 disabled:opacity-70"
            >
              {submitting && <Spinner size={18} />}
              Sign In
              {!submitting && <ArrowRight size={18} aria-hidden />}
            </button>

            <p className="flex items-center justify-center gap-2 text-xs text-slate-400">
              <ShieldCheck size={14} aria-hidden />
              Authorized store staff only
            </p>
          </form>

          <div className="mt-5 flex flex-wrap items-center justify-center gap-2 border-t border-slate-100 pt-4 text-[11px] text-slate-400">
            <span>Demo (password123):</span>
            {DEMO_ACCOUNTS.map((account) => (
              <button
                key={account.email}
                type="button"
                onClick={() => {
                  setEmail(account.email)
                  setPassword('password123')
                }}
                className="rounded-md px-2 py-1 font-medium text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50"
              >
                {account.label}
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
