// Shared by the report tables.

export const formatDateTime = (iso) =>
  iso ? new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

export const thClass = 'px-5 py-3'
export const tdClass = 'px-5 py-3'
