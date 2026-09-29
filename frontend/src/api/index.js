import client from './client'

const data = (r) => r.data.data

// Auth & profile
export const login = (email, password) => client.post('/login', { email, password, device_name: 'web' }).then((r) => r.data)
export const logout = () => client.post('/logout')
export const getMe = () => client.get('/me').then((r) => r.data)
export const changePassword = (payload) => client.put('/me/password', payload).then((r) => r.data)

// Dashboard & notifications
export const getDashboard = () => client.get('/dashboard').then((r) => r.data)
export const getNotifications = () => client.get('/notifications').then((r) => r.data)

// Billing & orders
export const getProducts = () => client.get('/products').then(data)
/** Look up a customer by { email } or { phone }. Rejects with 404 when unknown. */
export const findCustomer = (query) => client.get('/customers/lookup', { params: query }).then(data)
export const createOrder = (payload) => client.post('/orders', payload).then(data)
export const getOrderHistory = (email, page = 1) =>
  client.get(`/customers/${encodeURIComponent(email)}/orders`, { params: { page } }).then((r) => r.data)

// Inventory
export const getLowStock = (threshold) =>
  client
    .get('/products/low-stock', { params: threshold === '' || threshold == null ? {} : { threshold } })
    .then((r) => r.data)
export const createProduct = (payload) => client.post('/products', payload).then(data)
export const updateProduct = (id, payload) => client.put(`/products/${id}`, payload).then(data)
export const adjustStock = (id, payload) => client.post(`/products/${id}/stock`, payload).then((r) => r.data)
export const getStockMovements = (id, page = 1) =>
  client.get(`/products/${id}/movements`, { params: { page } }).then((r) => r.data)

// Reports: `name` is orders | customers | stock | employees
export const getReport = (name, params) => client.get(`/reports/${name}`, { params }).then((r) => r.data)
export const getEmployeeOptions = () => client.get('/reports/employee-options').then(data)
/** A signed, expiring link to the same download, for the phone QR code: { url, expires_at }. */
export const getReportShareLink = (name, params, format) =>
  client.get(`/reports/${name}/share-link`, { params: { ...params, format } }).then((r) => r.data)
/** Resolves with { blob, filename } for a whole report as `pdf` or `xlsx`. */
export const downloadReport = (name, params, format) =>
  client
    .get(`/reports/${name}/export`, { params: { ...params, format }, responseType: 'blob', timeout: 120000 })
    .then((r) => ({
      blob: r.data,
      filename: /filename="?([^";]+)"?/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? `${name}-report.${format}`,
    }))

// Settings (admin)
export const getUsers = () => client.get('/users').then(data)
export const createUser = (payload) => client.post('/users', payload).then(data)
export const updateUser = (id, payload) => client.put(`/users/${id}`, payload).then(data)
export const deleteUser = (id) => client.delete(`/users/${id}`)
export const resetUserPassword = (id, payload) => client.put(`/users/${id}/password`, payload).then((r) => r.data)
export const getRoles = () => client.get('/roles').then(data)
export const getPermissionCatalog = () => client.get('/permissions').then(data)
export const createRole = (payload) => client.post('/roles', payload).then(data)
export const updateRole = (id, payload) => client.put(`/roles/${id}`, payload).then(data)
export const deleteRole = (id) => client.delete(`/roles/${id}`)
