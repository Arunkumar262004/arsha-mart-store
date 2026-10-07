import { useEffect, useMemo, useState } from 'react'
import { ImageUp, Save, Trash2 } from 'lucide-react'
import { saveCompany } from '../../api'
import { parseApiError } from '../../api/client'
import { useBranding } from '../../branding/BrandingContext'
import { useToast } from '../../components/Toast'
import { Alert, Button, Card, Field, PageHeader, inputClass } from '../../components/ui'
import { STATES, gstinStateCode, stateName } from '../../lib/states'
import defaultFavicon from '../../assets/inofex-mark.png'

const DETAIL_KEYS = ['legal_name', 'gstin', 'pan', 'phone', 'email', 'website', 'address', 'city', 'state', 'state_code', 'pincode']

/**
 * Admin: the business name and tagline, the legal details printed as the
 * seller on bills and documents, plus logo and favicon uploads. The logo
 * appears in the sidebar, on the login page and on every printed document
 * (including invoice PDFs); the favicon is the browser tab icon.
 */
export default function Company() {
  const { name, tagline, details } = useBranding()
  // Remounts with fresh values when the branding loads or is saved.
  return <CompanyForm key={JSON.stringify([name, tagline, ...DETAIL_KEYS.map((k) => details[k])])} />
}

