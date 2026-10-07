// API calls for the purchasing module: suppliers, purchases, purchase and
// sales returns, customer receipts, supplier payments and expenses.
// Lists return the Laravel resource shape { data, meta, links }.
import client from './client'

const clean = (params = {}) =>
  Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined))

// Suppliers
export const getSuppliers = (params) => client.get('/suppliers', { params: clean(params) }).then((r) => r.data)
export const getSupplier = (id) => client.get(`/suppliers/${id}`).then((r) => r.data)
export const createSupplier = (data) => client.post('/suppliers', data).then((r) => r.data.data)
export const updateSupplier = (id, data) => client.put(`/suppliers/${id}`, data).then((r) => r.data.data)
export const deleteSupplier = (id) => client.delete(`/suppliers/${id}`).then((r) => r.data)

// Product picker for purchase / return forms (cost, GST rate, stock here).
export const getPurchasingProducts = () => client.get('/purchasing/products').then((r) => r.data.data)

// Purchases (goods received)
export const getPurchases = (params) => client.get('/purchases', { params: clean(params) }).then((r) => r.data)
export const getPurchase = (id) => client.get(`/purchases/${id}`).then((r) => r.data.data)
export const createPurchase = (data) => client.post('/purchases', data).then((r) => r.data.data)
export const cancelPurchase = (id) => client.post(`/purchases/${id}/cancel`).then((r) => r.data.data)

// Purchase returns (debit notes)
export const getPurchaseReturns = (params) => client.get('/purchase-returns', { params: clean(params) }).then((r) => r.data)
export const getPurchaseReturn = (id) => client.get(`/purchase-returns/${id}`).then((r) => r.data.data)
export const createPurchaseReturn = (data) => client.post('/purchase-returns', data).then((r) => r.data.data)
export const getReturnablePurchases = (supplierId) =>
  client.get('/purchase-returns/purchases', { params: { supplier_id: supplierId } }).then((r) => r.data.data)

// Sales returns (credit notes)
export const getSalesReturns = (params) => client.get('/sales-returns', { params: clean(params) }).then((r) => r.data)
export const getSalesReturn = (id) => client.get(`/sales-returns/${id}`).then((r) => r.data.data)
export const createSalesReturn = (data) => client.post('/sales-returns', data).then((r) => r.data.data)
export const lookupBillForReturn = (number) =>
  client.get('/sales-returns/lookup', { params: { number } }).then((r) => r.data.data)

// Receipts, payments, expenses: kind = 'receipts' | 'payments' | 'expenses'
export const getMoneyEntries = (kind, params) => client.get(`/${kind}`, { params: clean(params) }).then((r) => r.data)
export const createMoneyEntry = (kind, data) => client.post(`/${kind}`, data).then((r) => r.data.data)
export const cancelMoneyEntry = (kind, id) => client.post(`/${kind}/${id}/cancel`).then((r) => r.data.data)
export const getCustomersWithOutstanding = (search) =>
  client.get('/receipts/customers', { params: clean({ search }) }).then((r) => r.data.data)
export const getExpenseAccounts = () => client.get('/expenses/accounts').then((r) => r.data.data)
