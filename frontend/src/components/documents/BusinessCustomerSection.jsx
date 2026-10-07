import { useEffect, useRef, useState } from 'react'
import { Building2, ChevronDown, Printer } from 'lucide-react'
import { GSTIN_RE, STATES, gstinStateCode, normalizeGstin } from '../../lib/states'
import { Button, Card, Field, inputClass } from '../ui'
import TaxInvoiceModal from './TaxInvoiceModal'

export const EMPTY_B2B = { gstin: '', billingAddress: '', placeOfSupply: '' }

/** The B2B fields of a bill request (all null when the section is unused). */
export const b2bPayload = (b2b) => ({
  customer_gstin: normalizeGstin(b2b.gstin) || null,
  billing_address: b2b.billingAddress.trim() || null,
  place_of_supply: b2b.placeOfSupply || null,
})

/**
 * Optional "Business customer (GST invoice)" part of the billing screen:
 * GSTIN, billing address and place of supply. Auto-filled from the matched
 * customer's record; a place of supply outside the store's state switches
 * the bill to IGST (the server decides the same way).
 */
export default function BusinessCustomerSection({ b2b, setB2b, match, storeStateCode, setInterstate, fieldError = () => null }) {
  const [open, setOpen] = useState(Boolean(b2b.gstin))
  const filledFor = useRef(null)

  // Fill from the looked-up customer once per match, without overwriting typing.
  useEffect(() => {
    const c = match?.customer
    if (!c || filledFor.current === c.id) return
    filledFor.current = c.id
    if (!c.gstin && !c.address && !c.state_code) return
    setB2b((prev) => ({
      gstin: prev.gstin || (c.gstin ?? ''),
      billingAddress: prev.billingAddress || (c.address ?? ''),
      placeOfSupply: prev.placeOfSupply || (c.state_code ?? ''),
    }))
    if (c.gstin) setOpen(true)
  }, [match, setB2b])

  // Another state's place of supply means IGST (kept in sync with the parent's preview flag).
  useEffect(() => {
    if (b2b.placeOfSupply && storeStateCode) setInterstate(b2b.placeOfSupply !== storeStateCode)
  }, [b2b.placeOfSupply, storeStateCode, setInterstate])

  const set = (key, value) => setB2b((prev) => ({ ...prev, [key]: value }))
  const gstin = normalizeGstin(b2b.gstin)
  const gstinError = fieldError('customer_gstin') || (gstin.length === 15 && !GSTIN_RE.test(gstin) ? 'This GSTIN does not look valid.' : null)

  function changeGstin(value) {
    const upper = value.toUpperCase()
    setB2b((prev) => ({
      ...prev,
      gstin: upper,
      // The GSTIN's first two digits are the customer's state: a good default place of supply.
      placeOfSupply: prev.placeOfSupply || (normalizeGstin(upper).length >= 2 ? (gstinStateCode(upper) ?? '') : ''),
    }))
  }

  return (
    <Card padded={false}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center gap-2 px-5 py-4 text-left"
        aria-expanded={open}
      >
        <Building2 size={16} className="text-slate-400" aria-hidden />
        <span className="text-sm font-semibold text-slate-800">Business customer (GST invoice)</span>
        {gstin && !open && <span className="text-xs text-slate-500">GSTIN {gstin}</span>}
        <ChevronDown size={16} className={`ml-auto text-slate-400 transition ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      {open && (
        <div className="grid gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-3">
          <Field label="Customer GSTIN" error={gstinError} hint="15 characters, e.g. 29ABCDE1234F1Z5">
            <input className={`${inputClass} uppercase`} maxLength={15} value={b2b.gstin} onChange={(e) => changeGstin(e.target.value)} />
          </Field>
          <Field label="Place of supply" error={fieldError('place_of_supply')} hint={storeStateCode ? 'Another state = IGST' : undefined}>
            <select className={inputClass} value={b2b.placeOfSupply} onChange={(e) => set('placeOfSupply', e.target.value)}>
              <option value="">Same as store</option>
              {STATES.map((s) => (
                <option key={s.code} value={s.code}>{s.code} · {s.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Billing address" error={fieldError('billing_address')}>
            <textarea rows={1} className={inputClass} value={b2b.billingAddress} onChange={(e) => set('billingAddress', e.target.value)} />
          </Field>
        </div>
      )}
    </Card>
  )
}

/** Shown above the receipt after a bill with a GSTIN: print the A4 tax invoice. */
export function TaxInvoiceOffer({ order }) {
  const [open, setOpen] = useState(false)
  if (!order?.customer_gstin) return null
  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
        <Building2 size={18} className="text-brand-600" aria-hidden />
        <span>Business bill for GSTIN <b>{order.customer_gstin}</b>: print the A4 tax invoice for their records.</span>
        <Button size="sm" icon={Printer} className="ml-auto" onClick={() => setOpen(true)}>Print tax invoice</Button>
      </div>
      {open && <TaxInvoiceModal orderId={order.id} onClose={() => setOpen(false)} />}
    </>
  )
}