function CompanyForm() {
  const branding = useBranding()
  const toast = useToast()
  const [form, setForm] = useState(() => ({
    company_name: branding.name,
    tagline: branding.tagline ?? '',
    ...Object.fromEntries(DETAIL_KEYS.map((k) => [k, branding.details[k] ?? ''])),
  }))
  const field = (key) => ({ value: form[key], onChange: (e) => setForm((f) => ({ ...f, [key]: e.target.value })) })
  const [files, setFiles] = useState({ logo: null, favicon: null })
  const [remove, setRemove] = useState({ logo: false, favicon: false })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)

  // Local previews of picked files, released when they change.
  const picked = useMemo(
    () => ({
      logo: files.logo && URL.createObjectURL(files.logo),
      favicon: files.favicon && URL.createObjectURL(files.favicon),
    }),
    [files],
  )
  useEffect(
    () => () => Object.values(picked).forEach((url) => url && URL.revokeObjectURL(url)),
    [picked],
  )

  const pick = (key) => (event) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    setFiles((f) => ({ ...f, [key]: file }))
    setRemove((r) => ({ ...r, [key]: false }))
  }

  const clear = (key) => {
    setFiles((f) => ({ ...f, [key]: null }))
    setRemove((r) => ({ ...r, [key]: true }))
  }

  const preview = (key, uploaded, fallback) => {
    if (picked[key]) return picked[key]
    if (remove[key]) return fallback
    return uploaded ?? fallback
  }

  async function submit(event) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setError(null)
    try {
      const saved = await saveCompany({
        ...form,
        logo: files.logo ?? undefined,
        favicon: files.favicon ?? undefined,
        remove_logo: remove.logo,
        remove_favicon: remove.favicon,
      })
      branding.setBranding(saved)
      setFiles({ logo: null, favicon: null })
      setRemove({ logo: false, favicon: false })
      toast('Company details saved.')
    } catch (e) {
      const { message, errors: fieldErrors } = parseApiError(e)
      setErrors(fieldErrors)
      setError(message)
    } finally {
      setSaving(false)
    }
  }

  const hasLogo = files.logo || (!remove.logo && branding.uploadedLogo)
  const hasFavicon = files.favicon || (!remove.favicon && branding.uploadedFavicon)

  return (
    <>
      <PageHeader title="Company" description="Your business name, logo and browser icon" />

      <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          {error && <Alert>{error}</Alert>}

          <Card title="Business details">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Company name" error={errors.company_name?.[0]} hint="Shown in the sidebar, login page and browser tab">
                <input className={inputClass} value={form.company_name} maxLength={100} required
                  onChange={(e) => setForm((f) => ({ ...f, company_name: e.target.value }))} />
              </Field>
              <Field label="Tagline" error={errors.tagline?.[0]} hint="Optional, shown on the login page">
                <input className={inputClass} value={form.tagline} maxLength={150} placeholder="Retail billing & accounts"
                  onChange={(e) => setForm((f) => ({ ...f, tagline: e.target.value }))} />
              </Field>
            </div>
            <p className="mt-3 text-xs text-slate-500">
              Each store&apos;s own name, address and GSTIN (printed on invoices) are set under Settings → Stores.
            </p>
          </Card>

          <Card title="Legal & tax details">
            <p className="-mt-2 mb-4 text-xs text-slate-500">
              Printed as the seller on bills, tax invoices, quotations, challans and purchase documents. A store with its own
              address, phone or GSTIN (Settings → Stores) uses those instead, e.g. a branch in another state.
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Registered business name" error={errors.legal_name?.[0]} hint="As on your GST registration" className="sm:col-span-2">
                <input className={inputClass} maxLength={150} placeholder="Inofex Retail Private Limited" {...field('legal_name')} />
              </Field>
              <Field label="GSTIN" error={errors.gstin?.[0]}>
                <input className={`${inputClass} uppercase`} maxLength={15} placeholder="33ABCDE1234F1Z5" {...field('gstin')}
                  onChange={(e) => {
                    const gstin = e.target.value.toUpperCase().replace(/\s+/g, '')
                    const code = gstinStateCode(gstin)
                    setForm((f) => ({ ...f, gstin, ...(code && !f.state_code ? { state_code: code, state: stateName(code) } : {}) }))
                  }} />
              </Field>
              <Field label="PAN" error={errors.pan?.[0]}>
                <input className={`${inputClass} uppercase`} maxLength={10} placeholder="ABCDE1234F" {...field('pan')} />
              </Field>
              <Field label="Address" error={errors.address?.[0]} className="sm:col-span-2">
                <input className={inputClass} maxLength={255} placeholder="Door no, street, area" {...field('address')} />
              </Field>
              <Field label="City" error={errors.city?.[0]}>
                <input className={inputClass} maxLength={100} {...field('city')} />
              </Field>
              <Field label="PIN code" error={errors.pincode?.[0]}>
                <input className={inputClass} inputMode="numeric" maxLength={6} {...field('pincode')} />
              </Field>
              <Field label="State" error={errors.state_code?.[0]}>
                <select className={inputClass} value={form.state_code}
                  onChange={(e) => setForm((f) => ({ ...f, state_code: e.target.value, state: stateName(e.target.value) ?? '' }))}>
                  <option value="">Select state…</option>
                  {STATES.map((s) => <option key={s.code} value={s.code}>{s.name} ({s.code})</option>)}
                </select>
              </Field>
              <Field label="Phone" error={errors.phone?.[0]}>
                <input className={inputClass} maxLength={20} placeholder="+91 98765 00000" {...field('phone')} />
              </Field>
              <Field label="Email" error={errors.email?.[0]}>
                <input type="email" className={inputClass} maxLength={150} {...field('email')} />
              </Field>
              <Field label="Website" error={errors.website?.[0]}>
                <input className={inputClass} maxLength={150} placeholder="www.example.com" {...field('website')} />
              </Field>
            </div>
          </Card>

          <Card title="Logo">
            <ImageField
              image={preview('logo', branding.uploadedLogo, branding.logo)}
              custom={Boolean(hasLogo)}
              error={errors.logo?.[0]}
              hint="PNG, JPG or WebP up to 2 MB. A transparent PNG looks best. Used in the sidebar, login page and on invoices, quotations, challans and PDFs."
              onPick={pick('logo')}
              onRemove={() => clear('logo')}
              large
            />
          </Card>

          <Card title="Favicon (browser tab icon)">
            <ImageField
              image={preview('favicon', branding.uploadedFavicon, branding.uploadedLogo ?? defaultFavicon)}
              custom={Boolean(hasFavicon)}
              error={errors.favicon?.[0]}
              hint="A square image works best (it is resized to 128 × 128). Without one, the logo is used."
              onPick={pick('favicon')}
              onRemove={() => clear('favicon')}
            />
          </Card>

          <div className="flex justify-end">
            <Button type="submit" icon={Save} loading={saving}>Save changes</Button>
          </div>
        </div>

        <Card title="Preview" className="h-fit lg:sticky lg:top-24">
          <div className="rounded-xl border border-slate-200 p-4">
            <div className="flex items-center gap-3">
              <img src={preview('logo', branding.uploadedLogo, branding.mark)} alt="" className="h-10 w-10 object-contain" />
              <div className="min-w-0">
                <p className="truncate font-semibold text-slate-900">{form.company_name || 'Company name'}</p>
                <p className="truncate text-xs text-slate-500">Main Store</p>
              </div>
            </div>
          </div>
          <div className="mt-3 flex items-center gap-2 rounded-t-lg bg-slate-100 px-3 py-2 text-xs text-slate-600">
            <img src={preview('favicon', branding.uploadedFavicon, branding.uploadedLogo ?? defaultFavicon)} alt="" className="h-4 w-4 object-contain" />
            <span className="truncate">{form.company_name || 'Company name'}</span>
          </div>
          <p className="mt-3 text-xs text-slate-500">Changes apply after you save.</p>
        </Card>
      </form>
    </>
  )
}

function ImageField({ image, custom, error, hint, onPick, onRemove, large = false }) {
  return (
    <div className="flex flex-wrap items-center gap-5">
      <div className={`grid place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3 ${large ? 'h-32 w-32' : 'h-20 w-20'}`}>
        <img src={image} alt="" className="max-h-full max-w-full object-contain" />
      </div>
      <div className="min-w-0 flex-1 space-y-2">
        <div className="flex flex-wrap gap-2">
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
            <ImageUp size={16} aria-hidden /> Upload
            <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={onPick} />
          </label>
          {custom && (
            <Button variant="ghost" icon={Trash2} onClick={onRemove}>Use default</Button>
          )}
        </div>
        {error ? <p className="text-xs text-red-600">{error}</p> : <p className="text-xs text-slate-500">{hint}</p>}
      </div>
    </div>
  )
}
