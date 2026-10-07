import { useState } from 'react'
import { findCustomer } from '../../api'
import { Field, inputClass } from '../ui'
import { normalizeGstin } from '../../lib/states'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export const EMPTY_PARTY = { customer_id: null, customer_name: '', customer_email: '', customer_phone: '', customer_gstin: '', address: '' }

/** Party fields as sent to the API (empty strings become null). */
export const partyPayload = (party) => ({
  customer_id: party.customer_id,
  customer_name: party.customer_name.trim(),
  customer_email: party.customer_email.trim() || null,
  customer_phone: party.customer_phone.trim() || null,
  customer_gstin: normalizeGstin(party.customer_gstin) || null,
})

/**
 * Customer details on a quotation or challan. A known email or mobile fills
 * in the rest (incl. GSTIN and address) from the customer record.
 */
export default function PartyFields({ party, setParty, fieldError = () => null, addressLabel }) {
  const [hint, setHint] = useState(null)
  const set = (key, value) => setParty((p) => ({ ...p, [key]: value, ...(key === 'customer_email' || key === 'customer_phone' ? { customer_id: null } : {}) }))

  async function lookup(by) {
    const value = by === 'email' ? party.customer_email.trim() : party.customer_phone.replace(/\D/g, '')
    if (party.customer_id || (by === 'email' ? !EMAIL_RE.test(value) : value.length < 10)) return
    try {
      const c = await findCustomer(by === 'email' ? { email: value } : { phone: party.customer_phone.trim() })
      setParty((p) => ({
        ...p,
        customer_id: c.id,
        customer_name: p.customer_name || c.name,
        customer_email: p.customer_email || c.email,
        customer_phone: p.customer_phone || (c.phone ?? ''),
        customer_gstin: p.customer_gstin || (c.gstin ?? ''),
        address: p.address || (c.address ?? ''),
      }))
      setHint(`Existing customer: ${c.name}`)
    } catch {
      setHint(null)
    }
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Field label="Customer name" error={fieldError('customer_name')} hint={hint}>
        <input className={inputClass} value={party.customer_name} onChange={(e) => set('customer_name', e.target.value)} />
      </Field>
      <Field label="Email" error={fieldError('customer_email')} hint="Needed to make a bill from it">
        <input type="email" className={inputClass} value={party.customer_email} onChange={(e) => set('customer_email', e.target.value)} onBlur={() => lookup('email')} />
      </Field>
      <Field label="Mobile" error={fieldError('customer_phone')}>
        <input type="tel" className={inputClass} value={party.customer_phone} onChange={(e) => set('customer_phone', e.target.value)} onBlur={() => lookup('phone')} />
      </Field>
      <Field label="GSTIN" error={fieldError('customer_gstin')} hint="For business customers">
        <input
          className={`${inputClass} uppercase`}
          maxLength={15}
          value={party.customer_gstin}
          onChange={(e) => set('customer_gstin', e.target.value.toUpperCase())}
        />
      </Field>
      {addressLabel && (
        <Field label={addressLabel} error={fieldError('delivery_address')} className="sm:col-span-2">
          <textarea rows={2} className={inputClass} value={party.address} onChange={(e) => set('address', e.target.value)} />
        </Field>
      )}
    </div>
  )
}
