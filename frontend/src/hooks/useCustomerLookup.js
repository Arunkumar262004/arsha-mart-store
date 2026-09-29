import { useRef, useState } from 'react'
import { findCustomer } from '../api'
import { parseApiError } from '../api/client'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const EMPTY = { email: '', phone: '', name: '' }

const digits = (value) => (value ?? '').replace(/\D/g, '')
// Compare on the last 10 digits so "9578777764" equals "+919578777764".
const samePhone = (a, b) => digits(a).slice(-10) === digits(b).slice(-10)

const isLookupable = (field, value) =>
  field === 'email' ? EMAIL_RE.test(value.trim()) : digits(value).length >= 10

/**
 * Customer fields with two-way auto-fill: a known mobile fills email + name,
 * a known email fills mobile + name.
 *
 * Once a customer is matched, editing the *other* fields keeps the match and
 * reports the differences (`changes`), so billing can ask "update this
 * customer?" instead of silently creating a duplicate or failing. Editing the
 * field that produced the match starts over.
 *
 * status: idle | checking | found | modified | new
 */
export default function useCustomerLookup() {
  const [fields, setFields] = useState(EMPTY)
  const [status, setStatus] = useState('idle')
  const [match, setMatch] = useState(null) // { customer, by: 'phone' | 'email' }
  const [autofilled, setAutofilled] = useState([])
  const [lookupError, setLookupError] = useState(null)
  const seq = useRef(0)

  function change(field, value) {
    setLookupError(null)
    const next = { ...fields, [field]: value }

    if (match && field === match.by) {
      // The identifying field changed: this is a different person now.
      autofilled.filter((f) => f !== field).forEach((f) => (next[f] = ''))
      next.name = ''
      setFields(next)
      setMatch(null)
      setAutofilled([])
      setStatus('idle')
      return
    }

    setFields(next)
    if (match) setStatus(diff(match.customer, next).length ? 'modified' : 'found')
    else if (status === 'new' && field !== 'name') setStatus('idle')
  }

  async function lookup(field) {
    const value = fields[field]
    if (match || !isLookupable(field, value)) return

    const current = ++seq.current
    setStatus('checking')
    try {
      const customer = await findCustomer({ [field]: value.trim() })
      if (current !== seq.current) return
      const filled = ['name']
      const next = { ...fields, name: customer.name }
      if (field === 'phone') {
        next.email = customer.email
        filled.push('email')
      } else if (customer.phone) {
        next.phone = customer.phone
        filled.push('phone')
      }
      setFields(next)
      setAutofilled(filled)
      setMatch({ customer, by: field })
      setStatus('found')
    } catch (e) {
      if (current !== seq.current) return
      const err = parseApiError(e)
      if (err.status === 404) {
        setStatus('new')
      } else {
        setStatus('idle')
        setLookupError(err.errors?.[field]?.[0] ?? null)
      }
    }
  }

  /** Adopt a match reported by the server (the owner of a mobile number). */
  function adoptPhoneOwner(customer) {
    setMatch({ customer, by: 'phone' })
    setStatus(diff(customer, fields).length ? 'modified' : 'found')
  }

  function reset() {
    seq.current++
    setFields(EMPTY)
    setStatus('idle')
    setMatch(null)
    setAutofilled([])
    setLookupError(null)
  }

  const changes = match ? diff(match.customer, fields) : []

  return { ...fields, status, match, changes, autofilled, lookupError, change, lookup, adoptPhoneOwner, reset }
}

/** Fields where the form differs from the saved customer. */
function diff(customer, fields) {
  const changes = []
  if (fields.email.trim() && fields.email.trim().toLowerCase() !== customer.email) {
    changes.push({ field: 'email', label: 'Email', saved: customer.email, next: fields.email.trim().toLowerCase() })
  }
  if (fields.phone.trim() && customer.phone && !samePhone(fields.phone, customer.phone)) {
    changes.push({ field: 'phone', label: 'Mobile', saved: customer.phone, next: fields.phone.trim() })
  }
  if (fields.name.trim() && fields.name.trim() !== customer.name) {
    changes.push({ field: 'name', label: 'Name', saved: customer.name, next: fields.name.trim() })
  }
  return changes
}
