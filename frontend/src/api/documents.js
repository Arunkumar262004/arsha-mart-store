// API calls for the sales documents module: tax invoices, quotations,
// delivery challans and stock transfers. List calls resolve with the whole
// Laravel response ({ data, meta, ... }); single documents with `data`.
import client from './client'

const data = (r) => r.data.data
const body = (r) => r.data

/** Drops empty filters so the URL stays clean. */
const clean = (params = {}) => Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v != null))

/** Resolves with { blob, filename } for a PDF download. */
const pdf = (url, fallback) =>
  client.get(url, { responseType: 'blob', timeout: 120000 }).then((r) => ({
    blob: r.data,
    filename: /filename="?([^";]+)"?/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? fallback,
  }))

/** Products with the current store's stock, for the document forms. */
export const getDocumentProducts = () => client.get('/documents/products').then(data)

// Tax invoices (every bill). List: { data, meta, summary }.
export const getInvoices = (params) => client.get('/invoices', { params: clean(params) }).then(body)
export const getInvoice = (id) => client.get(`/invoices/${id}`).then(data)
export const downloadInvoicePdf = (id) => pdf(`/invoices/${id}/pdf`, `invoice-${id}.pdf`)

// Quotations
export const getQuotations = (params) => client.get('/quotations', { params: clean(params) }).then(body)
export const getQuotation = (id) => client.get(`/quotations/${id}`).then(data)
export const createQuotation = (payload) => client.post('/quotations', payload).then(data)
export const updateQuotation = (id, payload) => client.put(`/quotations/${id}`, payload).then(data)
export const setQuotationStatus = (id, status) => client.put(`/quotations/${id}/status`, { status }).then(data)
export const deleteQuotation = (id) => client.delete(`/quotations/${id}`)
/** Resolves with { data: order, quotation }. */
export const convertQuotation = (id, payload) => client.post(`/quotations/${id}/convert`, payload).then(body)

// Delivery challans
export const getChallans = (params) => client.get('/challans', { params: clean(params) }).then(body)
export const getChallan = (id) => client.get(`/challans/${id}`).then(data)
export const createChallan = (payload) => client.post('/challans', payload).then(data)
export const returnChallan = (id) => client.post(`/challans/${id}/return`).then(data)
export const cancelChallan = (id) => client.post(`/challans/${id}/cancel`).then(data)
/** Bill issued challans of one customer as one order; resolves with the order. */
export const invoiceChallans = (payload) => client.post('/challans/invoice', payload).then(data)

// Stock transfers
export const getTransfers = (params) => client.get('/transfers', { params: clean(params) }).then(body)
export const getTransfer = (id) => client.get(`/transfers/${id}`).then(data)
export const getTransferDestinations = () => client.get('/transfers/destinations').then(data)
export const createTransfer = (payload) => client.post('/transfers', payload).then(data)
export const receiveTransfer = (id) => client.post(`/transfers/${id}/receive`).then(data)
export const cancelTransfer = (id) => client.post(`/transfers/${id}/cancel`).then(data)
