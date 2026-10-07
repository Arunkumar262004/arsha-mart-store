// API calls for the accounts module: chart of accounts, vouchers / day book,
// ledgers, outstanding, financial statements, GST returns, day closing and
// sales analysis.
import client from './client'

const data = (r) => r.data.data

// Chart of accounts
export const getAccounts = (params) => client.get('/accounts', { params }).then((r) => r.data)
/** Light list for pickers: [{ id, code, name, group, type, is_party }]. */
export const getAccountOptions = (params) => client.get('/accounts/options', { params }).then(data)
export const createAccount = (payload) => client.post('/accounts', payload).then(data)
export const updateAccount = (id, payload) => client.put(`/accounts/${id}`, payload).then(data)
export const deleteAccount = (id) => client.delete(`/accounts/${id}`)

// Vouchers & day book
export const getVouchers = (params) => client.get('/vouchers', { params }).then((r) => r.data)
export const getVoucher = (id) => client.get(`/vouchers/${id}`).then(data)
export const createVoucher = (payload) => client.post('/vouchers', payload).then(data)
export const deleteVoucher = (id) => client.delete(`/vouchers/${id}`)

// Ledgers & outstanding
export const getLedger = (accountId, params) => client.get(`/accounts/${accountId}/ledger`, { params }).then((r) => r.data)
export const getOutstanding = (params) => client.get('/accounts/outstanding', { params }).then((r) => r.data)

// Financial statements: `name` is trial-balance | profit-loss | balance-sheet
export const getStatement = (name, params) => client.get(`/accounts/${name}`, { params }).then((r) => r.data)

// GST returns: `form` is gstr1 | gstr3b
export const getGstReport = (form, params) => client.get(`/accounts/gst/${form}`, { params }).then((r) => r.data)

// Day closing
export const getDayClosing = (params) => client.get('/accounts/day-closing', { params }).then((r) => r.data)
export const saveDayClosing = (payload) => client.post('/accounts/day-closing', payload).then(data)

// Sales analysis (reports.view)
export const getSalesAnalysis = (params) => client.get('/reports/sales-analysis', { params }).then((r) => r.data)

/**
 * Download a statement / return file (`path` without /api, e.g.
 * "/accounts/balance-sheet" with { format: 'pdf' }). Resolves with { blob, filename }.
 */
export const downloadAccountsFile = (path, params, fallbackName) =>
  client.get(path, { params, responseType: 'blob', timeout: 120000 }).then((r) => ({
    blob: r.data,
    filename: /filename="?([^";]+)"?/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? fallbackName,
  }))
